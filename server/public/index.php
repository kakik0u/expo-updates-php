<?php
declare(strict_types=1);
require __DIR__ . '/../src/App.php';
$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Server is not configured');
}
try {
    $app = new OtaApp(require $configFile);
    $app->handle();
} catch (Throwable $error) {
    error_log('OTA request failed: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo '{"error":"Internal server error"}';
}
