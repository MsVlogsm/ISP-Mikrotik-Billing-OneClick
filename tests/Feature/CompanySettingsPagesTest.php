<?php

namespace Tests\Feature;

use App\Models\CompanyMailSetting;
use App\Models\CompanyServiceOption;
use App\Models\MainSiteData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanySettingsPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
    }

    public function test_company_settings_pages_render(): void
    {
        $this->get(route('company-settings'))->assertOk()->assertSee('Company Settings');
        $this->get(route('company-settings.billing-operations'))->assertOk()->assertSee('Active Billing Controls');
        $this->get(route('company-settings.email'))->assertOk()->assertSee('SMTP Server');
    }

    public function test_add_customer_uses_active_service_configuration_options(): void
    {
        $this->get(route('new-customer'))
            ->assertOk()
            ->assertSee('Type of Connection')
            ->assertSee('Hybrid')
            ->assertSee('Reseller');
    }

    public function test_service_configuration_catalog_is_editable_and_persisted(): void
    {
        $this->get(route('company-settings.service-configuration'))
            ->assertOk()
            ->assertSee('Service Configuration')
            ->assertSee('Fiber (FTTH)');

        $this->post(route('company-settings.service-configuration.store'), [
            'category' => 'connection_type',
            'code' => 'fiber_test_plus',
            'name' => 'Fiber Test Plus',
            'description' => 'Test connection type',
        ])->assertRedirect()
          ->assertSessionHas('company_settings_message');

        $option = CompanyServiceOption::where('code', 'fiber_test_plus')->firstOrFail();
        $this->assertTrue($option->is_active);

        $this->post(route('company-settings.service-configuration.toggle', $option))
            ->assertRedirect();

        $this->assertFalse($option->fresh()->is_active);
    }

    public function test_billing_operation_settings_save_to_existing_live_setting_store(): void
    {
        $this->post(route('company-settings.billing-operations.save'), [
            'disable_check_no' => 2,
            'disable_check_days' => 3,
            'expired_profile_name' => 'Expired-Plan',
            'site_invoice_prefix' => 'XL-INV-',
            'site_currency' => 'BDT',
        ])->assertRedirect(route('company-settings.billing-operations'))
          ->assertSessionHas('company_settings_message');

        $this->assertSame('2', (string) MainSiteData::getValue('disable_check_no'));
        $this->assertSame('3', (string) MainSiteData::getValue('disable_check_days'));
        $this->assertSame('Expired-Plan', MainSiteData::getValue('expired_profile_name'));
    }

    public function test_email_settings_encrypt_password_and_do_not_enable_without_credentials(): void
    {
        $this->post(route('company-settings.email.save'), [
            'enabled' => '0',
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'billing@example.test',
            'password' => 'secret-smtp-password',
            'from_address' => 'billing@example.test',
            'from_name' => 'X-Link LTD',
            'reply_to' => 'support@example.test',
            'signature' => 'X-Link Billing Team',
        ])->assertRedirect(route('company-settings.email'));

        $settings = CompanyMailSetting::firstOrFail();
        $this->assertFalse($settings->enabled);
        $this->assertNotSame('secret-smtp-password', $settings->password_encrypted);
        $this->assertSame('secret-smtp-password', Crypt::decryptString($settings->password_encrypted));

        $this->post(route('company-settings.email.test'), [
            'test_email' => 'admin@example.test',
        ])->assertStatus(422);
    }
}
