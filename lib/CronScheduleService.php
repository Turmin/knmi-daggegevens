<?php

class CronScheduleService {
    private $db;
    private $dataService;

    public function __construct(PDO $db, KnmiDataService $dataService) {
        $this->db = $db;
        $this->dataService = $dataService;
        $this->ensureTable();
        $this->seedDefaultJob();
    }

    public static function taskOptions(): array {
        return [
            'download_import' => 'Download KNMI file and import',
            'download' => 'Download KNMI file only',
            'import' => 'Import missing/new days only'
        ];
    }

    public function listJobs(): array {
        $stmt = $this->db->query('SELECT * FROM knmi_cron_jobs ORDER BY enabled DESC, name ASC');
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function saveJob(array $data): array {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $name = trim((string)($data['name'] ?? ''));
        $task = (string)($data['task'] ?? '');
        $schedule = $this->normalizeSchedule((string)($data['schedule'] ?? ''));
        $enabled = !empty($data['enabled']) ? 1 : 0;

        if ($name === '') {
            throw new InvalidArgumentException('Cron name is required.');
        }

        if (!array_key_exists($task, self::taskOptions())) {
            throw new InvalidArgumentException('Unknown cron task.');
        }

        if (!$this->isValidSchedule($schedule)) {
            throw new InvalidArgumentException('Use a 5-part cron schedule, for example "15 8 * * *".');
        }

        if ($id > 0) {
            $stmt = $this->db->prepare('
                UPDATE knmi_cron_jobs
                SET name = :name,
                    task = :task,
                    schedule = :schedule,
                    enabled = :enabled,
                    updated_at = NOW()
                WHERE id = :id
            ');
            $stmt->execute([
                ':name' => $name,
                ':task' => $task,
                ':schedule' => $schedule,
                ':enabled' => $enabled,
                ':id' => $id
            ]);

            return ['success' => true, 'messages' => ['Cron schedule saved.']];
        }

        $stmt = $this->db->prepare('
            INSERT INTO knmi_cron_jobs
                (name, task, schedule, enabled, created_at, updated_at)
            VALUES
                (:name, :task, :schedule, :enabled, NOW(), NOW())
        ');
        $stmt->execute([
            ':name' => $name,
            ':task' => $task,
            ':schedule' => $schedule,
            ':enabled' => $enabled
        ]);

        return ['success' => true, 'messages' => ['Cron schedule added.']];
    }

    public function deleteJob(int $id): array {
        $stmt = $this->db->prepare('DELETE FROM knmi_cron_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return ['success' => true, 'messages' => ['Cron schedule deleted.']];
    }

    public function runJob(int $id): array {
        $stmt = $this->db->prepare('SELECT * FROM knmi_cron_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new InvalidArgumentException('Cron schedule not found.');
        }

        return $this->executeJob($job);
    }

    public function runDueJobs(?DateTimeImmutable $now = null): array {
        $now = $now ?: new DateTimeImmutable('now');
        $results = [];

        foreach ($this->listJobs() as $job) {
            if ((int)$job['enabled'] !== 1) {
                continue;
            }

            if (!$this->isDue($job, $now)) {
                continue;
            }

            $results[] = $this->executeJob($job, $now);
        }

        return $results;
    }

    private function ensureTable(): void {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS knmi_cron_jobs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                task VARCHAR(40) NOT NULL,
                schedule VARCHAR(40) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                last_run_at DATETIME NULL,
                last_status VARCHAR(20) NULL,
                last_message TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX enabled_schedule (enabled, schedule),
                INDEX last_run_at (last_run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    private function seedDefaultJob(): void {
        $count = (int)$this->db->query('SELECT COUNT(*) FROM knmi_cron_jobs')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO knmi_cron_jobs
                (name, task, schedule, enabled, created_at, updated_at)
            VALUES
                (:name, :task, :schedule, 0, NOW(), NOW())
        ');
        $stmt->execute([
            ':name' => 'Daily KNMI update',
            ':task' => 'download_import',
            ':schedule' => '15 8 * * *'
        ]);
    }

    private function executeJob(array $job, ?DateTimeImmutable $now = null): array {
        $now = $now ?: new DateTimeImmutable('now');
        $messages = [];
        $success = false;

        try {
            if ($job['task'] === 'download_import') {
                $download = $this->dataService->downloadDailyData();
                $messages = array_merge($messages, $download['messages'] ?? []);
                if ($download['success'] ?? false) {
                    $import = $this->dataService->importDailyData($this->db);
                    $success = (bool)($import['success'] ?? false);
                    $messages = array_merge($messages, $import['messages'] ?? []);
                }
            } elseif ($job['task'] === 'download') {
                $result = $this->dataService->downloadDailyData();
                $success = (bool)($result['success'] ?? false);
                $messages = $result['messages'] ?? [];
            } elseif ($job['task'] === 'import') {
                $result = $this->dataService->importDailyData($this->db);
                $success = (bool)($result['success'] ?? false);
                $messages = $result['messages'] ?? [];
            } else {
                throw new InvalidArgumentException('Unknown cron task.');
            }
        } catch (Throwable $e) {
            $success = false;
            $messages[] = $e->getMessage();
        }

        $messageText = implode(' | ', $messages);
        $stmt = $this->db->prepare('
            UPDATE knmi_cron_jobs
            SET last_run_at = :last_run_at,
                last_status = :last_status,
                last_message = :last_message,
                updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            ':last_run_at' => $now->format('Y-m-d H:i:s'),
            ':last_status' => $success ? 'success' : 'failed',
            ':last_message' => $messageText,
            ':id' => (int)$job['id']
        ]);

        return [
            'id' => (int)$job['id'],
            'name' => $job['name'],
            'task' => $job['task'],
            'success' => $success,
            'messages' => $messages ?: ['Cron task finished.']
        ];
    }

    private function normalizeSchedule(string $schedule): string {
        $schedule = trim(preg_replace('/\s+/', ' ', $schedule));
        $aliases = [
            '@hourly' => '0 * * * *',
            '@daily' => '15 8 * * *',
            '@weekly' => '15 8 * * 1',
            '@monthly' => '15 8 1 * *'
        ];

        return $aliases[$schedule] ?? $schedule;
    }

    private function isValidSchedule(string $schedule): bool {
        $parts = explode(' ', $schedule);
        if (count($parts) !== 5) {
            return false;
        }

        $ranges = [
            [0, 59],
            [0, 23],
            [1, 31],
            [1, 12],
            [0, 7]
        ];

        foreach ($parts as $index => $part) {
            if (!$this->isValidCronField($part, $ranges[$index][0], $ranges[$index][1])) {
                return false;
            }
        }

        return true;
    }

    private function isValidCronField(string $field, int $min, int $max): bool {
        foreach (explode(',', $field) as $segment) {
            if ($segment === '*') {
                continue;
            }

            if (preg_match('/^\*\/(\d+)$/', $segment, $match)) {
                $step = (int)$match[1];
                if ($step < 1 || $step > $max) {
                    return false;
                }
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)$/', $segment, $match)) {
                $start = (int)$match[1];
                $end = (int)$match[2];
                if ($start < $min || $end > $max || $start > $end) {
                    return false;
                }
                continue;
            }

            if (!ctype_digit($segment)) {
                return false;
            }

            $value = (int)$segment;
            if ($value < $min || $value > $max) {
                return false;
            }
        }

        return true;
    }

    private function isDue(array $job, DateTimeImmutable $now): bool {
        if (!$this->scheduleMatches($job['schedule'], $now)) {
            return false;
        }

        if (empty($job['last_run_at'])) {
            return true;
        }

        $lastRun = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $job['last_run_at']);
        return !$lastRun || $lastRun->format('Y-m-d H:i') !== $now->format('Y-m-d H:i');
    }

    private function scheduleMatches(string $schedule, DateTimeImmutable $date): bool {
        $parts = explode(' ', $schedule);
        if (count($parts) !== 5) {
            return false;
        }

        $values = [
            (int)$date->format('i'),
            (int)$date->format('G'),
            (int)$date->format('j'),
            (int)$date->format('n'),
            (int)$date->format('w')
        ];

        foreach ($parts as $index => $field) {
            if (!$this->fieldMatches($field, $values[$index])) {
                if ($index === 4 && $values[$index] === 0 && $this->fieldMatches($field, 7)) {
                    continue;
                }
                return false;
            }
        }

        return true;
    }

    private function fieldMatches(string $field, int $value): bool {
        foreach (explode(',', $field) as $segment) {
            if ($segment === '*') {
                return true;
            }

            if (preg_match('/^\*\/(\d+)$/', $segment, $match)) {
                if ($value % (int)$match[1] === 0) {
                    return true;
                }
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)$/', $segment, $match)) {
                if ($value >= (int)$match[1] && $value <= (int)$match[2]) {
                    return true;
                }
                continue;
            }

            if (ctype_digit($segment) && (int)$segment === $value) {
                return true;
            }
        }

        return false;
    }
}
