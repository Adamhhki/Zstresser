<?php
declare(strict_types=1);

require_once __DIR__ . '/downloader.php';

$storageDir = getenv('YTMP3_DATA_DIR') ?: __DIR__ . '/storage';
$jobsDir = $storageDir . '/jobs';
$logFile = $storageDir . '/worker.log';
$pollUs = (int)(getenv('WORKER_POLL_MS') ?: 1200) * 1000;
$staleS = 15 * 60;
$lockStaleS = 20 * 60;
$maxJobAgeS = 24 * 3600; // 24 hours max retention
$completedRetentionS = 3600; // 1 hour after completion/failure
$stopping = false;

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function() use (&$stopping) { $stopping = true; });
    pcntl_signal(SIGTERM, function() use (&$stopping) { $stopping = true; });
}

function writeLog(string $message): void {
    global $logFile;
    $timestamp = gmdate('Y-m-d H:i:s');
    $line = "[{$timestamp} UTC] {$message}\n";
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

writeLog("Starting PHP worker...");
if (!is_dir($jobsDir) && !mkdir($jobsDir, 0700, true) && !is_dir($jobsDir)) {
    writeLog("Could not create jobs directory: {$jobsDir}");
    exit(1);
}

writeLog("Watching {$jobsDir}");

$cleanupCounter = 0;

while (!$stopping) {
    try {
        $files = glob($jobsDir . '/*.json') ?: [];
        
        // Run cleanup every ~50 loops to avoid disk overhead on every tick
        if (++$cleanupCounter >= 50) {
            cleanupOldJobs($files, $maxJobAgeS, $completedRetentionS);
            $cleanupCounter = 0;
        }

        recoverStaleJobs($files, $staleS, $lockStaleS);

        $queued = [];
        foreach ($files as $file) {
            $job = readJob($file);
            if ($job && ($job['status'] ?? '') === 'queued') {
                $queued[] = ['file' => $file, 'createdAt' => $job['createdAt'] ?? ''];
            }
        }

        if (!empty($queued)) {
            usort($queued, fn($a, $b) => strcmp($a['createdAt'], $b['createdAt']));
            processJob($queued[0]['file']);
        } else {
            usleep($pollUs);
        }
    } catch (Exception $e) {
        writeLog("Loop error: " . $e->getMessage());
        sleep(2);
    }
}

writeLog("Stopped.");

function readJob(string $file): ?array {
    $content = @file_get_contents($file);
    if ($content === false) return null;
    return json_decode($content, true);
}

function writeJob(string $file, array $job): void {
    $job['updatedAt'] = gmdate('c');
    $temporary = $file . '.' . getmypid() . '.' . microtime(true) . '.tmp';
    $json = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($temporary, $json) === false) {
        throw new Exception("Could not write temporary job file: $temporary");
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $file)) {
        @unlink($temporary);
        throw new Exception("Could not rename temporary job file to: $file");
    }
}

function cleanupOldJobs(array $files, int $maxAge, int $completedRetention): void {
    $now = time();
    foreach ($files as $file) {
        $job = readJob($file);
        if (!$job) {
            // If file is unreadable or corrupt and older than 1 hour, remove it
            if ($now - @filemtime($file) > 3600) {
                @unlink($file);
            }
            continue;
        }

        $createdAt = strtotime($job['createdAt'] ?? 'now');
        $updatedAt = strtotime($job['updatedAt'] ?? $job['createdAt'] ?? 'now');
        $status = $job['status'] ?? '';

        // Remove if older than absolute max age
        if ($now - $createdAt > $maxAge) {
            @unlink($file);
            @unlink(str_replace('.json', '.lock', $file));
            continue;
        }

        // Remove if completed or failed and past retention period
        if (in_array($status, ['completed', 'failed'], true) && ($now - $updatedAt > $completedRetention)) {
            @unlink($file);
            @unlink(str_replace('.json', '.lock', $file));
        }
    }
}

function recoverStaleJobs(array $files, int $staleS, int $lockStaleS): void {
    $now = time();
    foreach ($files as $file) {
        $job = readJob($file);
        if (!$job || ($job['status'] ?? '') !== 'processing') continue;

        $lastUpdate = strtotime($job['updatedAt'] ?? $job['createdAt'] ?? 'now');
        if ($now - $lastUpdate < $staleS) continue;

        $lockFile = str_replace('.json', '.lock', $file);
        if (file_exists($lockFile)) {
            if ($now - filemtime($lockFile) < $lockStaleS) continue;
            @unlink($lockFile);
        }

        $lock = @fopen($lockFile, 'x');
        if ($lock) {
            $fresh = readJob($file);
            if ($fresh && ($fresh['status'] ?? '') === 'processing') {
                $fresh['status'] = 'queued';
                $fresh['error'] = '';
                writeJob($file, $fresh);
                writeLog("Requeued stale job {$fresh['id']}");
            }
            fclose($lock);
            @unlink($lockFile);
        }
    }
}

function processJob(string $file): void {
    $lockFile = str_replace('.json', '.lock', $file);
    $lock = @fopen($lockFile, 'x');
    if (!$lock) return;

    try {
        $job = readJob($file);
        if (!$job || ($job['status'] ?? '') !== 'queued') {
            fclose($lock);
            @unlink($lockFile);
            return;
        }

        $job['status'] = 'processing';
        $job['error'] = '';
        writeJob($file, $job);
        writeLog("Processing {$job['id']} ({$job['format']}) for URL: {$job['url']}");

        try {
            $result = ytdownloader($job['url'], $job['format']);
            
            $job = readJob($file) ?: $job;
            $job['status'] = 'completed';
            $job['title'] = mb_substr($result['title'] ?: 'Your file is ready', 0, 300);
            $job['downloadURL'] = $result['downloadURL'];
            $job['error'] = '';
            writeJob($file, $job);
            writeLog("Completed {$job['id']}");
        } catch (Exception $e) {
            $job = readJob($file) ?: $job;
            $job['status'] = 'failed';
            $job['error'] = preg_replace('/[\r\n\t]+/', ' ', substr($e->getMessage(), 0, 260));
            $job['downloadURL'] = '';
            writeJob($file, $job);
            writeLog("Job failed [{$job['id']}]: " . $e->getMessage());
        }

    } finally {
        if (is_resource($lock)) fclose($lock);
        @unlink($lockFile);
    }
}
