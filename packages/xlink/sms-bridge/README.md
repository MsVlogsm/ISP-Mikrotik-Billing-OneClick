# SmsBridge: Unified Bangladeshi SMS Gateway for Laravel 🇧🇩

A simple, unified Laravel SMS package to seamlessly connect with multiple Bangladeshi SMS Gateways (like AdnSms, ElitBuzz, AlphaSms) using a **single `.env` configuration structure**. No more confusing or duplicated environment variables when you need to switch SMS providers!

## 🔥 The Ultimate Smart Auto-Failover System
**Never lose a critical SMS (OTP, Alerts) again!** If you add multiple gateways to your system (e.g., AdnSms, Infobip, SSL Wireless), SmsBridge will automatically try them one by one based on your set priorities. **If your primary gateway API is down, out of balance, or times out, the system will instantly reroute and send the SMS automatically through the next active gateway behind the scenes!** No complicated `try-catch` loops required.

## Key Features
- **Smart Auto-Failover** — Automatically switch to the next gateway if the primary one fails.
- **Multiple Gateways** — Seamlessly connect to 12+ SMS providers simultaneously.
- **Bulk SMS** — Send to multiple recipients in a single call.
- **Queue Support** — Dispatch SMS to background jobs for high performance.
- **Laravel Notifications** — Native integration with Laravel's notification system.
- **BD Phone Formatting** — Automatic phone number normalization to `8801XXXXXXXXX` format.
- **Extensible** — Create custom drivers for any SMS gateway.

---

## Installation

1. Add the package to your `composer.json` (if testing locally without Packagist yet):
    ```json
    "repositories": [
        {
            "type": "path",
            "url": "path/to/SmsBridge"
        }
    ]
    ```

2. Require the package via Composer:
    ```bash
    composer require xlink/sms-bridge
    ```

3. Publish the configuration file:
    ```bash
    php artisan vendor:publish --tag="sms-bridge-config"
    ```

---

## Configuration (Database & UI Based - No `.env` required for credentials)

We've removed the necessity to use `.env` files for adding credentials! The package now features a fully-fledged Admin Panel that stores gateway information securely inside your Database.

1. Publish the views, database migrations, and configuration to your project:
    ```bash
    php artisan vendor:publish --provider="XLink\SmsBridge\Providers\SmsBridgeServiceProvider"
    ```

    *(Or run them individually via `--tag="sms-bridge-config"` and `--tag="sms-bridge-views"`)*

2. Crucial: Run the database migrations to create the `sms_gateways` table:
    ```bash
    php artisan migrate
    ```

3. Log into your main Laravel application.
4. Visit the secured gateway UI at: **`your-app.test/sms-bridge`**
5. Add your gateways (Infobip, AlphaSms, Elitbuzz etc) via the graphic interface. You can set the priority for fallback!

### Multi-Gateway Activation & Failover Logic

The true power of SmsBridge lies in its native **Smart Failover System**. You do not have to rely on a single SMS provider!

1. **Enable Multiple Gateways (The Checkbox System):**
   In the Admin UI, you will see an `Active` checkbox switch for each gateway. You can enable as many gateways as you want simultaneously. Only gateways that are checked as `Active` will participate in the SMS sending process.

2. **Priority Ordering:**
   When adding or editing a gateway, you can set a `Priority` number (e.g., 1, 2, 3) and mark one as `Default`.
   * The `Default` gateway is always prioritized first.
   * The rest of the `Active` gateways are sorted by their priority numbers.

3. **How the Failover Works:**
   When you send an SMS using `XLink\SmsBridge\Services\SmsBridgeService::sendSms()`, the system does the following:
   * It attempts to send the SMS using the **first active gateway** (the Default or highest priority one).
   * If that gateway **fails** (e.g., due to an invalid API key, insufficient balance, API downtime, or timeout), the system *automatically catches the error*.
   * It immediately falls back and tries to send the SMS using the **next active gateway** in your priority list.
   * This process continues until the SMS is successfully delivered or all active gateways have failed.

This guarantees maximum delivery rates for your OTPs and critical messages without writing complicated `try-catch` loops yourself!

---

## Usage

### Using the Facade (Recommended)

You can send SMS from any Controller, Job, or Service by importing the `SmsBridge` facade:

```php
use XLink\SmsBridge\Facades\SmsBridge;

// Send a simple message using the fluent builder
$response = SmsBridge::to('01712345678')
    ->message('Hello from SmsBridge!')
    ->send();

// Send bulk SMS by passing an array of numbers
$responseBulk = SmsBridge::to(['01712345678', '01987654321'])
    ->message('Hello everyone!')
    ->send();

// (Legacy Syntax is still supported)
// $response = SmsBridge::send('01712345678', 'Hello from SmsBridge!');

// Check the raw array response from the API
if (isset($response['error'])) {
    \Log::error("SMS Failed: ", $response);
}
```

### Background Queue Support

If you want to dispatch SMS via Laravel's Queue system to keep your application fast, use the `queue()` method instead of `send()`:

```php
// Dispatch to queue
SmsBridge::to('01712345678')
    ->message('This message is queued!')
    ->queue();
```
*Note: Make sure your `QUEUE_CONNECTION` in `.env` is configured properly (e.g., database, redis) and you are running `php artisan queue:work`.*

### Advanced API Endpoints

You can fetch Account Balance and Profile details from supported Gateways natively:

```php
use XLink\SmsBridge\Facades\SmsBridge;

// Get the current balance of the active gateway (returns string or null)
$balance = SmsBridge::balance();

// Get the profile info array of the active gateway (returns array or null)
$profile = SmsBridge::profile();
```

In the Admin UI, you can specify modular endpoints for these requests:
- **Base API URL**: e.g. `https://api.example-gateway.com/v3`
- **Send SMS Ext**: e.g. `sms/send`
- **Balance Ext**: e.g. `balance`
- **Profile Ext**: e.g. `me`

### Dependency Injection

Or, inject the underlying manager dynamically:

```php
use XLink\SmsBridge\SmsBridgeManager;

class NotificationController extends Controller
{
    public function sendWelcomeSms(SmsBridgeManager $smsBridge)
    {
        $smsBridge->driver()
            ->to('01712345678')
            ->message('Welcome to our platform!')
            ->send();
    }
}
```

---

## Supported Gateways
Currently supported `SMS_BRIDGE_GATEWAY` strings:
- `adnsms` (AdnSms)
- `elitbuzz` (ElitBuzz)
- `alphasms` (AlphaSms)
- `banglalink` (Banglalink)
- `bulksms` (BulkSMSBD)
- `esms` (eSMS)
- `grameenphone` (Grameenphone)
- `greenweb` (GreenWeb BD)
- `infobip` (Infobip)
- `mimsms` (MimSMS)
- `robi` (Robi)
- `smsnoc` (SMSNOC)
- `ssl` (SSL Wireless)

## Auto-Log & Testing Integration

SmsBridge includes a built-in `log` gateway designed specifically for local testing to stop you from wasting real SMS credits. It comes with some incredibly smart defaults:

1. **Implicit Fallback (Zero Config):** If you install the package and do not configure *any* active gateways in the admin panel, SmsBridge will automatically fall back to the `log` gateway. All your dispatched SMS messages will safely write to `storage/logs/laravel.log` without crashing!
2. **Mutual Exclusivity:** If you explicitly enable the `log` gateway from the admin UI, the system will automatically disable all your other real gateways. Conversely, if you enable a real gateway (e.g., Banglalink, SSL Wireless, etc.), the system automatically disables the `log` gateway.

This ensures you never accidentally send real SMS while debugging, or vice versa!

### Run Automated Tests
```bash
composer test          # Run automated SDK tests
composer test-coverage # Run tests with coverage
composer analyse       # Run static analysis
```

## License
This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
