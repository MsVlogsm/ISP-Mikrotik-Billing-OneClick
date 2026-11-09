<?php

namespace Tests\Feature;

use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerDetailsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_details_page_renders_profile_summary_and_grouped_sections(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($admin);

        $customer = CustomersInfo::create([
            'customer_unique_id' => 'DETAIL-PAGE-001',
            'customer_name' => 'Profile Layout Test',
            'mobile' => '01700000001',
            'email' => 'profile@example.test',
            'status' => 'active',
            'connection_date' => now()->subMonth()->toDateString(),
        ]);

        BillingInfo::create([
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'billing_type' => 'prepaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 0,
            'auto_disable' => true,
            'auto_disable_date' => now()->addDays(20),
        ]);

        $response = $this->get(route('customer.details', encrypt($customer->customer_unique_id)));

        $html = $response->getContent();
        $bodyStart = strpos($html, '<body');
        $html = $bodyStart === false ? $html : substr($html, $bodyStart);
        $actionsPosition = strpos($html, '<section class="customer-profile-actions');
        $summaryPosition = strpos($html, '<section class="customer-snapshot-grid');
        $this->assertNotFalse($actionsPosition, 'Customer action toolbar must render.');
        $this->assertNotFalse($summaryPosition, 'Customer summary cards must render.');
        $this->assertLessThan(
            $summaryPosition,
            $actionsPosition,
            'Customer actions must appear before the summary cards near the top of the profile.'
        );

        $response->assertOk()
            ->assertSee('Customer Profile')
            ->assertSee('Profile Layout Test')
            ->assertSee('Customer Information')
            ->assertSee('Connection &amp; PPPoE', false)
            ->assertSee('Billing Information')
            ->assertSee('ONU / OLT Information')
            ->assertSee('Account &amp; Contact', false)
            ->assertSee('Service Inventory')
            ->assertSee('Activity Log')
            ->assertSee('No ONU mapping found')
            ->assertSee('View Ledger')
            ->assertSee('Change Owner')
            ->assertSee('Change Class')
            ->assertSee('Change Password')
            ->assertSee('Monthly Recharge')
            ->assertSee('Change Billing Date')
            ->assertSee('Grace Time')
            ->assertSee('Cash / Credit')
            ->assertSee('Ticket History')
            ->assertSee('Real-Time Graph')
            ->assertSee('Send SMS')
            ->assertSee('POS Print')
            ->assertSee('Remote Login');
    }
}
