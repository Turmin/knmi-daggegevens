<?php

require_once __DIR__ . '/KnmiStationCatalog.php';

class YearlyStatsService {
    public const DEFAULT_YEARLY_START_DATE = '1901-01-01';
    public const DEFAULT_PRECIPITATION_START_DATE = '1906-01-01';

    private $db;
    private $cacheDir;

    public function __construct(PDO $db, ?string $cacheDir = null) {
        $this->db = $db;
        $this->cacheDir = $cacheDir ?: rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'knmi-yearly-stats';
    }

    public function getStats(
        int $station = KnmiStationCatalog::DEFAULT_STATION,
        bool $forceRefresh = false,
        string $yearlyStartDate = self::DEFAULT_YEARLY_START_DATE,
        string $precipitationStartDate = self::DEFAULT_PRECIPITATION_START_DATE
    ): array {
        $latestDate = $this->latestDataDate($station);
        $cachePath = $this->cachePath($station, $yearlyStartDate, $precipitationStartDate, $latestDate);

        if (!$forceRefresh) {
            $cached = $this->readCache($cachePath, $latestDate);
            if ($cached !== null) {
                $cached['cache_hit'] = true;
                $cached['cache_path'] = $cachePath;
                return $cached;
            }
        }

        $stats = $this->computeStats($station, $yearlyStartDate, $precipitationStartDate);
        $payload = [
            'latest_date' => $latestDate,
            'created_at' => date('c'),
            'rows' => $stats['rows'],
            'daily_records' => $stats['daily_records'],
            'cache_hit' => false,
            'cache_path' => $cachePath
        ];

        $this->writeCache($cachePath, $payload);

        return $payload;
    }

    public function warmCache(
        int $station = KnmiStationCatalog::DEFAULT_STATION,
        string $yearlyStartDate = self::DEFAULT_YEARLY_START_DATE,
        string $precipitationStartDate = self::DEFAULT_PRECIPITATION_START_DATE
    ): array {
        $stats = $this->getStats($station, true, $yearlyStartDate, $precipitationStartDate);
        $yearCount = count($stats['rows'] ?? []);
        $latestDate = $stats['latest_date'] ?: 'unknown latest date';

        return [
            'success' => true,
            'latest_date' => $stats['latest_date'],
            'cache_path' => $stats['cache_path'],
            'messages' => [
                'Yearly statistics cache rebuilt for station ' . $station . ' through ' . $latestDate . ' (' . $yearCount . ' years).'
            ]
        ];
    }

    private function latestDataDate(int $station): string {
        $stmt = $this->db->prepare('SELECT MAX(yyyymmdd) FROM knmi WHERE stn = :station');
        $stmt->bindValue(':station', (string)$station, PDO::PARAM_STR);
        $stmt->execute();

        return (string)($stmt->fetchColumn() ?: '');
    }

    private function cachePath(int $station, string $yearlyStartDate, string $precipitationStartDate, string $latestDate): string {
        $cacheKey = implode('-', [
            'v2',
            $station,
            preg_replace('/[^0-9]/', '', $yearlyStartDate),
            preg_replace('/[^0-9]/', '', $precipitationStartDate),
            preg_replace('/[^0-9]/', '', $latestDate)
        ]);

        return $this->cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    }

    private function readCache(string $path, string $latestDate): ?array {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload) || ($payload['latest_date'] ?? null) !== $latestDate) {
            return null;
        }

        if (!isset($payload['rows']) || !is_array($payload['rows'])) {
            return null;
        }

        if (isset($payload['daily_records']) && !is_array($payload['daily_records'])) {
            return null;
        }

        return $payload;
    }

    private function writeCache(string $path, array $payload): void {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        file_put_contents($path, json_encode($payload), LOCK_EX);
    }

    private function computeStats(int $station, string $yearlyStartDate, string $precipitationStartDate): array {
        $rows = $this->fetchYearRows($station, $yearlyStartDate);
        $rainMonthsByYear = $this->fetchRainMonthsByYear($station, $precipitationStartDate);

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

            $row['precipitation_mm'] = round($totalTenth * 0.1, 1);
            $row['precipitation_min_month'] = $minMonth['month'];
            $row['precipitation_min_month_mm'] = round($minMonth['precipitation_tenth'] * 0.1, 1);
            $row['precipitation_avg_month'] = null;
            $row['precipitation_avg_month_mm'] = round($averageTenth * 0.1, 1);
            $row['precipitation_max_month'] = $maxMonth['month'];
            $row['precipitation_max_month_mm'] = round($maxMonth['precipitation_tenth'] * 0.1, 1);
            $row['precipitation_days'] = $precipitationDays;
        }
        unset($row);

        return [
            'rows' => $rows,
            'daily_records' => [
                'warmest_day' => $this->fetchTemperatureRecord($station, $yearlyStartDate, 'tx', 'DESC'),
                'coldest_day' => $this->fetchTemperatureRecord($station, $yearlyStartDate, 'tn', 'ASC'),
                'wettest_day' => $this->fetchWettestDay($station, $precipitationStartDate)
            ]
        ];
    }

    private function fetchYearRows(int $station, string $yearlyStartDate): array {
        $stmt = $this->db->prepare("
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
                WHERE stn = :station
                    AND yyyymmdd >= :start_date
            ) AS daily
            GROUP BY YEAR(yyyymmdd)
            ORDER BY year ASC
        ");
        $stmt->bindValue(':station', (string)$station, PDO::PARAM_STR);
        $stmt->bindValue(':start_date', $yearlyStartDate, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchRainMonthsByYear(int $station, string $precipitationStartDate): array {
        $stmt = $this->db->prepare("
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
                WHERE stn = :station
                    AND yyyymmdd >= :precipitation_start_date
            ) AS rain_daily
            GROUP BY YEAR(yyyymmdd), MONTH(yyyymmdd)
            ORDER BY year ASC, month ASC
        ");
        $stmt->bindValue(':station', (string)$station, PDO::PARAM_STR);
        $stmt->bindValue(':precipitation_start_date', $precipitationStartDate, PDO::PARAM_STR);
        $stmt->execute();

        $rainMonthsByYear = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rainMonthsByYear[(int)$row['year']][] = [
                'month' => (int)$row['month'],
                'precipitation_tenth' => (float)$row['precipitation_tenth'],
                'precipitation_days' => (int)$row['precipitation_days']
            ];
        }

        return $rainMonthsByYear;
    }

    private function fetchTemperatureRecord(int $station, string $startDate, string $column, string $direction): ?array {
        if (!in_array($column, ['tn', 'tx'], true) || !in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Invalid temperature record query.');
        }

        $stmt = $this->db->prepare("
            SELECT yyyymmdd AS date, ROUND(value_num * 0.1, 1) AS value
            FROM (
                SELECT yyyymmdd, CAST(NULLIF(TRIM({$column}), '') AS SIGNED) AS value_num
                FROM knmi
                WHERE stn = :station
                    AND yyyymmdd >= :start_date
            ) AS daily
            WHERE value_num IS NOT NULL
            ORDER BY value_num {$direction}, yyyymmdd ASC
            LIMIT 1
        ");
        $stmt->bindValue(':station', (string)$station, PDO::PARAM_STR);
        $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function fetchWettestDay(int $station, string $precipitationStartDate): ?array {
        $stmt = $this->db->prepare("
            SELECT yyyymmdd AS date, ROUND(precipitation_tenth * 0.1, 1) AS value
            FROM (
                SELECT
                    yyyymmdd,
                    CASE
                        WHEN rh_num < 0 THEN 1
                        ELSE rh_num
                    END AS precipitation_tenth
                FROM (
                    SELECT yyyymmdd, CAST(NULLIF(TRIM(rh), '') AS SIGNED) AS rh_num
                    FROM knmi
                    WHERE stn = :station
                        AND yyyymmdd >= :precipitation_start_date
                ) AS rain_daily
            ) AS daily
            WHERE precipitation_tenth IS NOT NULL
            ORDER BY precipitation_tenth DESC, yyyymmdd ASC
            LIMIT 1
        ");
        $stmt->bindValue(':station', (string)$station, PDO::PARAM_STR);
        $stmt->bindValue(':precipitation_start_date', $precipitationStartDate, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

?>
