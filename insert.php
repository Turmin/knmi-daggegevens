<?php
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/lib/KnmiDataService.php';

if (PHP_SAPI !== 'cli') {
    header('Location: admin/index.php');
    exit;
}

$skipDownload = in_array('--skip-download', $_SERVER['argv'] ?? [], true);
$service = new KnmiDataService(__DIR__);

try {
    if (!$skipDownload) {
        $download = $service->downloadDailyData();
        foreach ($download['messages'] ?? [] as $message) {
            echo $message . PHP_EOL;
        }

        if (!($download['success'] ?? false)) {
            exit(1);
        }
    }

    $db = (new Database())->connect();
    $import = $service->importDailyData($db);

    foreach ($import['messages'] ?? [] as $message) {
        echo $message . PHP_EOL;
    }

    if (isset($import['last_database_date'])) {
        echo 'Latest database date: ' . $import['last_database_date'] . PHP_EOL;
    }

    exit(($import['success'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
