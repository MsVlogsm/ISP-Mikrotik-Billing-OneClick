<?php

/**
 * X-Link Billing default role boundaries.
 * Super Admin bypasses all Laravel gates. Admin and Manager permissions are
 * intentionally explicit; custom roles remain configurable by Super Admin.
 */
return [
    'Super Admin' => [
        'summary' => 'সম্পূর্ণ সিস্টেম নিয়ন্ত্রণ',
        'capabilities' => [
            'সব মডিউল, customer, billing, payment, report ও staff পরিচালনা',
            'Role/permission তৈরি ও পরিবর্তন; Super Admin role সুরক্ষিত',
            'MikroTik, OLT, network inventory, SMS/system settings ও backups',
        ],
        'restrictions' => ['শেষ Super Admin account/role সরিয়ে সিস্টেম lockout করা যাবে না।'],
        'permissions' => [],
        'all' => true,
    ],
    'Admin' => [
        'summary' => 'ব্যবসায়িক অপারেশন, customer/billing ও staff account পরিচালনা',
        'capabilities' => [
            'Customer তৈরি, দেখা, edit, activate/disable এবং প্রয়োজনে delete',
            'Bill update, payment collection/edit, payment history ও financial reports',
            'Package/product/content পরিচালনা এবং non-Super-Admin user account পরিচালনা',
            'Support ticket ও customer communication পরিচালনা',
            'Stock product, supplier, purchase, asset, warranty, damage ও stock ledger পরিচালনা',
            'Reseller accounts, expense ledger, audit logs, review moderation, vouchers ও purchase requests পরিচালনা',
        ],
        'restrictions' => [
            'Role/permission matrix edit করা বা কাউকে Super Admin করা যাবে না।',
            'MikroTik/OLT, network credentials, global system/SMS/payment settings ও database backup পরিচালনা নয়।',
        ],
        'permissions' => [
            'user_management' => ['view-user', 'create-user', 'edit-user', 'delete-user', 'password-reset'],
            'customer_operations' => ['view-customer', 'all-customer', 'search-customer', 'create-customer', 'edit-customer', 'delete-customer', 'enable-customer', 'disable-customer', 'enable-pending-customer', 'pending-customer', 'recent-customer', 'inactive-customer', 'free-customer', 'customer-billing-info', 'customer-official-info', 'customer-server-info'],
            'billing_and_collection' => ['update-bill', 'payment-collection', 'payment-edit', 'payment-delete', 'payment-history', 'payment-collection-edit', 'payment-collection-invoice', 'payment-collection-report', 'amount-collection', 'amount-collection-edit', 'amount-collection-report', 'collection-customer', 'collection-list', 'without-collection-list'],
            'packages_and_products' => ['package-setup', 'package-setup-create', 'package-setup-edit', 'package-setup-delete', 'edit-package', 'delete-package', 'create-product', 'edit-product', 'delete-product'],
            'content_and_support' => ['create-web-content', 'edit-web-content', 'delete-web-content', 'create-sms', 'edit-sms', 'delete-sms', 'complain-list', 'view-tickets', 'manage-tickets', 'bandwidth-support'],
            'address_and_reseller_operations' => ['address-order', 'reseller-cashflow', 'reseller-ledger', 'manage-customer-assignment', 'push-customers'],
            'stock_inventory' => ['stock-inventory-view', 'stock-inventory-report', 'stock-inventory-movement', 'stock-inventory-manage', 'stock-inventory-delete'],
            'admin_tools' => ['manage-resellers', 'manage-expenses', 'view-audit-logs', 'manage-reviews', 'view-vouchers', 'manage-purchase-requests', 'view-profit-summary'],
        ],
        'all' => false,
    ],
    'Manager' => [
        'summary' => 'দৈনন্দিন customer service ও collection; destructive/system access সীমিত',
        'capabilities' => [
            'Customer দেখা, তৈরি, edit, pending activation, enable/disable ও search',
            'Payment collection, payment history/invoice এবং collection reports দেখা',
            'Complaint ও support ticket পরিচালনা',
            'Stock/Asset list ও report দেখা; stock issue/sale movement রেকর্ড করা',
        ],
        'restrictions' => [
            'Customer delete, bill amount/date update, payment edit/delete করা যাবে না।',
            'User/role management, package pricing, MikroTik/OLT/network ও system settings নয়।',
            'Stock receive/adjustment, product/supplier edit/delete, purchase ও inventory settings নয়।',
        ],
        'permissions' => [
            'customer_operations' => ['view-customer', 'all-customer', 'search-customer', 'create-customer', 'edit-customer', 'enable-customer', 'disable-customer', 'enable-pending-customer', 'pending-customer', 'recent-customer', 'inactive-customer', 'free-customer', 'customer-billing-info', 'customer-official-info'],
            'billing_and_collection' => ['payment-collection', 'payment-history', 'payment-collection-invoice', 'payment-collection-report', 'amount-collection', 'amount-collection-report', 'collection-customer', 'collection-list', 'without-collection-list'],
            'content_and_support' => ['complain-list', 'view-tickets', 'manage-tickets', 'bandwidth-support'],
            'stock_inventory' => ['stock-inventory-view', 'stock-inventory-report', 'stock-inventory-movement'],
        ],
        'all' => false,
    ],
    'Reseller' => [
        'summary' => 'নিজের reseller account-এর customer ও ledger-এ সীমিত access',
        'capabilities' => [
            'শুধু নিজের reseller_id-তে যুক্ত customer ও নিজের ledger/cashflow',
        ],
        'restrictions' => [
            'অন্য reseller/customer, global billing, staff/role, network ও system settings-এ access নয়।',
        ],
        'permissions' => [],
        'all' => false,
    ],
];
