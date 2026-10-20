<?php
// standalone direct test on Render without Laravel boot
header('Content-Type: application/json; charset=utf-8');

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
];

// Test ports
$ports = [
    'gmail_465' => ['host' => 'smtp.gmail.com', 'port' => 465],
    'gmail_587' => ['host' => 'smtp.gmail.com', 'port' => 587],
    'brevo_587' => ['host' => 'smtp-relay.brevo.com', 'port' => 587],
    'resend_smtp' => ['host' => 'smtp.resend.com', 'port' => 587],
];

foreach ($ports as $label => $target) {
    $t0 = microtime(true);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($target['host'], $target['port'], $errno, $errstr, 4);
    $ms = round((microtime(true) - $t0) * 1000, 2);
    if ($fp) {
        $banner = fread($fp, 512);
        fclose($fp);
        $results[$label] = "CONNECTED OK ({$ms}ms) - Banner: " . trim($banner);
    } else {
        $results[$label] = "FAILED / BLOCKED: [{$errno}] {$errstr} ({$ms}ms)";
    }
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
