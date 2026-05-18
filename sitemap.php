<?php
require_once __DIR__ . '/config/Database.php';

header('Content-Type: application/xml; charset=utf-8');

function sitemapBaseUrl(): string {
    $scheme = 'http';
    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ) {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'knmi.turmin.com';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

    return $scheme . '://' . $host . $scriptDir;
}

function xmlEscape($value): string {
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function writeXmlHeader(): void {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
}

function writeSitemapEntry(string $loc, ?string $lastmod = null): void {
    echo "  <sitemap>\n";
    echo '    <loc>' . xmlEscape($loc) . "</loc>\n";
    if ($lastmod) {
        echo '    <lastmod>' . xmlEscape($lastmod) . "</lastmod>\n";
    }
    echo "  </sitemap>\n";
}

function writeUrlEntry(string $loc, ?string $lastmod = null, string $changefreq = 'never', string $priority = '0.5'): void {
    echo "  <url>\n";
    echo '    <loc>' . xmlEscape($loc) . "</loc>\n";
    if ($lastmod) {
        echo '    <lastmod>' . xmlEscape($lastmod) . "</lastmod>\n";
    }
    echo '    <changefreq>' . xmlEscape($changefreq) . "</changefreq>\n";
    echo '    <priority>' . xmlEscape($priority) . "</priority>\n";
    echo "  </url>\n";
}

function connectSitemapDatabase(): PDO {
    $credentialsFile = dirname(__DIR__) . '/knmi.sitemap.credentials.php';
    $database = new Database(is_file($credentialsFile) ? $credentialsFile : null);
    return $database->connect();
}

function getSitemapYears(PDO $db): array {
    $stmt = $db->query("
        SELECT
            YEAR(yyyymmdd) AS year,
            MAX(yyyymmdd) AS lastmod
        FROM knmi
        WHERE stn = 260
        GROUP BY YEAR(yyyymmdd)
        ORDER BY year DESC
    ");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function getLatestDate(PDO $db): ?string {
    $latestDate = $db->query('SELECT MAX(yyyymmdd) FROM knmi WHERE stn = 260')->fetchColumn();
    return $latestDate ?: null;
}

function writeSitemapIndex(PDO $db, string $baseUrl): void {
    $years = getSitemapYears($db);
    $latestDate = $years[0]['lastmod'] ?? getLatestDate($db);

    writeXmlHeader();
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    writeSitemapEntry($baseUrl . '/sitemap.php?section=pages', $latestDate ?: null);

    foreach ($years as $year) {
        writeSitemapEntry($baseUrl . '/sitemap.php?year=' . rawurlencode((string)$year['year']), $year['lastmod'] ?? null);
    }

    echo "</sitemapindex>\n";
}

function writePagesSitemap(PDO $db, string $baseUrl): void {
    $latestDate = getLatestDate($db);

    writeXmlHeader();
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    writeUrlEntry($baseUrl . '/precipitation.php', $latestDate, 'monthly', '0.7');

    foreach (glob(__DIR__ . '/doc/*.pdf') ?: [] as $document) {
        $modified = filemtime($document);
        writeUrlEntry(
            $baseUrl . '/doc/' . rawurlencode(basename($document)),
            $modified ? date('Y-m-d', $modified) : null,
            'yearly',
            '0.3'
        );
    }

    echo "</urlset>\n";
}

function writeYearSitemap(PDO $db, string $baseUrl, int $year): void {
    $startDate = sprintf('%04d-01-01', $year);
    $endDate = sprintf('%04d-12-31', $year);
    $stmt = $db->prepare('
        SELECT yyyymmdd AS date
        FROM knmi
        WHERE stn = 260
            AND yyyymmdd BETWEEN :start_date AND :end_date
        ORDER BY yyyymmdd DESC
    ');
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);

    writeXmlHeader();
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    while ($date = $stmt->fetchColumn()) {
        writeUrlEntry($baseUrl . '/' . $date, $date, 'never', '0.5');
    }

    echo "</urlset>\n";
}

try {
    $db = connectSitemapDatabase();
    $baseUrl = sitemapBaseUrl();

    if (($_GET['section'] ?? '') === 'pages') {
        writePagesSitemap($db, $baseUrl);
        exit;
    }

    if (isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year'])) {
        writeYearSitemap($db, $baseUrl, (int)$_GET['year']);
        exit;
    }

    writeSitemapIndex($db, $baseUrl);
} catch (Throwable $e) {
    error_log('Sitemap error: ' . $e->getMessage());
    http_response_code(500);
    writeXmlHeader();
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>';
}
