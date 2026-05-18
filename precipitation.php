<?php
require_once __DIR__ . '/config/Database.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

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
    $error = 'Precipitation data could not be loaded.';
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yearly precipitation - KNMI Daily Data</title>
    <meta name="description" content="Interactive yearly precipitation chart for KNMI daily data from De Bilt.">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('knmi-theme');
            const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = savedTheme || preferredTheme;
        })();
    </script>
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
                                    <span>Yearly precipitation</span>
                                </h1>
                                <p class="mb-0 mt-2">Annual precipitation totals for the De Bilt weather station</p>
                                <?php if ($firstYear && $lastYear): ?>
                                <small class="text-light">Available years: <?php echo h($firstYear); ?> to <?php echo h($lastYear); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="preference-controls" aria-label="Display settings">
                                <div class="btn-group btn-group-sm" role="group" aria-label="Theme">
                                    <button type="button" class="btn btn-outline-light" id="themeToggle" title="Dark" aria-label="Dark">
                                        <i class="bi bi-moon-stars"></i>
                                    </button>
                                </div>
                                <a class="btn btn-light btn-sm" href="./" title="Back to daily data" aria-label="Back to daily data">
                                    <i class="bi bi-house"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?php echo h($error); ?></div>
        <?php elseif (!$rows): ?>
        <div class="alert alert-warning" role="alert">No precipitation data is available.</div>
        <?php else: ?>
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="weather-card h-100">
                    <div class="card-body">
                        <div class="weather-metric monthly-stat">
                            <i class="bi bi-calendar-range text-primary fs-3"></i>
                            <div>
                                <div class="metric-value"><?php echo h($firstYear . '-' . $lastYear); ?></div>
                                <small>Period</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="weather-card h-100">
                    <div class="card-body">
                        <div class="weather-metric monthly-stat">
                            <i class="bi bi-droplet text-info fs-3"></i>
                            <div>
                                <div class="metric-value"><?php echo h(number_format($average, 1, '.', '')); ?> mm</div>
                                <small>Average per year</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="weather-card h-100">
                    <div class="card-body">
                        <div class="weather-metric monthly-stat">
                            <i class="bi bi-cloud-arrow-down text-success fs-3"></i>
                            <div>
                                <div class="metric-value"><?php echo h($wettest['year']); ?></div>
                                <small>Wettest year: <?php echo h(number_format((float)$wettest['precipitation_mm'], 1, '.', '')); ?> mm</small>
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
                                <i class="bi bi-graph-up me-2"></i>Precipitation by year
                            </h4>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Chart type">
                                <button type="button" class="btn btn-outline-light active" data-chart-type="bar" title="Bar chart">
                                    <i class="bi bi-bar-chart"></i>
                                </button>
                                <button type="button" class="btn btn-outline-light" data-chart-type="line" title="Line chart">
                                    <i class="bi bi-activity"></i>
                                </button>
                                <button type="button" class="btn btn-outline-light" id="downloadChart" title="Download chart">
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
                        <h4 class="mb-0"><i class="bi bi-table me-2"></i>Yearly totals</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Year</th>
                                        <th>Precipitation</th>
                                        <th>Days with precipitation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_reverse($rows) as $row): ?>
                                    <tr>
                                        <td><?php echo h($row['year']); ?></td>
                                        <td><?php echo h(number_format((float)$row['precipitation_mm'], 1, '.', '')); ?> mm</td>
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
                        <strong>KNMI Daily Data</strong> -
                        <a href="./" class="text-decoration-none">Daily weather data</a>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <?php if ($rows): ?>
    <script>
        const yearlyPrecipitation = <?php echo json_encode($rows, JSON_NUMERIC_CHECK); ?>;
        const downloadFilename = <?php echo json_encode($downloadFilename); ?>;
        let precipitationChart;

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
                label: 'Precipitation (mm)',
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

        function setTheme(theme) {
            localStorage.setItem('knmi-theme', theme);
            document.documentElement.dataset.theme = theme;

            const toggle = document.getElementById('themeToggle');
            const icon = toggle ? toggle.querySelector('i') : null;
            if (toggle) {
                const nextLabel = theme === 'dark' ? 'Light' : 'Dark';
                toggle.title = nextLabel;
                toggle.setAttribute('aria-label', nextLabel);
            }
            if (icon) {
                icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
            }

            applyChartTheme();
        }

        document.addEventListener('DOMContentLoaded', () => {
            const ctx = document.getElementById('precipitationChart');
            const colors = chartColors();

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
                            text: 'Annual precipitation totals',
                            color: colors.text,
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: context => `${context.dataset.label}: ${context.parsed.y} mm`
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
                                text: 'Precipitation (mm)',
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

            const themeToggle = document.getElementById('themeToggle');
            if (themeToggle) {
                themeToggle.addEventListener('click', () => {
                    setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
                });
            }

            setTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');
        });
    </script>
    <?php endif; ?>
</body>
</html>
