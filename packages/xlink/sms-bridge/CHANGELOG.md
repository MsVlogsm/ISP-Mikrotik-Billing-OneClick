# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-03-07

### Added
- **Global Strict Typing:** Implemented `declare(strict_types=1);` natively across all package components.
- **Custom Exceptions:** Added `XLink\SmsBridge\Exceptions\SmsBridgeException` for detailed logging and standardized exception management instead of throwing generic errors.
- **Laravel Notification Channel Support:** Integrated `XLink\SmsBridge\Notifications\SmsChannel` allowing users to fire SMS directly via Laravel's native Notifications (`->notify(new InvoicePaid())`).
- **Interactive UI Toggles:** Replaced static activation status badges with a universally active AJAX-style toggle switch for all physical nodes and the test log node.
- **Native Logging Gateway:** The `log` platform now permanently occupies the number 1 slot. It automatically catches sent messages if no live platform is active (Zero Configuration Failback), and forces a UI mutually exclusive relationship with real node activations.

### Changed
- **SmsResponse Objects:** Responses are now parsed into a `final readonly class` mapping exact JSON components to prevent mutation and provide highly predictable object properties (`->isSuccessful()`).
- **Gateway Abstraction Layer:** Redesigned `$driver->validate()` traits to ensure configuration credentials exist prior to firing outgoing HTTP commands, resolving empty credential crashes.
- **Configuration Security:** Migrated active database storage to a JSON-isolated UI. Added native masking (password toggle eyes) to the `API Key` and `API Secret` inputs inside the application GUI.
- **Driver Locking:** Appended `final` declarations to all driver classes to prevent unsafe subclassing overrides.
- **Failover Logic Enhancement:** Service-level error catching now forces an immediate iterative jump to the next active priority-driven gateway, instead of failing the outgoing queue.

### Removed
- Extracted references to obsolete `.env` implementations previously forcing manual gateway registration.
- Removed legacy test modules and static `InvalidArgumentException` usages.
