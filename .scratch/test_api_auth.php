<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::find(1);
$token = $user->createToken('test_debug')->plainTextToken;
echo "Generated Token: " . $token . PHP_EOL;

// Test requests with curl
$endpoints = [
    '/api/admin/profile',
    '/api/admin/dashboard/stats',
    '/api/admin/drivers',
    '/api/admin/roles-permissions',
];

foreach ($endpoints as $endpoint) {
    $ch = curl_init("http://127.0.0.1:8000" . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        "Accept: application/json",
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "Endpoint {$endpoint} => HTTP {$httpCode} : " . substr($res, 0, 150) . PHP_EOL;
}
