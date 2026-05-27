<?php
require_once __DIR__ . '/config/Database.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatMetricValue($value, $unit = '', $spaceBeforeUnit = true) {
    if ($value === null || $value === '') {
        return '--';
    }

    $formatted = h(number_format((float)$value, 1, '.', ''));
    if ($unit === '') {
        return $formatted;
    }

    return $formatted . ($spaceBeforeUnit ? '&nbsp;' : '') . h($unit);
}

function monthLabel($month, $language) {
    $monthIndex = (int)$month - 1;
    $monthNames = [
        'nl' => ['jan', 'feb', 'maa', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'],
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
    ];

    return $monthNames[$language][$monthIndex] ?? '';
}

function formatMonthMetricValue($month, $value, $language, $unit = 'mm') {
    if ($month === null || $month === '' || $value === null || $value === '') {
        return '--';
    }

    return formatMetricValue($value, $unit)
        . ' <span data-month-format="short" data-month-number="' . h($month) . '">(' . h(monthLabel($month, $language)) . ')</span>';
}

function sortAttribute($value) {
    return ' data-sort-value="' . h($value === null || $value === '' ? '' : $value) . '"';
}

function requestIsSecure() {
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $forwardedProto === 'https'
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
        || (($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '') === 'on')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"') !== false;
}

function isLocalHostName($host) {
    $hostOnly = strtolower(preg_replace('/:\d+$/', '', (string)$host));
    return in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
}

function appBasePath() {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
}

function appAssetPath($path) {
    return appBasePath() . '/' . ltrim($path, '/');
}

function siteBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'knmi.turmin.com';
    $scheme = isLocalHostName($host)
        ? (requestIsSecure() ? 'https' : 'http')
        : 'https';

    return $scheme . '://' . $host . appBasePath();
}

$pageLanguage = isset($_GET['lang']) && $_GET['lang'] === 'en' ? 'en' : 'nl';
$pageTitle = $pageLanguage === "en"
    ? "Yearly statistics - KNMI Daily Data"
    : "Jaarstatistieken - KNMI Daggegevens";
$pageDescription = $pageLanguage === "en"
    ? "Interactive yearly precipitation, temperature, and sunshine statistics for KNMI daily data from De Bilt."
    : "Interactieve grafiek met jaarlijkse neerslag-, temperatuur- en zonstatistieken voor KNMI daggegevens van De Bilt.";

$yearlyStartDate = '1901-01-01';
$precipitationStartDate = '1906-01-01';
$rows = [];
$error = null;

try {
    $database = new Database();
    $db = $database->connect();
    $stmt = $db->prepare("
        SELECT
            YEAR(yyyymmdd) AS year,
            COUNT(*) AS available_days,
            ROUND(MIN(tn_num) * 0.1, 1) AS temp_min_c,
            ROUND(AVG(tg_num) * 0.1, 1) AS temp_avg_c,
            ROUND(MAX(tx_num) * 0.1, 1) AS temp_max_c,
            ROUND(SUM(CASE WHEN sq_num < 0 THEN 0 ELSE sq_num END) * 0.1, 1) AS sunshine_hours
        FROM (
            SELECT
                yyyymmdd,
                CAST(NULLIF(TRIM(tn), '') AS SIGNED) AS tn_num,
                CAST(NULLIF(TRIM(tg), '') AS SIGNED) AS tg_num,
                CAST(NULLIF(TRIM(tx), '') AS SIGNED) AS tx_num,
                CAST(NULLIF(TRIM(sq), '') AS SIGNED) AS sq_num
            FROM knmi
            WHERE stn = 260
                AND yyyymmdd >= :start_date
        ) AS daily
        GROUP BY YEAR(yyyymmdd)
        ORDER BY year ASC
    ");
    $stmt->bindValue(':start_date', $yearlyStartDate, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $rainMonthStmt = $db->prepare("
        SELECT
            YEAR(yyyymmdd) AS year,
            MONTH(yyyymmdd) AS month,
            SUM(CASE WHEN rh_num < 0 THEN 1 ELSE rh_num END) AS precipitation_tenth,
            SUM(CASE WHEN rh_num != 0 THEN 1 ELSE 0 END) AS precipitation_days
        FROM (
            SELECT
                yyyymmdd,
                CAST(NULLIF(TRIM(rh), '') AS SIGNED) AS rh_num
            FROM knmi
            WHERE stn = 260
                AND yyyymmdd >= :precipitation_start_date
        ) AS rain_daily
        GROUP BY YEAR(yyyymmdd), MONTH(yyyymmdd)
        ORDER BY year ASC, month ASC
    ");
    $rainMonthStmt->bindValue(':precipitation_start_date', $precipitationStartDate, PDO::PARAM_STR);
    $rainMonthStmt->execute();
    $rainMonthRows = $rainMonthStmt ? $rainMonthStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $rainMonthsByYear = [];

    foreach ($rainMonthRows as $rainMonthRow) {
        $rainMonthsByYear[(int)$rainMonthRow['year']][] = [
            'month' => (int)$rainMonthRow['month'],
            'precipitation_tenth' => (float)$rainMonthRow['precipitation_tenth'],
            'precipitation_days' => (int)$rainMonthRow['precipitation_days']
        ];
    }

    foreach ($rows as &$row) {
        $row['precipitation_mm'] = null;
        $row['precipitation_min_month'] = null;
        $row['precipitation_min_month_mm'] = null;
        $row['precipitation_avg_month'] = null;
        $row['precipitation_avg_month_mm'] = null;
        $row['precipitation_max_month'] = null;
        $row['precipitation_max_month_mm'] = null;
        $row['precipitation_days'] = null;

        $rainMonths = $rainMonthsByYear[(int)$row['year']] ?? [];
        if (!$rainMonths) {
            continue;
        }

        $totalTenth = 0.0;
        $precipitationDays = 0;
        $minMonth = null;
        $maxMonth = null;

        foreach ($rainMonths as $rainMonth) {
            $totalTenth += $rainMonth['precipitation_tenth'];
            $precipitationDays += $rainMonth['precipitation_days'];

            if ($minMonth === null || $rainMonth['precipitation_tenth'] < $minMonth['precipitation_tenth']) {
                $minMonth = $rainMonth;
            }

            if ($maxMonth === null || $rainMonth['precipitation_tenth'] > $maxMonth['precipitation_tenth']) {
                $maxMonth = $rainMonth;
            }
        }

        $averageTenth = $totalTenth / count($rainMonths);
        $averageMonth = null;

        foreach ($rainMonths as $rainMonth) {
            $distance = abs($rainMonth['precipitation_tenth'] - $averageTenth);
            $currentDistance = $averageMonth === null
                ? null
                : abs($averageMonth['precipitation_tenth'] - $averageTenth);

            if ($averageMonth === null || $distance < $currentDistance) {
                $averageMonth = $rainMonth;
            }
        }

        $row['precipitation_mm'] = round($totalTenth * 0.1, 1);
        $row['precipitation_min_month'] = $minMonth['month'];
        $row['precipitation_min_month_mm'] = round($minMonth['precipitation_tenth'] * 0.1, 1);
        $row['precipitation_avg_month'] = $averageMonth['month'];
        $row['precipitation_avg_month_mm'] = round($averageMonth['precipitation_tenth'] * 0.1, 1);
        $row['precipitation_max_month'] = $maxMonth['month'];
        $row['precipitation_max_month_mm'] = round($maxMonth['precipitation_tenth'] * 0.1, 1);
        $row['precipitation_days'] = $precipitationDays;
    }
    unset($row);
} catch (Throwable $e) {
    error_log('Yearly statistics page error: ' . $e->getMessage());
    $error = true;
}

$firstYear = $rows ? (int)$rows[0]['year'] : null;
$lastYear = $rows ? (int)$rows[count($rows) - 1]['year'] : null;
$firstRainYear = null;
$wettest = null;
$driest = null;
$warmest = null;
$sunniest = null;
$total = 0.0;
$currentYear = (int)date('Y');
$completeRows = [];

foreach ($rows as $row) {
    if ($firstRainYear === null && $row['precipitation_mm'] !== null && $row['precipitation_mm'] !== '') {
        $firstRainYear = (int)$row['year'];
    }

    if ((int)$row['year'] !== $currentYear) {
        $completeRows[] = $row;
    }
}

$summaryRows = $completeRows ?: $rows;
$rainSummaryRows = array_values(array_filter($summaryRows, static function($row) {
    return $row['precipitation_mm'] !== null && $row['precipitation_mm'] !== '';
}));

foreach ($rainSummaryRows as $row) {
    $amount = (float)$row['precipitation_mm'];
    $total += $amount;
    if ($wettest === null || $amount > (float)$wettest['precipitation_mm']) {
        $wettest = $row;
    }
    if ($driest === null || $amount < (float)$driest['precipitation_mm']) {
        $driest = $row;
    }
}

foreach ($summaryRows as $row) {
    if (
        $row['temp_avg_c'] !== null
        && $row['temp_avg_c'] !== ''
        && ($warmest === null || (float)$row['temp_avg_c'] > (float)$warmest['temp_avg_c'])
    ) {
        $warmest = $row;
    }
    if (
        $row['sunshine_hours'] !== null
        && $row['sunshine_hours'] !== ''
        && ($sunniest === null || (float)$row['sunshine_hours'] > (float)$sunniest['sunshine_hours'])
    ) {
        $sunniest = $row;
    }
}

$average = $rainSummaryRows ? round($total / count($rainSummaryRows), 1) : null;
$downloadFilename = $firstYear && $lastYear
    ? 'yearly-statistics-' . $firstYear . '-' . $lastYear . '.png'
    : 'yearly-statistics-chart.png';
$canonicalUrl = siteBaseUrl() . '/yearly.php';
$faviconHref = appAssetPath('favicon.svg');
?>
<!DOCTYPE html>
<html lang="<?php echo h($pageLanguage); ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($pageTitle); ?></title>
    <link rel="canonical" href="<?php echo h($canonicalUrl); ?>">
    <link rel="icon" type="image/svg+xml" href="<?php echo h($faviconHref); ?>">
    <link rel="shortcut icon" href="<?php echo h($faviconHref); ?>">
    <meta name="theme-color" content="#0a66c2">
    <meta name="description" content="<?php echo h($pageDescription); ?>">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('knmi-theme');
            const savedLanguage = localStorage.getItem('knmi-language') || '<?php echo h($pageLanguage); ?>';
            const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = savedTheme || preferredTheme;
            document.documentElement.lang = ['nl', 'en'].includes(savedLanguage) ? savedLanguage : 'nl';
        })();
    </script>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.js"></script>
    <link rel="stylesheet" href="css/modern-style.css?v=<?php echo filemtime(__DIR__ . '/css/modern-style.css'); ?>">
</head>
<body class="yearly-page">
    <div class="container-fluid main-container">
        <div class="row mb-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <div class="app-header">
                            <div class="app-title">
                                <h1 class="mb-0">
                                    <i class="bi bi-bar-chart-line me-2"></i>
                                    <span data-i18n="yearlyHeading">Jaarstatistieken</span>
                                </h1>
                                <p class="mb-0 mt-2" data-i18n="yearlySubtitle">Jaarlijkse weerstatistieken voor meetstation De Bilt</p>
                                <?php if ($firstYear && $lastYear): ?>
                                <small class="text-light">
                                    <span data-i18n="availableYears">Beschikbare jaren:</span>
                                    <?php echo h($firstYear); ?>
                                    <span data-i18n="to">t/m</span>
                                    <?php echo h($lastYear); ?>
                                    <?php if ($firstRainYear && $firstRainYear > $firstYear): ?>
                                    <span class="d-block">
                                        <span data-i18n="precipitationFrom">Neerslag vanaf:</span>
                                        <?php echo h($firstRainYear); ?>
                                    </span>
                                    <?php endif; ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="preference-controls" data-i18n-aria-label="displaySettings" aria-label="Weergave-instellingen">
                                <div class="btn-group btn-group-sm" role="group" aria-label="Theme">
                                    <button type="button" class="btn btn-outline-light" id="themeToggle" title="Donker" aria-label="Donker">
                                        <i class="bi bi-moon-stars"></i>
                                    </button>
                                </div>
                                <div class="btn-group btn-group-sm" role="group" data-i18n-aria-label="language" aria-label="Taal">
                                    <button type="button" class="btn <?php echo $pageLanguage === 'nl' ? 'btn-light active' : 'btn-outline-light'; ?>" data-language="nl" aria-pressed="<?php echo $pageLanguage === 'nl' ? 'true' : 'false'; ?>">NL</button>
                                    <button type="button" class="btn <?php echo $pageLanguage === 'en' ? 'btn-light active' : 'btn-outline-light'; ?>" data-language="en" aria-pressed="<?php echo $pageLanguage === 'en' ? 'true' : 'false'; ?>">EN</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <nav class="insight-navigation" data-i18n-aria-label="dataViews" aria-label="Dataweergaven">
                    <a href="./" class="insight-link">
                        <i class="bi bi-calendar-check"></i>
                        <span data-i18n="dailyData">Daggegevens</span>
                    </a>
                    <a href="<?php echo h(appAssetPath('yearly.php')); ?>" class="insight-link active" aria-current="page">
                        <i class="bi bi-bar-chart-line"></i>
                        <span data-i18n="yearlyStatistics">Jaarstatistieken</span>
                    </a>
                </nav>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger" role="alert" data-i18n="yearlyLoadError">Jaarstatistieken konden niet worden geladen.</div>
        <?php elseif (!$rows): ?>
        <div class="alert alert-warning" role="alert" data-i18n="yearlyNoData">Er zijn geen jaarstatistieken beschikbaar.</div>
        <?php else: ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-cloud-rain-heavy me-2"></i><span data-i18n="overview">Overzicht</span></h4>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 metric-card-grid">
                            <div class="col-md-6 col-xl-2">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-calendar-range text-primary fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo h($firstYear . '-' . $lastYear); ?></div>
                                        <small data-i18n="period">Periode</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-xl-2">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-droplet text-info fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo formatMetricValue($average, 'mm'); ?></div>
                                        <small data-i18n="averagePerYear">Gem. neerslag per volledig jaar</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-xl-2">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-cloud-arrow-down text-success fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo $wettest ? h($wettest['year']) : '--'; ?></div>
                                        <small>
                                            <span data-i18n="wettestYear">Natste jaar:</span>
                                            <?php echo formatMetricValue($wettest['precipitation_mm'] ?? null, 'mm'); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-xl-2">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-sun text-warning fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo $driest ? h($driest['year']) : '--'; ?></div>
                                        <small>
                                            <span data-i18n="driestYear">Droogste jaar:</span>
                                            <?php echo formatMetricValue($driest['precipitation_mm'] ?? null, 'mm'); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-xl-2">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-thermometer-sun text-danger fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo $warmest ? h($warmest['year']) : '--'; ?></div>
                                        <small>
                                            <span data-i18n="warmestYear">Warmste jaar:</span>
                                            <?php echo formatMetricValue($warmest['temp_avg_c'] ?? null, '°C', false); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-xl-2">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-brightness-high text-warning fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo $sunniest ? h($sunniest['year']) : '--'; ?></div>
                                        <small>
                                            <span data-i18n="sunniestYear">Zonnigste jaar:</span>
                                            <?php echo formatMetricValue($sunniest['sunshine_hours'] ?? null, 'h'); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                            <h4 class="mb-0">
                                <i class="bi bi-graph-up me-2"></i><span data-i18n="yearlyChart">Jaarstatistieken per jaar</span>
                            </h4>
                            <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                <div class="btn-group btn-group-sm yearly-chart-actions" role="group" data-i18n-aria-label="metricType" aria-label="Statistiek">
                                    <button type="button" class="btn btn-outline-light active" data-metric-type="precipitation" data-i18n-title="precipitation" data-i18n-aria-label="precipitation" title="Neerslag" aria-label="Neerslag">
                                        <i class="bi bi-droplet"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light" data-metric-type="temperature" data-i18n-title="temperature" data-i18n-aria-label="temperature" title="Temperatuur" aria-label="Temperatuur">
                                        <i class="bi bi-thermometer-half"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light" data-metric-type="sunshine" data-i18n-title="sunshine" data-i18n-aria-label="sunshine" title="Zon" aria-label="Zon">
                                        <i class="bi bi-sun"></i>
                                    </button>
                                </div>
                                <div class="btn-group btn-group-sm yearly-chart-actions" role="group" data-i18n-aria-label="chartType" aria-label="Grafiektype">
                                    <button type="button" class="btn btn-outline-light" data-chart-type="bar" data-i18n-title="barChart" data-i18n-aria-label="barChart" title="Staafdiagram" aria-label="Staafdiagram">
                                        <i class="bi bi-bar-chart"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light active" data-chart-type="line" data-i18n-title="lineChart" data-i18n-aria-label="lineChart" title="Lijndiagram" aria-label="Lijndiagram">
                                        <i class="bi bi-activity"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light" id="downloadChart" data-i18n-title="downloadChart" data-i18n-aria-label="downloadChart" title="Grafiek downloaden" aria-label="Grafiek downloaden">
                                        <i class="bi bi-download"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container yearly-chart-container">
                            <canvas id="yearlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-table me-2"></i><span data-i18n="yearlyTotals">Jaarlijkse statistieken</span></h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle sortable-table" id="yearlyStatsTable">
                                <thead>
                                    <tr>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="0" data-i18n="year">Jaar</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="1" data-i18n="rainTotal">Neerslag totaal</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="2" data-i18n="rainMinMonth">Droogste maand</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="3" data-i18n="rainAvgMonth">Gem. maand</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="4" data-i18n="rainMaxMonth">Natste maand</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="5" data-i18n="daysWithPrecipitation">Dagen met neerslag</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="6" data-i18n="tempMin">Laagste min.</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="7" data-i18n="tempAvg">Gem. temp.</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="8" data-i18n="tempMax">Hoogste max.</button></th>
                                        <th aria-sort="none"><button type="button" class="table-sort-button" data-sort-column="9" data-i18n="sunHours">Zonuren</button></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_reverse($rows) as $row): ?>
                                    <tr>
                                        <td<?php echo sortAttribute($row['year']); ?>><?php echo h($row['year']); ?></td>
                                        <td<?php echo sortAttribute($row['precipitation_mm']); ?>><?php echo formatMetricValue($row['precipitation_mm'], 'mm'); ?></td>
                                        <td<?php echo sortAttribute($row['precipitation_min_month_mm']); ?>><?php echo formatMonthMetricValue($row['precipitation_min_month'], $row['precipitation_min_month_mm'], $pageLanguage); ?></td>
                                        <td<?php echo sortAttribute($row['precipitation_avg_month_mm']); ?>><?php echo formatMonthMetricValue($row['precipitation_avg_month'], $row['precipitation_avg_month_mm'], $pageLanguage); ?></td>
                                        <td<?php echo sortAttribute($row['precipitation_max_month_mm']); ?>><?php echo formatMonthMetricValue($row['precipitation_max_month'], $row['precipitation_max_month_mm'], $pageLanguage); ?></td>
                                        <td<?php echo sortAttribute($row['precipitation_days']); ?>><?php echo $row['precipitation_days'] === null ? '--' : h($row['precipitation_days']); ?></td>
                                        <td<?php echo sortAttribute($row['temp_min_c']); ?>><?php echo formatMetricValue($row['temp_min_c'], '°C', false); ?></td>
                                        <td<?php echo sortAttribute($row['temp_avg_c']); ?>><?php echo formatMetricValue($row['temp_avg_c'], '°C', false); ?></td>
                                        <td<?php echo sortAttribute($row['temp_max_c']); ?>><?php echo formatMetricValue($row['temp_max_c'], '°C', false); ?></td>
                                        <td<?php echo sortAttribute($row['sunshine_hours']); ?>><?php echo formatMetricValue($row['sunshine_hours'], 'h'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <footer class="row mt-5">
            <div class="col-12">
                <div class="text-center text-muted">
                    <p class="mb-0">
                        <strong data-i18n="appName">KNMI Daggegevens</strong> -
                        <a href="./" class="text-decoration-none" data-i18n="dailyData">Daggegevens</a>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <script src="js/app-i18n.js?v=<?php echo filemtime(__DIR__ . '/js/app-i18n.js'); ?>"></script>
    <script>
        const yearlyStats = <?php echo json_encode($rows, JSON_NUMERIC_CHECK); ?>;
        const downloadFilename = <?php echo json_encode($downloadFilename); ?>;
        let currentLanguage = ['nl', 'en'].includes(document.documentElement.lang)
            ? document.documentElement.lang
            : 'nl';
        let yearlyChart;
        let activeMetric = 'precipitation';
        let activeChartType = 'line';

        const metricConfig = {
            precipitation: {
                titleKey: 'yearlyChartTitlePrecipitation',
                axisKey: 'yearlyAxisPrecipitation',
                unitKey: 'yearlyUnitMm',
                beginAtZero: true,
                datasets: [
                    { labelKey: 'yearlyChartDatasetRainMinMonth', dataKey: 'precipitation_min_month_mm', monthKey: 'precipitation_min_month' },
                    { labelKey: 'yearlyChartDatasetRainAvgMonth', dataKey: 'precipitation_avg_month_mm', monthKey: 'precipitation_avg_month' },
                    { labelKey: 'yearlyChartDatasetRainMaxMonth', dataKey: 'precipitation_max_month_mm', monthKey: 'precipitation_max_month' }
                ]
            },
            temperature: {
                titleKey: 'yearlyChartTitleTemperature',
                axisKey: 'yearlyAxisTemperature',
                unitKey: 'yearlyUnitCelsius',
                beginAtZero: false,
                datasets: [
                    { labelKey: 'yearlyChartDatasetTempMin', dataKey: 'temp_min_c' },
                    { labelKey: 'yearlyChartDatasetTempAvg', dataKey: 'temp_avg_c' },
                    { labelKey: 'yearlyChartDatasetTempMax', dataKey: 'temp_max_c' }
                ]
            },
            sunshine: {
                titleKey: 'yearlyChartTitleSunshine',
                axisKey: 'yearlyAxisSunshine',
                unitKey: 'yearlyUnitHours',
                beginAtZero: true,
                datasets: [
                    { labelKey: 'yearlyChartDatasetSunshine', dataKey: 'sunshine_hours' }
                ]
            }
        };

        function t(key) {
            return window.AppI18n?.[currentLanguage]?.[key] || window.AppI18n?.nl?.[key] || key;
        }

        function monthName(monthNumber) {
            const monthIndex = Number(monthNumber) - 1;
            return window.AppI18n?.[currentLanguage]?.months?.[monthIndex]
                || window.AppI18n?.nl?.months?.[monthIndex]
                || '';
        }

        function shortMonthName(monthNumber) {
            const monthIndex = Number(monthNumber) - 1;
            return window.AppI18n?.[currentLanguage]?.shortMonths?.[monthIndex]
                || window.AppI18n?.nl?.shortMonths?.[monthIndex]
                || monthName(monthNumber).slice(0, 3);
        }

        function updateMonthLabels() {
            document.querySelectorAll('[data-month-number]').forEach(element => {
                const label = element.dataset.monthFormat === 'short'
                    ? shortMonthName(element.dataset.monthNumber)
                    : monthName(element.dataset.monthNumber);
                element.textContent = element.dataset.monthFormat === 'short' ? `(${label})` : label;
            });
        }

        function sortTable(table, columnIndex, direction) {
            const tbody = table.querySelector('tbody');
            const collator = new Intl.Collator(currentLanguage, { numeric: true, sensitivity: 'base' });
            const rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort((rowA, rowB) => {
                const cellA = rowA.children[columnIndex];
                const cellB = rowB.children[columnIndex];
                const valueA = cellA?.dataset.sortValue ?? cellA?.textContent.trim() ?? '';
                const valueB = cellB?.dataset.sortValue ?? cellB?.textContent.trim() ?? '';
                const missingA = valueA === '';
                const missingB = valueB === '';

                if (missingA && missingB) return 0;
                if (missingA) return 1;
                if (missingB) return -1;

                const numberA = Number(valueA);
                const numberB = Number(valueB);
                const result = Number.isNaN(numberA) || Number.isNaN(numberB)
                    ? collator.compare(valueA, valueB)
                    : numberA - numberB;

                return direction === 'ascending' ? result : -result;
            });

            rows.forEach(row => tbody.appendChild(row));
        }

        function initSortableTable() {
            const table = document.getElementById('yearlyStatsTable');
            if (!table) return;

            const headers = Array.from(table.querySelectorAll('thead th'));
            table.querySelectorAll('[data-sort-column]').forEach(button => {
                button.addEventListener('click', () => {
                    const header = button.closest('th');
                    const nextDirection = header.getAttribute('aria-sort') === 'ascending'
                        ? 'descending'
                        : 'ascending';

                    headers.forEach(item => item.setAttribute('aria-sort', 'none'));
                    header.setAttribute('aria-sort', nextDirection);
                    sortTable(table, Number(button.dataset.sortColumn), nextDirection);
                });
            });
        }

        function updateLanguageControls() {
            document.querySelectorAll('[data-language]').forEach(button => {
                const isActive = button.dataset.language === currentLanguage;
                button.classList.toggle('active', isActive);
                button.classList.toggle('btn-light', isActive);
                button.classList.toggle('btn-outline-light', !isActive);
                button.setAttribute('aria-pressed', String(isActive));
            });
        }

        function applyLanguage(language) {
            if (!['nl', 'en'].includes(language)) return;

            currentLanguage = language;
            localStorage.setItem('knmi-language', language);
            document.documentElement.lang = language;
            document.title = t('yearlyPageTitle');

            const metaDescription = document.querySelector('meta[name="description"]');
            if (metaDescription) {
                metaDescription.content = t('yearlyPageDescription');
            }

            document.querySelectorAll('[data-i18n]').forEach(element => {
                element.textContent = t(element.dataset.i18n);
            });

            document.querySelectorAll('[data-i18n-title]').forEach(element => {
                element.title = t(element.dataset.i18nTitle);
            });

            document.querySelectorAll('[data-i18n-aria-label]').forEach(element => {
                element.setAttribute('aria-label', t(element.dataset.i18nAriaLabel));
            });

            updateLanguageControls();
            updateMonthLabels();
            updateThemeToggle();
            updateChartLanguage();
        }

        function chartColors() {
            const isDark = document.documentElement.dataset.theme === 'dark';
            return {
                text: isDark ? '#edf4fb' : '#172033',
                grid: isDark ? 'rgba(196, 217, 236, 0.16)' : 'rgba(20, 52, 85, 0.12)',
                palette: isDark
                    ? [
                        { stroke: '#6bb6ff', fill: 'rgba(107, 182, 255, 0.32)' },
                        { stroke: '#5ee0d1', fill: 'rgba(94, 224, 209, 0.24)' },
                        { stroke: '#ffbd6b', fill: 'rgba(255, 189, 107, 0.24)' }
                    ]
                    : [
                        { stroke: '#0a66c2', fill: 'rgba(10, 102, 194, 0.22)' },
                        { stroke: '#0f9488', fill: 'rgba(15, 148, 136, 0.20)' },
                        { stroke: '#fd7e14', fill: 'rgba(253, 126, 20, 0.20)' }
                    ]
            };
        }

        function datasetFor(definition, index) {
            const colors = chartColors();
            const palette = colors.palette[index % colors.palette.length];
            return {
                type: activeChartType,
                label: t(definition.labelKey),
                data: yearlyStats.map(row => row[definition.dataKey]),
                borderColor: palette.stroke,
                backgroundColor: palette.fill,
                borderWidth: activeChartType === 'line' ? 3 : 1,
                borderRadius: activeChartType === 'bar' ? 3 : 0,
                fill: activeChartType === 'line' && metricConfig[activeMetric].datasets.length === 1,
                pointRadius: activeChartType === 'line' ? 3 : 0,
                pointHoverRadius: 5,
                tension: 0.25
            };
        }

        function updateChart(mode = 'none') {
            if (!yearlyChart) return;

            const config = metricConfig[activeMetric];
            const colors = chartColors();
            yearlyChart.data.labels = yearlyStats.map(row => row.year);
            yearlyChart.data.datasets = config.datasets.map((definition, index) => datasetFor(definition, index));
            yearlyChart.options.plugins.legend.labels.color = colors.text;
            yearlyChart.options.plugins.title.color = colors.text;
            yearlyChart.options.plugins.title.text = t(config.titleKey);
            yearlyChart.options.scales.x.ticks.color = colors.text;
            yearlyChart.options.scales.y.ticks.color = colors.text;
            yearlyChart.options.scales.x.grid.color = colors.grid;
            yearlyChart.options.scales.y.grid.color = colors.grid;
            yearlyChart.options.scales.y.title.color = colors.text;
            yearlyChart.options.scales.y.title.text = t(config.axisKey);
            yearlyChart.options.scales.y.beginAtZero = config.beginAtZero;
            yearlyChart.update(mode);
        }

        function updateChartLanguage() {
            updateChart('none');
        }

        function applyChartTheme() {
            updateChart('none');
        }

        function updateMetricControls() {
            document.querySelectorAll('[data-metric-type]').forEach(button => {
                button.classList.toggle('active', button.dataset.metricType === activeMetric);
            });

            document.querySelectorAll('[data-chart-type]').forEach(button => {
                button.classList.toggle('active', button.dataset.chartType === activeChartType);
            });
        }

        function updateThemeToggle() {
            const toggle = document.getElementById('themeToggle');
            const icon = toggle ? toggle.querySelector('i') : null;
            if (toggle) {
                const nextKey = document.documentElement.dataset.theme === 'dark' ? 'themeLight' : 'themeDark';
                toggle.title = t(nextKey);
                toggle.setAttribute('aria-label', t(nextKey));
            }
            if (icon) {
                icon.className = document.documentElement.dataset.theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
            }
        }

        function setTheme(theme) {
            localStorage.setItem('knmi-theme', theme);
            document.documentElement.dataset.theme = theme;

            updateThemeToggle();
            applyChartTheme();
        }

        document.addEventListener('DOMContentLoaded', () => {
            applyLanguage(currentLanguage);

            const ctx = document.getElementById('yearlyChart');
            const colors = chartColors();

            if (ctx && yearlyStats.length && typeof Chart !== 'undefined') {
                yearlyChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: yearlyStats.map(row => row.year),
                        datasets: metricConfig[activeMetric].datasets.map((definition, index) => datasetFor(definition, index))
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        plugins: {
                            legend: {
                                labels: {
                                    color: colors.text,
                                    usePointStyle: true
                                }
                            },
                            title: {
                                display: true,
                                text: t(metricConfig[activeMetric].titleKey),
                                color: colors.text,
                                font: {
                                    size: 16,
                                    weight: 'bold'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: context => {
                                        const config = metricConfig[activeMetric];
                                        const definition = config.datasets[context.datasetIndex];
                                        const row = yearlyStats[context.dataIndex] || {};
                                        const month = definition.monthKey ? monthName(row[definition.monthKey]) : '';
                                        const suffix = month ? ` (${month})` : '';

                                        return `${context.dataset.label}: ${context.parsed.y} ${t(config.unitKey)}${suffix}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    color: colors.grid
                                },
                                ticks: {
                                    color: colors.text,
                                    maxRotation: 0,
                                    autoSkip: true,
                                    autoSkipPadding: 18
                                }
                            },
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: colors.grid
                                },
                                ticks: {
                                    color: colors.text
                                },
                                title: {
                                    display: true,
                                    text: t(metricConfig[activeMetric].axisKey),
                                    color: colors.text
                                }
                            }
                        }
                    }
                });

                document.querySelectorAll('[data-metric-type]').forEach(button => {
                    button.addEventListener('click', () => {
                        activeMetric = button.dataset.metricType;
                        activeChartType = metricConfig[activeMetric].datasets.length > 1 ? 'line' : 'bar';
                        updateMetricControls();
                        updateChart();
                    });
                });

                document.querySelectorAll('[data-chart-type]').forEach(button => {
                    button.addEventListener('click', () => {
                        activeChartType = button.dataset.chartType;
                        updateMetricControls();
                        updateChart();
                    });
                });

                updateMetricControls();

                const downloadButton = document.getElementById('downloadChart');
                if (downloadButton) {
                    downloadButton.addEventListener('click', () => {
                        const link = document.createElement('a');
                        link.download = downloadFilename;
                        link.href = yearlyChart.toBase64Image('image/png', 1);
                        link.click();
                    });
                }
            }

            document.querySelectorAll('[data-language]').forEach(button => {
                button.addEventListener('click', () => {
                    applyLanguage(button.dataset.language);
                });
            });

            initSortableTable();

            const themeToggle = document.getElementById('themeToggle');
            if (themeToggle) {
                themeToggle.addEventListener('click', () => {
                    setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
                });
            }

            setTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');
        });
    </script>
</body>
</html>
