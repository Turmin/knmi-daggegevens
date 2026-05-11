<?php
// index.php
require_once 'config/Database.php';
require_once 'models/WeatherData.php';

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

// Set default date (today or latest available)
$defaultDate = min(date('Y-m-d'), $lastDate);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KNMI Daggegevens</title>
    <link rel="icon" type="image/x-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='14'>☀️</text></svg>">
    <meta name="description" content="Historische KNMI daggegevens met vergelijkingsfunctie en interactieve grafieken">
    <meta name="keywords" content="knmi, weer, weerstatistieken, temperatuur, neerslag, verdamping, zonneschijnduur, straling, bedekkingsgraad, zicht, luchtvochtigheid">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>">
    <meta property="og:title" content="KNMI Daggegevens - Nederlandse Weerdata">
    <meta property="og:description" content="Bekijk historische weergegevens van het KNMI met moderne interface en vergelijkingsfunctie">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.js"></script>
    <!-- Bootstrap Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="css/modern-style.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Loading Screen -->
    <div id="loadingScreen" class="loading-screen">
        <div class="spinner-container">
            <div class="weather-spinner">
                <i class="bi bi-cloud-sun"></i>
            </div>
            <h3>KNMI Daggegevens</h3>
            <p>Weerdata wordt geladen...</p>
        </div>
    </div>

    <div class="container-fluid main-container" id="mainContent" style="display: none;">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header text-center">
                        <h1 class="mb-0">
                            <i class="bi bi-cloud-sun me-2"></i>
                            KNMI Daggegevens
                        </h1>
                        <p class="mb-0 mt-2">Historische weerdata van Nederland • Meetstation De Bilt</p>
                        <small class="text-light">Beschikbare data: <?php echo date('d-m-Y', strtotime($firstDate)); ?> tot <?php echo date('d-m-Y', strtotime($lastDate)); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Date Navigation -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="date-navigation">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-6">
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
                        <div class="col-md-6">
                            <div class="btn-group w-100" role="group">
                                <button type="button" class="btn btn-outline-primary btn-custom" id="prevDay" title="Vorige dag (Ctrl+←)">
                                    <i class="bi bi-chevron-left"></i> <span class="d-none d-sm-inline">Vorige dag</span>
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-custom" id="nextDay" title="Volgende dag (Ctrl+→)">
                                    <span class="d-none d-sm-inline">Volgende dag</span> <i class="bi bi-chevron-right"></i>
                                </button>
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
                        <input class="form-check-input" type="checkbox" id="comparisonMode" title="Vergelijk twee datums (Ctrl+C)">
                        <label class="form-check-label fw-bold" for="comparisonMode">
                            <i class="bi bi-arrow-left-right me-2"></i>Vergelijkingsmodus
                            <small class="text-muted ms-2">(Vergelijk weerdata van twee verschillende dagen)</small>
                        </label>
                    </div>
                    <div id="comparisonDatePicker" class="mt-3" style="display: none;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Vergelijk met:</label>
                                <input type="date" 
                                       class="form-control" 
                                       id="comparisonDate" 
                                       min="<?php echo $firstDate; ?>"
                                       max="<?php echo $lastDate; ?>">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="btn-group">
                                    <button class="btn btn-success btn-custom" id="quickCompare" title="Vergelijk met gisteren">
                                        <i class="bi bi-lightning me-1"></i>Gisteren
                                    </button>
                                    <button class="btn btn-info btn-custom" id="weekCompare" title="Vergelijk met vorige week">
                                        <i class="bi bi-calendar-week me-1"></i>Vorige week
                                    </button>
                                    <button class="btn btn-warning btn-custom" id="yearCompare" title="Vergelijk met vorig jaar">
                                        <i class="bi bi-calendar me-1"></i>Vorig jaar
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
                <span class="visually-hidden">Laden...</span>
            </div>
            <p class="mt-2">Weergegevens ophalen...</p>
        </div>

        <!-- Weather Data -->
        <div class="row" id="weatherData">
            <!-- Primary Day Card -->
            <div class="col-lg-6 mb-4">
                <div class="weather-card fade-in" id="primaryCard">
                    <div class="card-header">
                        <h3 class="mb-0" id="primaryDayTitle">
                            <i class="bi bi-calendar-check me-2"></i>
                            <span class="loading-placeholder">Datum wordt geladen...</span>
                        </h3>
                        <small>Meetstation: De Bilt (260)</small>
                    </div>
                    <div class="card-body p-4">
                        <!-- Temperature Section -->
                        <h5 class="text-primary mb-3">
                            <i class="bi bi-thermometer-half me-2"></i>Temperatuur
                        </h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Gemiddeld</span>
                                        <span class="metric-value" id="primaryTemp">
                                            <div class="loading-placeholder">--°C</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Minimum <small id="primaryTempMinTime" class="text-muted"></small></span>
                                        <span class="metric-value" id="primaryTempMin">
                                            <div class="loading-placeholder">--°C</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Maximum <small id="primaryTempMaxTime" class="text-muted"></small></span>
                                        <span class="metric-value" id="primaryTempMax">
                                            <div class="loading-placeholder">--°C</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Wind Section -->
                        <h5 class="text-primary mb-3">
                            <i class="bi bi-wind me-2"></i>Wind
                        </h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Richting</span>
                                        <span class="metric-value" id="primaryWindDirection">
                                            <div class="loading-placeholder">-- <div class="wind-indicator"><i class="bi bi-arrow-up wind-arrow"></i></div></div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Snelheid <small id="primaryWindScale" class="text-muted"></small></span>
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
                                        <span>Max windstoot <small id="primaryWindGustTime" class="text-muted"></small></span>
                                        <span class="metric-value" id="primaryWindGust">
                                            <div class="loading-placeholder">-- km/h</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Max windsnelheid <small id="primaryWindMaxTime" class="text-muted"></small></span>
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
                                    <i class="bi bi-droplet me-2"></i>Neerslag
                                </h6>
                                <div class="weather-metric mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Hoeveelheid</span>
                                        <span class="metric-value" id="primaryRain">
                                            <div class="loading-placeholder">-- mm</div>
                                        </span>
                                    </div>
                                </div>
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Duur</span>
                                        <span class="metric-value" id="primaryRainDuration">
                                            <div class="loading-placeholder">-- uur</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary mb-2">
                                    <i class="bi bi-brightness-high me-2"></i>Zon
                                </h6>
                                <div class="weather-metric mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Duur</span>
                                        <span class="metric-value" id="primarySun">
                                            <div class="loading-placeholder">-- uur</div>
                                        </span>
                                    </div>
                                </div>
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Percentage</span>
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
                                    <i class="bi bi-speedometer2 me-2"></i>Luchtdruk
                                </h6>
                                <div class="weather-metric mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Gemiddeld</span>
                                        <span class="metric-value" id="primaryPressure">
                                            <div class="loading-placeholder">-- hPa</div>
                                        </span>
                                    </div>
                                </div>
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Min/Max</span>
                                        <span class="metric-value" id="primaryPressureRange">
                                            <div class="loading-placeholder">--/-- hPa</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary mb-2">
                                    <i class="bi bi-moisture me-2"></i>Luchtvochtigheid
                                </h6>
                                <div class="weather-metric mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Gemiddeld</span>
                                        <span class="metric-value" id="primaryHumidity">
                                            <div class="loading-placeholder">--%</div>
                                        </span>
                                    </div>
                                </div>
                                <div class="weather-metric">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Min/Max</span>
                                        <span class="metric-value" id="primaryHumidityRange">
                                            <div class="loading-placeholder">--/--%</div>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comparison Day Card (Hidden by default) -->
            <div class="col-lg-6 mb-4" id="comparisonCard" style="display: none;">
                <div class="weather-card fade-in comparison-card">
                    <div class="card-header" style="background: linear-gradient(45deg, #ff6b6b, #ee5a24);">
                        <h3 class="mb-0" id="comparisonDayTitle">
                            <i class="bi bi-calendar-x me-2"></i>
                            <span class="loading-placeholder">Vergelijkingsdatum wordt geladen...</span>
                        </h3>
                        <small>Vergelijking • Meetstation: De Bilt (260)</small>
                    </div>
                    <div class="card-body p-4" id="comparisonContent">
                        <!-- Dynamic comparison content will be loaded here -->
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
                                <i class="bi bi-graph-up me-2"></i>Statistieken
                                <small class="text-light">Laatste 7 dagen</small>
                            </h4>
                            <div class="btn-group flex-wrap" role="group">
                                <input type="radio" class="btn-check" name="chartType" id="tempChart" autocomplete="off" checked>
                                <label class="btn btn-outline-light btn-sm" for="tempChart" title="Temperatuurverloop">
                                    <i class="bi bi-thermometer me-1"></i>Temperatuur
                                </label>

                                <input type="radio" class="btn-check" name="chartType" id="rainChart" autocomplete="off">
                                <label class="btn btn-outline-light btn-sm" for="rainChart" title="Neerslagverloop">
                                    <i class="bi bi-droplet me-1"></i>Neerslag
                                </label>

                                <input type="radio" class="btn-check" name="chartType" id="windChart" autocomplete="off">
                                <label class="btn btn-outline-light btn-sm" for="windChart" title="Windsnelheidverloop">
                                    <i class="bi bi-wind me-1"></i>Wind
                                </label>

                                <input type="radio" class="btn-check" name="chartType" id="sunChart" autocomplete="off">
                                <label class="btn btn-outline-light btn-sm" for="sunChart" title="Zonnesschijnverloop">
                                    <i class="bi bi-sun me-1"></i>Zon
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="mainChart"></canvas>
                        </div>
                        <div class="chart-controls mt-3 text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary" id="chart7Days">7 dagen</button>
                                <button type="button" class="btn btn-outline-secondary" id="chart30Days">30 dagen</button>
                                <button type="button" class="btn btn-outline-secondary" id="chartYear">Dit jaar</button>
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
                            <i class="bi bi-calendar-month me-2"></i>Maandstatistieken
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row g-3" id="monthlyStatsContent">
                            <!-- Monthly statistics will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="weather-card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="bi bi-lightning me-2"></i>Snelle Acties
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3 text-center">
                                <button class="btn btn-outline-primary w-100 quick-action" data-action="yesterday">
                                    <i class="bi bi-skip-backward fs-4"></i>
                                    <div class="mt-2">Gisteren</div>
                                </button>
                            </div>
                            <div class="col-md-3 text-center">
                                <button class="btn btn-outline-primary w-100 quick-action" data-action="lastWeek">
                                    <i class="bi bi-calendar-week fs-4"></i>
                                    <div class="mt-2">Vorige week</div>
                                </button>
                            </div>
                            <div class="col-md-3 text-center">
                                <button class="btn btn-outline-primary w-100 quick-action" data-action="lastMonth">
                                    <i class="bi bi-calendar-month fs-4"></i>
                                    <div class="mt-2">Vorige maand</div>
                                </button>
                            </div>
                            <div class="col-md-3 text-center">
                                <button class="btn btn-outline-primary w-100 quick-action" data-action="random">
                                    <i class="bi bi-shuffle fs-4"></i>
                                    <div class="mt-2">Willekeurige dag</div>
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
                    <p class="mb-2">
                        <strong>KNMI Daggegevens Interface</strong> • 
                        Data: <a href="https://knmi.nl" target="_blank" rel="noopener" class="text-decoration-none">Koninklijk Nederlands Meteorologisch Instituut</a>
                    </p>
                    <p class="small">
                        Laatste update: <?php echo date('d-m-Y H:i'); ?> • 
                        <a href="javascript:void(0)" id="aboutBtn" class="text-decoration-none">Over deze website</a> • 
                        <a href="javascript:void(0)" id="helpBtn" class="text-decoration-none">Help & Sneltoetsen</a>
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
                    <h5 class="modal-title">Over KNMI Daggegevens</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Deze moderne interface toont historische weergegevens van het KNMI (Koninklijk Nederlands Meteorologisch Instituut) voor meetstation De Bilt.</p>
                    
                    <h6>Features:</h6>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check-circle text-success me-2"></i>Responsive design voor alle apparaten</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Vergelijk twee datums naast elkaar</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Interactieve grafieken en statistieken</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Sneltoetsen voor navigatie</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Maandelijkse statistieken</li>
                    </ul>
                    
                    <p class="text-muted small mt-3">
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
                    <h5 class="modal-title">Help & Sneltoetsen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Sneltoetsen:</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tr><td><kbd>Ctrl</kbd> + <kbd>←</kbd></td><td>Vorige dag</td></tr>
                            <tr><td><kbd>Ctrl</kbd> + <kbd>→</kbd></td><td>Volgende dag</td></tr>
                            <tr><td><kbd>Ctrl</kbd> + <kbd>C</kbd></td><td>Vergelijkingsmodus</td></tr>
                            <tr><td><kbd>Ctrl</kbd> + <kbd>T</kbd></td><td>Ga naar vandaag</td></tr>
                        </table>
                    </div>
                    
                    <h6>Navigatie:</h6>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-phone me-2"></i>Mobiel: veeg links/rechts voor vorige/volgende dag</li>
                        <li><i class="bi bi-mouse me-2"></i>Klik op datumpicker voor specifieke datum</li>
                    </ul>

                    <h6>Afkortingen windrichting:</h6>
                    <div class="row">
                        <div class="col-6">
                            <small>
                                <strong>N:</strong> Noord<br>
                                <strong>NO:</strong> Noordoost<br>
                                <strong>O:</strong> Oost<br>
                                <strong>ZO:</strong> Zuidoost<br>
                            </small>
                        </div>
                        <div class="col-6">
                            <small>
                                <strong>Z:</strong> Zuid<br>
                                <strong>ZW:</strong> Zuidwest<br>
                                <strong>W:</strong> West<br>
                                <strong>NW:</strong> Noordwest<br>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Configuration -->
    <script>
        // Configuration - Pas dit aan naar jouw setup
        const API_BASE_URL = 'api/weather.php';  // Of gebruik volledige URL: 'http://yoursite.com/api/weather.php'
        const FIRST_DATE = '<?php echo $firstDate; ?>';
        const LAST_DATE = '<?php echo $lastDate; ?>';
        const DEFAULT_DATE = '<?php echo $defaultDate; ?>';
    </script>

    <!-- External Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script src="js/weather-api.js"></script>
    <script src="js/weather-app.js"></script>
    <script src="js/chart-manager.js"></script>
    
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
            const installPrompt = document.createElement('div');
            installPrompt.className = 'install-prompt';
            installPrompt.innerHTML = `
                <span>📱 Installeer KNMI Weer App</span>
                <button onclick="installPWA()">Installeren</button>
                <button onclick="dismissInstall()" style="margin-left: 10px; background: transparent; color: white; border: 1px solid white;">Later</button>
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
                window.weatherApp.showMessage('Een nieuwe versie is beschikbaar. Herlaad de pagina om bij te werken.', 'info');
            }
        }

        // Handle online/offline status
        window.addEventListener('online', function() {
            if (window.weatherApp) {
                window.weatherApp.showMessage('Internetverbinding hersteld.', 'success');
            }
        });

        window.addEventListener('offline', function() {
            if (window.weatherApp) {
                window.weatherApp.showMessage('Offline modus: sommige functies zijn beperkt.', 'warning');
            }
        });
    </script>
</body>
</html>