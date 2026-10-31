# MikroTik RouterOS API & ISP Billing Management System

<div align="center">

![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=for-the-badge&logo=laravel&logoColor=white)
![Filament](https://img.shields.io/badge/Filament-5.x-F59E0B?style=for-the-badge&logo=laravel&logoColor=white)
![MikroTik API](https://img.shields.io/badge/MikroTik-RouterOS%20API-000000?style=for-the-badge)
![License](https://img.shields.io/badge/License-AGPL--3.0-blue?style=for-the-badge)

A premium, modern Internet Service Provider (ISP) Billing & MikroTik RouterOS API management platform built on Laravel, Livewire, and Filament. It allows network administrators and resellers to seamlessly manage subscribers, PPPoE connections, hotspot vouchers, automated payment reconciliations, and commissions from a unified interface.

[Key Features](#key-features) • [Architecture](#system-architecture) • [Getting Started](#getting-started) • [Payment Webhooks](#webhook--mfs-auto-activation-setup) • [Screenshots](#screenshots-preview) • [Tech Stack](#tech-stack) • [License](#license)

</div>

---

## Key Features

🚀 **Comprehensive Billing & User Management**
- **PPPoE Secrets & Hotspot Billing:** Fully integrated CRUD operations directly communicating with MikroTik RouterOS.
- **Dynamic Address Pool Allocation:** Set and track IP pools for PPPoE and Hotspots.
- **Router Live Logging:** Collects real-time events and log outputs from connected MikroTik devices.

💼 **Reseller & Commission Ecosystem**
- **Wallet & Transactions:** Resellers can manage a credit-based wallet, buy packages or generate vouchers, and check their purchase history.
- **Upfront & Deferred Commissions:** Flexible configuration for commission structures paid upfront or during consumer activation.
- **Voucher Generator:** Bulk-generate internet access vouchers for Hotspot packages.

💳 **Automated Payment & Activation Webhooks**
- **bKash Merchant IPN:** Automated instant activation via bKash Merchant Webhook.
- **Android SMS Forwarder Integration:** Automated MFS SMS parsing (bKash, Nagad, Rocket) via specialized webhooks using secure API tokens.
- **Auto-Provisioning:** Instantly activates client lines on the MikroTik router upon successful payment matching.

🛡️ **Administration & Audits**
- **Filament Admin Panel:** Modern, responsive, and gorgeous dashboard for admins and resellers.
- **Spatie Activity Logs:** Thorough audit trails tracking every administrative action, payment edit, and router modification.
- **Role-Based Access Control:** Fine-grained authorization utilizing Spatie Laravel Permission.
- **Full-Text Search:** Scalable searches across customers and bills using Laravel Scout.

---

## System Architecture

The interaction flow between the client, administrative panel, MikroTik routers, and payment webhooks:

```mermaid
graph TD
    Client[Client / Reseller / Admin] -->|HTTPS Requests| Laravel[Laravel Filament App]
    Laravel -->|Spatie RBAC| Auth[Access Control / Jetstream]
    Laravel -->|RouterOS API / Port 8728| RouterOS[MikroTik RouterOS]
    Laravel -->|Eloquent ORM| DB[(MySQL Database)]
    Android[Android SMS Forwarder App] -->|MFS SMS Webhook / Sanctum Token| API[Laravel API Endpoint]
    bKash[bKash IPN Server] -->|Payment Callback| API
    API -->|Reconcile & Match| Laravel
    Reseller[Reseller Wallet] -->|Purchase Package / Voucher| Customer[PPP Secrets / Hotspot Vouchers]
    Customer -->|Provision via API| RouterOS
```

---

## Getting Started

### One-click server installation (recommended)

The OneClick installer is intended for a **fresh Ubuntu Server 22.04 or 24.04 LTS** machine. Run it as root or with sudo:

    git clone --depth 1 https://github.com/MsVlogsm/ISP-Mikrotik-Billing-OneClick.git
    cd ISP-Mikrotik-Billing-OneClick
    sudo bash install.sh

Optional installation settings can be supplied before running the installer:

    sudo env APP_DIR=/var/www/isp-billing DOMAIN=bill.example.com BILLING_PORT=8081 PORTAL_PORT=8082 DB_NAME=isp_billing DB_USER=isp_billing bash install.sh

The installer prompts for the company name and Super Admin account, creates a dedicated database user, installs the PHP and Node dependencies, builds assets, runs migrations and seeders, and configures Nginx plus systemd queue/scheduler services. Generated database credentials are saved in /root/isp-billing-install-summary.txt with root-only permissions.

**Important safety notes**
- This is a new-server installer, not an in-place updater. It refuses to run when an existing .env is found in the target application directory.
- Do not point APP_DIR at an existing production installation. Back up and use a separate, tested deployment procedure for upgrades.
- The installer configures HTTP on the billing and portal ports. It does not issue TLS certificates, change firewall policy, or configure MikroTik/OLT devices.
- Make sure DNS points to the server and inbound access to the selected ports is allowed before testing the URLs.

### Manual development installation

For local development or a manually managed environment:

1. Clone the repository:

       git clone https://github.com/MsVlogsm/ISP-Mikrotik-Billing-OneClick.git
       cd ISP-Mikrotik-Billing-OneClick

2. Install PHP dependencies:

       composer install

3. Install frontend dependencies and compile assets:

       npm install
       npm run build

4. Configure the environment and generate the application key:

       cp .env.example .env
       php artisan key:generate

5. Configure a MySQL database and update the database credentials in .env.

6. Run migrations and seed setup data:

       php artisan migrate --seed

7. Start the local development environment:

       npm run dev

## Background processing and scheduled tasks

The OneClick installer creates and enables dedicated systemd services for the Laravel queue worker and scheduler. Check them with:

    systemctl status isp-billing-worker.service
    systemctl status isp-billing-scheduler.service

For manually managed installations that do not use the OneClick systemd services, configure a single cron entry to run the Laravel scheduler:

    * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1

### Scheduled Tasks:
- **Router Log Polling:** Runs every minute to pull log events from MikroTik.
- **Monthly Billing:** Automatically runs on the last day of the month at `23:45` (Asia/Dhaka timezone).
- **Billing SMS Notifications:** Sends out monthly bill SMS on the 1st of every month at `10:00`.
- **Alert Creation:** Generates daily connection & payment alerts at `08:00`.
- **User Disabling:** Automatically disables expired secrets/users daily at `08:30`.
- **MikroTik Sync:** Daily background synchronization of all secrets and hotspot accounts.
- **Log Pruning:** Cleans up router logs older than defined retention days daily at `04:00`.

---

## Webhook & MFS Auto-Activation Setup

The platform features automated activation through payment notifications.

### 1. bKash Merchant Webhook (IPN)
Configure your bKash Merchant account to forward payments to the following route:
- **Endpoint URL:** `https://your-domain.com/api/payment/bkash/ipn`
- **Method:** `POST`
- **CSRF Protection:** Automatically bypassed for this route.
- **Action:** Validates the transaction status, amounts, and maps the `payerReference` to the subscriber code to instantly renew and enable their MikroTik PPPoE / Hotspot connection.

### 2. MFS SMS Receiver Webhook (Android Forwarder)
For local manual payments where a merchant portal isn't available, you can use any Android SMS Forwarder app (e.g., **SMS to Webhook**, **MacroDroid**, or **Tasker**) to forward received transactional SMS.

- **Endpoint URL (Header Auth):** `https://your-domain.com/api/payment/mfs/sms-receiver`
  - **Headers Required:**
    - `Content-Type: application/json`
    - `Authorization: Bearer [YOUR_SANCTUM_API_TOKEN]`
- **Alternative URL (Query Parameter Auth):**
  - `https://your-domain.com/api/payment/mfs/sms-receiver?api_token=[YOUR_SANCTUM_API_TOKEN]`
  *(Generate your token from: Admin Dashboard -> Profile Settings -> API Tokens)*

- **Sample Request Payload:**
  ```json
  {
    "sender": "bKash",
    "message": "You have received BDT 500.00 from 01712345678. Ref CUSTOMER_1029. Fee 0.00. Balance 12000.00. TrxID A1B2C3D4E5"
  }
  ```

#### Recommended Forwarding Rules (Security Best Practices)
To prevent leakage of private OTPs or unauthorized code executions:
- **Sender Filter:** Set the app to only forward messages matching `bKash`, `NAGAD`, `Rocket`, `16216`, or `DBBL`.
- **Message Content Filter:**
  - *Must contain:* `Received BDT`, `received`, `Payment`, `TxnID`, `TrxID`, or `Tk`.
  - *Must NOT contain:* `OTP`, `verification code`, `security code`, `PIN`, or `password`.

---

## Screenshots Preview

| **Dashboard Analytics & System Health** | **Router & IP Management** |
|:---:|:---:|
| ![Dashboard Preview](public/images/interface/dashboard-auto.png) <br> *Placeholder: Admin statistics, active routers, and revenue collection charts.* | ![Router Management](public/images/interface/live-traffic-monitor.png) <br> *Placeholder: Connected MikroTik routers, status checker, and synchronization.* |
| **Subscriber Profiles & PPP Secrets** | **Reseller Commision Wallet & Vouchers** |
| ![PPP Secrets Management](public/images/interface/customer-setup.png) <br> *Placeholder: Managing user packages, passwords, profiles, and connection state.* | ![Reseller Commission Panel](public/images/interface/expance-management-and-profite-loss.png) <br> *Placeholder: Reseller credit dashboard, commission transaction log, and vouchers.* |

---

## Tech Stack

The core libraries and packages keeping the platform running smoothly:

### Backend Architecture
- **Framework:** Laravel 12.x
- **Admin Panel:** Filament PHP v5
- **Dynamic Frontend:** Livewire v4
- **Router Connection:** [Evilfreelancer RouterOS API PHP Wrapper](https://github.com/evilfreelancer/routeros-api-php)
- **Role Control:** [Spatie Laravel Permission](https://github.com/spatie/laravel-permission)
- **Audit Trails:** [Spatie Activity Log](https://github.com/spatie/laravel-activitylog)
- **PDF Invoices:** [carlos-meneses/laravel-mpdf](https://github.com/carlos-meneses/laravel-mpdf)
- **Spreadsheets:** [phpoffice/phpspreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) & [spatie/simple-excel](https://github.com/spatie/simple-excel)

### Frontend & Assets
- **CSS Utility Engine:** Tailwind CSS, Bootstrap 5, Custom Sass
- **Chart Visualizations:** ApexCharts
- **Component Helpers:** Popper.js & Moment.js
- **UI Libraries:** jQuery & jQuery UI
- **Notifications:** SweetAlert2 & Toastr

---

## License

This project is open-source software licensed under the [AGPL-3.0 license](/LICENSE).

---

## Developed & Maintained by

**X-Link Limited**

## Support & Customization

For setup support, custom ISP module integration, payment gateway configuration, or technical queries, please use the repository issue tracker or your organization's official support channel.

*Feel free to submit a pull request or open an issue on the repository to contribute to the project.*
