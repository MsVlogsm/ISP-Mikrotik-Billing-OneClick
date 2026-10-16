# How to Add a New SMS Gateway

SmsBridge is built on a modular `Manager` pattern, making it extremely easy to add your own custom SMS providers!

Follow these **3 Simple Steps** to register and use a brand new gateway:

---

### Step 1: Create the Gateway Driver Class

Navigate to `src/Gateways/` and create your new driver class (e.g., `MyCustomGateway.php`).
Your class **must** extend `AbstractGateway` and be marked as `final`. It must implement two core methods: `validate()` and `processSend()`.

*(Optional)* You may also override `public function checkBalance(): ?string` and `public function checkProfile(): ?array` to support the Advanced API Endpoints.

```php
<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Response\SmsResponse;
use XLink\SmsBridge\Exceptions\SmsBridgeException;

final class MyCustomGateway extends AbstractGateway
{
    /**
     * Validate that the required configuration keys are present.
     */
    protected function validate(): void
    {
        if (empty($this->config['url'])) {
            throw SmsBridgeException::missingConfig('API URL is required for MyCustomGateway.');
        }
        if (empty($this->config['api_key'])) {
            throw SmsBridgeException::missingConfig('API Key is required for MyCustomGateway.');
        }
    }

    /**
     * Process the actual HTTP request.
     */
    protected function processSend(string $phone, string $message): SmsResponse
    {
        // \$this->httpClient() is automatically provided by AbstractGateway
        // and handles timeouts and retries for you!
        \$response = \$this->httpClient()->post(\$this->config['url'], [
            'api_token' => \$this->config['api_key'],
            'to'        => \$phone,
            'text'      => \$message,
        ]);

        \$data = \$response->json();

        // Check if your specific API returned a success flag
        \$isSuccess = isset(\$data['status']) && \$data['status'] === 'success';

        // Return the standardized SmsResponse object
        return new SmsResponse(
            \$isSuccess,
            \$isSuccess ? 'Message sent securely' : (\$data['error_message'] ?? 'Unknown Error'),
            \$data
        );
    }
}
```

---

### Step 2: Register it in the Manager

Open `src/SmsBridgeManager.php` and map your completely lowercased `name` key to your new class inside the `buildGateway()` method.

```php
    protected function buildGateway(string \$driver): AbstractGateway
    {
        \$gateways = [
            'adnsms' => Gateways\AdnSmsGateway::class,
            // ... existing gateways ...
            'mycustom' => Gateways\MyCustomGateway::class, // <-- Add your gateway here
        ];

        // ...
```

---

### Step 3: Add it to the UI Dropdown

Finally, allow users to select your new gateway from the UI.
Open `resources/views/gateways/index.blade.php`.

**1. Add it to the HTML Select Options:**
Find the `<select name="name" ...>` tag around line 100, and add:
```html
<option value="mycustom">My Custom Gateway</option>
```

**2. Add it to the JS Labels Dictionary:**
Find the `gatewayLabels` JavaScript object at the bottom of the same file and map the form labels to your gateway requirements so the UI adapts:
```javascript
        const gatewayLabels = {
            // ... existing gateways ...
            mycustom: {
                url: "MyCustom Base Endpoint",
                key: "API Token",
                secret: "Not Used (Optional)",
                sender: "Sender ID"
            },
        };
```

**You're done!**
Users can now add your new `MyCustomGateway` directly from the admin interface, set custom endpoints (`api_send_url`, `api_balance_url`, `api_profile_url`), and fully integrate with the package's automated Queue, Failover, and Notification systems!
