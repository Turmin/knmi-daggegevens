<?php
// models/WeatherData.php
class WeatherData {
    private $conn;
    private $table = 'knmi';
    
    public function __construct($database) {
        $this->conn = $database;
    }
    
    /**
     * Get weather data for a specific date
     */
    public function getDataByDate($date, $station = 260) {
        $query = "
            SELECT 
                stn,
                yyyymmdd as date,
                ddvec as wind_direction,
                fhvec as wind_speed_vector,
                fg as wind_speed_avg,
                fhx as wind_speed_max,
                fhxh as wind_speed_max_hour,
                fhn as wind_speed_min,
                fhnh as wind_speed_min_hour,
                fxx as wind_gust_max,
                fxxh as wind_gust_max_hour,
                tg as temp_avg,
                tn as temp_min,
                tnh as temp_min_hour,
                tx as temp_max,
                txh as temp_max_hour,
                t10n as temp_ground_min,
                t10nh as temp_ground_min_period,
                sq as sun_duration,
                sp as sun_percentage,
                q as radiation_global,
                dr as rain_duration,
                rh as rain_amount,
                rhx as rain_amount_max,
                rhxh as rain_amount_max_hour,
                pg as pressure_avg,
                px as pressure_max,
                pxh as pressure_max_hour,
                pn as pressure_min,
                pnh as pressure_min_hour,
                vvn as visibility_min,
                vvnh as visibility_min_hour,
                vvx as visibility_max,
                vvxh as visibility_max_hour,
                ng as cloud_cover,
                ug as humidity_avg,
                ux as humidity_max,
                uxh as humidity_max_hour,
                un as humidity_min,
                unh as humidity_min_hour,
                ev24 as evaporation,
                CASE 
                    WHEN DAYOFWEEK(yyyymmdd) = 1 THEN 'zondag'
                    WHEN DAYOFWEEK(yyyymmdd) = 2 THEN 'maandag' 
                    WHEN DAYOFWEEK(yyyymmdd) = 3 THEN 'dinsdag'
                    WHEN DAYOFWEEK(yyyymmdd) = 4 THEN 'woensdag'
                    WHEN DAYOFWEEK(yyyymmdd) = 5 THEN 'donderdag'
                    WHEN DAYOFWEEK(yyyymmdd) = 6 THEN 'vrijdag'
                    WHEN DAYOFWEEK(yyyymmdd) = 7 THEN 'zaterdag'
                END as day_name,
                CASE
                    WHEN MONTH(yyyymmdd) = 1 THEN 'januari'
                    WHEN MONTH(yyyymmdd) = 2 THEN 'februari'
                    WHEN MONTH(yyyymmdd) = 3 THEN 'maart'
                    WHEN MONTH(yyyymmdd) = 4 THEN 'april'
                    WHEN MONTH(yyyymmdd) = 5 THEN 'mei'
                    WHEN MONTH(yyyymmdd) = 6 THEN 'juni'
                    WHEN MONTH(yyyymmdd) = 7 THEN 'juli'
                    WHEN MONTH(yyyymmdd) = 8 THEN 'augustus'
                    WHEN MONTH(yyyymmdd) = 9 THEN 'september'
                    WHEN MONTH(yyyymmdd) = 10 THEN 'oktober'
                    WHEN MONTH(yyyymmdd) = 11 THEN 'november'
                    WHEN MONTH(yyyymmdd) = 12 THEN 'december'
                END as month_name,
                CONCAT(
                    CASE 
                        WHEN DAYOFWEEK(yyyymmdd) = 1 THEN 'zondag'
                        WHEN DAYOFWEEK(yyyymmdd) = 2 THEN 'maandag' 
                        WHEN DAYOFWEEK(yyyymmdd) = 3 THEN 'dinsdag'
                        WHEN DAYOFWEEK(yyyymmdd) = 4 THEN 'woensdag'
                        WHEN DAYOFWEEK(yyyymmdd) = 5 THEN 'donderdag'
                        WHEN DAYOFWEEK(yyyymmdd) = 6 THEN 'vrijdag'
                        WHEN DAYOFWEEK(yyyymmdd) = 7 THEN 'zaterdag'
                    END, ', ',
                    DAY(yyyymmdd), ' ',
                    CASE
                        WHEN MONTH(yyyymmdd) = 1 THEN 'januari'
                        WHEN MONTH(yyyymmdd) = 2 THEN 'februari'
                        WHEN MONTH(yyyymmdd) = 3 THEN 'maart'
                        WHEN MONTH(yyyymmdd) = 4 THEN 'april'
                        WHEN MONTH(yyyymmdd) = 5 THEN 'mei'
                        WHEN MONTH(yyyymmdd) = 6 THEN 'juni'
                        WHEN MONTH(yyyymmdd) = 7 THEN 'juli'
                        WHEN MONTH(yyyymmdd) = 8 THEN 'augustus'
                        WHEN MONTH(yyyymmdd) = 9 THEN 'september'
                        WHEN MONTH(yyyymmdd) = 10 THEN 'oktober'
                        WHEN MONTH(yyyymmdd) = 11 THEN 'november'
                        WHEN MONTH(yyyymmdd) = 12 THEN 'december'
                    END, ' ',
                    YEAR(yyyymmdd)
                ) as date_formatted,
                MONTH(yyyymmdd) as month,
                YEAR(yyyymmdd) as year
            FROM {$this->table}
            WHERE stn = :station AND yyyymmdd = :date
            ORDER BY yyyymmdd DESC
            LIMIT 1
        ";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':station', $station, PDO::PARAM_INT);
            $stmt->bindParam(':date', $date, PDO::PARAM_STR);
            $stmt->execute();
            
            $result = $stmt->fetch();
            
            if ($result) {
                return $this->formatWeatherData($result);
            }
            
            return null;
        } catch(PDOException $e) {
            error_log("Query error: " . $e->getMessage());
            throw new Exception("Error retrieving weather data");
        }
    }
    
    /**
     * Get weather data for multiple days (for charts)
     */
    public function getDataForPeriod($startDate, $endDate, $station = 260) {
        $query = "
            SELECT 
                yyyymmdd as date,
                tg as temp_avg,
                tn as temp_min,
                tx as temp_max,
                fg as wind_speed_avg,
                rh as rain_amount,
                dr as rain_duration,
                sq as sun_duration,
                pg as pressure_avg,
                CONCAT(DAY(yyyymmdd), ' ', 
                    CASE
                        WHEN MONTH(yyyymmdd) = 1 THEN 'jan'
                        WHEN MONTH(yyyymmdd) = 2 THEN 'feb'
                        WHEN MONTH(yyyymmdd) = 3 THEN 'mrt'
                        WHEN MONTH(yyyymmdd) = 4 THEN 'apr'
                        WHEN MONTH(yyyymmdd) = 5 THEN 'mei'
                        WHEN MONTH(yyyymmdd) = 6 THEN 'jun'
                        WHEN MONTH(yyyymmdd) = 7 THEN 'jul'
                        WHEN MONTH(yyyymmdd) = 8 THEN 'aug'
                        WHEN MONTH(yyyymmdd) = 9 THEN 'sep'
                        WHEN MONTH(yyyymmdd) = 10 THEN 'okt'
                        WHEN MONTH(yyyymmdd) = 11 THEN 'nov'
                        WHEN MONTH(yyyymmdd) = 12 THEN 'dec'
                    END
                ) as date_short
            FROM {$this->table}
            WHERE stn = :station 
                AND yyyymmdd BETWEEN :start_date AND :end_date
            ORDER BY yyyymmdd ASC
        ";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':station', $station, PDO::PARAM_INT);
            $stmt->bindParam(':start_date', $startDate, PDO::PARAM_STR);
            $stmt->bindParam(':end_date', $endDate, PDO::PARAM_STR);
            $stmt->execute();
            
            $results = $stmt->fetchAll();
            
            return array_map([$this, 'formatChartData'], $results);
        } catch(PDOException $e) {
            error_log("Query error: " . $e->getMessage());
            throw new Exception("Error retrieving period data");
        }
    }
    
    /**
     * Get monthly statistics
     */
    public function getMonthlyStats($year, $month, $station = 260) {
        $query = "
            SELECT 
                COUNT(*) as total_days,
                AVG(tg) as temp_avg_month,
                MIN(tn) as temp_min_month,
                MAX(tx) as temp_max_month,
                SUM(rh) as rain_total_month,
                SUM(sq) as sun_total_month,
                AVG(fg) as wind_avg_month,
                AVG(pg) as pressure_avg_month,
                SUM(CASE WHEN rh > 0 THEN 1 ELSE 0 END) as rain_days,
                SUM(CASE WHEN tx >= 200 THEN 1 ELSE 0 END) as summer_days,
                SUM(CASE WHEN tn < 0 THEN 1 ELSE 0 END) as frost_days
            FROM {$this->table}
            WHERE stn = :station 
                AND YEAR(yyyymmdd) = :year 
                AND MONTH(yyyymmdd) = :month
        ";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':station', $station, PDO::PARAM_INT);
            $stmt->bindParam(':year', $year, PDO::PARAM_INT);
            $stmt->bindParam(':month', $month, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            
            return $this->formatMonthlyStats($result);
        } catch(PDOException $e) {
            error_log("Query error: " . $e->getMessage());
            throw new Exception("Error retrieving monthly statistics");
        }
    }
    
    /**
     * Get date range available in database
     */
    public function getDateRange($station = 260) {
        $query = "
            SELECT 
                MIN(yyyymmdd) as first_date,
                MAX(yyyymmdd) as last_date
            FROM {$this->table}
            WHERE stn = :station
        ";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':station', $station, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch();
        } catch(PDOException $e) {
            error_log("Query error: " . $e->getMessage());
            throw new Exception("Error retrieving date range");
        }
    }
    
    /**
     * Format weather data for API response
     */
    private function formatWeatherData($data) {
        return [
            'station' => $data['stn'],
            'date' => $data['date'],
            'date_formatted' => $data['date_formatted'],
            'day_name' => $data['day_name'],
            'month_name' => $data['month_name'],
            'month' => $data['month'],
            'year' => $data['year'],
            'temperature' => [
                'avg' => $this->convertTemperature($data['temp_avg']),
                'min' => $this->convertTemperature($data['temp_min']),
                'max' => $this->convertTemperature($data['temp_max']),
                'ground_min' => $this->convertTemperature($data['temp_ground_min']),
                'min_hour' => $data['temp_min_hour'],
                'max_hour' => $data['temp_max_hour'],
                'ground_min_period' => $data['temp_ground_min_period']
            ],
            'wind' => [
                'direction' => $this->convertWindDirection($data['wind_direction']),
                'direction_degrees' => $data['wind_direction'],
                'direction_text' => $this->getWindDirectionText($data['wind_direction']),
                'speed_vector' => $this->convertWindSpeed($data['wind_speed_vector']),
                'speed_avg' => $this->convertWindSpeed($data['wind_speed_avg']),
                'speed_max' => $this->convertWindSpeed($data['wind_speed_max']),
                'speed_min' => $this->convertWindSpeed($data['wind_speed_min']),
                'gust_max' => $this->convertWindSpeed($data['wind_gust_max']),
                'beaufort' => $this->getBeaufortScale($this->convertWindSpeed($data['wind_speed_avg'])),
                'speed_max_hour' => $data['wind_speed_max_hour'],
                'speed_min_hour' => $data['wind_speed_min_hour'],
                'gust_max_hour' => $data['wind_gust_max_hour']
            ],
            'precipitation' => [
                'amount' => $this->convertPrecipitation($data['rain_amount']),
                'duration' => $this->convertDuration($data['rain_duration']),
                'amount_max' => $this->convertPrecipitation($data['rain_amount_max']),
                'amount_max_hour' => $data['rain_amount_max_hour']
            ],
            'sunshine' => [
                'duration' => $this->convertDuration($data['sun_duration']),
                'percentage' => $data['sun_percentage'],
                'radiation' => $this->convertRadiation($data['radiation_global'])
            ],
            'pressure' => [
                'avg' => $this->convertPressure($data['pressure_avg']),
                'max' => $this->convertPressure($data['pressure_max']),
                'min' => $this->convertPressure($data['pressure_min']),
                'max_hour' => $data['pressure_max_hour'],
                'min_hour' => $data['pressure_min_hour']
            ],
            'visibility' => [
                'min' => $this->convertVisibility($data['visibility_min']),
                'max' => $this->convertVisibility($data['visibility_max']),
                'min_hour' => $data['visibility_min_hour'],
                'max_hour' => $data['visibility_max_hour']
            ],
            'humidity' => [
                'avg' => $data['humidity_avg'],
                'max' => $data['humidity_max'],
                'min' => $data['humidity_min'],
                'max_hour' => $data['humidity_max_hour'],
                'min_hour' => $data['humidity_min_hour']
            ],
            'cloud_cover' => $data['cloud_cover'],
            'evaporation' => $this->convertEvaporation($data['evaporation'])
        ];
    }
    
    /**
     * Format chart data
     */
    private function formatChartData($data) {
        return [
            'date' => $data['date'],
            'date_short' => $data['date_short'],
            'temp_avg' => $this->convertTemperature($data['temp_avg']),
            'temp_min' => $this->convertTemperature($data['temp_min']),
            'temp_max' => $this->convertTemperature($data['temp_max']),
            'wind_speed' => $this->convertWindSpeed($data['wind_speed_avg']),
            'rain_amount' => $this->convertPrecipitation($data['rain_amount']),
            'rain_duration' => $this->convertDuration($data['rain_duration']),
            'sun_duration' => $this->convertDuration($data['sun_duration']),
            'pressure' => $this->convertPressure($data['pressure_avg'])
        ];
    }
    
    /**
     * Format monthly statistics
     */
    private function formatMonthlyStats($data) {
        return [
            'total_days' => $data['total_days'],
            'temperature' => [
                'avg' => $this->convertTemperature($data['temp_avg_month']),
                'min' => $this->convertTemperature($data['temp_min_month']),
                'max' => $this->convertTemperature($data['temp_max_month'])
            ],
            'precipitation' => [
                'total' => $this->convertPrecipitation($data['rain_total_month']),
                'days' => $data['rain_days']
            ],
            'sunshine' => [
                'total' => $this->convertDuration($data['sun_total_month'])
            ],
            'wind' => [
                'avg' => $this->convertWindSpeed($data['wind_avg_month'])
            ],
            'pressure' => [
                'avg' => $this->convertPressure($data['pressure_avg_month'])
            ],
            'special_days' => [
                'summer_days' => $data['summer_days'], // >20°C
                'frost_days' => $data['frost_days']    // <0°C
            ]
        ];
    }
    
    // Conversion methods
    private function convertTemperature($value) {
        return $value !== null ? round($value * 0.1, 1) : null;
    }
    
    private function convertWindSpeed($value) {
        return $value !== null ? round($value * 0.1 * 3.6, 1) : null; // Convert to km/h
    }
    
    private function convertPrecipitation($value) {
        if ($value === null) return null;
        return $value < 0 ? 0.1 : round($value * 0.1, 1);
    }
    
    private function convertDuration($value) {
        return $value !== null ? round($value * 0.1, 1) : null;
    }
    
    private function convertPressure($value) {
        return $value !== null ? round($value * 0.1, 1) : null;
    }
    
    private function convertVisibility($value) {
        return $value !== null ? round($value * 0.1, 1) : null;
    }
    
    private function convertRadiation($value) {
        return $value !== null ? round($value / 1000, 1) : null; // Convert to kJ/cm²
    }
    
    private function convertEvaporation($value) {
        return $value !== null ? round($value * 0.1, 1) : null;
    }
    
    private function convertWindDirection($degrees) {
        return $degrees;
    }
    
    private function getWindDirectionText($degrees) {
        if ($degrees == 0) return 'Windstil';
        if ($degrees == 990) return 'Veranderlijk';
        
        $directions = [
            'N', 'NNO', 'NO', 'ONO', 'O', 'OZO', 'ZO', 'ZZO',
            'Z', 'ZZW', 'ZW', 'WZW', 'W', 'WNW', 'NW', 'NNW'
        ];
        
        $index = round($degrees / 22.5) % 16;
        return $directions[$index];
    }
    
    private function getBeaufortScale($windSpeed) {
        if ($windSpeed === null) return null;
        
        $kmhToMS = $windSpeed / 3.6; // Convert km/h to m/s
        
        if ($kmhToMS <= 0.2) return ['scale' => 0, 'description' => 'Windstil'];
        if ($kmhToMS <= 1.5) return ['scale' => 1, 'description' => 'Zwakke wind'];
        if ($kmhToMS <= 3.3) return ['scale' => 2, 'description' => 'Zwakke wind'];
        if ($kmhToMS <= 5.4) return ['scale' => 3, 'description' => 'Matige wind'];
        if ($kmhToMS <= 7.9) return ['scale' => 4, 'description' => 'Matige wind'];
        if ($kmhToMS <= 10.7) return ['scale' => 5, 'description' => 'Vrij krachtige wind'];
        if ($kmhToMS <= 13.8) return ['scale' => 6, 'description' => 'Krachtige wind'];
        if ($kmhToMS <= 17.1) return ['scale' => 7, 'description' => 'Harde wind'];
        if ($kmhToMS <= 20.7) return ['scale' => 8, 'description' => 'Stormachtige wind'];
        if ($kmhToMS <= 24.4) return ['scale' => 9, 'description' => 'Storm'];
        if ($kmhToMS <= 28.4) return ['scale' => 10, 'description' => 'Zware storm'];
        if ($kmhToMS <= 32.6) return ['scale' => 11, 'description' => 'Zeer zware storm'];
        return ['scale' => 12, 'description' => 'Orkaan'];
    }
}
?>