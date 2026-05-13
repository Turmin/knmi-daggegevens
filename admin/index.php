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

function getAdminStats(PDO $db, KnmiDataService $service): array {
    $stats = [
        'table_exists' => tableExists($db),
        'total_records' => 0,
        'first_date' => null,
        'latest_date' => null,
        'missing_days' => 0,
        'missing_preview' => [],
        'file' => $service->getDataFileInfo(),
        'zip_available' => class_exists('ZipArchive') || function_exists('gzinflate'),
        'zip_support' => getZipSupportLabel()
    ];

    $missingDates = $service->getMissingDates($db);
    $stats['missing_days'] = count($missingDates);
    $stats['missing_preview'] = array_slice($missingDates, 0, 8);

    if (!$stats['table_exists']) {
        return $stats;
    }

    $stmt = $db->query("
        SELECT
            COUNT(*) as total_records,
            COUNT(DISTINCT yyyymmdd) as available_days,
            MIN(yyyymmdd) as first_date,
            MAX(yyyymmdd) as latest_date
        FROM knmi
        WHERE stn = 260
    ");
    $row = $stmt->fetch() ?: [];

    $stats['total_records'] = (int) ($row['total_records'] ?? 0);
    $stats['first_date'] = $row['first_date'] ?? null;
    $stats['latest_date'] = $row['latest_date'] ?? null;

    return $stats;
}

function exportCsv(PDO $db): void {
    $columns = [
        'stn', 'yyyymmdd', 'ddvec', 'fhvec', 'fg', 'fhx', 'fhxh', 'fhn', 'fhnh',
        'fxx', 'fxxh', 'tg', 'tn', 'tnh', 'tx', 'txh', 't10n', 't10nh',
        'sq', 'sp', 'q', 'dr', 'rh', 'rhx', 'rhxh', 'pg', 'px', 'pxh',
        'pn', 'pnh', 'vvn', 'vvnh', 'vvx', 'vvxh', 'ng', 'ug', 'ux', 'uxh',
        'un', 'unh', 'ev24'
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="knmi_260_export_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, $columns);

    $stmt = $db->query('SELECT `' . implode('`,`', $columns) . '` FROM knmi WHERE stn = 260 ORDER BY yyyymmdd ASC');
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

if ($isLoggedIn) {
    try {
        require_once __DIR__ . '/../config/Database.php';
        require_once __DIR__ . '/../models/WeatherData.php';
        require_once __DIR__ . '/../lib/KnmiDataService.php';

        $service = new KnmiDataService(dirname(__DIR__));
        $db = (new Database())->connect();
        $weatherData = new WeatherData($db);

        if (($_GET['export'] ?? '') === 'csv') {
            if (!tableExists($db)) {
                redirectAdmin(['type' => 'warning', 'messages' => ['The knmi table does not exist yet. Import data before exporting.']]);
            }
            exportCsv($db);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            requireValidCsrf();
            $action = $_POST['action'];
            $result = null;

            if ($action === 'download') {
                $result = $service->downloadDailyData();
            } elseif ($action === 'import') {
                $result = $service->importDailyData($db);
            } elseif ($action === 'download_import') {
                $download = $service->downloadDailyData();
                if ($download['success'] ?? false) {
                    $import = $service->importDailyData($db);
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

        $stats = getAdminStats($db, $service);

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
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14'%3E%E2%98%80%EF%B8%8F%3C/text%3E%3C/svg%3E">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #eef3f8; min-height: 100vh; font-family: 'Segoe UI', system-ui, sans-serif; color: #172033; }
        .admin-container { max-width: 1180px; padding: 2rem 1rem; }
        .admin-card { background: #fff; border: 1px solid rgba(20,52,85,.12); border-radius: 8px; box-shadow: 0 8px 26px rgba(17,40,68,.10); margin-bottom: 1.25rem; overflow: hidden; }
        .admin-header { background: linear-gradient(135deg, #0a66c2 0%, #0f9488 100%); color: #fff; padding: 1.25rem; }
        .card-body { padding: 1.25rem; }
        .stat-card { border-left: 4px solid #0a66c2; background: #f8fbfe; border-radius: 8px; padding: 1rem; min-height: 118px; }
        .stat-number { font-size: 1.55rem; font-weight: 700; color: #0a66c2; overflow-wrap: anywhere; }
        .status-row { border: 1px solid #dde7f1; border-radius: 8px; padding: .75rem; background: #f8fbfe; }
        .action-btn { min-height: 44px; }
        .action-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .admin-toolbar { align-items: stretch; }
        .admin-toolbar .btn,
        .admin-user-badge { min-height: 31px; display: inline-flex; align-items: center; }
        .admin-user-badge { padding: .25rem .5rem; font-size: .875rem; font-weight: 600; }
        .admin-toolbar form { margin: 0; display: flex; }
        .login-shell { max-width: 520px; margin: 8vh auto; }
        pre { white-space: pre-wrap; word-break: break-word; max-height: 360px; overflow: auto; }
        @media (max-width: 420px) { .action-grid { grid-template-columns: 1fr; } }
    </style>
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
                        <small>Station 260</small>
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
                        <div class="stat-number"><?php echo (int) ($stats['missing_days'] ?? 0); ?></div>
                        <small>Dates in file but not in DB</small>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-lg-6">
                    <div class="admin-card">
                        <div class="card-body">
                            <h2 class="h5 mb-3"><i class="bi bi-cloud-download text-primary me-2"></i>Update data</h2>
                            <div class="d-grid gap-2">
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                    <input type="hidden" name="action" value="download_import">
                                    <button class="btn btn-primary action-btn w-100" type="submit">Download KNMI file and import missing/new days</button>
                                </form>
                                <div class="action-grid">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                        <input type="hidden" name="action" value="download">
                                        <button class="btn btn-outline-primary action-btn w-100" type="submit">Download only</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                        <input type="hidden" name="action" value="import">
                                        <button class="btn btn-outline-success action-btn w-100" type="submit">Import missing/new</button>
                                    </form>
                                </div>
                                <a class="btn btn-outline-secondary action-btn" href="?export=csv">Export CSV</a>
                                <?php if (!empty($stats['missing_preview'])): ?>
                                    <div class="small text-muted">
                                        First missing dates: <?php echo h(implode(', ', $stats['missing_preview'])); ?><?php echo ((int) ($stats['missing_days'] ?? 0) > count($stats['missing_preview'])) ? ' ...' : ''; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
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
                                <div class="status-row d-flex justify-content-between"><span>KNMI file</span><span class="badge bg-<?php echo ($stats['file']['exists'] ?? false) ? 'success' : 'warning'; ?>"><?php echo ($stats['file']['exists'] ?? false) ? 'Present' : 'Missing'; ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>ZIP extraction</span><span class="badge bg-<?php echo ($stats['zip_available'] ?? false) ? 'success' : 'danger'; ?>"><?php echo h($stats['zip_support'] ?? 'Unavailable'); ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>Login security</span><span class="badge bg-success">Active</span></div>
                                <div class="status-row d-flex justify-content-between"><span>Admin credentials</span><span class="badge bg-<?php echo $credentialsOutsideWebRoot ? 'success' : 'warning'; ?>"><?php echo $credentialsOutsideWebRoot ? 'Outside webroot' : 'Legacy location'; ?></span></div>
                            </div>
                            <div class="small text-muted mt-3">
                                File: <?php echo h($service ? basename($service->getDataFilePath()) : 'etmgeg_260.txt'); ?>,
                                <?php echo h(formatBytes($stats['file']['size'] ?? null)); ?>,
                                modified: <?php echo h($stats['file']['modified_at'] ?? '-'); ?>
                            </div>
                        </div>
                    </div>
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
</body>
</html>
