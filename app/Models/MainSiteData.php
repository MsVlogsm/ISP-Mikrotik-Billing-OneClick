<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class MainSiteData extends Model
{
    use HasFactory;

    private const ENCRYPTED_SETTING_TYPES = [
        'site_secret_key',
        'site_secret_value',
        'payment_bkash_username',
        'payment_bkash_password',
        'payment_bkash_app_key',
        'payment_bkash_app_secret',
        'payment_nagad_merchant_id',
        'payment_nagad_public_key',
        'payment_nagad_private_key',
        'payment_sslcommerz_store_id',
        'payment_sslcommerz_store_password',
    ];

    public static function encryptedSettingTypes(): array
    {
        return self::ENCRYPTED_SETTING_TYPES;
    }

    public static function isEncryptedSetting(string $type): bool
    {
        return in_array($type, self::ENCRYPTED_SETTING_TYPES, true);
    }

    private static function decryptSettingValue(string $type, mixed $value): mixed
    {
        if (! self::isEncryptedSetting($type) || ! is_string($value)) {
            return $value;
        }

        if (! str_starts_with($value, 'enc:v1:')) {
            // Backward-compatible read of existing plaintext values.
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, 7));
        } catch (\Throwable $exception) {
            report($exception);
            return null;
        }
    }

    private static function encryptSettingValue(string $type, mixed $value): mixed
    {
        if (! self::isEncryptedSetting($type) || ! is_string($value) || trim($value) === '') {
            return $value;
        }

        if (str_starts_with($value, 'enc:v1:')) {
            return $value;
        }

        return 'enc:v1:'.Crypt::encryptString($value);
    }

    protected $fillable = [
        'type',
        'value',
    ];

    /**
     * Get a specific value by its type name, returning the decoded array or string.
     */
    public static function getValue(string $type, $default = null)
    {
        // Sensitive values are decrypted directly from the database and are
        // never placed in the long-lived shared settings cache.
        if (self::isEncryptedSetting($type)) {
            $record = self::where('type', $type)->first();

            if (! $record) {
                return $default;
            }

            return self::decryptSettingValue($type, $record->getRawOriginal('value'));
        }

        try {
            $active = self::getActive();
            if (property_exists($active, $type)) {
                return $active->$type;
            }
        } catch (\Throwable $e) {
            // Fallback to database query if cache fails
        }

        $record = self::where('type', $type)->first();

        if (! $record) {
            return $default;
        }

        // Return array if JSON, otherwise string
        $raw = $record->getRawOriginal('value');
        $decoded = @json_decode($raw, true);
        $value = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $record->value;

        return self::decryptSettingValue($type, $value);
    }

    /**
     * Update or create a specific key-value record
     */
    public static function setValue(string $type, $value): self
    {
        // Encrypt sensitive gateway/site credentials at rest before persistence.
        $value = self::encryptSettingValue($type, $value);

        // Encode to JSON if array
        if (is_array($value)) {
            $value = json_encode($value);
        }

        return self::updateOrCreate(
            ['type' => $type],
            ['value' => $value]
        );
    }

    /**
     * Get all active data and return it as an object
     * so that existing Blade templates ($siteData->hero_title) don't break.
     */
    public static function getActive()
    {
        return Cache::rememberForever('main_site_data_active', function () {
            $data = new \stdClass;
            $records = self::all();

            foreach ($records as $record) {
                // Return array if JSON, otherwise string
                $raw = $record->getRawOriginal('value');
                $decoded = @json_decode($raw, true);
                $value = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $record->value;
                $key = $record->type;
                // Encrypted credentials are deliberately omitted from the
                // persistent cache. getValue() reads them from storage directly.
                $data->$key = self::isEncryptedSetting($key) ? null : $value;
            }

            // Defaults in case they don't exist in DB
            $defaults = [
                'is_active' => true,
                'hero_title' => null,
                'hero_subtitle' => null,
                'hero_slides' => [],
                'about_tagline' => null,
                'about_title' => null,
                'about_body' => null,
                'services' => [],
                'gallery_items' => [],
                'packages_section_title' => null,
                'packages_section_subtitle' => null,
                'registration_link' => null,
                'team_title' => null,
                'team_subtitle' => null,
                'team_members' => [],
                'blog_title' => null,
                'blog_subtitle' => null,
                'blog_posts' => [],
                'testimonial_title' => null,
                'testimonial_subtitle' => null,
                'testimonials' => [],
                'contact_title' => null,
                'contact_subtitle' => null,
                'google_map_embed' => null,
                'footer_copyright' => null,
                'social_facebook' => null,
                'social_twitter' => null,
                'social_instagram' => null,
                'social_youtube' => null,
                'social_whatsapp' => null,
                'valuable_clients' => [],
                'theme_preset' => 'fintech',
                'theme_name' => 'ocean_blue',
                'theme_primary_color' => '#0284c7',
                'theme_accent_color' => '#38bdf8',
                'theme_card_style' => 'glass',
                'theme_border_radius' => '16px',
                'theme_font_size' => 'medium',
                'theme_font_family' => 'Outfit',
                'theme_nav_style' => 'sidebar',
                'theme_widget_style' => 'glass',
                'theme_mode' => 'dark',
                'theme_transparency' => '0.5',
                'theme_blur' => '16px',
                'theme_animations' => '1.0',
                'theme_gradient_intensity' => '0.7',
            ];

            foreach ($defaults as $key => $defaultValue) {
                if (! property_exists($data, $key)) {
                    $data->$key = $defaultValue;
                }
            }

            return $data;
        });
    }

    protected static function booted(): void
    {
        // Only invalidate the cached MainSiteData snapshot; never flush unrelated app caches.
        static::saved(fn () => Cache::forget('main_site_data_active'));
        static::deleted(fn () => Cache::forget('main_site_data_active'));
    }
}
