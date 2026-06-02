<?php
require_once __DIR__ . '/../lib/KnmiStationCatalog.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$supportedStations = KnmiStationCatalog::all();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>KNMI Daily Data API documentation</title>
    <meta name="description" content="Documentation for the KNMI Daily Data JSON API endpoints, parameters, response envelopes, and rate limits.">
    <meta name="robots" content="index, follow">
    <link rel="icon" type="image/png" sizes="32x32" href="../icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../icons/favicon-16x16.png">
    <link rel="shortcut icon" href="../icons/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="../icons/apple-touch-icon.png">
    <meta name="theme-color" content="#0a66c2">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --page-bg: #f5f7fb;
            --surface: #ffffff;
            --surface-soft: #eef4fb;
            --text: #17202c;
            --muted: #5e6b7a;
            --line: #d8e1ec;
            --primary: #0a66c2;
            --primary-dark: #074f97;
            --code-bg: #111827;
            --code-text: #e5edf7;
        }

        html {
            background: var(--page-bg);
        }

        body {
            min-height: 100vh;
            margin: 0;
            color: var(--text);
            background:
                linear-gradient(180deg, rgba(10, 102, 194, 0.08), transparent 320px),
                var(--page-bg);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }

        a {
            color: var(--primary);
        }

        a:hover {
            color: var(--primary-dark);
        }

        .api-shell {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 56px;
        }

        .api-header {
            display: grid;
            gap: 16px;
            padding: 24px 0 20px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-dark);
            font-size: 0.92rem;
            font-weight: 700;
        }

        h1 {
            max-width: 760px;
            margin: 0;
            font-size: clamp(2rem, 6vw, 3rem);
            line-height: 1.08;
            font-weight: 800;
        }

        .intro {
            max-width: 760px;
            margin: 0;
            color: var(--muted);
            font-size: 1.05rem;
            line-height: 1.6;
        }

        .top-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 4px;
        }

        .btn-api {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 6px;
            font-weight: 700;
        }

        .doc-layout {
            display: grid;
            grid-template-columns: minmax(0, 240px) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .doc-nav {
            position: sticky;
            top: 16px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.86);
            backdrop-filter: blur(10px);
        }

        .doc-nav a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 6px;
            color: var(--text);
            text-decoration: none;
            font-weight: 650;
        }

        .doc-nav a:hover {
            background: var(--surface-soft);
            color: var(--primary-dark);
        }

        .doc-content {
            display: grid;
            gap: 18px;
        }

        .doc-section {
            padding: 22px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--surface);
            box-shadow: 0 10px 30px rgba(23, 32, 44, 0.06);
        }

        .doc-section h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 14px;
            font-size: 1.35rem;
            font-weight: 800;
        }

        .doc-section h3 {
            margin: 20px 0 10px;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .doc-section p {
            color: var(--muted);
            line-height: 1.6;
        }

        .endpoint-list {
            display: grid;
            gap: 14px;
        }

        .endpoint {
            display: grid;
            gap: 10px;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fbfdff;
        }

        .endpoint-title {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .method {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 9px;
            border-radius: 5px;
            background: #dff3ea;
            color: #12633e;
            font-size: 0.78rem;
            font-weight: 800;
        }

        code,
        pre {
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
        }

        code {
            color: #0b4f8a;
        }

        pre {
            overflow-x: auto;
            margin: 0;
            padding: 14px;
            border-radius: 8px;
            color: var(--code-text);
            background: var(--code-bg);
            line-height: 1.55;
        }

        pre code {
            color: inherit;
        }

        .table {
            margin-bottom: 0;
            vertical-align: middle;
        }

        .table th {
            color: #344154;
            white-space: nowrap;
        }

        .note {
            display: flex;
            gap: 10px;
            padding: 12px 14px;
            border: 1px solid #c7dcf5;
            border-radius: 8px;
            color: #164a7a;
            background: #eef6ff;
        }

        .footer-link {
            margin-top: 24px;
            color: var(--muted);
            font-size: 0.95rem;
        }

        @media (max-width: 860px) {
            .api-shell {
                width: min(100% - 24px, 1180px);
                padding-top: 18px;
            }

            .doc-layout {
                grid-template-columns: 1fr;
            }

            .doc-nav {
                position: static;
            }

            .doc-nav div {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
            }

            .doc-nav a {
                border: 1px solid var(--line);
                background: #fff;
            }

            .doc-section {
                padding: 18px;
            }
        }
    </style>
</head>
<body>
    <main class="api-shell">
        <header class="api-header">
            <div class="eyebrow"><i class="bi bi-braces"></i> JSON API</div>
            <h1>KNMI Daily Data API documentation</h1>
            <p class="intro">
                Public endpoints for historical KNMI daily weather data. All responses are JSON and use the same response envelope.
            </p>
            <div class="top-actions">
                <a class="btn btn-primary btn-api" href="weather/range"><i class="bi bi-play-circle"></i> Try range endpoint</a>
            </div>
        </header>

        <div class="doc-layout">
            <nav class="doc-nav" aria-label="API documentation">
                <div>
                    <a href="#basics"><i class="bi bi-info-circle"></i> Basics</a>
                    <a href="#endpoints"><i class="bi bi-list-check"></i> Endpoints</a>
                    <a href="#responses"><i class="bi bi-filetype-json"></i> Responses</a>
                    <a href="#rate-limit"><i class="bi bi-speedometer2"></i> Rate limit</a>
                    <a href="#fields"><i class="bi bi-table"></i> Fields</a>
                </div>
            </nav>

            <div class="doc-content">
                <section class="doc-section" id="basics">
                    <h2><i class="bi bi-info-circle"></i> Basics</h2>
                    <p>Base URL:</p>
                    <pre><code>/api/weather</code></pre>
                    <h3>Common query parameters</h3>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Parameter</th>
                                    <th>Type</th>
                                    <th>Default</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>station</code></td>
                                    <td>integer</td>
                                    <td><code>260</code></td>
                                    <td>Supported KNMI station number. Use <code>/api/weather/stations</code> to list them. The default is De Bilt.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="note mt-3">
                        <i class="bi bi-calendar3"></i>
                        <span>Dates must use the <code>YYYY-MM-DD</code> format.</span>
                    </div>
                    <h3 class="mt-4">Supported stations</h3>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($supportedStations as $station): ?>
                                    <tr>
                                        <td><code><?php echo (int)$station['id']; ?></code><?php echo $station['is_default'] ? ' <span class="badge text-bg-primary">default</span>' : ''; ?></td>
                                        <td><?php echo h($station['name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="doc-section" id="endpoints">
                    <h2><i class="bi bi-list-check"></i> Endpoints</h2>
                    <div class="endpoint-list">
                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>List supported stations</strong>
                            </div>
                            <pre><code>/api/weather/stations</code></pre>
                            <p class="mb-0">Returns the supported KNMI stations and the default station.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Get one day</strong>
                            </div>
                            <pre><code>/api/weather/day?date=2024-01-15
/api/weather/day?date=2024-01-15&amp;station=260</code></pre>
                            <p class="mb-0">Returns a full daily record with temperature, wind, precipitation, sunshine, pressure, visibility, humidity, cloud cover, and evaporation.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Get a period</strong>
                            </div>
                            <pre><code>/api/weather/period?start=2024-01-01&amp;end=2024-01-07
/api/weather/period?start=2024-01-01&amp;end=2024-01-07&amp;station=260</code></pre>
                            <p class="mb-0">Returns chart-friendly daily records for a date range.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Get monthly statistics</strong>
                            </div>
                            <pre><code>/api/weather/stats?year=2024&amp;month=1
/api/weather/stats?year=2024&amp;month=1&amp;station=260</code></pre>
                            <p class="mb-0">Returns monthly summaries such as temperature, precipitation, sunshine, wind, and pressure.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Get available date range</strong>
                            </div>
                            <pre><code>/api/weather/range
/api/weather/range?station=260</code></pre>
                            <p class="mb-0">Returns the first and last available date.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Get calendar-day climate stats</strong>
                            </div>
                            <pre><code>/api/weather/calendar-day?date=2024-06-02
/api/weather/climate-day?date=2024-06-02</code></pre>
                            <p class="mb-0">Returns a historical comparison for the same calendar day. <code>climate-day</code> is an alias.</p>
                        </article>
                    </div>
                </section>

                <section class="doc-section" id="responses">
                    <h2><i class="bi bi-filetype-json"></i> Responses</h2>
                    <h3>Success</h3>
                    <pre><code>{
  "success": true,
  "data": {},
  "timestamp": "2026-06-02T10:00:00+02:00"
}</code></pre>
                    <h3>Error</h3>
                    <pre><code>{
  "success": false,
  "error": {
    "code": 400,
    "message": "Date parameter required"
  },
  "timestamp": "2026-06-02T10:00:00+02:00"
}</code></pre>
                    <h3>Common error codes</h3>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Meaning</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><code>400</code></td><td>A required parameter is missing, the date format is invalid, or the station is unsupported.</td></tr>
                                <tr><td><code>404</code></td><td>The endpoint or data for the requested date was not found.</td></tr>
                                <tr><td><code>405</code></td><td>The HTTP method is not supported.</td></tr>
                                <tr><td><code>429</code></td><td>The rate limit was exceeded.</td></tr>
                                <tr><td><code>500</code></td><td>Internal error or failed database connection.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="doc-section" id="rate-limit">
                    <h2><i class="bi bi-speedometer2"></i> Rate limit</h2>
                    <p>The API is rate limited per client before a database connection is opened.</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tbody>
                                <tr><th>Default</th><td><code>120</code> requests per <code>60</code> seconds</td></tr>
                                <tr><th>Configurable through</th><td><code>KNMI_API_RATE_LIMIT_REQUESTS</code> and <code>KNMI_API_RATE_LIMIT_WINDOW_SECONDS</code></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <h3>Headers</h3>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Header</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><code>X-RateLimit-Limit</code></td><td>Maximum requests in the current window.</td></tr>
                                <tr><td><code>X-RateLimit-Remaining</code></td><td>Requests left in the current window.</td></tr>
                                <tr><td><code>X-RateLimit-Reset</code></td><td>Unix timestamp when the window resets.</td></tr>
                                <tr><td><code>Retry-After</code></td><td>Seconds to wait; only sent with <code>429</code>.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="doc-section" id="fields">
                    <h2><i class="bi bi-table"></i> Main fields</h2>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><code>temperature</code></td><td>Average, minimum, maximum, and related hour values in °C.</td></tr>
                                <tr><td><code>wind</code></td><td>Wind direction, average speed, maxima, gusts, and Beaufort. Speeds are km/h.</td></tr>
                                <tr><td><code>precipitation</code></td><td>Amount and duration. Amounts are mm, duration is hours.</td></tr>
                                <tr><td><code>sunshine</code></td><td>Sunshine duration, percentage, and global radiation. Global radiation is J/cm².</td></tr>
                                <tr><td><code>pressure</code></td><td>Average, minimum, and maximum pressure in hPa.</td></tr>
                                <tr><td><code>humidity</code></td><td>Average, minimum, and maximum relative humidity.</td></tr>
                                <tr><td><code>visibility</code></td><td>Minimum and maximum visibility values.</td></tr>
                                <tr><td><code>evaporation</code></td><td>Evaporation in mm.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="footer-link mb-0">The underlying file remains available through <a href="weather.php">api/weather.php</a>.</p>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
