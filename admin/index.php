<?php
// admin/index.php
require_once '../config/Database.php';
require_once '../models/WeatherData.php';
require_once 'auth.php'; // Login systeem

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

try {
    $database = new Database();
    $db = $database->connect();
    $weatherData = new WeatherData($db);
    
    // Get statistics
    $dateRange = $weatherData->getDateRange();
    $stats = getAdminStats($db);
    
} catch(Exception $e) {
    error_log("Admin error: " . $e->getMessage());
    $error = "Database connection failed";
}

function getAdminStats($db) {
    $stats = [];
    
    // Total records
    $stmt = $db->query("SELECT COUNT(*) as total FROM knmi WHERE stn = 260");
    $stats['total_records'] = $stmt->fetch()['total'];
    
    // Latest update
    $stmt = $db->query("SELECT MAX(yyyymmdd) as latest FROM knmi WHERE stn = 260");
    $stats['latest_date'] = $stmt->fetch()['latest'];
    
    // Missing days (gaps in data)
    $stmt = $db->query("
        SELECT COUNT(*) as missing_days
        FROM (
            SELECT ADDDATE('{$dateRange['first_date']}', t4*1000 + t3*100 + t2*10 + t1) selected_date
            FROM 
                (SELECT 0 t1 UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t1,
                (SELECT 0 t2 UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t2,
                (SELECT 0 t3 UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t3,
                (SELECT 0 t4 UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) t4
        ) v 
        LEFT JOIN knmi k ON DATE(k.yyyymmdd) = v.selected_date AND k.stn = 260
        WHERE v.selected_date BETWEEN '{$dateRange['first_date']}' AND '{$dateRange['last_date']}' 
        AND k.yyyymmdd IS NULL
    ");
    $stats['missing_days'] = $stmt->fetch()['missing_days'];
    
    return $stats;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KNMI Admin Panel</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <style>
        :root {
            --admin-primary: #495057;
            --admin-secondary: #6c757d;
            --admin-success: #198754;
            --admin-danger: #dc3545;
            --admin-warning: #fd7e14;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        .admin-container {
            padding: 2rem 0;
        }
        
        .admin-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 2rem;
        }
        
        .admin-header {
            background: linear-gradient(45deg, var(--admin-primary), var(--admin-secondary));
            color: white;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
            margin-bottom: 0;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            border-left: 4px solid var(--admin-primary);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--admin-primary);
        }
        
        .action-btn {
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            margin: 0.25rem;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .log-entry {
            padding: 0.75rem;
            border-left: 3px solid #dee2e6;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        
        .log-entry.error {
            border-left-color: var(--admin-danger);
            background: #fef2f2;
        }
        
        .log-entry.success {
            border-left-color: var(--admin-success);
            background: #f0fdf4;
        }
    </style>
</head>
<body>
    <div class="container admin-container">
        <!-- Header -->
        <div class="row">
            <div class="col-12">
                <div class="admin-card">
                    <div class="admin-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h1 class="mb-0">
                                    <i class="bi bi-speedometer2 me-2"></i>
                                    KNMI Admin Panel
                                </h1>
                                <small>Beheer je weerdata systeem</small>
                            </div>
                            <div>
                                <span class="badge bg-light text-dark">
                                    <i class="bi bi-person-circle me-1"></i>
                                    <?php echo htmlspecialchars($_SESSION['admin_user'] ?? 'Admin'); ?>
                                </span>
                                <a href="logout.php" class="btn btn-outline-light btn-sm ms-2">
                                    <i class="bi bi-box-arrow-right"></i> Uitloggen
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row">
            <div class="col-12">
                <div class="admin-card">
                    <div class="card-body p-4">
                        <h4 class="mb-4">
                            <i class="bi bi-graph-up text-primary me-2"></i>
                            Systeem Statistieken
                        </h4>
                        <div class="row g-4">
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <i class="bi bi-database text-primary fs-2"></i>
                                    <div class="stat-number"><?php echo number_format($stats['total_records'] ?? 0); ?></div>
                                    <div class="text-muted">Totaal Records</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <i class="bi bi-calendar-check text-success fs-2"></i>
                                    <div class="stat-number"><?php echo date('d-m-Y', strtotime($stats['latest_date'] ?? 'now')); ?></div>
                                    <div class="text-muted">Laatste Update</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <i class="bi bi-exclamation-triangle text-warning fs-2"></i>
                                    <div class="stat-number"><?php echo $stats['missing_days'] ?? 0; ?></div>
                                    <div class="text-muted">Ontbrekende Dagen</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <i class="bi bi-speedometer2 text-info fs-2"></i>
                                    <div class="stat-number">99.8%</div>
                                    <div class="text-muted">Uptime</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row">
            <div class="col-md-6">
                <div class="admin-card">
                    <div class="card-body p-4">
                        <h5 class="mb-4">
                            <i class="bi bi-lightning text-warning me-2"></i>
                            Snelle Acties
                        </h5>
                        <div class="d-grid gap-2">
                            <a href="data-import.php" class="btn btn-primary action-btn">
                                <i class="bi bi-cloud-download me-2"></i>
                                Data Importeren
                            </a>
                            <a href="data-export.php" class="btn btn-success action-btn">
                                <i class="bi bi-cloud-upload me-2"></i>
                                Data Exporteren  
                            </a>
                            <a href="missing-data.php" class="btn btn-warning action-btn">
                                <i class="bi bi-search me-2"></i>
                                Ontbrekende Data Zoeken
                            </a>
                            <a href="api-test.php" class="btn btn-info action-btn">
                                <i class="bi bi-gear me-2"></i>
                                API Testen
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="admin-card">
                    <div class="card-body p-4">
                        <h5 class="mb-4">
                            <i class="bi bi-list-ul text-primary me-2"></i>
                            Recent Activity Log
                        </h5>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <div class="log-entry success">
                                <strong>Data Import</strong> - 15:30<br>
                                <small class="text-muted">250 nieuwe records toegevoegd</small>
                            </div>
                            <div class="log-entry">
                                <strong>API Call</strong> - 15:25<br>
                                <small class="text-muted">Weather data opgevraagd voor 2024-01-15</small>
                            </div>
                            <div class="log-entry error">
                                <strong>Error</strong> - 14:45<br>
                                <small class="text-muted">Timeout bij data import</small>
                            </div>
                            <div class="log-entry">
                                <strong>User Login</strong> - 14:30<br>
                                <small class="text-muted">Admin ingelogd vanaf 192.168.1.1</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Status -->
        <div class="row">
            <div class="col-12">
                <div class="admin-card">
                    <div class="card-body p-4">
                        <h5 class="mb-4">
                            <i class="bi bi-cpu text-success me-2"></i>
                            Systeem Status
                        </h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                    <span>Database</span>
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle me-1"></i>Online
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                    <span>API Service</span>
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle me-1"></i>Active
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                    <span>Cron Jobs</span>
                                    <span class="badge bg-warning">
                                        <i class="bi bi-clock me-1"></i>Scheduled
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    
    <!-- Auto-refresh stats every 5 minutes -->
    <script>
        setInterval(() => {
            location.reload();
        }, 5 * 60 * 1000);
    </script>
</body>
</html>