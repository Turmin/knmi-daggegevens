<?php

class ApiRateLimiter {
    private $storageDir;
    private $maxRequests;
    private $windowSeconds;
    private $filePrefix = 'knmi_api_rate_';

    public function __construct(?string $storageDir = null, int $maxRequests = 120, int $windowSeconds = 60) {
        $this->storageDir = $storageDir ?: rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'knmi-api-rate-limit';
        $this->maxRequests = max(1, $maxRequests);
        $this->windowSeconds = max(1, $windowSeconds);
    }

    public static function clientIdentifierFromServer(array $server): string {
        $remoteAddress = self::validIp($server['REMOTE_ADDR'] ?? '') ?: 'unknown';
        $clientAddress = $remoteAddress;

        if ($remoteAddress === 'unknown' || self::isPrivateOrReservedIp($remoteAddress)) {
            $forwardedAddress = self::firstPublicForwardedIp($server);
            if ($forwardedAddress !== null) {
                $clientAddress = $forwardedAddress;
            }
        }

        return 'ip:' . $clientAddress;
    }

    public function check(string $clientIdentifier): array {
        $now = time();

        if (!$this->ensureStorageDir()) {
            return $this->result(true, $now, $this->maxRequests - 1);
        }

        $this->cleanup($now);

        $filePath = $this->storageDir . DIRECTORY_SEPARATOR . $this->filePrefix . hash('sha256', $clientIdentifier) . '.json';
        $handle = @fopen($filePath, 'c+');

        if ($handle === false) {
            return $this->result(true, $now, $this->maxRequests - 1);
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return $this->result(true, $now, $this->maxRequests - 1);
        }

        $contents = stream_get_contents($handle);
        $timestamps = $this->decodeTimestamps($contents);
        $cutoff = $now - $this->windowSeconds;
        $timestamps = array_values(array_filter($timestamps, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        }));

        if (count($timestamps) >= $this->maxRequests) {
            $oldestTimestamp = min($timestamps);
            $retryAfter = max(1, $this->windowSeconds - ($now - $oldestTimestamp));
            $result = $this->result(false, $now + $retryAfter, 0, $retryAfter);

            flock($handle, LOCK_UN);
            fclose($handle);

            return $result;
        }

        $timestamps[] = $now;
        $remaining = max(0, $this->maxRequests - count($timestamps));
        $resetAt = min($timestamps) + $this->windowSeconds;

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($timestamps));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $this->result(true, $resetAt, $remaining);
    }

    private function ensureStorageDir(): bool {
        if (is_dir($this->storageDir)) {
            return is_writable($this->storageDir);
        }

        return @mkdir($this->storageDir, 0700, true);
    }

    private function cleanup(int $now): void {
        if (mt_rand(1, 100) !== 1) {
            return;
        }

        foreach (glob($this->storageDir . DIRECTORY_SEPARATOR . $this->filePrefix . '*.json') ?: [] as $filePath) {
            $modifiedAt = filemtime($filePath);
            if ($modifiedAt !== false && $modifiedAt < $now - ($this->windowSeconds * 2)) {
                @unlink($filePath);
            }
        }
    }

    private function decodeTimestamps(string $contents): array {
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $decoded), function($timestamp) {
            return $timestamp > 0;
        }));
    }

    private function result(bool $allowed, int $resetAt, int $remaining, int $retryAfter = 0): array {
        return [
            'allowed' => $allowed,
            'limit' => $this->maxRequests,
            'remaining' => $remaining,
            'reset' => $resetAt,
            'retry_after' => $retryAfter
        ];
    }

    private static function firstPublicForwardedIp(array $server): ?string {
        $headers = [
            $server['HTTP_CF_CONNECTING_IP'] ?? '',
            $server['HTTP_X_REAL_IP'] ?? '',
            $server['HTTP_X_FORWARDED_FOR'] ?? ''
        ];

        foreach ($headers as $headerValue) {
            foreach (explode(',', (string)$headerValue) as $candidate) {
                $ip = self::validIp(trim($candidate));
                if ($ip !== null && !self::isPrivateOrReservedIp($ip)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    private static function validIp(string $ip): ?string {
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private static function isPrivateOrReservedIp(string $ip): bool {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
