<?php

require_once __DIR__ . '/KnmiStationCatalog.php';

class KnmiDataService {
    public const STATION = KnmiStationCatalog::DEFAULT_STATION;
    public const DATA_URL_TEMPLATE = 'https://cdn.knmi.nl/knmi/map/page/klimatologie/gegevens/daggegevens/etmgeg_%d.zip';
    public const DATA_SCRIPT_URL = 'https://www.daggegevens.knmi.nl/klimatologie/daggegevens';
    private const IMPORT_BATCH_SIZE = 1000;

    private $rootDir;

    private $columns = [
        'stn', 'yyyymmdd', 'ddvec', 'fhvec', 'fg', 'fhx', 'fhxh', 'fhn', 'fhnh',
        'fxx', 'fxxh', 'tg', 'tn', 'tnh', 'tx', 'txh', 't10n', 't10nh',
        'sq', 'sp', 'q', 'dr', 'rh', 'rhx', 'rhxh', 'pg', 'px', 'pxh',
        'pn', 'pnh', 'vvn', 'vvnh', 'vvx', 'vvxh', 'ng', 'ug', 'ux', 'uxh',
        'un', 'unh', 'ev24'
    ];

    public function __construct(?string $rootDir = null) {
        $this->rootDir = $rootDir ?: dirname(__DIR__);
    }

    public function getDataFilePath(?int $station = null): string {
        return $this->getStationDataFilePath($station ?: self::STATION);
    }

    public function getStations(): array {
        return KnmiStationCatalog::all();
    }

    public function getStationIds(): array {
        return KnmiStationCatalog::ids();
    }

    public function downloadDailyData(?int $station = null): array {
        $messages = [];
        $files = [];
        $failed = 0;

        foreach ($this->stationIdsFor($station) as $stationId) {
            $result = $this->downloadStationDailyData($stationId);
            $messages = array_merge($messages, $result['messages'] ?? []);
            $files = array_merge($files, $result['files'] ?? []);

            if (!($result['success'] ?? false)) {
                $failed++;
            }
        }

        return [
            'success' => $failed === 0,
            'messages' => $messages,
            'files' => array_values(array_unique($files)),
            'file_info' => $this->getDataFileInfo($station, false)
        ];
    }

    private function downloadStationDailyData(int $station): array {
        $zipFile = $this->rootDir . '/etmgeg_' . $station . '.zip';
        $dataFile = $this->getStationDataFilePath($station);
        $stationLabel = $this->stationLabel($station);
        $messages = [];
        $context = stream_context_create([
            'http' => ['timeout' => 120],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);

        $contents = $this->downloadUrl(sprintf(self::DATA_URL_TEMPLATE, $station), $context);
        if ($contents === false) {
            $fallback = $this->downloadDailyTextData($station);
            if ($fallback['success'] ?? false) {
                return [
                    'success' => true,
                    'messages' => $fallback['messages'] ?? [],
                    'files' => [basename($dataFile)],
                    'file_info' => $this->getDataFileInfo($station, false)
                ];
            }

            return $this->result(false, array_merge(['Could not download KNMI ZIP data file for ' . $stationLabel . '.'], $fallback['messages'] ?? []));
        }

        if (file_put_contents($zipFile, $contents, LOCK_EX) === false) {
            return $this->result(false, ['Could not write downloaded ZIP file for ' . $stationLabel . '.']);
        }
        $messages[] = 'Downloaded KNMI ZIP file for ' . $stationLabel . '.';

        $extract = $this->extractDownloadedZip($zipFile, $station);
        @unlink($zipFile);

        if (!($extract['success'] ?? false)) {
            $fallback = $this->downloadDailyTextData($station);
            if ($fallback['success'] ?? false) {
                return [
                    'success' => true,
                    'messages' => array_merge($messages, $extract['messages'] ?? [], $fallback['messages'] ?? []),
                    'files' => [basename($dataFile)],
                    'file_info' => $this->getDataFileInfo($station, false)
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
            'file_info' => $this->getDataFileInfo($station, false)
        ];
    }

    public function importDailyData(PDO $db, ?int $station = null): array {
        $availableFiles = $this->getAvailableStationFiles($station);
        if (!$availableFiles) {
            return $this->result(false, ['No KNMI station data files found. Download data before importing.']);
        }

        $this->ensureTable($db);

        $placeholders = '(' . implode(',', array_fill(0, count($this->columns), '?')) . ')';
        $sql = 'INSERT IGNORE INTO knmi (`' . implode('`,`', $this->columns) . '`) VALUES ' . $placeholders;
        $stmt = $db->prepare($sql);
        $inserted = 0;
        $messages = [];

        foreach ($availableFiles as $stationId => $dataFile) {
            $result = $this->importStationDailyData($db, $stmt, (int)$stationId, $dataFile);
            $inserted += (int)($result['inserted'] ?? 0);
            $messages = array_merge($messages, $result['messages'] ?? []);
        }

        if ($inserted === 0 && !$messages) {
            $messages[] = 'No missing or new rows found.';
        }

        return [
            'success' => true,
            'messages' => $messages ?: ['No missing or new rows found.'],
            'inserted' => $inserted,
            'last_database_date' => $this->getLastDatabaseDate($db, $station),
            'file_info' => $this->getDataFileInfo($station, false)
        ];
    }

    public function getDataFileInfo(?int $station = null, bool $scanRows = true): array {
        $info = [
            'exists' => false,
            'path' => $this->rootDir,
            'modified_at' => null,
            'size' => null,
            'rows' => 0,
            'latest_date' => null,
            'files' => []
        ];

        foreach ($this->stationIdsFor($station) as $stationId) {
            $dataFile = $this->getStationDataFilePath($stationId);
            $fileInfo = [
                'station' => $stationId,
                'station_name' => KnmiStationCatalog::name($stationId),
                'exists' => is_file($dataFile),
                'path' => $dataFile,
                'modified_at' => null,
                'size' => null,
                'rows' => 0,
                'latest_date' => null
            ];

            if (!$fileInfo['exists']) {
                $info['files'][] = $fileInfo;
                continue;
            }

            $info['exists'] = true;

            $modifiedAt = filemtime($dataFile);
            $size = filesize($dataFile);
            $fileInfo['modified_at'] = $modifiedAt === false ? null : date('Y-m-d H:i:s', $modifiedAt);
            $fileInfo['size'] = $size === false ? null : $size;
            $info['size'] = ($info['size'] ?? 0) + (int)($fileInfo['size'] ?? 0);

            if ($modifiedAt !== false && ($info['modified_at'] === null || strtotime($fileInfo['modified_at']) > strtotime($info['modified_at']))) {
                $info['modified_at'] = $fileInfo['modified_at'];
            }

            if (!$scanRows) {
                $fileInfo['latest_date'] = $this->getLatestDateFromDataFile($dataFile, $stationId);
            } else {
                $handle = fopen($dataFile, 'r');
                if (!$handle) {
                    $info['files'][] = $fileInfo;
                    continue;
                }

                while (($line = fgets($handle)) !== false) {
                    $row = $this->parseDataLine($line, $stationId);
                    if (!$row) {
                        continue;
                    }
                    $fileInfo['rows']++;
                    $fileInfo['latest_date'] = $row['yyyymmdd'];
                }
                fclose($handle);
            }

            $info['rows'] += (int)$fileInfo['rows'];
            if ($fileInfo['latest_date'] !== null && ($info['latest_date'] === null || $fileInfo['latest_date'] > $info['latest_date'])) {
                $info['latest_date'] = $fileInfo['latest_date'];
            }

            $info['files'][] = $fileInfo;
        }

        return $info;
    }

    public function getDataFileDates(?int $station = null, ?int $limit = null): array {
        $dates = [];

        foreach ($this->stationIdsFor($station) as $stationId) {
            $dataFile = $this->getStationDataFilePath($stationId);
            if (!is_file($dataFile)) {
                continue;
            }

            $handle = fopen($dataFile, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $row = $this->parseDataLine($line, $stationId);
                if (!$row) {
                    continue;
                }
                $dates[$row['stn'] . ':' . $row['yyyymmdd']] = $row['stn'] . ' ' . $row['yyyymmdd'];

                if ($limit !== null && count($dates) >= $limit) {
                    break;
                }
            }

            fclose($handle);

            if ($limit !== null && count($dates) >= $limit) {
                break;
            }
        }

        return array_values($dates);
    }

    public function getMissingDateSummary(PDO $db, ?int $station = null, int $previewLimit = 8): array {
        $preview = [];
        $count = 0;
        $tableExists = $this->databaseTableExists($db);

        foreach ($this->stationIdsFor($station) as $stationId) {
            $dataFile = $this->getStationDataFilePath($stationId);
            if (!is_file($dataFile)) {
                continue;
            }

            $existingDates = $tableExists ? $this->getExistingDatesForStation($db, $stationId) : [];
            $handle = fopen($dataFile, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $row = $this->parseDataLine($line, $stationId);
                if (!$row) {
                    continue;
                }

                if (isset($existingDates[$row['yyyymmdd']])) {
                    continue;
                }

                $count++;
                if (count($preview) < $previewLimit) {
                    $preview[] = $row['stn'] . ' ' . $row['yyyymmdd'];
                }
            }

            fclose($handle);
        }

        return [
            'count' => $count,
            'preview' => $preview
        ];
    }

    public function getMissingDates(PDO $db, ?int $station = null, ?int $limit = null): array {
        if (!$this->databaseTableExists($db)) {
            return $this->getDataFileDates($station, $limit);
        }

        $missingDates = [];

        foreach ($this->stationIdsFor($station) as $stationId) {
            $dataFile = $this->getStationDataFilePath($stationId);
            if (!is_file($dataFile)) {
                continue;
            }

            $existingDates = $this->getExistingDatesForStation($db, $stationId);
            $handle = fopen($dataFile, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $row = $this->parseDataLine($line, $stationId);
                if (!$row) {
                    continue;
                }

                if (!isset($existingDates[$row['yyyymmdd']])) {
                    $missingDates[] = $row['stn'] . ' ' . $row['yyyymmdd'];
                }

                if ($limit !== null && count($missingDates) >= $limit) {
                    break;
                }
            }

            fclose($handle);

            if ($limit !== null && count($missingDates) >= $limit) {
                break;
            }
        }

        return $missingDates;
    }

    public function getLastDatabaseDate(PDO $db, ?int $station = null): ?string {
        if (!$this->databaseTableExists($db)) {
            return null;
        }

        if ($station !== null) {
            $stmt = $db->prepare('SELECT MAX(yyyymmdd) as latest FROM knmi WHERE stn = :station');
            $stmt->execute([':station' => $station]);
            $latest = $stmt->fetch()['latest'] ?? null;
            return $latest ?: null;
        }

        $stationIds = implode(',', array_map('intval', $this->getStationIds()));
        $stmt = $db->query('SELECT MAX(yyyymmdd) as latest FROM knmi WHERE stn IN (' . $stationIds . ')');
        $latest = $stmt ? ($stmt->fetch()['latest'] ?? null) : null;

        return $latest ?: null;
    }

    private function extractDownloadedZip(string $zipFile, int $station): array {
        if (class_exists('ZipArchive')) {
            return $this->extractWithZipArchive($zipFile, $station);
        }

        return $this->extractWithBuiltInZipReader($zipFile, $station);
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

    private function downloadDailyTextData(int $station): array {
        $dataFile = $this->getStationDataFilePath($station);
        $stationLabel = $this->stationLabel($station);
        $payload = http_build_query([
            'start' => '19010101',
            'end' => date('Ymd'),
            'stns' => (string) $station,
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

        if ($contents === false || !$this->containsKnmiRows($contents, $station)) {
            return $this->result(false, ['Could not download KNMI text data through script endpoint for ' . $stationLabel . '.']);
        }

        if (file_put_contents($dataFile, $contents, LOCK_EX) === false) {
            return $this->result(false, ['Could not write KNMI text data file for ' . $stationLabel . '.']);
        }

        return [
            'success' => true,
            'messages' => ['Downloaded KNMI text data through script endpoint for ' . $stationLabel . '.'],
            'files' => [basename($dataFile)]
        ];
    }

    private function containsKnmiRows(string $contents, int $station): bool {
        return preg_match('/^\\s*' . $station . '\\s*,\\s*\\d{8}\\s*,/m', $contents) === 1;
    }

    private function extractWithZipArchive(string $zipFile, int $station): array {
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
            'messages' => ['Extracted ' . $this->stationLabel($station) . ' with ZipArchive: ' . implode(', ', $files)],
            'files' => [basename($this->getStationDataFilePath($station))]
        ];
    }

    private function extractWithBuiltInZipReader(string $zipFile, int $station): array {
        if (!function_exists('gzinflate')) {
            return $this->result(false, ['ZipArchive is unavailable and PHP zlib/gzinflate is unavailable.']);
        }

        $zipData = file_get_contents($zipFile);
        if ($zipData === false) {
            return $this->result(false, ['Could not read downloaded ZIP file.']);
        }

        $dataFile = $this->getStationDataFilePath($station);
        $entry = $this->findZipEntry($zipData, basename($dataFile));
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

        if (file_put_contents($dataFile, $text, LOCK_EX) === false) {
            return $this->result(false, ['Could not write extracted KNMI text file.']);
        }

        return [
            'success' => true,
            'messages' => ['Extracted ' . $this->stationLabel($station) . ' with built-in ZIP fallback: ' . $entry['name']],
            'files' => [basename($dataFile)]
        ];
    }

    private function getLatestDateFromDataFile(string $dataFile, int $station): ?string {
        $size = filesize($dataFile);
        if ($size === false || $size <= 0) {
            return null;
        }

        $handle = fopen($dataFile, 'rb');
        if (!$handle) {
            return null;
        }

        $position = $size;
        $buffer = '';
        $chunkSize = 65536;

        while ($position > 0) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;

            if (fseek($handle, $position) !== 0) {
                break;
            }

            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            $lines = preg_split('/\r\n|\r|\n/', $buffer);
            if ($lines === false) {
                break;
            }

            if ($position > 0) {
                $buffer = array_shift($lines) ?? '';
            }

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $row = $this->parseDataLine($lines[$i], $station);
                if ($row) {
                    fclose($handle);
                    return $row['yyyymmdd'];
                }
            }
        }

        fclose($handle);
        return null;
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

    private function importStationDailyData(PDO $db, PDOStatement $stmt, int $station, string $dataFile): array {
        $stationLabel = $this->stationLabel($station);
        $existingDates = $this->getExistingDatesForStation($db, $station);
        $inserted = 0;
        $attempted = 0;
        $batchCount = 0;

        $handle = fopen($dataFile, 'r');
        if (!$handle) {
            return [
                'success' => false,
                'messages' => ['Could not read KNMI text data file for ' . $stationLabel . '.'],
                'inserted' => 0
            ];
        }

        $db->beginTransaction();
        try {
            while (($line = fgets($handle)) !== false) {
                $row = $this->parseDataLine($line, $station);
                if (!$row) {
                    continue;
                }

                $date = $row['yyyymmdd'];
                if (isset($existingDates[$date])) {
                    continue;
                }

                $stmt->execute(array_values($row));
                $inserted += $stmt->rowCount() > 0 ? 1 : 0;
                $attempted++;
                $batchCount++;
                $existingDates[$date] = true;

                if ($batchCount >= self::IMPORT_BATCH_SIZE) {
                    $db->commit();
                    $db->beginTransaction();
                    $batchCount = 0;
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            fclose($handle);
            throw $e;
        }

        fclose($handle);

        if ($attempted === 0) {
            return [
                'success' => true,
                'messages' => ['No missing or new rows found for ' . $stationLabel . '.'],
                'inserted' => 0
            ];
        }

        $skipped = $attempted - $inserted;
        $message = 'Imported ' . $inserted . ' missing/new row(s) for ' . $stationLabel . '.';
        if ($skipped > 0) {
            $message .= ' ' . $skipped . ' row(s) were skipped by database uniqueness checks.';
        }

        return [
            'success' => true,
            'messages' => [$message],
            'inserted' => $inserted
        ];
    }

    private function readRowsMissingFromDatabase(array $existingDates): array {
        $rows = [];

        foreach ($this->getStationIds() as $station) {
            $dataFile = $this->getStationDataFilePath((int)$station);
            if (!is_file($dataFile)) {
                continue;
            }

            $handle = fopen($dataFile, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $row = $this->parseDataLine($line, (int)$station);
                if (!$row) {
                    continue;
                }

                $stationKey = (string)$row['stn'];
                $date = $row['yyyymmdd'];
                if (isset($existingDates[$stationKey][$date])) {
                    continue;
                }

                $rows[] = $row;
                $existingDates[$stationKey][$date] = true;
            }

            fclose($handle);
        }

        return $rows;
    }

    private function getExistingDates(PDO $db): array {
        if (!$this->databaseTableExists($db)) {
            return [];
        }

        $dates = [];
        $stationIds = implode(',', array_map('intval', $this->getStationIds()));
        $stmt = $db->query('SELECT stn, yyyymmdd FROM knmi WHERE stn IN (' . $stationIds . ')');

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $station = (string)$row['stn'];
            $date = (string)$row['yyyymmdd'];
            if (!isset($dates[$station])) {
                $dates[$station] = [];
            }
            $dates[$station][$date] = true;
        }

        return $dates;
    }

    private function getExistingDatesForStation(PDO $db, int $station): array {
        if (!$this->databaseTableExists($db)) {
            return [];
        }

        $dates = [];
        $stmt = $db->prepare('SELECT yyyymmdd FROM knmi WHERE stn = :station');
        $stmt->execute([':station' => $station]);

        while (($date = $stmt->fetchColumn()) !== false) {
            $dates[(string)$date] = true;
        }

        return $dates;
    }

    private function parseDataLine(string $line, ?int $expectedStation = null): ?array {
        if (!preg_match('/^\s*(\d+)\s*,\s*\d{8}\s*,/', $line, $matches)) {
            return null;
        }

        $station = (int)$matches[1];
        if ($expectedStation !== null && $station !== $expectedStation) {
            return null;
        }

        if (!KnmiStationCatalog::exists($station)) {
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

        $this->dropLegacyDateUniqueIndexes($db);
        $this->ensureStationDateUniqueIndex($db);
    }

    private function dropLegacyDateUniqueIndexes(PDO $db): void {
        $stmt = $db->query('SHOW INDEX FROM knmi');
        if (!$stmt) {
            return;
        }

        $indexes = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $keyName = (string)($row['Key_name'] ?? '');
            if ($keyName === '' || $keyName === 'PRIMARY' || (int)($row['Non_unique'] ?? 1) !== 0) {
                continue;
            }

            if (!isset($indexes[$keyName])) {
                $indexes[$keyName] = [];
            }

            $indexes[$keyName][(int)($row['Seq_in_index'] ?? 0)] = (string)($row['Column_name'] ?? '');
        }

        foreach ($indexes as $keyName => $columns) {
            ksort($columns);
            $columns = array_values($columns);
            if (count($columns) !== 1 || $columns[0] !== 'yyyymmdd') {
                continue;
            }

            try {
                $db->exec('ALTER TABLE knmi DROP INDEX `' . str_replace('`', '``', $keyName) . '`');
            } catch (Throwable $e) {
                error_log('Could not drop legacy yyyymmdd unique index ' . $keyName . ': ' . $e->getMessage());
            }
        }
    }

    private function ensureStationDateUniqueIndex(PDO $db): void {
        $stmt = $db->query("SHOW INDEX FROM knmi WHERE Key_name = 'station_date_unique'");
        if ($stmt && $stmt->fetchColumn()) {
            return;
        }

        try {
            $db->exec('ALTER TABLE knmi ADD UNIQUE KEY station_date_unique (`stn`(10), `yyyymmdd`)');
        } catch (Throwable $e) {
            error_log('Could not add station_date_unique index: ' . $e->getMessage());
        }
    }

    private function getStationDataFilePath(int $station): string {
        return $this->rootDir . '/etmgeg_' . $station . '.txt';
    }

    private function getAvailableStationFiles(?int $station = null): array {
        $files = [];

        foreach ($this->stationIdsFor($station) as $stationId) {
            $file = $this->getStationDataFilePath($stationId);
            if (is_file($file)) {
                $files[$stationId] = $file;
            }
        }

        return $files;
    }

    private function stationIdsFor(?int $station = null): array {
        if ($station === null) {
            return array_map('intval', $this->getStationIds());
        }

        if (!KnmiStationCatalog::exists($station)) {
            throw new InvalidArgumentException('Unsupported KNMI station: ' . $station);
        }

        return [(int)$station];
    }

    private function stationLabel(int $station): string {
        $name = KnmiStationCatalog::name($station);
        return ($name ?: 'Station') . ' (' . $station . ')';
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
