<?php

namespace Tests\Feature;

use App\Jobs\SendBulkSmsJob;
use App\Models\CustomersInfo;
use App\Models\SmsDispatchLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SmsServicePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
    }

    public function test_sms_overview_send_and_reports_pages_render_without_records(): void
    {
        $this->get(route('sms-service'))
            ->assertOk()
            ->assertSee('SMS Service Overview')
            ->assertSee('Recipient Attempts');

        $this->get(route('sms-service.send'))
            ->assertOk()
            ->assertSee('Compose SMS')
            ->assertSee('Send SMS Now')
            ->assertSee('name="recipient_type"', false);

        $this->get(route('sms-service.reports'))
            ->assertOk()
            ->assertSee('SMS Reports & Delivery History')
            ->assertSee('No SMS delivery attempts match these filters.');
    }

    public function test_sms_dispatch_export_contains_a_stable_header(): void
    {
        $this->get(route('sms-service.reports.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('Log ID')
            ->assertSee('Gateway Response');
    }

    public function test_direct_sms_requires_an_explicit_valid_recipient_and_message(): void
    {
        $this->post(route('sms-service.send.store'), [
            'recipient_type' => 'customer',
            'sms_type' => 'General',
            'message' => '',
        ])->assertSessionHasErrors(['customer_id', 'message']);

        $this->assertDatabaseCount('sms_dispatch_logs', 0);
    }

    public function test_bulk_job_records_missing_mobile_as_failed_without_sending(): void
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'SMS-NO-MOBILE-01',
            'customer_name' => 'SMS Missing Mobile',
            'mobile' => null,
            'status' => 'active',
            'disable_count' => 0,
        ]);

        (new SendBulkSmsJob([$customer->id], 'Test message for {CUSTOMER_NAME}', (int) auth()->id()))->handle();

        $this->assertDatabaseHas('sms_dispatch_logs', [
            'customer_id' => $customer->id,
            'recipient' => '',
            'sms_type' => 'Bulk SMS',
            'status' => 'failed',
            'gateway_response' => 'Customer has no mobile number.',
        ]);
        $this->assertSame(1, SmsDispatchLog::where('customer_id', $customer->id)->count());
    }
}
