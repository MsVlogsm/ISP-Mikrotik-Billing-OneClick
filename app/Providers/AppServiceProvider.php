<?php

namespace App\Providers;

use App\Services\Olt\Adapters\VsolSnmpReadOnlyAdapter;
use App\Services\Olt\Adapters\MultiVendorSnmpReadOnlyAdapter;
use App\Services\Olt\OltReadOnlyAdapterManager;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Blaze\Blaze;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OltReadOnlyAdapterManager::class, function ($app) {
            $manager = new OltReadOnlyAdapterManager();
            $manager->register($app->make(VsolSnmpReadOnlyAdapter::class));
            $manager->register($app->make(MultiVendorSnmpReadOnlyAdapter::class));
            return $manager;
        });

        $forwardedPort = $_SERVER['HTTP_X_FORWARDED_PORT'] ?? null;
        $requestPort = $forwardedPort !== null && ctype_digit((string) $forwardedPort)
            ? (int) $forwardedPort
            : (int) ($_SERVER['SERVER_PORT'] ?? 0);
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($requestPort === 8082 || str_starts_with($requestHost, 'portal.')) {
            config(['session.cookie' => 'portal_session']);
        }

        // Dynamically register Telescope only if the package is installed
        if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class) && class_exists(\App\Providers\TelescopeServiceProvider::class)) {
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        // Keep one public domain and generate URLs using the active public port.
        $requestHost = request()->getHost();
        $requestPort = (int) request()->getPort();
        if ($requestPort === 8081 || str_starts_with($requestHost, 'billing.')) {
            URL::forceRootUrl('http://bill.xlinkbd.net:8081');
        } elseif ($requestPort === 8082 || str_starts_with($requestHost, 'portal.')) {
            URL::forceRootUrl('http://bill.xlinkbd.net:8082');
        }


        // Apply explicitly enabled company SMTP settings to all mail sends, including queue workers.
        // The SMTP password is encrypted at rest; failures fall back to the existing .env mail config.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('company_mail_settings')) {
                $mailSettings = \Illuminate\Support\Facades\DB::table('company_mail_settings')->orderBy('id')->first();
                if ($mailSettings && (bool) $mailSettings->enabled && $mailSettings->mailer === 'smtp') {
                    $password = null;
                    if (! empty($mailSettings->password_encrypted)) {
                        try { $password = \Illuminate\Support\Facades\Crypt::decryptString($mailSettings->password_encrypted); }
                        catch (\Throwable $e) { $password = null; }
                    }
                    config([
                        'mail.default' => 'smtp',
                        'mail.mailers.smtp.transport' => 'smtp',
                        'mail.mailers.smtp.scheme' => $mailSettings->encryption === 'ssl' ? 'smtps' : ($mailSettings->encryption === 'tls' ? 'smtp' : null),
                        'mail.mailers.smtp.host' => $mailSettings->host,
                        'mail.mailers.smtp.port' => (int) $mailSettings->port,
                        'mail.mailers.smtp.username' => $mailSettings->username,
                        'mail.mailers.smtp.password' => $password,
                        'mail.from.address' => $mailSettings->from_address,
                        'mail.from.name' => $mailSettings->from_name,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Keep application boot safe during migrations and when the settings table is unavailable.
        }

        // Set dynamic application locale from database settings
        try {
            if (class_exists(\App\Models\MainSiteData::class) && \Illuminate\Support\Facades\Schema::hasTable('main_site_data')) {
                if (app()->runningInConsole()) {
                    $locale = \App\Models\MainSiteData::getValue('site_locale', 'en');
                } else {
                    $host = request()->getHost();
                    if ($host && (str_starts_with($host, 'billing.') || str_starts_with($host, 'portal.'))) {
                        $locale = \App\Models\MainSiteData::getValue('site_locale', 'en');
                    } else {
                        $locale = \App\Models\MainSiteData::getValue('main_site_locale', 'en');
                    }
                }
                \Illuminate\Support\Facades\App::setLocale($locale);
            }
        } catch (\Throwable $e) {
            // Avoid issues during setup or migrations
        }

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) {
            try {
                if (! \Illuminate\Support\Facades\Schema::hasTable('company_mail_settings')) return;
                $signature = trim((string) \Illuminate\Support\Facades\DB::table('company_mail_settings')->orderBy('id')->value('signature'));
                if ($signature === '' || ! $event->message instanceof \Symfony\Component\Mime\Email) return;
                $message = $event->message;
                $text = $message->getTextBody();
                if (is_string($text) && trim($text) !== '' && ! str_contains($text, $signature)) {
                    $message->text(rtrim($text)."\n\n".$signature);
                }
                $html = $message->getHtmlBody();
                if (is_string($html) && trim($html) !== '' && ! str_contains(strip_tags($html), $signature)) {
                    $message->html($html.'<hr><p>'.nl2br(e($signature)).'</p>');
                }
            } catch (\Throwable $e) {
                // A signature setting must never block an outbound message.
            }
        });

        Gate::before(function ($user, $ability) {
            if (method_exists($user, 'hasRole')) {
                return $user->hasRole('Super Admin') ? true : null;
            }

            return null;
        });

        Gate::define('viewLogViewer', function ($user = null) {
            return auth()->check() && hasAccess(['Super Admin'], ['all-customer']);
        });

        // Optimize Livewire components in the specified directory
        Blaze::optimize()->in(resource_path('views/components'));

        Paginator::useBootstrapFive();

        Auth::provider('pppoe_provider', function ($app, array $config) {
            return new class($app['hash'], $config['model']) extends EloquentUserProvider
            {
                public function validateCredentials(Authenticatable $user, array $credentials)
                {
                    $plain = $credentials['password'];
                    // getAuthPassword() calls the model's custom getter, returning decrypted password if encrypted,
                    // or the raw value if it is legacy plaintext / bcrypt hash.
                    $auth_password = $user->getAuthPassword();

                    // Check plain text (includes newly decrypted passwords and legacy plain text)
                    if ($plain === $auth_password) {
                        return true;
                    }

                    // Fallback to standard hashing only if it looks like a legacy bcrypt hash
                    if (str_starts_with($auth_password, '$2y$') || str_starts_with($auth_password, '$2a$')) {
                        return parent::validateCredentials($user, $credentials);
                    }

                    return false;
                }
            };
        });

        // Listen to Login Event
        Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
            $user = $event->user;
            if ($user) {
                $ip = request()->ip();
                $userAgent = request()->userAgent();
                $username = $user instanceof \App\Models\PPPSecrets ? $user->username : ($user->email ?? $user->name ?? 'Unknown');

                \App\Models\UserLoginLog::create([
                    'authenticatable_id' => $user->id,
                    'authenticatable_type' => get_class($user),
                    'username' => $username,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'action' => 'login',
                ]);

                // Track client portal login count and session duration
                if ($user instanceof \App\Models\PPPSecrets) {
                    $user->increment('login_count');
                    session(['portal_login_time' => now()]);
                }
            }
        });

        // Listen to Logout Event
        Event::listen(\Illuminate\Auth\Events\Logout::class, function ($event) {
            $user = $event->user;
            if ($user) {
                $ip = request()->ip();
                $userAgent = request()->userAgent();
                $username = $user instanceof \App\Models\PPPSecrets ? $user->username : ($user->email ?? $user->name ?? 'Unknown');

                \App\Models\UserLoginLog::create([
                    'authenticatable_id' => $user->id,
                    'authenticatable_type' => get_class($user),
                    'username' => $username,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'action' => 'logout',
                ]);
            }
        });
    }
}
