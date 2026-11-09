<?php

namespace Tests\Feature;

use App\Ai\Agents\XLinkAssistant;
use App\Ai\Tools\OnlinePppoeCount;
use App\Ai\Tools\RevenueSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Models\Conversation;
use Spatie\Permission\Models\Permission;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiAssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_exposes_two_named_read_only_business_tools(): void
    {
        $agent = new XLinkAssistant(User::factory()->make());
        $tools = collect($agent->tools())->keyBy(fn ($tool) => $tool->name());

        $this->assertSame(['online_pppoe_count', 'revenue_summary'], $tools->keys()->sort()->values()->all());
    }

    public function test_revenue_tool_denies_users_without_billing_report_permission(): void
    {
        $tool = new RevenueSummary(User::factory()->create());
        $result = json_decode((string) $tool->handle(new Request([])), true);

        $this->assertSame('forbidden', $result['error'] ?? null);
        $this->assertArrayNotHasKey('total', $result);
    }

    public function test_live_pppoe_tool_denies_users_without_network_inventory_permission(): void
    {
        $tool = new OnlinePppoeCount(User::factory()->create());
        $result = json_decode((string) $tool->handle(new Request([])), true);

        $this->assertSame('forbidden', $result['error'] ?? null);
        $this->assertArrayNotHasKey('total', $result);
    }

    public function test_fake_agent_executes_a_registered_tool_call_without_external_api_access(): void
    {
        $user = User::factory()->create();
        XLinkAssistant::fake([
            new ToolCall('tool-call-1', 'revenue_summary', []),
            'The report tool was executed.',
        ]);

        $response = (new XLinkAssistant($user))->prompt('How much have I collected today?', provider: 'gemini', model: 'fake-model');

        $this->assertSame('The report tool was executed.', $response->text);
        $this->assertSame('revenue_summary', $response->toolCalls->first()?->name);
        $this->assertSame('forbidden', json_decode((string) $response->toolResults->first()?->result, true)['error'] ?? null);
    }
    public function test_agent_persists_conversations_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        XLinkAssistant::fake([
            new ToolCall('tool-call-2', 'revenue_summary', []),
            'Conversation was saved.',
        ]);

        $agent = (new XLinkAssistant($user))->forUser($user);
        $response = $agent->prompt('How much have I collected today?', provider: 'gemini', model: 'fake-model');

        $this->assertNotNull($response->conversationId);
        $this->assertDatabaseHas('agent_conversations', [
            'id' => $response->conversationId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
        ]);
        $this->assertDatabaseHas('agent_conversation_messages', [
            'conversation_id' => $response->conversationId,
            'agent' => XLinkAssistant::class,
            'role' => 'user',
        ]);
    }

    public function test_history_endpoints_only_return_the_authenticated_users_conversations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Permission::findOrCreate('network-inventory', 'web');
        $user->givePermissionTo('network-inventory');
        $other->givePermissionTo('network-inventory');

        XLinkAssistant::fake(['Saved reply.']);
        $response = (new XLinkAssistant($user))->forUser($user)->prompt('Show my network status.', provider: 'gemini', model: 'fake-model');
        $id = $response->conversationId;

        $this->actingAs($user)->getJson('/ai-engineer/history')
            ->assertOk()->assertJsonPath('items.0.id', $id);
        $this->actingAs($user)->getJson('/ai-engineer/history/'.$id)
            ->assertOk()->assertJsonPath('messages.0.content', 'Show my network status.');
        $this->actingAs($other)->getJson('/ai-engineer/history/'.$id)->assertNotFound();
    }

}
