<?php
require_once __DIR__ . '/lib/KnmiDataService.php';

if (PHP_SAPI !== 'cli') {
    header('Location: admin/index.php');
    exit;
}

$service = new KnmiDataService(__DIR__);
$result = $service->downloadDailyData();

foreach ($result['messages'] ?? [] as $message) {
    echo $message . PHP_EOL;
}

exit(($result['success'] ?? false) ? 0 : 1);
