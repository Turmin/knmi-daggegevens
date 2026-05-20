<?php
require_once __DIR__ . '/config/Database.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
$translations = [
    'nl' => [
        'title' => 'Jaarlijkse neerslag - KNMI Daggegevens',
        'description' => 'Interactieve grafiek met jaarlijkse neerslagtotalen voor KNMI daggegevens van De Bilt.',
        'heading' => 'Jaarlijkse neerslag',
        'subtitle' => 'Jaarlijkse neerslagtotalen voor meetstation De Bilt',
        'availableYears' => 'Beschikbare jaren:',
        'to' => 't/m',
        'displaySettings' => 'Weergave-instellingen',
        'themeDark' => 'Donker',
        'themeLight' => 'Licht',
        'language' => 'Taal',
        'backHome' => 'Terug naar daggegevens',
        'loadError' => 'Neerslaggegevens konden niet worden geladen.',
        'noData' => 'Er zijn geen neerslaggegevens beschikbaar.',
        'overview' => 'Overzicht',
        'period' => 'Periode',
        'averagePerYear' => 'Gemiddeld per jaar',
        'wettestYear' => 'Natste jaar:',
        'precipitationByYear' => 'Neerslag per jaar',
        'barChart' => 'Staafdiagram',
        'lineChart' => 'Lijndiagram',
        'downloadChart' => 'Grafiek downloaden',
        'chartType' => 'Grafiektype',
        'yearlyTotals' => 'Jaarlijkse totalen',
        'year' => 'Jaar',
        'precipitation' => 'Neerslag',
        'daysWithPrecipitation' => 'Dagen met neerslag',
        'appName' => 'KNMI Daggegevens',
        'dailyData' => 'Daggegevens',
        'chartTitle' => 'Jaarlijkse neerslagtotalen',
        'chartDataset' => 'Neerslag',
        'precipitationMm' => 'Neerslag (mm)',
        'mm' => 'mm'
    ],
    'en' => [
        'title' => 'Yearly precipitation - KNMI Daily Data',
        'description' => 'Interactive yearly precipitation chart for KNMI daily data from De Bilt.',
        'heading' => 'Yearly precipitation',
        'subtitle' => 'Annual precipitation totals for the De Bilt weather station',
        'availableYears' => 'Available years:',
        'to' => 'to',
        'displaySettings' => 'Display settings',
        'themeDark' => 'Dark',
        'themeLight' => 'Light',
        'language' => 'Language',
        'backHome' => 'Back to daily data',
        'loadError' => 'Precipitation data could not be loaded.',
        'noData' => 'No precipitation data is available.',
        'overview' => 'Overview',
        'period' => 'Period',
        'averagePerYear' => 'Average per year',
        'wettestYear' => 'Wettest year:',
        'precipitationByYear' => 'Precipitation by year',
        'barChart' => 'Bar chart',
        'lineChart' => 'Line chart',
        'downloadChart' => 'Download chart',
        'chartType' => 'Chart type',
        'yearlyTotals' => 'Yearly totals',
        'year' => 'Year',
        'precipitation' => 'Precipitation',
        'daysWithPrecipitation' => 'Days with precipitation',
        'appName' => 'KNMI Daily Data',
        'dailyData' => 'Daily weather data',
        'chartTitle' => 'Annual precipitation totals',
        'chartDataset' => 'Precipitation',
        'precipitationMm' => 'Precipitation (mm)',
        'mm' => 'mm'
    ]
];
$text = $translations[$pageLanguage];

$rows = [];
$error = null;

try {
    $database = new Database();
    $db = $database->connect();
    $stmt = $db->query("
        SELECT
            YEAR(yyyymmdd) AS year,
            ROUND(SUM(CASE WHEN rh < 0 THEN 1 ELSE rh END) * 0.1, 1) AS precipitation_mm,
            SUM(CASE WHEN rh != 0 THEN 1 ELSE 0 END) AS precipitation_days
        FROM knmi
        WHERE stn = 260
        GROUP BY YEAR(yyyymmdd)
        ORDER BY year ASC
    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    error_log('Precipitation page error: ' . $e->getMessage());
    $error = true;
}

$firstYear = $rows ? (int)$rows[0]['year'] : null;
$lastYear = $rows ? (int)$rows[count($rows) - 1]['year'] : null;
$wettest = null;
$total = 0.0;

foreach ($rows as $row) {
    $amount = (float)$row['precipitation_mm'];
    $total += $amount;
    if ($wettest === null || $amount > (float)$wettest['precipitation_mm']) {
        $wettest = $row;
    }
}

$average = $rows ? round($total / count($rows), 1) : null;
$downloadFilename = $firstYear && $lastYear
    ? 'precipitation-' . $firstYear . '-' . $lastYear . '.png'
    : 'precipitation-chart.png';
$canonicalUrl = siteBaseUrl() . '/precipitation.php';
$faviconHref = appAssetPath('favicon.svg');
?>
<!DOCTYPE html>
<html lang="<?php echo h($pageLanguage); ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($text['title']); ?></title>
    <link rel="canonical" href="<?php echo h($canonicalUrl); ?>">
    <link rel="icon" type="image/svg+xml" href="<?php echo h($faviconHref); ?>">
    <link rel="shortcut icon" href="<?php echo h($faviconHref); ?>">
    <meta name="theme-color" content="#0a66c2">
    <meta name="description" content="<?php echo h($text['description']); ?>">
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
<body class="precipitation-page">
    <div class="container-fluid main-container">
        <div class="row mb-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <div class="app-header">
                            <div class="app-title">
                                <h1 class="mb-0">
                                    <i class="bi bi-cloud-rain-heavy me-2"></i>
                                    <span data-i18n="heading"><?php echo h($text['heading']); ?></span>
                                </h1>
                                <p class="mb-0 mt-2" data-i18n="subtitle"><?php echo h($text['subtitle']); ?></p>
                                <?php if ($firstYear && $lastYear): ?>
                                <small class="text-light">
                                    <span data-i18n="availableYears"><?php echo h($text['availableYears']); ?></span>
                                    <?php echo h($firstYear); ?>
                                    <span data-i18n="to"><?php echo h($text['to']); ?></span>
                                    <?php echo h($lastYear); ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="preference-controls" data-i18n-aria-label="displaySettings" aria-label="<?php echo h($text['displaySettings']); ?>">
                                <div class="btn-group btn-group-sm" role="group" aria-label="Theme">
                                    <button type="button" class="btn btn-outline-light" id="themeToggle" title="<?php echo h($text['themeDark']); ?>" aria-label="<?php echo h($text['themeDark']); ?>">
                                        <i class="bi bi-moon-stars"></i>
                                    </button>
                                </div>
                                <div class="btn-group btn-group-sm" role="group" data-i18n-aria-label="language" aria-label="<?php echo h($text['language']); ?>">
                                    <button type="button" class="btn <?php echo $pageLanguage === 'nl' ? 'btn-light active' : 'btn-outline-light'; ?>" data-language="nl" aria-pressed="<?php echo $pageLanguage === 'nl' ? 'true' : 'false'; ?>">NL</button>
                                    <button type="button" class="btn <?php echo $pageLanguage === 'en' ? 'btn-light active' : 'btn-outline-light'; ?>" data-language="en" aria-pressed="<?php echo $pageLanguage === 'en' ? 'true' : 'false'; ?>">EN</button>
                                </div>
                                <a class="btn btn-light btn-sm" href="./" data-i18n-title="backHome" data-i18n-aria-label="backHome" title="<?php echo h($text['backHome']); ?>" aria-label="<?php echo h($text['backHome']); ?>">
                                    <i class="bi bi-house"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger" role="alert" data-i18n="loadError"><?php echo h($text['loadError']); ?></div>
        <?php elseif (!$rows): ?>
        <div class="alert alert-warning" role="alert" data-i18n="noData"><?php echo h($text['noData']); ?></div>
        <?php else: ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-cloud-rain-heavy me-2"></i><span data-i18n="overview"><?php echo h($text['overview']); ?></span></h4>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 metric-card-grid">
                            <div class="col-md-4">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-calendar-range text-primary fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo h($firstYear . '-' . $lastYear); ?></div>
                                        <small data-i18n="period"><?php echo h($text['period']); ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-droplet text-info fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo h(number_format($average, 1, '.', '')); ?>&nbsp;mm</div>
                                        <small data-i18n="averagePerYear"><?php echo h($text['averagePerYear']); ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="weather-metric monthly-stat">
                                    <i class="bi bi-cloud-arrow-down text-success fs-3"></i>
                                    <div>
                                        <div class="metric-value"><?php echo h($wettest['year']); ?></div>
                                        <small>
                                            <span data-i18n="wettestYear"><?php echo h($text['wettestYear']); ?></span>
                                            <?php echo h(number_format((float)$wettest['precipitation_mm'], 1, '.', '')); ?>&nbsp;mm
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
                                <i class="bi bi-graph-up me-2"></i><span data-i18n="precipitationByYear"><?php echo h($text['precipitationByYear']); ?></span>
                            </h4>
                            <div class="btn-group btn-group-sm precipitation-chart-actions" role="group" data-i18n-aria-label="chartType" aria-label="<?php echo h($text['chartType']); ?>">
                                <button type="button" class="btn btn-outline-light active" data-chart-type="bar" data-i18n-title="barChart" data-i18n-aria-label="barChart" title="<?php echo h($text['barChart']); ?>" aria-label="<?php echo h($text['barChart']); ?>">
                                    <i class="bi bi-bar-chart"></i>
                                </button>
                                <button type="button" class="btn btn-outline-light" data-chart-type="line" data-i18n-title="lineChart" data-i18n-aria-label="lineChart" title="<?php echo h($text['lineChart']); ?>" aria-label="<?php echo h($text['lineChart']); ?>">
                                    <i class="bi bi-activity"></i>
                                </button>
                                <button type="button" class="btn btn-outline-light" id="downloadChart" data-i18n-title="downloadChart" data-i18n-aria-label="downloadChart" title="<?php echo h($text['downloadChart']); ?>" aria-label="<?php echo h($text['downloadChart']); ?>">
                                    <i class="bi bi-download"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container precipitation-chart-container">
                            <canvas id="precipitationChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-table me-2"></i><span data-i18n="yearlyTotals"><?php echo h($text['yearlyTotals']); ?></span></h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th data-i18n="year"><?php echo h($text['year']); ?></th>
                                        <th data-i18n="precipitation"><?php echo h($text['precipitation']); ?></th>
                                        <th data-i18n="daysWithPrecipitation"><?php echo h($text['daysWithPrecipitation']); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_reverse($rows) as $row): ?>
                                    <tr>
                                        <td><?php echo h($row['year']); ?></td>
                                        <td><?php echo h(number_format((float)$row['precipitation_mm'], 1, '.', '')); ?>&nbsp;mm</td>
                                        <td><?php echo h($row['precipitation_days']); ?></td>
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
                        <strong data-i18n="appName"><?php echo h($text['appName']); ?></strong> -
                        <a href="./" class="text-decoration-none" data-i18n="dailyData"><?php echo h($text['dailyData']); ?></a>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <script>
        const yearlyPrecipitation = <?php echo json_encode($rows, JSON_NUMERIC_CHECK); ?>;
        const downloadFilename = <?php echo json_encode($downloadFilename); ?>;
        const precipitationI18n = <?php echo json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        let currentLanguage = ['nl', 'en'].includes(document.documentElement.lang)
            ? document.documentElement.lang
            : 'nl';
        let precipitationChart;

        function t(key) {
            return precipitationI18n[currentLanguage]?.[key] || precipitationI18n.nl[key] || key;
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
            document.title = t('title');

            const metaDescription = document.querySelector('meta[name="description"]');
            if (metaDescription) {
                metaDescription.content = t('description');
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
            updateThemeToggle();
            updateChartLanguage();
        }

        function chartColors() {
            const isDark = document.documentElement.dataset.theme === 'dark';
            return {
                text: isDark ? '#edf4fb' : '#172033',
                grid: isDark ? 'rgba(196, 217, 236, 0.16)' : 'rgba(20, 52, 85, 0.12)',
                fill: isDark ? 'rgba(107, 182, 255, 0.32)' : 'rgba(10, 102, 194, 0.22)',
                stroke: isDark ? '#6bb6ff' : '#0a66c2'
            };
        }

        function datasetFor(type) {
            const colors = chartColors();
            return {
                type,
                label: t('chartDataset'),
                data: yearlyPrecipitation.map(row => row.precipitation_mm),
                borderColor: colors.stroke,
                backgroundColor: colors.fill,
                borderWidth: type === 'line' ? 3 : 1,
                borderRadius: type === 'bar' ? 3 : 0,
                fill: type === 'line',
                pointRadius: type === 'line' ? 3 : 0,
                pointHoverRadius: 5,
                tension: 0.25
            };
        }

        function updateChartLanguage() {
            if (!precipitationChart) return;

            precipitationChart.data.datasets[0].label = t('chartDataset');
            precipitationChart.options.plugins.title.text = t('chartTitle');
            precipitationChart.options.scales.y.title.text = t('precipitationMm');
            precipitationChart.update('none');
        }

        function applyChartTheme() {
            if (!precipitationChart) return;

            const colors = chartColors();
            precipitationChart.options.plugins.legend.labels.color = colors.text;
            precipitationChart.options.plugins.title.color = colors.text;
            precipitationChart.options.scales.x.ticks.color = colors.text;
            precipitationChart.options.scales.y.ticks.color = colors.text;
            precipitationChart.options.scales.x.grid.color = colors.grid;
            precipitationChart.options.scales.y.grid.color = colors.grid;
            precipitationChart.options.scales.y.title.color = colors.text;
            precipitationChart.data.datasets[0].borderColor = colors.stroke;
            precipitationChart.data.datasets[0].backgroundColor = colors.fill;
            precipitationChart.update('none');
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

            const ctx = document.getElementById('precipitationChart');
            const colors = chartColors();

            if (ctx && yearlyPrecipitation.length && typeof Chart !== 'undefined') {
                precipitationChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: yearlyPrecipitation.map(row => row.year),
                        datasets: [datasetFor('bar')]
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
                                text: t('chartTitle'),
                                color: colors.text,
                                font: {
                                    size: 16,
                                    weight: 'bold'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: context => `${context.dataset.label}: ${context.parsed.y} ${t('mm')}`
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
                                    text: t('precipitationMm'),
                                    color: colors.text
                                }
                            }
                        }
                    }
                });

                document.querySelectorAll('[data-chart-type]').forEach(button => {
                    button.addEventListener('click', () => {
                        document.querySelectorAll('[data-chart-type]').forEach(item => item.classList.remove('active'));
                        button.classList.add('active');
                        precipitationChart.data.datasets[0] = datasetFor(button.dataset.chartType);
                        precipitationChart.update();
                    });
                });

                const downloadButton = document.getElementById('downloadChart');
                if (downloadButton) {
                    downloadButton.addEventListener('click', () => {
                        const link = document.createElement('a');
                        link.download = downloadFilename;
                        link.href = precipitationChart.toBase64Image('image/png', 1);
                        link.click();
                    });
                }
            }

            document.querySelectorAll('[data-language]').forEach(button => {
                button.addEventListener('click', () => {
                    applyLanguage(button.dataset.language);
                });
            });

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
