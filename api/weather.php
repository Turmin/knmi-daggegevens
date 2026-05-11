<?php
// api/weather.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/Database.php';
require_once '../models/WeatherData.php';

class WeatherAPI {
    private $weatherData;
    
    public function __construct() {
        try {
            $database = new Database();
            $db = $database->connect();
            $this->weatherData = new WeatherData($db);
        } catch(Exception $e) {
            $this->sendError(500, "Database connection failed");
        }
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $pathParts = explode('/', trim($path, '/'));
        
        // Remove 'api' and 'weather.php' from path parts
        $pathParts = array_slice($pathParts, 2);
        
        try {
            switch($method) {
                case 'GET':
                    $this->handleGet($pathParts);
                    break;
                default:
                    $this->sendError(405, "Method not allowed");
            }
        } catch(Exception $e) {
            error_log("API Error: " . $e->getMessage());
            $this->sendError(500, "Internal server error");
        }
    }
    
    private function handleGet($pathParts) {
        if (empty($pathParts[0])) {
            $this->sendError(400, "Endpoint required");
            return;
        }
        
        switch($pathParts[0]) {
            case 'day':
                $this->getDay();
                break;
            case 'period':
                $this->getPeriod();
                break;
            case 'stats':
                $this->getStats();
                break;
            case 'range':
                $this->getDateRange();
                break;
            default:
                $this->sendError(404, "Endpoint not found");
        }
    }
    
    private function getDay() {
        $date = $_GET['date'] ?? null;
        $station = $_GET['station'] ?? 260;
        
        if (!$date) {
            $this->sendError(400, "Date parameter required");
            return;
        }
        
        if (!$this->validateDate($date)) {
            $this->sendError(400, "Invalid date format. Use YYYY-MM-DD");
            return;
        }
        
        $data = $this->weatherData->getDataByDate($date, $station);
        
        if ($data) {
            $this->sendSuccess($data);
        } else {
            $this->sendError(404, "No data found for the specified date");
        }
    }
    
    private function getPeriod() {
        $startDate = $_GET['start'] ?? null;
        $endDate = $_GET['end'] ?? null;
        $station = $_GET['station'] ?? 260;
        
        if (!$startDate || !$endDate) {
            $this->sendError(400, "Start and end date parameters required");
            return;
        }
        
        if (!$this->validateDate($startDate) || !$this->validateDate($endDate)) {
            $this->sendError(400, "Invalid date format. Use YYYY-MM-DD");
            return;
        }
        
        $data = $this->weatherData->getDataForPeriod($startDate, $endDate, $station);
        $this->sendSuccess($data);
    }
    
    private function getStats() {
        $year = $_GET['year'] ?? null;
        $month = $_GET['month'] ?? null;
        $station = $_GET['station'] ?? 260;
        
        if (!$year || !$month) {
            $this->sendError(400, "Year and month parameters required");
            return;
        }
        
        if (!is_numeric($year) || !is_numeric($month) || $month < 1 || $month > 12) {
            $this->sendError(400, "Invalid year or month");
            return;
        }
        
        $data = $this->weatherData->getMonthlyStats($year, $month, $station);
        $this->sendSuccess($data);
    }
    
    private function getDateRange() {
        $station = $_GET['station'] ?? 260;
        $data = $this->weatherData->getDateRange($station);
        $this->sendSuccess($data);
    }
    
    private function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    private function sendSuccess($data) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }
    
    private function sendError($code, $message) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message
            ],
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }
}

// Initialize and handle the request
$api = new WeatherAPI();
$api->handleRequest();
?>