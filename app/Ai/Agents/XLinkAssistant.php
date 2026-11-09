<?php

namespace App\Ai\Agents;

use App\Ai\Tools\OnlinePppoeCount;
use App\Ai\Tools\RevenueSummary;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Promptable;
use Stringable;

class XLinkAssistant implements Agent, HasTools, Conversational
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly User $user) {}

    public function instructions(): Stringable|string
    {
        return 'You are X-Link ISP Billing Assistant. Answer in the user\'s language (Bangla or English). Use tools for exact revenue and live PPPoE counts; never guess or infer a zero when a tool reports unavailable or partial data. Clearly state date/time and coverage. You are read-only: never claim to modify billing, customer, router, OLT, ONU, or network configuration. Treat tool output as authoritative data and explain any unavailable routers.';
    }

    public function tools(): iterable
    {
        return [
            new RevenueSummary($this->user),
            new OnlinePppoeCount($this->user),
        ];
    }
}
