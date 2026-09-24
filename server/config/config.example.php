<?php
return [
    'base_url' => 'https://example.com/ota',
    'database' => [
        'host' => 'localhost', 'name' => 'ota', 'user' => 'ota_user', 'password' => 'change-me',
    ],
    'storage_path' => __DIR__ . '/../storage',
    'default_channel' => 'production',
    'require_signing' => true,
    // PEM certificate used to verify publisher signatures. This is public; never upload the private key.
    'signing_certificate_path' => __DIR__ . '/certificate.pem',
    'signing_key_id' => 'main',
];
