<?php
// config/Database.php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn = null;

    public function __construct($configPath = null) {
        $configPath = $configPath ?: dirname(__DIR__, 2) . '/knmi.database.credentials.php';

        if (!file_exists($configPath)) {
            throw new Exception("Database configuration file not found");
        }

        $config = require $configPath;

        foreach (['host', 'db_name', 'username', 'password'] as $key) {
            if (!isset($config[$key]) || $config[$key] === '') {
                throw new Exception("Database configuration is missing: " . $key);
            }
        }

        $this->host = $config['host'];
        $this->db_name = $config['db_name'];
        $this->username = $config['username'];
        $this->password = $config['password'];
    }

    public function connect() {
        if ($this->conn === null) {
            try {
                $this->conn = new PDO(
                    "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                    $this->username,
                    $this->password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch(PDOException $e) {
                error_log("Connection error: " . $e->getMessage());
                throw new Exception("Database connection failed");
            }
        }
        return $this->conn;
    }
}
?>
