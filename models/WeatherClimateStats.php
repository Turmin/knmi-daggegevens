<?php
// models/WeatherClimateStats.php
class WeatherClimateStats {
    private $conn;
    private $table = 'knmi';

    public function __construct($database) {
        $this->conn = $database;
    }

    public function getCalendarDayStats($date, $station = 260) {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new Exception("Invalid date");
        }

        $month = (int)date('n', $timestamp);
        $day = (int)date('j', $timestamp);

        $query = "
            SELECT
                yyyymmdd AS date,
                YEAR(yyyymmdd) AS year,
                tx AS temp_max,
                rh AS rain_amount,
                sq AS sun_duration
            FROM {$this->table}
            WHERE stn = :station
                AND MONTH(yyyymmdd) = :month
                AND DAY(yyyymmdd) = :day
            ORDER BY yyyymmdd ASC
        ";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':station', $station, PDO::PARAM_INT);
            $stmt->bindParam(':month', $month, PDO::PARAM_INT);
            $stmt->bindParam(':day', $day, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();

            return $this->formatCalendarDayStats($date, $month, $day, $rows);
        } catch(PDOException $e) {
            error_log("Query error: " . $e->getMessage());
            throw new Exception("Error retrieving calendar day statistics");
        }
    }

    private function formatCalendarDayStats($date, $month, $day, $rows) {
        $temperatureRows = [];
        $rainRows = [];
        $sunRows = [];
        $selectedTemperature = null;
        $selectedRain = null;
        $selectedSun = null;
        $dryDays = 0;
        $rainDays = 0;

        foreach ($rows as $row) {
            if ($row['temp_max'] !== null) {
                $item = [
                    'date' => $row['date'],
                    'year' => (int)$row['year'],
                    'raw' => (float)$row['temp_max'],
                    'value' => $this->convertTemperature($row['temp_max'])
                ];
                $temperatureRows[] = $item;
                if ($row['date'] === $date) {
                    $selectedTemperature = $item;
                }
            }

            if ($row['rain_amount'] !== null) {
                $rainRaw = (float)$row['rain_amount'];
                $item = [
                    'date' => $row['date'],
                    'year' => (int)$row['year'],
                    'raw' => $rainRaw,
                    'value' => $this->convertPrecipitation($row['rain_amount'])
                ];
                $rainRows[] = $item;
                if ($rainRaw == 0.0) {
                    $dryDays++;
                } else {
                    $rainDays++;
                }
                if ($row['date'] === $date) {
                    $selectedRain = $item;
                }
            }

            if ($row['sun_duration'] !== null) {
                $item = [
                    'date' => $row['date'],
                    'year' => (int)$row['year'],
                    'raw' => (float)$row['sun_duration'],
                    'value' => $this->convertDuration($row['sun_duration'])
                ];
                $sunRows[] = $item;
                if ($row['date'] === $date) {
                    $selectedSun = $item;
                }
            }
        }

        $temperatureAverage = $this->averageValues($temperatureRows);
        $rainAverage = $this->averageValues($rainRows);
        $sunAverage = $this->averageValues($sunRows);

        return [
            'date' => $date,
            'month' => $month,
            'day' => $day,
            'sample_size' => count($rows),
            'temperature' => [
                'years' => count($temperatureRows),
                'selected' => $selectedTemperature ? $selectedTemperature['value'] : null,
                'average' => $temperatureAverage,
                'delta' => $this->difference($selectedTemperature, $temperatureAverage),
                'rank_warmest' => $this->rankDescending($temperatureRows, $selectedTemperature),
                'warmer_than_percent' => $this->percentBelow($temperatureRows, $selectedTemperature),
                'warmest' => $this->summarizeExtreme($this->extreme($temperatureRows, 'max')),
                'coldest' => $this->summarizeExtreme($this->extreme($temperatureRows, 'min'))
            ],
            'precipitation' => [
                'years' => count($rainRows),
                'selected' => $selectedRain ? $selectedRain['value'] : null,
                'average' => $rainAverage,
                'delta' => $this->difference($selectedRain, $rainAverage),
                'wettest' => $this->summarizeExtreme($this->extreme($rainRows, 'max')),
                'dry_days' => $dryDays,
                'rain_days' => $rainDays
            ],
            'sunshine' => [
                'years' => count($sunRows),
                'selected' => $selectedSun ? $selectedSun['value'] : null,
                'average' => $sunAverage,
                'delta' => $this->difference($selectedSun, $sunAverage),
                'sunniest' => $this->summarizeExtreme($this->extreme($sunRows, 'max'))
            ]
        ];
    }

    private function averageValues($items) {
        if (!$items) {
            return null;
        }

        $total = 0.0;
        foreach ($items as $item) {
            $total += $item['value'];
        }

        return round($total / count($items), 1);
    }

    private function difference($selectedItem, $average) {
        if (!$selectedItem || $average === null) {
            return null;
        }

        return round($selectedItem['value'] - $average, 1);
    }

    private function rankDescending($items, $selectedItem) {
        if (!$items || !$selectedItem) {
            return null;
        }

        usort($items, function($a, $b) {
            if ($a['raw'] == $b['raw']) {
                return strcmp($a['date'], $b['date']);
            }

            return $a['raw'] < $b['raw'] ? 1 : -1;
        });

        $rank = 1;
        $previousRaw = null;
        foreach ($items as $index => $item) {
            if ($previousRaw !== null && $item['raw'] != $previousRaw) {
                $rank = $index + 1;
            }

            if ($item['date'] === $selectedItem['date']) {
                return $rank;
            }

            $previousRaw = $item['raw'];
        }

        return null;
    }

    private function percentBelow($items, $selectedItem) {
        if (!$items || !$selectedItem || count($items) < 2) {
            return null;
        }

        $otherYears = 0;
        $below = 0;

        foreach ($items as $item) {
            if ($item['date'] === $selectedItem['date']) {
                continue;
            }

            $otherYears++;
            if ($item['raw'] < $selectedItem['raw']) {
                $below++;
            }
        }

        if ($otherYears === 0) {
            return null;
        }

        return round(($below / $otherYears) * 100);
    }

    private function extreme($items, $mode) {
        if (!$items) {
            return null;
        }

        $best = null;
        foreach ($items as $item) {
            if ($best === null) {
                $best = $item;
                continue;
            }

            if ($mode === 'max' && $item['raw'] > $best['raw']) {
                $best = $item;
            }

            if ($mode === 'min' && $item['raw'] < $best['raw']) {
                $best = $item;
            }
        }

        return $best;
    }

    private function summarizeExtreme($item) {
        if (!$item) {
            return null;
        }

        return [
            'date' => $item['date'],
            'year' => $item['year'],
            'value' => $item['value']
        ];
    }

    private function convertTemperature($value) {
        return $value !== null ? round($value * 0.1, 1) : null;
    }

    private function convertPrecipitation($value) {
        if ($value === null) return null;
        return $value < 0 ? 0.1 : round($value * 0.1, 1);
    }

    private function convertDuration($value) {
        return $value !== null ? round($value * 0.1, 1) : null;
    }
}
?>
