<?php
declare(strict_types=1);

session_start();

$preferredCredentialsFile = dirname(__DIR__, 2) . '/knmi.admin.credentials.php';
$legacyCredentialsFile = __DIR__ . '/admin_credentials.php';
$credentialsFile = is_file($preferredCredentialsFile) || !is_file($legacyCredentialsFile)
    ? $preferredCredentialsFile
    : $legacyCredentialsFile;
$credentialsOutsideWebRoot = $credentialsFile === $preferredCredentialsFile;
$setupRequired = false;
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectAdmin(?array $flash = null): void {
    if ($flash !== null) {
        $_SESSION['admin_flash'] = $flash;
    }
    header('Location: index.php');
    exit;
}

function requireValidCsrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', $token)) {
        redirectAdmin(['type' => 'danger', 'messages' => ['Invalid security token. Please try again.']]);
    }
}

function formatDateValue(?string $date): string {
    return $date ? date('d-m-Y', strtotime($date)) : '-';
}

function formatBytes($bytes): string {
    if ($bytes === null || $bytes === false) return '-';
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
}

function dataFileCountLabel(array $fileInfo): string {
    $files = $fileInfo['files'] ?? [];
    $total = count($files);
    $available = 0;

    foreach ($files as $file) {
        if (!empty($file['exists'])) {
            $available++;
        }
    }

    return $available . '/' . $total . ' station files';
}

function normalizePostedStation($station): ?int {
    if ($station === null || $station === '' || $station === 'all') {
        return null;
    }

    if (!is_numeric($station) || !KnmiStationCatalog::exists((int)$station)) {
        throw new InvalidArgumentException('Unknown KNMI station.');
    }

    return (int)$station;
}

function loadCredentials(string $credentialsFile): ?array {
    if (!is_file($credentialsFile)) {
        return null;
    }

    $credentials = require $credentialsFile;
    if (!is_array($credentials) || empty($credentials['username']) || empty($credentials['password_hash'])) {
        return null;
    }

    return $credentials;
}

function getZipSupportLabel(): string {
    if (class_exists('ZipArchive')) {
        return 'ZipArchive';
    }

    return function_exists('gzinflate') ? 'Built-in fallback' : 'Unavailable';
}

function tableExists(PDO $db): bool {
    $stmt = $db->query("SHOW TABLES LIKE 'knmi'");
    return (bool) $stmt->fetchColumn();
}

function getAdminStats(PDO $db, KnmiDataService $service, bool $checkMissing = false): array {
    $stats = [
        'table_exists' => tableExists($db),
        'total_records' => 0,
        'first_date' => null,
        'latest_date' => null,
        'missing_days' => null,
        'missing_preview' => [],
        'missing_checked' => $checkMissing,
        'file' => $service->getDataFileInfo(null, false),
        'zip_available' => class_exists('ZipArchive') || function_exists('gzinflate'),
        'zip_support' => getZipSupportLabel()
    ];

    if ($checkMissing) {
        $missingDates = $service->getMissingDateSummary($db);
        $stats['missing_days'] = (int)($missingDates['count'] ?? 0);
        $stats['missing_preview'] = $missingDates['preview'] ?? [];
    }

    if (!$stats['table_exists']) {
        return $stats;
    }

    $stationIds = implode(',', array_map('intval', $service->getStationIds()));
    $stmt = $db->query("
        SELECT
            COUNT(*) as total_records,
            MIN(yyyymmdd) as first_date,
            MAX(yyyymmdd) as latest_date
        FROM knmi
        WHERE stn IN (" . $stationIds . ")
    ");
    $row = $stmt->fetch() ?: [];

    $stats['total_records'] = (int) ($row['total_records'] ?? 0);
    $stats['first_date'] = $row['first_date'] ?? null;
    $stats['latest_date'] = $row['latest_date'] ?? null;

    return $stats;
}

function exportCsv(PDO $db, KnmiDataService $service): void {
    $columns = [
        'stn', 'yyyymmdd', 'ddvec', 'fhvec', 'fg', 'fhx', 'fhxh', 'fhn', 'fhnh',
        'fxx', 'fxxh', 'tg', 'tn', 'tnh', 'tx', 'txh', 't10n', 't10nh',
        'sq', 'sp', 'q', 'dr', 'rh', 'rhx', 'rhxh', 'pg', 'px', 'pxh',
        'pn', 'pnh', 'vvn', 'vvnh', 'vvx', 'vvxh', 'ng', 'ug', 'ux', 'uxh',
        'un', 'unh', 'ev24'
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="knmi_stations_export_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, $columns);

    $stationIds = implode(',', array_map('intval', $service->getStationIds()));
    $stmt = $db->query('SELECT `' . implode('`,`', $columns) . '` FROM knmi WHERE stn IN (' . $stationIds . ') ORDER BY stn ASC, yyyymmdd ASC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

$credentials = loadCredentials($credentialsFile);
$setupRequired = $credentials === null;

if ($setupRequired && ($_POST['action'] ?? '') === 'setup') {
    requireValidCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordRepeat = (string) ($_POST['password_repeat'] ?? '');

    if ($username === '' || strlen($password) < 10 || $password !== $passwordRepeat) {
        redirectAdmin(['type' => 'danger', 'messages' => ['Choose a username and a matching password of at least 10 characters.']]);
    }

    $content = "<?php\nreturn " . var_export([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('c')
    ], true) . ";\n";

    if (file_put_contents($preferredCredentialsFile, $content, LOCK_EX) === false) {
        redirectAdmin(['type' => 'danger', 'messages' => ['Could not write admin credentials file.']]);
    }

    redirectAdmin(['type' => 'success', 'messages' => ['Admin account created. You can now log in.']]);
}
$isLoggedIn = !$setupRequired && (($_SESSION['admin_logged_in'] ?? false) === true);

if (($_POST['action'] ?? '') === 'logout') {
    requireValidCsrf();
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    redirectAdmin(['type' => 'success', 'messages' => ['Logged out.']]);
}

if (!$setupRequired && ($_POST['action'] ?? '') === 'login') {
    requireValidCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === $credentials['username'] && password_verify($password, $credentials['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $credentials['username'];
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        redirectAdmin(['type' => 'success', 'messages' => ['Logged in.']]);
    }

    redirectAdmin(['type' => 'danger', 'messages' => ['Invalid username or password.']]);
}

$db = null;
$weatherData = null;
$service = null;
$adminError = null;
$stats = null;
$apiPreview = null;
$cronService = null;
$cronJobs = [];
$cronTaskOptions = [];
$stationOptions = [];
$cronTokenFile = dirname(__DIR__, 2) . '/knmi.cron.credentials.php';
$cronTokenConfigured = is_file($cronTokenFile);
$checkMissingDates = ($_GET['check_missing'] ?? '') === '1';

if ($isLoggedIn) {
    try {
        require_once __DIR__ . '/../config/Database.php';
        require_once __DIR__ . '/../models/WeatherData.php';
        require_once __DIR__ . '/../lib/KnmiDataService.php';
        require_once __DIR__ . '/../lib/CronScheduleService.php';

        $service = new KnmiDataService(dirname(__DIR__));
        $db = (new Database())->connect();
        $weatherData = new WeatherData($db);
        $cronService = new CronScheduleService($db, $service);
        $cronTaskOptions = CronScheduleService::taskOptions();
        $stationOptions = $service->getStations();

        if (($_GET['export'] ?? '') === 'csv') {
            if (!tableExists($db)) {
                redirectAdmin(['type' => 'warning', 'messages' => ['The knmi table does not exist yet. Import data before exporting.']]);
            }
            exportCsv($db, $service);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            requireValidCsrf();
            $action = $_POST['action'];
            $result = null;

            if ($action === 'cron_save') {
                $result = $cronService->saveJob($_POST);
            } elseif ($action === 'cron_delete') {
                $result = $cronService->deleteJob((int)($_POST['id'] ?? 0));
            } elseif ($action === 'cron_run') {
                $result = $cronService->runJob((int)($_POST['id'] ?? 0));
            } elseif ($action === 'download') {
                $station = normalizePostedStation($_POST['station'] ?? null);
                $result = $service->downloadDailyData($station);
            } elseif ($action === 'import') {
                $station = normalizePostedStation($_POST['station'] ?? null);
                $result = $service->importDailyData($db, $station);
            } elseif ($action === 'download_import') {
                $station = normalizePostedStation($_POST['station'] ?? null);
                $download = $service->downloadDailyData($station);
                if ($download['success'] ?? false) {
                    $import = $service->importDailyData($db, $station);
                    $result = [
                        'success' => ($import['success'] ?? false),
                        'messages' => array_merge($download['messages'] ?? [], $import['messages'] ?? [])
                    ];
                } else {
                    $result = $download;
                }
            }

            if ($result !== null) {
                $_SESSION['admin_activity'][] = [
                    'time' => date('Y-m-d H:i:s'),
                    'action' => $action,
                    'success' => (bool) ($result['success'] ?? false),
                    'messages' => $result['messages'] ?? []
                ];
                $_SESSION['admin_activity'] = array_slice($_SESSION['admin_activity'], -6);

                redirectAdmin([
                    'type' => ($result['success'] ?? false) ? 'success' : 'danger',
                    'messages' => $result['messages'] ?? ['Action finished.']
                ]);
            }
        }

        $stats = getAdminStats($db, $service, $checkMissingDates);
        $cronJobs = $cronService->listJobs();

        $apiDate = $_GET['api_date'] ?? ($stats['latest_date'] ?? null);
        if ($apiDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $apiDate)) {
            $apiPreview = $weatherData->getDataByDate($apiDate);
        }
    } catch (Throwable $e) {
        error_log('Admin error: ' . $e->getMessage());
        $adminError = $e->getMessage();
    }
}

$csrf = $_SESSION['admin_csrf'];
$adminUser = $_SESSION['admin_user'] ?? ($credentials['username'] ?? 'Admin');
$activity = array_reverse($_SESSION['admin_activity'] ?? []);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KNMI Admin Panel</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../icons/favicon-16x16.png">
    <link rel="shortcut icon" href="../icons/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="../icons/apple-touch-icon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="css/admin-style.css" rel="stylesheet">
</head>
<body>
    <div class="container admin-container">
        <?php if ($setupRequired): ?>
            <div class="login-shell">
                <div class="admin-card">
                    <div class="admin-header">
                        <h1 class="h4 mb-1">KNMI Admin Setup</h1>
                        <div>Create the first admin login.</div>
                    </div>
                    <div class="card-body">
                        <?php if ($flash): ?>
                            <div class="alert alert-<?php echo h($flash['type']); ?>">
                                <?php foreach ($flash['messages'] as $message): ?><div><?php echo h($message); ?></div><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                            <input type="hidden" name="action" value="setup">
                            <div class="mb-3">
                                <label class="form-label" for="username">Username</label>
                                <input class="form-control" id="username" name="username" autocomplete="username" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="password">Password</label>
                                <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" minlength="10" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="password_repeat">Repeat password</label>
                                <input class="form-control" id="password_repeat" name="password_repeat" type="password" autocomplete="new-password" minlength="10" required>
                            </div>
                            <button class="btn btn-primary w-100" type="submit">Create admin</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php elseif (!$isLoggedIn): ?>
            <div class="login-shell">
                <div class="admin-card">
                    <div class="admin-header">
                        <h1 class="h4 mb-1">KNMI Admin Login</h1>
                        <div>Log in to download and import weather data.</div>
                    </div>
                    <div class="card-body">
                        <?php if ($flash): ?>
                            <div class="alert alert-<?php echo h($flash['type']); ?>">
                                <?php foreach ($flash['messages'] as $message): ?><div><?php echo h($message); ?></div><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                            <input type="hidden" name="action" value="login">
                            <div class="mb-3">
                                <label class="form-label" for="login_username">Username</label>
                                <input class="form-control" id="login_username" name="username" autocomplete="username" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="login_password">Password</label>
                                <input class="form-control" id="login_password" name="password" type="password" autocomplete="current-password" required>
                            </div>
                            <button class="btn btn-primary w-100" type="submit">Log in</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="admin-card">
                <div class="admin-header d-flex flex-column flex-md-row justify-content-between gap-3">
                    <div>
                        <h1 class="h3 mb-1"><i class="bi bi-speedometer2 me-2"></i>KNMI Admin Panel</h1>
                        <div>Download, import, repair missing days, and verify API output.</div>
                    </div>
                    <div class="admin-toolbar d-flex flex-wrap gap-2">
                        <span class="badge bg-light text-dark admin-user-badge"><i class="bi bi-person-circle me-1"></i><?php echo h($adminUser); ?></span>
                        <a class="btn btn-outline-light btn-sm" href="../"><i class="bi bi-house-door me-1"></i>Main page</a>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                            <input type="hidden" name="action" value="logout">
                            <button class="btn btn-outline-light btn-sm" type="submit"><i class="bi bi-box-arrow-right me-1"></i>Log out</button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo h($flash['type']); ?>">
                    <?php foreach ($flash['messages'] as $message): ?><div><?php echo h($message); ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($adminError): ?>
                <div class="alert alert-danger"><?php echo h($adminError); ?></div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="text-muted">Database records</div>
                        <div class="stat-number"><?php echo number_format((int) ($stats['total_records'] ?? 0)); ?></div>
                        <small>Configured stations</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="text-muted">Latest DB day</div>
                        <div class="stat-number"><?php echo h(formatDateValue($stats['latest_date'] ?? null)); ?></div>
                        <small><?php echo h($stats['latest_date'] ?? '-'); ?></small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="text-muted">Latest file day</div>
                        <div class="stat-number"><?php echo h(formatDateValue($stats['file']['latest_date'] ?? null)); ?></div>
                        <small><?php echo h($stats['file']['latest_date'] ?? '-'); ?></small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="text-muted">Missing days</div>
                        <div class="stat-number"><?php echo ($stats['missing_checked'] ?? false) ? (int)($stats['missing_days'] ?? 0) : '-'; ?></div>
                        <small>
                            <?php if ($stats['missing_checked'] ?? false): ?>
                                Dates in file but not in DB
                            <?php else: ?>
                                <a href="?check_missing=1">Check missing days</a>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-lg-6">
                    <div class="admin-card">
                        <div class="card-body">
                            <h2 class="h5 mb-3"><i class="bi bi-cloud-download text-primary me-2"></i>Update data</h2>
                            <form method="post" class="d-grid gap-2">
                                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                <div>
                                    <label class="form-label" for="update_station">Station</label>
                                    <select class="form-select" id="update_station" name="station">
                                        <option value="all">All configured stations</option>
                                        <?php foreach ($stationOptions as $station): ?>
                                            <option value="<?php echo (int)$station['id']; ?>"><?php echo h($station['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="d-grid gap-2">
                                    <button class="btn btn-primary action-btn w-100" type="submit" name="action" value="download_import">Download KNMI file and import missing/new days</button>
                                    <div class="action-grid">
                                        <button class="btn btn-outline-primary action-btn w-100" type="submit" name="action" value="download">Download only</button>
                                        <button class="btn btn-outline-success action-btn w-100" type="submit" name="action" value="import">Import missing/new</button>
                                    </div>
                                </div>
                                <a class="btn btn-outline-secondary action-btn" href="?export=csv">Export CSV</a>
                                <?php if (!empty($stats['missing_preview'])): ?>
                                    <div class="small text-muted">
                                        First missing dates: <?php echo h(implode(', ', $stats['missing_preview'])); ?><?php echo ((int) ($stats['missing_days'] ?? 0) > count($stats['missing_preview'])) ? ' ...' : ''; ?>
                                    </div>
                                <?php elseif (!($stats['missing_checked'] ?? false)): ?>
                                    <div class="small text-muted">
                                        Missing-date scan is skipped on normal page load to keep admin fast.
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="admin-card">
                        <div class="card-body">
                            <h2 class="h5 mb-3"><i class="bi bi-check2-circle text-success me-2"></i>System status</h2>
                            <div class="d-grid gap-2">
                                <div class="status-row d-flex justify-content-between"><span>Database connection</span><span class="badge bg-<?php echo $db ? 'success' : 'danger'; ?>"><?php echo $db ? 'Online' : 'Offline'; ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>Table knmi</span><span class="badge bg-<?php echo ($stats['table_exists'] ?? false) ? 'success' : 'warning'; ?>"><?php echo ($stats['table_exists'] ?? false) ? 'Present' : 'Missing'; ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>KNMI files</span><span class="badge bg-<?php echo ($stats['file']['exists'] ?? false) ? 'success' : 'warning'; ?>"><?php echo h(dataFileCountLabel($stats['file'] ?? [])); ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>ZIP extraction</span><span class="badge bg-<?php echo ($stats['zip_available'] ?? false) ? 'success' : 'danger'; ?>"><?php echo h($stats['zip_support'] ?? 'Unavailable'); ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>Login security</span><span class="badge bg-success">Active</span></div>
                                <div class="status-row d-flex justify-content-between"><span>Admin credentials</span><span class="badge bg-<?php echo $credentialsOutsideWebRoot ? 'success' : 'warning'; ?>"><?php echo $credentialsOutsideWebRoot ? 'Outside webroot' : 'Legacy location'; ?></span></div>
                            </div>
                            <div class="small text-muted mt-3">
                                Files: <?php echo h(dataFileCountLabel($stats['file'] ?? [])); ?>,
                                <?php echo h(formatBytes($stats['file']['size'] ?? null)); ?>,
                                modified: <?php echo h($stats['file']['modified_at'] ?? '-'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Cron schedules</h2>
                            <div class="small text-muted">Database-managed jobs. Run <code>cron.php</code> every minute from one server cron.</div>
                            <div class="small text-muted mt-1">
                                Schedule fields: <code>minute</code> <code>hour</code> <code>day</code> <code>month</code> <code>weekday</code>
                            </div>
                        </div>
                        <div class="text-lg-end small">
                            <div>
                                Token file:
                                <span class="badge bg-<?php echo $cronTokenConfigured ? 'success' : 'warning'; ?>">
                                    <?php echo $cronTokenConfigured ? 'Configured' : 'Missing'; ?>
                                </span>
                            </div>
                            <code>* * * * * php <?php echo h(dirname(__DIR__) . '/cron.php'); ?> &gt;/dev/null 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <form class="cron-row mb-3" method="post">
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label" for="new_cron_name">Name</label>
                                <input class="form-control" id="new_cron_name" name="name" value="Daily KNMI update" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="new_cron_task">Task</label>
                                <select class="form-select" id="new_cron_task" name="task">
                                    <?php foreach ($cronTaskOptions as $taskKey => $taskLabel): ?>
                                        <option value="<?php echo h($taskKey); ?>"><?php echo h($taskLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="new_cron_station">Station</label>
                                <select class="form-select" id="new_cron_station" name="station">
                                    <option value="all">All stations</option>
                                    <?php foreach ($stationOptions as $station): ?>
                                        <option value="<?php echo (int)$station['id']; ?>"><?php echo h($station['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="new_cron_schedule">Schedule</label>
                                <input class="form-control" id="new_cron_schedule" name="schedule" value="15 8 * * *" required>
                            </div>
                            <div class="col-md-1">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="new_cron_enabled" name="enabled" value="1">
                                    <label class="form-check-label" for="new_cron_enabled">Enabled</label>
                                </div>
                            </div>
                            <div class="col-md-1">
                                <button class="btn btn-primary w-100" type="submit" name="action" value="cron_save">Add</button>
                            </div>
                        </div>
                        <div class="small text-muted mt-2 cron-schedule-description" id="new_cron_schedule_description" aria-live="polite">
                            <?php echo h(CronScheduleService::describeSchedule('15 8 * * *')); ?>
                        </div>
                        <div class="small text-muted mt-2">Examples: <code>*/5 * * * *</code>, <code>15 8 * * *</code>, <code>0,30 8-12 * * *</code>, <code>@daily</code>.</div>
                    </form>

                    <?php if ($cronJobs): ?>
                        <?php foreach ($cronJobs as $job): ?>
                            <?php $jobScheduleDescription = CronScheduleService::describeSchedule((string)$job['schedule']); ?>
                            <form class="cron-row" method="post">
                                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                <input type="hidden" name="id" value="<?php echo h($job['id']); ?>">
                                <div class="row g-2 align-items-end">
                                    <div class="col-lg-2">
                                        <label class="form-label" for="cron_name_<?php echo h($job['id']); ?>">Name</label>
                                        <input class="form-control" id="cron_name_<?php echo h($job['id']); ?>" name="name" value="<?php echo h($job['name']); ?>" required>
                                    </div>
                                    <div class="col-lg-2">
                                        <label class="form-label" for="cron_task_<?php echo h($job['id']); ?>">Task</label>
                                        <select class="form-select" id="cron_task_<?php echo h($job['id']); ?>" name="task">
                                            <?php foreach ($cronTaskOptions as $taskKey => $taskLabel): ?>
                                                <option value="<?php echo h($taskKey); ?>" <?php echo $job['task'] === $taskKey ? 'selected' : ''; ?>><?php echo h($taskLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-lg-3">
                                        <label class="form-label" for="cron_station_<?php echo h($job['id']); ?>">Station</label>
                                        <select class="form-select" id="cron_station_<?php echo h($job['id']); ?>" name="station">
                                            <option value="all">All stations</option>
                                            <?php foreach ($stationOptions as $station): ?>
                                                <option value="<?php echo (int)$station['id']; ?>" <?php echo (string)($job['station'] ?? '') === (string)$station['id'] ? 'selected' : ''; ?>><?php echo h($station['label']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-lg-2">
                                        <label class="form-label d-inline-flex align-items-center gap-1" for="cron_schedule_<?php echo h($job['id']); ?>">
                                            Schedule
                                            <span class="cron-help-icon" tabindex="0" title="<?php echo h($jobScheduleDescription); ?>" aria-label="Schedule: <?php echo h($jobScheduleDescription); ?>">
                                                <i class="bi bi-info-circle"></i>
                                            </span>
                                        </label>
                                        <input class="form-control" id="cron_schedule_<?php echo h($job['id']); ?>" name="schedule" value="<?php echo h($job['schedule']); ?>" required>
                                    </div>
                                    <div class="col-lg-1">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="cron_enabled_<?php echo h($job['id']); ?>" name="enabled" value="1" <?php echo (int)$job['enabled'] === 1 ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="cron_enabled_<?php echo h($job['id']); ?>">On</label>
                                        </div>
                                    </div>
                                    <div class="col-lg-2">
                                        <div class="btn-group w-100" role="group">
                                            <button class="btn btn-outline-primary" type="submit" name="action" value="cron_save">Save</button>
                                            <button class="btn btn-outline-success" type="submit" name="action" value="cron_run">Run</button>
                                            <button class="btn btn-outline-danger" type="submit" name="action" value="cron_delete" formnovalidate onclick="return confirm('Delete this cron schedule?');">Delete</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="cron-meta small text-muted mt-2">
                                    Station:
                                    <?php echo h(($job['station'] ?? null) ? ((KnmiStationCatalog::name((int)$job['station']) ?: 'Station') . ' (' . (int)$job['station'] . ')') : 'All stations'); ?>,
                                    Last run: <?php echo h($job['last_run_at'] ?: '-'); ?>,
                                    status:
                                    <span class="badge bg-<?php echo ($job['last_status'] ?? '') === 'success' ? 'success' : (($job['last_status'] ?? '') === 'failed' ? 'danger' : 'secondary'); ?>">
                                        <?php echo h($job['last_status'] ?: 'never'); ?>
                                    </span>
                                    <?php if (!empty($job['last_message'])): ?>
                                        <span class="ms-1"><?php echo h($job['last_message']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted">No cron schedules yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="admin-card">
                        <div class="card-body">
                            <h2 class="h5 mb-3"><i class="bi bi-search text-primary me-2"></i>Day/API check</h2>
                            <form class="row g-2 mb-3" method="get">
                                <div class="col">
                                    <input class="form-control" type="date" name="api_date" value="<?php echo h($_GET['api_date'] ?? ($stats['latest_date'] ?? '')); ?>">
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-primary" type="submit">Check</button>
                                </div>
                            </form>
                            <?php if ($apiPreview): ?>
                                <div class="mb-2">
                                    <strong><?php echo h($apiPreview['date_formatted'] ?? $apiPreview['date']); ?></strong><br>
                                    Temperature: <?php echo h($apiPreview['temperature']['avg'] ?? '-'); ?> C,
                                    precipitation: <?php echo h($apiPreview['precipitation']['amount'] ?? '-'); ?> mm
                                </div>
                                <a href="../api/weather.php/day?date=<?php echo h($apiPreview['date']); ?>" target="_blank" rel="noopener">Open JSON API response</a>
                            <?php else: ?>
                                <div class="text-muted">No day selected or no data found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="admin-card">
                        <div class="card-body">
                            <h2 class="h5 mb-3"><i class="bi bi-list-ul text-primary me-2"></i>Latest actions</h2>
                            <?php if ($activity): ?>
                                <?php foreach ($activity as $item): ?>
                                    <div class="status-row mb-2">
                                        <div class="d-flex justify-content-between gap-2">
                                            <strong><?php echo h($item['action']); ?></strong>
                                            <span class="badge bg-<?php echo $item['success'] ? 'success' : 'danger'; ?>"><?php echo h($item['time']); ?></span>
                                        </div>
                                        <?php foreach (($item['messages'] ?? []) as $message): ?>
                                            <div class="small text-muted"><?php echo h($message); ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted">No actions in this session yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script src="js/cron-schedule.js"></script>
</body>
</html>
