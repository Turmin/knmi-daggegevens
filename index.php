<?php
// index.php
require_once 'config/Database.php';
require_once 'models/WeatherData.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function isValidDateString($date) {
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function getRequestedDate() {
    foreach (['date', 'datum'] as $param) {
        if (!empty($_GET[$param])) {
            return $_GET[$param] === 'today' ? date('Y-m-d') : $_GET[$param];
        }
    }

    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (preg_match('~/(\\d{4}-\\d{2}-\\d{2})/?$~', $requestPath, $matches)) {
        return $matches[1];
    }

    return null;
}

function getDatePathFromRequest() {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (preg_match('~/(\\d{4}-\\d{2}-\\d{2})/?$~', $requestPath, $matches)) {
        return $matches[1];
    }

    return null;
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

function siteBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'knmi.turmin.com';
    $scheme = isLocalHostName($host)
        ? (requestIsSecure() ? 'https' : 'http')
        : 'https';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

    return $scheme . '://' . $host . $scriptDir;
}

function appBasePath() {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
}

function appAssetPath($path) {
    return appBasePath() . '/' . ltrim($path, '/');
}

function homePageUrl() {
    return siteBaseUrl() . '/';
}

function datePageUrl($date) {
    return siteBaseUrl() . '/' . rawurlencode($date);
}

function formatPageDate($date, $language = 'nl') {
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $monthIndex = (int)date('n', $timestamp) - 1;
    $months = [
        'nl' => ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
    ];

    return (int)date('j', $timestamp) . ' ' . $months[$language][$monthIndex] . ' ' . date('Y', $timestamp);
}

function formatMetaNumber($value, $language = 'nl') {
    if ($value === null || $value === '') {
        return null;
    }

    return number_format((float)$value, 1, $language === 'nl' ? ',' : '.', '');
}

function weatherMetaDescription($weather, $pageDate, $language = 'nl') {
    if (!$weather) {
        return $language === 'en'
            ? 'Historical KNMI daily weather data for ' . $pageDate . ' in De Bilt.'
            : 'Historische KNMI daggegevens voor ' . $pageDate . ' in De Bilt.';
    }

    $average = formatMetaNumber($weather['temperature']['avg'] ?? null, $language);
    $maximum = formatMetaNumber($weather['temperature']['max'] ?? null, $language);
    $rain = formatMetaNumber($weather['precipitation']['amount'] ?? null, $language);
    $sun = formatMetaNumber($weather['sunshine']['duration'] ?? null, $language);
    $wind = $weather['wind']['direction_text'] ?? null;

    if ($language === 'en') {
        $parts = [];
        if ($average !== null) {
            $parts[] = 'average temperature ' . $average . ' °C';
        }
        if ($maximum !== null) {
            $parts[] = 'maximum ' . $maximum . ' °C';
        }
        if ($rain !== null) {
            $parts[] = $rain . ' mm precipitation';
        }
        if ($sun !== null) {
            $parts[] = $sun . ' hours of sunshine';
        }
        if ($wind) {
            $parts[] = 'wind from ' . $wind;
        }
        if (!$parts) {
            return 'Historical KNMI daily weather data for ' . $pageDate . ' in De Bilt.';
        }

        return 'Weather on ' . $pageDate . ' in De Bilt: ' . implode(', ', $parts) . '.';
    }

    $parts = [];
    if ($average !== null) {
        $parts[] = 'gemiddeld ' . $average . ' °C';
    }
    if ($maximum !== null) {
        $parts[] = 'maximaal ' . $maximum . ' °C';
    }
    if ($rain !== null) {
        $parts[] = $rain . ' mm neerslag';
    }
    if ($sun !== null) {
        $parts[] = $sun . ' uur zon';
    }
    if ($wind) {
        $parts[] = 'wind ' . $wind;
    }
    if (!$parts) {
        return 'Historische KNMI daggegevens voor ' . $pageDate . ' in De Bilt.';
    }

    return 'Het weer op ' . $pageDate . ' in De Bilt: ' . implode(', ', $parts) . '.';
}

function getDocumentLinks() {
    $labelMap = [
        'beaufortschaal.pdf' => ['nl' => 'Beaufortschaal', 'en' => 'Beaufort scale'],
        'windroos.pdf' => ['nl' => 'Windroos', 'en' => 'Wind rose']
    ];

    $documents = [];
    foreach (glob(__DIR__ . '/doc/*.pdf') ?: [] as $path) {
        $filename = basename($path);
        $fallback = ucwords(str_replace(['-', '_'], ' ', pathinfo($filename, PATHINFO_FILENAME)));
        $labels = $labelMap[$filename] ?? ['nl' => $fallback, 'en' => $fallback];

        $documents[] = [
            'href' => 'doc/' . rawurlencode($filename),
            'filename' => $filename,
            'label_nl' => $labels['nl'],
            'label_en' => $labels['en']
        ];
    }

    return $documents;
}

$requestedDate = getRequestedDate();
$datePathFromRequest = getDatePathFromRequest();
$weatherData = null;

// Initialize weather data
try {
    $database = new Database();
    $db = $database->connect();
    $weatherData = new WeatherData($db);
    
    // Get date range for navigation limits
    $dateRange = $weatherData->getDateRange();
    $firstDate = $dateRange['first_date'];
    $lastDate = $dateRange['last_date'];
    
} catch(Exception $e) {
    error_log("Initialization error: " . $e->getMessage());
    $firstDate = '1970-01-01';
    $lastDate = date('Y-m-d');
}

$pageLanguage = isset($_GET['lang']) && $_GET['lang'] === 'en' ? 'en' : 'nl';

// Set default date (today or latest available)
$defaultDate = min(date('Y-m-d'), $lastDate);
$requestedDateIsCanonical = false;
if ($requestedDate && isValidDateString($requestedDate) && $requestedDate >= $firstDate && $requestedDate <= $lastDate) {
    $defaultDate = $requestedDate;
    $requestedDateIsCanonical = $datePathFromRequest === $requestedDate;
}

$isHomeRequest = !$requestedDate && !$datePathFromRequest;
$canonicalUrl = $isHomeRequest ? homePageUrl() : datePageUrl($defaultDate);

if (
    $requestedDate
    && isValidDateString($requestedDate)
    && $requestedDate >= $firstDate
    && $requestedDate <= $lastDate
    && !$requestedDateIsCanonical
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
) {
    header('Location: ' . $canonicalUrl, true, 301);
    exit;
}

$initialWeatherData = null;
if ($weatherData instanceof WeatherData) {
    try {
        $initialWeatherData = $weatherData->getDataByDate($defaultDate);
    } catch(Exception $e) {
        error_log("Initial weather data error: " . $e->getMessage());
    }
}

$pageDate = formatPageDate($defaultDate, $pageLanguage);
$pageTitle = $pageLanguage === 'en'
    ? 'Weather on ' . $pageDate . ' - KNMI Daily Data'
    : 'Het weer op ' . $pageDate . ' - KNMI Daggegevens';
$pageDescription = weatherMetaDescription($initialWeatherData, $pageDate, $pageLanguage);
$documents = getDocumentLinks();
$faviconHref = appAssetPath('favicon.svg');
$initialWeatherJson = json_encode(
    $initialWeatherData,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
if ($initialWeatherJson === false) {
    $initialWeatherJson = 'null';
}
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
    <meta name="keywords" content="knmi, weer, weerstatistieken, temperatuur, neerslag, verdamping, zonneschijnduur, straling, bedekkingsgraad, zicht, luchtvochtigheid">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('knmi-theme');
            const savedLanguage = localStorage.getItem('knmi-language') || '<?php echo h($pageLanguage); ?>';
            const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';

            document.documentElement.dataset.theme = savedTheme || preferredTheme;
            document.documentElement.lang = savedLanguage;
        })();
    </script>
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo h($canonicalUrl); ?>">
    <meta property="og:title" content="<?php echo h($pageTitle); ?>">
    <meta property="og:description" content="<?php echo h($pageDescription); ?>">
    
    <!-- Bootstrap CSS -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.js" defer></script>
    <!-- Bootstrap Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="css/modern-style.css?v=<?php echo filemtime(__DIR__ . '/css/modern-style.css'); ?>">
</head>
<body>
    <div class="container-fluid main-container" id="mainContent">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <div class="app-header">
                            <div class="app-title">
                                <div class="mb-0 app-name">
                                    <i class="bi bi-cloud-sun me-2"></i>
                                    <span data-i18n="appName">KNMI Daggegevens</span>
                                </div>
                                <p class="mb-0 mt-2" data-i18n="tagline">Historische weerdata van Nederland</p>
                            </div>
                            <div class="preference-controls" aria-label="Weergave-instellingen">
                                <div class="btn-group btn-group-sm" role="group" aria-label="Theme">
                                    <button type="button" class="btn btn-outline-light" id="themeToggle" data-i18n-title="themeDark" aria-label="Schakel donker thema in">
                                        <i class="bi bi-moon-stars"></i>
                                    </button>
                                </div>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Language">
                                    <button type="button" class="btn btn-light active" data-language="nl" aria-pressed="true">NL</button>
                                    <button type="button" class="btn btn-outline-light" data-language="en" aria-pressed="false">EN</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Views -->
        <div class="row mb-4">
            <div class="col-12">
                <nav class="insight-navigation" data-i18n-aria-label="dataViews" aria-label="Dataweergaven">
                    <a href="./" class="insight-link active" aria-current="page">
                        <i class="bi bi-calendar-check"></i>
                        <span data-i18n="dailyData">Daggegevens</span>
                    </a>
                    <a href="<?php echo h(appAssetPath('precipitation.php')); ?>" class="insight-link">
                        <i class="bi bi-cloud-rain-heavy"></i>
                        <span data-i18n="annualPrecipitation">Jaarlijkse neerslag</span>
                    </a>
                </nav>
            </div>
        </div>

        <!-- Date Navigation -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="date-navigation">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-5">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-calendar-date"></i>
                                </span>
                                <input type="date" 
                                       class="form-control" 
                                       id="primaryDate" 
                                       value="<?php echo $defaultDate; ?>"
                                       min="<?php echo $firstDate; ?>"
                                       max="<?php echo $lastDate; ?>">
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="btn-group w-100 date-action-group" role="group" aria-label="Datum navigatie">
                                <button type="button" class="btn btn-outline-primary btn-custom" id="prevDay" title="Vorige dag (Ctrl+←)" data-i18n-title="prevDay">
                                    <i class="bi bi-chevron-left"></i> <span class="d-none d-sm-inline" data-i18n="prevDay">Vorige dag</span>
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-custom" id="latestDay" title="Laatste dag (Ctrl+T)" data-i18n-title="latestDay">
                                    <i class="bi bi-calendar-check"></i> <span class="d-none d-sm-inline" data-i18n="latestDay">Laatste dag</span>
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-custom" id="nextDay" title="Volgende dag (Ctrl+→)" data-i18n-title="nextDay">
                                    <span class="d-none d-sm-inline" data-i18n="nextDay">Volgende dag</span> <i class="bi bi-chevron-right"></i>
                                </button>
                                <!-- <button type="button" class="btn btn-outline-secondary btn-custom" id="refreshData" data-i18n-title="refresh" aria-label="Ververs data">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button> -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comparison Toggle -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="comparison-toggle">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="comparisonMode" title="Vergelijk twee datums (Ctrl+C)" data-i18n-title="comparisonMode">
                        <label class="form-check-label fw-bold" for="comparisonMode">
                            <i class="bi bi-arrow-left-right me-2"></i><span data-i18n="comparisonMode">Vergelijkingsmodus</span>
                            <!-- <small class="text-muted ms-2" data-i18n="comparisonHint">(Vergelijk weerdata van twee verschillende dagen)</small> -->
                        </label>
                    </div>
                    <div id="comparisonDatePicker" class="mt-3" style="display: none;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" data-i18n="compareWith">Vergelijk met:</label>
                                <input type="date" 
                                       class="form-control" 
                                       id="comparisonDate" 
                                       min="<?php echo $firstDate; ?>"
                                       max="<?php echo $lastDate; ?>">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="btn-group">
                                    <button class="btn btn-success btn-custom" id="quickCompare" title="Vergelijk met gisteren" data-i18n-title="yesterday">
                                        <i class="bi bi-lightning me-1"></i><span data-i18n="yesterday">Gisteren</span>
                                    </button>
                                    <button class="btn btn-info btn-custom" id="weekCompare" title="Vergelijk met vorige week" data-i18n-title="previousWeek">
                                        <i class="bi bi-calendar-week me-1"></i><span data-i18n="previousWeek">Vorige week</span>
                                    </button>
                                    <button class="btn btn-warning btn-custom" id="yearCompare" title="Vergelijk met vorig jaar" data-i18n-title="previousYear">
                                        <i class="bi bi-calendar me-1"></i><span data-i18n="previousYear">Vorig jaar</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Messages -->
        <div id="statusMessages"></div>

        <!-- Loading Spinner -->
        <div class="loading-spinner text-center" id="loadingSpinner" style="display: none;">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden" data-i18n="loading">Laden...</span>
            </div>
            <p class="mt-2" data-i18n="fetchingWeather">Weergegevens ophalen...</p>
        </div>

        <!-- Weather Data -->
        <div class="row" id="weatherData">
            <!-- Primary Day Card -->
            <div class="col-lg-6 mb-4">
                <div class="weather-card fade-in" id="primaryCard">
                    <div class="card-header">
                        <h1 class="mb-0 primary-date-title" id="primaryDayTitle">
                            <i class="bi bi-calendar-check me-2"></i>
                            <?php echo h($pageDate); ?>
                        </h1>
                        <small data-i18n="station">Meetstation: De Bilt (260)</small>
                    </div>
                    <div class="card-body p-4">
                        <!-- Temperature Section -->
                        <h5 class="text-primary mb-3">
                            <i class="bi bi-thermometer-half me-2"></i><span data-i18n="temperature">Temperatuur</span>
                        </h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label" data-i18n="average">Gemiddeld</span>
                                        <span class="metric-value" id="primaryTemp">
                                            <div class="loading-placeholder">--°C</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label"><span data-i18n="minimum">Minimum</span> <small id="primaryTempMinTime" class="text-muted"></small></span>
                                        <span class="metric-value" id="primaryTempMin">
                                            <div class="loading-placeholder">--°C</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label"><span data-i18n="maximum">Maximum</span> <small id="primaryTempMaxTime" class="text-muted"></small></span>
                                        <span class="metric-value" id="primaryTempMax">
                                            <div class="loading-placeholder">--°C</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Wind Section -->
                        <h5 class="text-primary mb-3">
                            <i class="bi bi-wind me-2"></i><span data-i18n="wind">Wind</span>
                        </h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label" data-i18n="direction">Richting</span>
                                        <span class="metric-value" id="primaryWindDirection">
                                            <div class="loading-placeholder">-- <div class="wind-indicator"><i class="bi bi-arrow-up wind-arrow"></i></div></div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label"><span data-i18n="speed">Snelheid</span><small id="primaryWindScale" class="text-muted metric-subtext"></small></span>
                                        <span class="metric-value" id="primaryWind">
                                            <div class="loading-placeholder">-- km/h</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Wind Info -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label"><span data-i18n="maxGust">Max windstoot</span> <small id="primaryWindGustTime" class="text-muted"></small></span>
                                        <span class="metric-value" id="primaryWindGust">
                                            <div class="loading-placeholder">-- km/h</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label"><span data-i18n="maxWindSpeed">Max windsnelheid</span> <small id="primaryWindMaxTime" class="text-muted"></small></span>
                                        <span class="metric-value" id="primaryWindMax">
                                            <div class="loading-placeholder">-- km/h</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Precipitation & Sun Section -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <h6 class="text-primary mb-2">
                                    <i class="bi bi-droplet me-2"></i><span data-i18n="precipitation">Neerslag</span>
                                </h6>
                                <div class="weather-metric mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label" data-i18n="amount">Hoeveelheid</span>
                                        <span class="metric-value" id="primaryRain">
                                            <div class="loading-placeholder">-- mm</div>
                                        </span>
                                    </div>
                                </div>
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label" data-i18n="duration">Duur</span>
                                        <span class="metric-value" id="primaryRainDuration">
                                            <div class="loading-placeholder">-- uur</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary mb-2">
                                    <i class="bi bi-brightness-high me-2"></i><span data-i18n="sun">Zon</span>
                                </h6>
                                <div class="weather-metric mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label" data-i18n="duration">Duur</span>
                                        <span class="metric-value" id="primarySun">
                                            <div class="loading-placeholder">-- uur</div>
                                        </span>
                                    </div>
                                </div>
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label" data-i18n="percentage">Percentage</span>
                                        <span class="metric-value" id="primarySunPercentage">
                                            <div class="loading-placeholder">--%</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Metrics -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <h6 class="text-primary mb-2">
                                    <i class="bi bi-speedometer2 me-2"></i><span data-i18n="pressure">Luchtdruk</span>
                                </h6>
                                <div class="weather-metric mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label" data-i18n="average">Gemiddeld</span>
                                        <span class="metric-value" id="primaryPressure">
                                            <div class="loading-placeholder">-- hPa</div>
                                        </span>
                                    </div>
                                </div>
                                <div class="weather-metric metric-range-metric">
                                    <span class="metric-value metric-range-value" id="primaryPressureRange">
                                        <span class="metric-range-row">
                                            <span class="metric-range-prefix" data-i18n="minimumShort">Min</span>
                                            <span class="metric-range-number">--&nbsp;hPa</span>
                                        </span>
                                        <span class="metric-range-row">
                                            <span class="metric-range-prefix" data-i18n="maximumShort">Max</span>
                                            <span class="metric-range-number">--&nbsp;hPa</span>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary mb-2">
                                    <i class="bi bi-moisture me-2"></i><span data-i18n="humidity">Luchtvochtigheid</span>
                                </h6>
                                <div class="weather-metric mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="metric-label" data-i18n="average">Gemiddeld</span>
                                        <span class="metric-value" id="primaryHumidity">
                                            <div class="loading-placeholder">--%</div>
                                        </span>
                                    </div>
                                </div>
                                <div class="weather-metric metric-range-metric">
                                    <span class="metric-value metric-range-value" id="primaryHumidityRange">
                                        <span class="metric-range-row">
                                            <span class="metric-range-prefix" data-i18n="minimumShort">Min</span>
                                            <span class="metric-range-number">--%</span>
                                        </span>
                                        <span class="metric-range-row">
                                            <span class="metric-range-prefix" data-i18n="maximumShort">Max</span>
                                            <span class="metric-range-number">--%</span>
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comparison Day Card (Hidden by default) -->
            <div class="col-lg-6 mb-4" id="comparisonCard" style="display: none;">
                <div class="weather-card fade-in comparison-card">
                    <div class="card-header comparison-header">
                        <h3 class="mb-0" id="comparisonDayTitle">
                            <i class="bi bi-calendar-x me-2"></i>
                            <span class="loading-placeholder" data-i18n="comparisonDateLoading">Vergelijkingsdatum wordt geladen...</span>
                        </h3>
                        <small data-i18n="comparisonStation">Vergelijking - Meetstation: De Bilt (260)</small>
                    </div>
                    <div class="card-body p-4" id="comparisonContent">
                        <!-- Dynamic comparison content will be loaded here -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar Day Statistics -->
        <div class="row mb-4" id="calendarStatsRow">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                            <h4 class="mb-0" id="calendarStatsTitle">
                                <i class="bi bi-calendar3-week me-2"></i><span data-i18n="calendarStats">Deze datum door de jaren heen</span>
                            </h4>
                            <small class="text-light" id="calendarStatsSubtitle" data-i18n="loading">Laden...</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 metric-card-grid" id="calendarStatsContent">
                            <div class="col-12 text-muted" data-i18n="loading">Laden...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                            <h4 class="mb-2 mb-md-0">
                                <i class="bi bi-graph-up me-2"></i><span data-i18n="statistics">Statistieken</span>
                                <small class="text-light" id="chartRangeLabel">Laatste 7 dagen</small>
                            </h4>
                            <div class="btn-group flex-wrap" role="group">
                                <input type="radio" class="btn-check" name="chartType" id="tempChart" autocomplete="off" checked>
                                <label class="btn btn-outline-light btn-sm" for="tempChart" title="Temperatuurverloop" data-i18n-title="chartTemp">
                                    <i class="bi bi-thermometer me-1"></i><span data-i18n="chartTemp">Temperatuur</span>
                                </label>

                                <input type="radio" class="btn-check" name="chartType" id="rainChart" autocomplete="off">
                                <label class="btn btn-outline-light btn-sm" for="rainChart" title="Neerslagverloop" data-i18n-title="chartRain">
                                    <i class="bi bi-droplet me-1"></i><span data-i18n="chartRain">Neerslag</span>
                                </label>

                                <input type="radio" class="btn-check" name="chartType" id="windChart" autocomplete="off">
                                <label class="btn btn-outline-light btn-sm" for="windChart" title="Windsnelheidverloop" data-i18n-title="chartWind">
                                    <i class="bi bi-wind me-1"></i><span data-i18n="chartWind">Wind</span>
                                </label>

                                <input type="radio" class="btn-check" name="chartType" id="sunChart" autocomplete="off">
                                <label class="btn btn-outline-light btn-sm" for="sunChart" title="Zonnesschijnverloop" data-i18n-title="chartSun">
                                    <i class="bi bi-sun me-1"></i><span data-i18n="chartSun">Zon</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="mainChart"></canvas>
                        </div>
                        <div class="chart-controls mt-3 text-center">
                            <div class="btn-group btn-group-sm chart-range-group" role="group">
                                <button type="button" class="btn btn-outline-secondary" id="chart7Days" data-i18n="days7">7 dagen</button>
                                <button type="button" class="btn btn-outline-secondary" id="chart30Days" data-i18n="days30">30 dagen</button>
                                <button type="button" class="btn btn-outline-secondary" id="chartYear" data-i18n="thisYear">Jaar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Statistics -->
        <div class="row mt-4" id="monthlyStatsRow">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <h4 class="mb-0" id="monthlyStatsTitle">
                            <i class="bi bi-calendar-month me-2"></i><span data-i18n="monthlyStats">Maandstatistieken</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 metric-card-grid" id="monthlyStatsContent">
                            <!-- Monthly statistics will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="weather-card quick-actions-card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="bi bi-lightning me-2"></i><span data-i18n="quickActions">Snelle acties</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 quick-actions-grid">
                            <div class="col-6 col-md-3 text-center">
                                <button class="btn btn-outline-primary w-100 quick-action" data-action="yesterday">
                                    <i class="bi bi-skip-backward fs-4"></i>
                                    <div class="mt-2" data-i18n="yesterday">Gisteren</div>
                                </button>
                            </div>
                            <div class="col-6 col-md-3 text-center">
                                <button class="btn btn-outline-primary w-100 quick-action" data-action="lastWeek">
                                    <i class="bi bi-calendar-week fs-4"></i>
                                    <div class="mt-2" data-i18n="previousWeek">Vorige week</div>
                                </button>
                            </div>
                            <div class="col-6 col-md-3 text-center">
                                <button class="btn btn-outline-primary w-100 quick-action" data-action="lastMonth">
                                    <i class="bi bi-calendar-month fs-4"></i>
                                    <div class="mt-2" data-i18n="lastMonth">Vorige maand</div>
                                </button>
                            </div>
                            <div class="col-6 col-md-3 text-center">
                                <button class="btn btn-outline-primary w-100 quick-action" data-action="random">
                                    <i class="bi bi-shuffle fs-4"></i>
                                    <div class="mt-2" data-i18n="randomDay">Willekeurige dag</div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="row mt-5">
            <div class="col-12">
                <div class="text-center text-muted">
                    <p class="small footer-data-range mb-2">
                        <span data-i18n="stationShort">Meetstation De Bilt</span>
                        <span class="footer-separator">•</span>
                        <span id="availableDataText" data-first-date="<?php echo h($firstDate); ?>" data-last-date="<?php echo h($lastDate); ?>">Beschikbare data: <?php echo h(date('d-m-Y', strtotime($firstDate))); ?> tot <?php echo h(date('d-m-Y', strtotime($lastDate))); ?></span>
                    </p>
                    <?php if ($documents): ?>
                    <p class="small footer-documents mb-2">
                        <span data-i18n="documents">Documenten:</span>
                        <?php foreach ($documents as $index => $document): ?>
                            <?php if ($index > 0): ?><span class="footer-separator">•</span><?php endif; ?>
                            <a href="<?php echo h($document['href']); ?>"
                               target="_blank"
                               rel="noopener"
                               class="text-decoration-none"
                               data-doc-nl="<?php echo h($document['label_nl']); ?>"
                               data-doc-en="<?php echo h($document['label_en']); ?>"><?php echo h($document['label_nl']); ?></a>
                        <?php endforeach; ?>
                    </p>
                    <?php endif; ?>
                    <p class="mb-2">
                        <strong data-i18n="appName">KNMI Daggegevens</strong> •
                        <span data-i18n="footerData">Data:</span> <a href="https://knmi.nl" target="_blank" rel="noopener" class="text-decoration-none">Koninklijk Nederlands Meteorologisch Instituut</a>
                    </p>
                    <p class="small">
                        <span id="lastUpdateText" data-update-time="<?php echo date('c'); ?>">Laatste update: <?php echo date('d-m-Y H:i'); ?></span> •
                        <span id="lastRefreshText"></span>
                        <span id="lastRefreshSeparator" style="display: none;"> • </span>
                        <a href="<?php echo h(appAssetPath('precipitation.php')); ?>" class="text-decoration-none" data-i18n="annualPrecipitation">Jaarlijkse neerslag</a> •
                        <a href="javascript:void(0)" id="aboutBtn" class="text-decoration-none" data-i18n="aboutSite">Over deze website</a> •
                        <a href="javascript:void(0)" id="helpBtn" class="text-decoration-none" data-i18n="helpShortcuts">Help & Sneltoetsen</a>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modals -->
    <!-- About Modal -->
    <div class="modal fade" id="aboutModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" data-i18n="aboutTitle">Over KNMI Daggegevens</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" data-i18n-aria-label="close"></button>
                </div>
                <div class="modal-body">
                    <p data-i18n="aboutText">Deze interface toont historische weergegevens van het KNMI (Koninklijk Nederlands Meteorologisch Instituut) voor meetstation De Bilt.</p>
                    
                    <h6 data-i18n="features">Functies:</h6>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check-circle text-success me-2"></i><span data-i18n="featureResponsive">Responsive ontwerp voor alle apparaten</span></li>
                        <li><i class="bi bi-check-circle text-success me-2"></i><span data-i18n="featureCompare">Vergelijk twee datums naast elkaar</span></li>
                        <li><i class="bi bi-check-circle text-success me-2"></i><span data-i18n="featureCharts">Interactieve grafieken en statistieken</span></li>
                        <li><i class="bi bi-check-circle text-success me-2"></i><span data-i18n="featureShortcuts">Sneltoetsen voor navigatie</span></li>
                        <li><i class="bi bi-check-circle text-success me-2"></i><span data-i18n="featureMonthly">Maandelijkse statistieken</span></li>
                    </ul>
                    
                    <p class="text-muted small mt-3" id="aboutAvailableDataText">
                        Beschikbare data: <?php echo date('d-m-Y', strtotime($firstDate)); ?> tot <?php echo date('d-m-Y', strtotime($lastDate)); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" data-i18n="helpTitle">Help & Sneltoetsen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" data-i18n-aria-label="close"></button>
                </div>
                <div class="modal-body">
                    <h6 data-i18n="shortcuts">Sneltoetsen:</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tr><td><kbd>Ctrl</kbd> + <kbd>←</kbd></td><td data-i18n="shortcutPrev">Vorige dag</td></tr>
                            <tr><td><kbd>Ctrl</kbd> + <kbd>→</kbd></td><td data-i18n="shortcutNext">Volgende dag</td></tr>
                            <tr><td><kbd>Ctrl</kbd> + <kbd>C</kbd></td><td data-i18n="shortcutCompare">Vergelijkingsmodus</td></tr>
                            <tr><td><kbd>Ctrl</kbd> + <kbd>T</kbd></td><td data-i18n="shortcutLatest">Ga naar laatste dag</td></tr>
                        </table>
                    </div>
                    
                    <h6 data-i18n="navigation">Navigatie:</h6>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-phone me-2"></i><span data-i18n="mobileSwipe">Mobiel: veeg links/rechts voor vorige/volgende dag</span></li>
                        <li><i class="bi bi-mouse me-2"></i><span data-i18n="datePickerHelp">Klik op de datumpicker voor een specifieke datum</span></li>
                    </ul>

                    <h6 data-i18n="windDirectionShortcuts">Afkortingen windrichting:</h6>
                    <div class="row">
                        <div class="col-6">
                            <small>
                                <strong>N:</strong> <span data-i18n="north">Noord</span><br>
                                <strong>NO:</strong> <span data-i18n="northeast">Noordoost</span><br>
                                <strong>O:</strong> <span data-i18n="east">Oost</span><br>
                                <strong>ZO:</strong> <span data-i18n="southeast">Zuidoost</span><br>
                            </small>
                        </div>
                        <div class="col-6">
                            <small>
                                <strong>Z:</strong> <span data-i18n="south">Zuid</span><br>
                                <strong>ZW:</strong> <span data-i18n="southwest">Zuidwest</span><br>
                                <strong>W:</strong> <span data-i18n="west">West</span><br>
                                <strong>NW:</strong> <span data-i18n="northwest">Noordwest</span><br>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Configuration -->
    <script>
        const API_BASE_URL = 'api/weather.php';
        const FIRST_DATE = '<?php echo h($firstDate); ?>';
        const LAST_DATE = '<?php echo h($lastDate); ?>';
        const DEFAULT_DATE = '<?php echo h($defaultDate); ?>';
        const APP_BASE_PATH = '<?php echo h(appBasePath()); ?>';
        const SITE_BASE_URL = '<?php echo h(siteBaseUrl()); ?>';
        const INITIAL_PAGE_IS_DATE_PAGE = <?php echo $isHomeRequest ? 'false' : 'true'; ?>;
        const INITIAL_WEATHER_DATA = <?php echo $initialWeatherJson; ?>;
    </script>

    <!-- External Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script src="js/app-i18n.js?v=<?php echo filemtime(__DIR__ . '/js/app-i18n.js'); ?>"></script>
    <script src="js/weather-api.js?v=<?php echo filemtime(__DIR__ . '/js/weather-api.js'); ?>"></script>
    <script src="js/weather-app.js?v=<?php echo filemtime(__DIR__ . '/js/weather-app.js'); ?>"></script>
    <script src="js/chart-manager.js?v=<?php echo filemtime(__DIR__ . '/js/chart-manager.js'); ?>"></script>
    
    <!-- PWA Service Worker -->
    <script>
        // Service Worker Registration
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('sw.js')
                    .then(function(registration) {
                        console.log('ServiceWorker registration successful');
                        
                        // Check for updates
                        registration.addEventListener('updatefound', () => {
                            const newWorker = registration.installing;
                            newWorker.addEventListener('statechange', () => {
                                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                    // New version available
                                    showUpdateAvailable();
                                }
                            });
                        });
                    })
                    .catch(function(error) {
                        console.log('ServiceWorker registration failed: ', error);
                    });
            });
        }

        // PWA Install Prompt
        let deferredPrompt;
        
        window.addEventListener('beforeinstallprompt', (e) => {
            // Prevent the mini-infobar from appearing on mobile
            e.preventDefault();
            // Stash the event so it can be triggered later
            deferredPrompt = e;
            // Show install button
            showInstallPrompt();
        });

        function showInstallPrompt() {
            const language = localStorage.getItem('knmi-language') || 'nl';
            const t = window.weatherApp
                ? window.weatherApp.t.bind(window.weatherApp)
                : (key) => window.AppI18n?.[language]?.[key] || window.AppI18n?.nl?.[key] || key;
            const installPrompt = document.createElement('div');
            installPrompt.className = 'install-prompt';
            installPrompt.innerHTML = `
                <span><i class="bi bi-phone me-2"></i>${t('installApp')}</span>
                <button onclick="installPWA()">${t('install')}</button>
                <button onclick="dismissInstall()" style="margin-left: 10px; background: transparent; color: white; border: 1px solid white;">${t('later')}</button>
            `;
            document.body.appendChild(installPrompt);
            installPrompt.style.display = 'block';
        }

        function installPWA() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('User accepted the install prompt');
                    }
                    deferredPrompt = null;
                    dismissInstall();
                });
            }
        }

        function dismissInstall() {
            const prompt = document.querySelector('.install-prompt');
            if (prompt) {
                prompt.remove();
            }
        }

        function showUpdateAvailable() {
            if (window.weatherApp) {
                window.weatherApp.showMessage(window.weatherApp.t('newVersion'), 'info');
            }
        }

        // Handle online/offline status
        window.addEventListener('online', function() {
            if (window.weatherApp) {
                window.weatherApp.showMessage(window.weatherApp.t('connectionRestored'), 'success');
            }
        });

        window.addEventListener('offline', function() {
            if (window.weatherApp) {
                window.weatherApp.showMessage(window.weatherApp.t('offlineMode'), 'warning');
            }
        });
    </script>
</body>
</html>
