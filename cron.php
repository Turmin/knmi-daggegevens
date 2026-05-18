<?php
declare(strict_types=1);

require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/lib/KnmiDataService.php';
require_once __DIR__ . '/lib/CronScheduleService.php';

$isCli = PHP_SAPI === 'cli';

function loadCronToken(): ?string {
    $credentialsFile = dirname(__DIR__) . '/knmi.cron.credentials.php';
    if (!is_file($credentialsFile)) {
        return null;
    }

    $credentials = require $credentialsFile;
    return is_array($credentials) && !empty($credentials['token'])
        ? (string)$credentials['token']
        : null;
}

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    $expectedToken = loadCronToken();
    $providedToken = (string)($_GET['token'] ?? '');
    if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or missing cron token.'
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

try {
    $db = (new Database())->connect();
    $dataService = new KnmiDataService(__DIR__);
    $cronService = new CronScheduleService($db, $dataService);
    $results = $cronService->runDueJobs();

    $payload = [
        'success' => true,
        'ran' => count($results),
        'results' => $results,
        'timestamp' => date('c')
    ];
} catch (Throwable $e) {
    http_response_code(500);
    $payload = [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c')
    ];
}

if ($isCli) {
    echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($payload, JSON_PRETTY_PRINT);
}
