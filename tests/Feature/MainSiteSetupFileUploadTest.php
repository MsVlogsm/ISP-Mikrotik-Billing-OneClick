<?php

namespace Tests\Feature;

use App\Livewire\MainSiteSetup;
use App\Models\MainSiteData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MainSiteSetupFileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
    }

    public function test_legacy_scalar_logo_state_is_rendered_as_single_file_upload_array(): void
    {
        MainSiteData::setValue('site_logo', 'brand/existing-logo.png');

        Livewire::test(MainSiteSetup::class)
            ->assertOk()
            ->assertSee('Main Site Logo');
    }

    public function test_saving_single_logo_keeps_only_the_latest_path_and_avoids_max_files_validation_error(): void
    {
        Storage::disk('public')->put('brand/old-logo.png', 'old image');
        Storage::disk('public')->put('brand/new-logo.png', 'new image');

        Livewire::test(MainSiteSetup::class)
            ->set('data.site_logo', [
                'old-file' => 'brand/old-logo.png',
                'new-file' => 'brand/new-logo.png',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('brand/new-logo.png', MainSiteData::getValue('site_logo'));
    }
}
