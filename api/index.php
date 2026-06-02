<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>KNMI Daggegevens API documentatie</title>
    <meta name="description" content="Documentatie voor de KNMI Daggegevens JSON API endpoints, parameters, responsevormen en rate limits.">
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
            <h1>KNMI Daggegevens API documentatie</h1>
            <p class="intro">
                Publieke endpoints voor historische KNMI daggegevens van meetstation De Bilt. Alle responses zijn JSON en gebruiken dezelfde response envelope.
            </p>
            <div class="top-actions">
                <a class="btn btn-primary btn-api" href="weather.php/range"><i class="bi bi-play-circle"></i> Probeer range endpoint</a>
                <a class="btn btn-outline-primary btn-api" href="../"><i class="bi bi-house"></i> Terug naar app</a>
            </div>
        </header>

        <div class="doc-layout">
            <nav class="doc-nav" aria-label="API documentatie">
                <div>
                    <a href="#basis"><i class="bi bi-info-circle"></i> Basis</a>
                    <a href="#endpoints"><i class="bi bi-list-check"></i> Endpoints</a>
                    <a href="#responses"><i class="bi bi-filetype-json"></i> Responses</a>
                    <a href="#rate-limit"><i class="bi bi-speedometer2"></i> Rate limit</a>
                    <a href="#velden"><i class="bi bi-table"></i> Velden</a>
                </div>
            </nav>

            <div class="doc-content">
                <section class="doc-section" id="basis">
                    <h2><i class="bi bi-info-circle"></i> Basis</h2>
                    <p>Basis-URL:</p>
                    <pre><code>/api/weather.php</code></pre>
                    <h3>Algemene query parameters</h3>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Parameter</th>
                                    <th>Type</th>
                                    <th>Default</th>
                                    <th>Beschrijving</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>station</code></td>
                                    <td>integer</td>
                                    <td><code>260</code></td>
                                    <td>KNMI-stationnummer. De app gebruikt De Bilt.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="note mt-3">
                        <i class="bi bi-calendar3"></i>
                        <span>Datums moeten het formaat <code>YYYY-MM-DD</code> gebruiken.</span>
                    </div>
                </section>

                <section class="doc-section" id="endpoints">
                    <h2><i class="bi bi-list-check"></i> Endpoints</h2>
                    <div class="endpoint-list">
                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Een dag ophalen</strong>
                            </div>
                            <pre><code>weather.php/day?date=2024-01-15
weather.php/day?date=2024-01-15&amp;station=260</code></pre>
                            <p class="mb-0">Geeft een volledig dagrecord terug met temperatuur, wind, neerslag, zon, luchtdruk, zicht, luchtvochtigheid, bewolking en verdamping.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Periode ophalen</strong>
                            </div>
                            <pre><code>weather.php/period?start=2024-01-01&amp;end=2024-01-07
weather.php/period?start=2024-01-01&amp;end=2024-01-07&amp;station=260</code></pre>
                            <p class="mb-0">Geeft grafiekvriendelijke dagrecords terug voor een periode.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Maandstatistieken</strong>
                            </div>
                            <pre><code>weather.php/stats?year=2024&amp;month=1
weather.php/stats?year=2024&amp;month=1&amp;station=260</code></pre>
                            <p class="mb-0">Geeft samenvattingen voor een maand terug, zoals temperatuur, neerslag, zon, wind en luchtdruk.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Beschikbare datumbereik</strong>
                            </div>
                            <pre><code>weather.php/range
weather.php/range?station=260</code></pre>
                            <p class="mb-0">Geeft de eerste en laatste beschikbare datum terug.</p>
                        </article>

                        <article class="endpoint">
                            <div class="endpoint-title">
                                <span class="method">GET</span>
                                <strong>Kalenderdag door de jaren heen</strong>
                            </div>
                            <pre><code>weather.php/calendar-day?date=2024-06-02
weather.php/climate-day?date=2024-06-02</code></pre>
                            <p class="mb-0">Geeft historische vergelijking voor dezelfde kalenderdag. <code>climate-day</code> is een alias.</p>
                        </article>
                    </div>
                </section>

                <section class="doc-section" id="responses">
                    <h2><i class="bi bi-filetype-json"></i> Responses</h2>
                    <h3>Succes</h3>
                    <pre><code>{
  "success": true,
  "data": {},
  "timestamp": "2026-06-02T10:00:00+02:00"
}</code></pre>
                    <h3>Fout</h3>
                    <pre><code>{
  "success": false,
  "error": {
    "code": 400,
    "message": "Date parameter required"
  },
  "timestamp": "2026-06-02T10:00:00+02:00"
}</code></pre>
                    <h3>Veelvoorkomende foutcodes</h3>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Betekenis</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><code>400</code></td><td>Verplichte parameter ontbreekt of datumformaat is ongeldig.</td></tr>
                                <tr><td><code>404</code></td><td>Endpoint of data voor de gevraagde datum is niet gevonden.</td></tr>
                                <tr><td><code>405</code></td><td>HTTP-methode wordt niet ondersteund.</td></tr>
                                <tr><td><code>429</code></td><td>Rate limit overschreden.</td></tr>
                                <tr><td><code>500</code></td><td>Interne fout of databaseverbinding mislukt.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="doc-section" id="rate-limit">
                    <h2><i class="bi bi-speedometer2"></i> Rate limit</h2>
                    <p>De API wordt per client gelimiteerd voordat de databaseverbinding wordt geopend.</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tbody>
                                <tr><th>Default</th><td><code>120</code> requests per <code>60</code> seconden</td></tr>
                                <tr><th>Aanpasbaar via</th><td><code>KNMI_API_RATE_LIMIT_REQUESTS</code> en <code>KNMI_API_RATE_LIMIT_WINDOW_SECONDS</code></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <h3>Headers</h3>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Header</th>
                                    <th>Beschrijving</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><code>X-RateLimit-Limit</code></td><td>Maximum requests in het huidige venster.</td></tr>
                                <tr><td><code>X-RateLimit-Remaining</code></td><td>Requests over in het huidige venster.</td></tr>
                                <tr><td><code>X-RateLimit-Reset</code></td><td>Unix timestamp waarop het venster reset.</td></tr>
                                <tr><td><code>Retry-After</code></td><td>Aantal seconden wachten; alleen bij <code>429</code>.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="doc-section" id="velden">
                    <h2><i class="bi bi-table"></i> Belangrijkste velden</h2>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Veld</th>
                                    <th>Beschrijving</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><code>temperature</code></td><td>Gemiddelde, minimum, maximum en bijbehorende uurvakken in °C.</td></tr>
                                <tr><td><code>wind</code></td><td>Windrichting, gemiddelde snelheid, maxima, windstoten en Beaufort. Snelheden zijn km/h.</td></tr>
                                <tr><td><code>precipitation</code></td><td>Hoeveelheid en duur. Hoeveelheden zijn mm, duur is uren.</td></tr>
                                <tr><td><code>sunshine</code></td><td>Zonneschijnduur, percentage en globale straling. Globale straling is J/cm².</td></tr>
                                <tr><td><code>pressure</code></td><td>Gemiddelde, minimum en maximum luchtdruk in hPa.</td></tr>
                                <tr><td><code>humidity</code></td><td>Gemiddelde, minimum en maximum relatieve luchtvochtigheid.</td></tr>
                                <tr><td><code>visibility</code></td><td>Minimum en maximum zichtwaarden.</td></tr>
                                <tr><td><code>evaporation</code></td><td>Verdamping in mm.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="footer-link mb-0">De ruwe API blijft beschikbaar via <a href="weather.php">weather.php</a>.</p>
                </section>
            </div>
        </div>
    </main>
</body>
</html>
