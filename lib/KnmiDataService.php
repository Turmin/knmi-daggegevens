<?php

class KnmiDataService {
    public const STATION = 260;
    public const DATA_URL = 'https://cdn.knmi.nl/knmi/map/page/klimatologie/gegevens/daggegevens/etmgeg_260.zip';
    public const DATA_SCRIPT_URL = 'https://www.daggegevens.knmi.nl/klimatologie/daggegevens';

    private $rootDir;
    private $dataFile;

    private $columns = [
        'stn', 'yyyymmdd', 'ddvec', 'fhvec', 'fg', 'fhx', 'fhxh', 'fhn', 'fhnh',
        'fxx', 'fxxh', 'tg', 'tn', 'tnh', 'tx', 'txh', 't10n', 't10nh',
        'sq', 'sp', 'q', 'dr', 'rh', 'rhx', 'rhxh', 'pg', 'px', 'pxh',
        'pn', 'pnh', 'vvn', 'vvnh', 'vvx', 'vvxh', 'ng', 'ug', 'ux', 'uxh',
        'un', 'unh', 'ev24'
    ];

    public function __construct(?string $rootDir = null) {
        $this->rootDir = $rootDir ?: dirname(__DIR__);
        $this->dataFile = $this->rootDir . '/etmgeg_260.txt';
    }

    public function getDataFilePath(): string {
        return $this->dataFile;
    }

    public function downloadDailyData(): array {
        $zipFile = $this->rootDir . '/etmgeg_260.zip';
        $messages = [];
        $context = stream_context_create([
            'http' => ['timeout' => 120],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);

        $contents = $this->downloadUrl(self::DATA_URL, $context);
        if ($contents === false) {
            $fallback = $this->downloadDailyTextData();
            if ($fallback['success'] ?? false) {
                return [
                    'success' => true,
                    'messages' => $fallback['messages'] ?? [],
                    'files' => [basename($this->dataFile)],
                    'file_info' => $this->getDataFileInfo()
                ];
            }

            return $this->result(false, array_merge(['Could not download KNMI ZIP data file.'], $fallback['messages'] ?? []));
        }

        if (file_put_contents($zipFile, $contents, LOCK_EX) === false) {
            return $this->result(false, ['Could not write downloaded ZIP file.']);
        }
        $messages[] = 'Downloaded KNMI ZIP file.';

        $extract = $this->extractDownloadedZip($zipFile);
        @unlink($zipFile);

        if (!($extract['success'] ?? false)) {
            $fallback = $this->downloadDailyTextData();
            if ($fallback['success'] ?? false) {
                return [
                    'success' => true,
                    'messages' => array_merge($messages, $extract['messages'] ?? [], $fallback['messages'] ?? []),
                    'files' => [basename($this->dataFile)],
                    'file_info' => $this->getDataFileInfo()
                ];
            }

            return $this->result(false, array_merge(
                $messages,
                $extract['messages'] ?? ['Could not extract downloaded ZIP file.'],
                $fallback['messages'] ?? []
            ));
        }

        $messages = array_merge($messages, $extract['messages'] ?? []);

        return [
            'success' => true,
            'messages' => $messages,
            'files' => $extract['files'] ?? [],
            'file_info' => $this->getDataFileInfo()
        ];
    }

    public function importDailyData(PDO $db): array {
        if (!is_file($this->dataFile)) {
            return $this->result(false, ['Data file not found: ' . basename($this->dataFile)]);
        }

        $this->ensureTable($db);
        $existingDates = $this->getExistingDates($db);
        $rows = $this->readRowsMissingFromDatabase($existingDates);

        if (!$rows) {
            return [
                'success' => true,
                'messages' => ['No missing or new rows found.'],
                'inserted' => 0,
                'last_database_date' => $this->getLastDatabaseDate($db),
                'file_info' => $this->getDataFileInfo()
            ];
        }

        $placeholders = '(' . implode(',', array_fill(0, count($this->columns), '?')) . ')';
        $sql = 'INSERT INTO knmi (`' . implode('`,`', $this->columns) . '`) VALUES ' . $placeholders;
        $stmt = $db->prepare($sql);
        $inserted = 0;

        $db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
                $inserted++;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'success' => true,
            'messages' => ['Imported ' . $inserted . ' missing/new row(s).'],
            'inserted' => $inserted,
            'last_database_date' => $this->getLastDatabaseDate($db),
            'file_info' => $this->getDataFileInfo()
        ];
    }

    public function getDataFileInfo(): array {
        $info = [
            'exists' => is_file($this->dataFile),
            'path' => $this->dataFile,
            'modified_at' => null,
            'size' => null,
            'rows' => 0,
            'latest_date' => null
        ];

        if (!$info['exists']) {
            return $info;
        }

        $modifiedAt = filemtime($this->dataFile);
        $size = filesize($this->dataFile);
        $info['modified_at'] = $modifiedAt === false ? null : date('Y-m-d H:i:s', $modifiedAt);
        $info['size'] = $size === false ? null : $size;

        $handle = fopen($this->dataFile, 'r');
        if (!$handle) {
            return $info;
        }

        while (($line = fgets($handle)) !== false) {
            $row = $this->parseDataLine($line);
            if (!$row) {
                continue;
            }
            $info['rows']++;
            $info['latest_date'] = $row['yyyymmdd'];
        }

        fclose($handle);

        return $info;
    }

    public function getDataFileDates(): array {
        $dates = [];
        if (!is_file($this->dataFile)) {
            return [];
        }

        $handle = fopen($this->dataFile, 'r');
        if (!$handle) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $row = $this->parseDataLine($line);
            if (!$row) {
                continue;
            }
            $dates[$row['yyyymmdd']] = true;
        }

        fclose($handle);

        return array_keys($dates);
    }

    public function getMissingDates(PDO $db): array {
        $fileDates = $this->getDataFileDates();
        if (!$fileDates) {
            return [];
        }

        if (!$this->databaseTableExists($db)) {
            return $fileDates;
        }

        $existingDates = $this->getExistingDates($db);
        $missingDates = [];

        foreach ($fileDates as $date) {
            if (!isset($existingDates[$date])) {
                $missingDates[] = $date;
            }
        }

        return $missingDates;
    }

    public function getLastDatabaseDate(PDO $db): ?string {
        if (!$this->databaseTableExists($db)) {
            return null;
        }

        $stmt = $db->query('SELECT MAX(yyyymmdd) as latest FROM knmi WHERE stn = ' . self::STATION);
        $latest = $stmt->fetch()['latest'] ?? null;

        return $latest ?: null;
    }

    private function extractDownloadedZip(string $zipFile): array {
        if (class_exists('ZipArchive')) {
            return $this->extractWithZipArchive($zipFile);
        }

        return $this->extractWithBuiltInZipReader($zipFile);
    }

    private function downloadUrl(string $url, $context) {
        $contents = @file_get_contents($url, false, $context);
        if ($contents !== false) {
            return $contents;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $contents = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($contents === false || $statusCode >= 400) {
            return false;
        }

        return $contents;
    }

    private function downloadDailyTextData(): array {
        $payload = http_build_query([
            'start' => '19010101',
            'end' => date('Ymd'),
            'stns' => (string) self::STATION,
            'vars' => 'ALL'
        ], '', '&');

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content' => $payload,
                'timeout' => 120
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);

        $contents = @file_get_contents(self::DATA_SCRIPT_URL, false, $context);
        if ($contents === false && function_exists('curl_init')) {
            $curl = curl_init(self::DATA_SCRIPT_URL);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            $contents = curl_exec($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($contents === false || $statusCode >= 400) {
                $contents = false;
            }
        }

        if ($contents === false || !$this->containsKnmiRows($contents)) {
            return $this->result(false, ['Could not download KNMI text data through script endpoint.']);
        }

        if (file_put_contents($this->dataFile, $contents, LOCK_EX) === false) {
            return $this->result(false, ['Could not write KNMI text data file.']);
        }

        return [
            'success' => true,
            'messages' => ['Downloaded KNMI text data through script endpoint.']
        ];
    }

    private function containsKnmiRows(string $contents): bool {
        return preg_match('/^\\s*' . self::STATION . '\\s*,\\s*\\d{8}\\s*,/m', $contents) === 1;
    }

    private function extractWithZipArchive(string $zipFile): array {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return $this->result(false, ['Could not open downloaded ZIP file.']);
        }

        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[] = $zip->getNameIndex($i);
        }

        $zip->extractTo($this->rootDir);
        $zip->close();

        return [
            'success' => true,
            'messages' => ['Extracted with ZipArchive: ' . implode(', ', $files)],
            'files' => $files
        ];
    }

    private function extractWithBuiltInZipReader(string $zipFile): array {
        if (!function_exists('gzinflate')) {
            return $this->result(false, ['ZipArchive is unavailable and PHP zlib/gzinflate is unavailable.']);
        }

        $zipData = file_get_contents($zipFile);
        if ($zipData === false) {
            return $this->result(false, ['Could not read downloaded ZIP file.']);
        }

        $entry = $this->findZipEntry($zipData, basename($this->dataFile));
        if (!$entry) {
            return $this->result(false, ['Could not find KNMI text file in downloaded ZIP file.']);
        }

        if (($entry['flags'] & 1) === 1) {
            return $this->result(false, ['Downloaded ZIP file is encrypted and cannot be extracted.']);
        }

        $localHeader = substr($zipData, $entry['local_offset'], 30);
        if (strlen($localHeader) < 30) {
            return $this->result(false, ['Invalid ZIP local file header.']);
        }

        $local = unpack('Vsig/vversion/vflags/vcompression/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', $localHeader);
        if (!$local || ($local['sig'] ?? null) !== 0x04034b50) {
            return $this->result(false, ['Invalid ZIP local file signature.']);
        }

        $dataStart = $entry['local_offset'] + 30 + $local['nameLength'] + $local['extraLength'];
        $compressedData = substr($zipData, $dataStart, $entry['compressed_size']);

        if ($entry['compression'] === 0) {
            $text = $compressedData;
        } elseif ($entry['compression'] === 8) {
            $text = @gzinflate($compressedData);
            if ($text === false) {
                return $this->result(false, ['Could not inflate KNMI text file from ZIP file.']);
            }
        } else {
            return $this->result(false, ['Unsupported ZIP compression method: ' . $entry['compression']]);
        }

        if (file_put_contents($this->dataFile, $text, LOCK_EX) === false) {
            return $this->result(false, ['Could not write extracted KNMI text file.']);
        }

        return [
            'success' => true,
            'messages' => ['Extracted with built-in ZIP fallback: ' . $entry['name']],
            'files' => [$entry['name']]
        ];
    }

    private function findZipEntry(string $zipData, string $preferredFileName): ?array {
        $eocdPos = strrpos($zipData, "\x50\x4b\x05\x06");
        if ($eocdPos === false) {
            return null;
        }

        $eocd = unpack('Vsig/vdisk/vstartDisk/vdiskEntries/vtotalEntries/VcentralSize/VcentralOffset/vcommentLength', substr($zipData, $eocdPos, 22));
        if (!$eocd || ($eocd['sig'] ?? null) !== 0x06054b50) {
            return null;
        }

        $position = $eocd['centralOffset'];
        $candidate = null;

        for ($i = 0; $i < $eocd['totalEntries']; $i++) {
            $header = substr($zipData, $position, 46);
            if (strlen($header) < 46) {
                break;
            }

            $entry = unpack('Vsig/vmade/vneeded/vflags/vcompression/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/VlocalOffset', $header);
            if (!$entry || ($entry['sig'] ?? null) !== 0x02014b50) {
                break;
            }

            $name = substr($zipData, $position + 46, $entry['nameLength']);
            $position += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];

            if ($name === '' || substr($name, -1) === '/') {
                continue;
            }

            $info = [
                'name' => $name,
                'flags' => $entry['flags'],
                'compression' => $entry['compression'],
                'compressed_size' => $entry['compressedSize'],
                'uncompressed_size' => $entry['uncompressedSize'],
                'local_offset' => $entry['localOffset']
            ];

            if (basename($name) === $preferredFileName) {
                return $info;
            }

            if ($candidate === null && strtolower(substr($name, -4)) === '.txt') {
                $candidate = $info;
            }
        }

        return $candidate;
    }

    private function readRowsMissingFromDatabase(array $existingDates): array {
        $rows = [];
        $handle = fopen($this->dataFile, 'r');
        if (!$handle) {
            return $rows;
        }

        while (($line = fgets($handle)) !== false) {
            $row = $this->parseDataLine($line);
            if (!$row) {
                continue;
            }

            $date = $row['yyyymmdd'];
            if (isset($existingDates[$date])) {
                continue;
            }

            $rows[] = $row;
            $existingDates[$date] = true;
        }

        fclose($handle);

        return $rows;
    }

    private function getExistingDates(PDO $db): array {
        if (!$this->databaseTableExists($db)) {
            return [];
        }

        $dates = [];
        $stmt = $db->query('SELECT yyyymmdd FROM knmi WHERE stn = ' . self::STATION);

        while (($date = $stmt->fetchColumn()) !== false) {
            $dates[$date] = true;
        }

        return $dates;
    }

    private function parseDataLine(string $line): ?array {
        if (!preg_match('/^\s*' . self::STATION . '\s*,\s*\d{8}\s*,/', $line)) {
            return null;
        }

        $parts = array_map('trim', explode(',', rtrim($line)));
        $parts = array_slice($parts, 0, count($this->columns));

        if (count($parts) < count($this->columns)) {
            $parts = array_pad($parts, count($this->columns), '');
        }

        $date = $parts[1] ?? '';
        if (!preg_match('/^\d{8}$/', $date)) {
            return null;
        }
        $parts[1] = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);

        return array_combine($this->columns, $parts);
    }

    private function ensureTable(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `knmi` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `stn` varchar(255) NOT NULL,
                `yyyymmdd` date NOT NULL,
                `ddvec` varchar(255) DEFAULT NULL,
                `fhvec` varchar(255) DEFAULT NULL,
                `fg` varchar(255) DEFAULT NULL,
                `fhx` varchar(255) DEFAULT NULL,
                `fhxh` varchar(255) DEFAULT NULL,
                `fhn` varchar(255) DEFAULT NULL,
                `fhnh` varchar(255) DEFAULT NULL,
                `fxx` varchar(255) DEFAULT NULL,
                `fxxh` varchar(255) DEFAULT NULL,
                `tg` varchar(255) DEFAULT NULL,
                `tn` varchar(255) DEFAULT NULL,
                `tnh` varchar(255) DEFAULT NULL,
                `tx` varchar(255) DEFAULT NULL,
                `txh` varchar(255) DEFAULT NULL,
                `t10n` varchar(255) DEFAULT NULL,
                `t10nh` varchar(255) DEFAULT NULL,
                `sq` varchar(255) DEFAULT NULL,
                `sp` varchar(255) DEFAULT NULL,
                `q` varchar(255) DEFAULT NULL,
                `dr` varchar(255) DEFAULT NULL,
                `rh` varchar(255) DEFAULT NULL,
                `rhx` varchar(255) DEFAULT NULL,
                `rhxh` varchar(255) DEFAULT NULL,
                `pg` varchar(255) DEFAULT NULL,
                `px` varchar(255) DEFAULT NULL,
                `pxh` varchar(255) DEFAULT NULL,
                `pn` varchar(255) DEFAULT NULL,
                `pnh` varchar(255) DEFAULT NULL,
                `vvn` varchar(255) DEFAULT NULL,
                `vvnh` varchar(255) DEFAULT NULL,
                `vvx` varchar(255) DEFAULT NULL,
                `vvxh` varchar(255) DEFAULT NULL,
                `ng` varchar(255) DEFAULT NULL,
                `ug` varchar(255) DEFAULT NULL,
                `ux` varchar(255) DEFAULT NULL,
                `uxh` varchar(255) DEFAULT NULL,
                `un` varchar(255) DEFAULT NULL,
                `unh` varchar(255) DEFAULT NULL,
                `ev24` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `station_date` (`stn`, `yyyymmdd`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private function databaseTableExists(PDO $db): bool {
        $stmt = $db->query("SHOW TABLES LIKE 'knmi'");
        return (bool) $stmt->fetchColumn();
    }

    private function result(bool $success, array $messages): array {
        return [
            'success' => $success,
            'messages' => $messages
        ];
    }
}
