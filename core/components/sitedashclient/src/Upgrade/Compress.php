<?php

namespace modmore\SiteDashClient\Upgrade;

use modmore\SiteDashClient\CommandInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class Compress implements CommandInterface {
    protected $modx;
    protected $files = [];
    protected $backupDirectory;
    protected $backupZipPath;
    private $logs = [];

    public function __construct(\modX $modx, $backupDir)
    {
        $this->modx = $modx;

        $backupDir = preg_replace(array("/\.*[\/|\\\]/i", "/[\/|\\\]+/i"), array('/', '/'), $backupDir);
        $this->backupDirectory = MODX_CORE_PATH . rtrim($backupDir, '/') . '/';
        $this->backupZipPath = MODX_CORE_PATH . rtrim($backupDir, '/') . '.zip';
        set_time_limit(90);
    }

    public function run()
    {
        if (!file_exists($this->backupDirectory) || !is_dir($this->backupDirectory)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'The provided backup directory does not exist.',
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory)
            ], JSON_PRETTY_PRINT);
            return;
        }

        $this->log('Creating backup zip file in: ' . str_replace(MODX_CORE_PATH, '{core_path}', $this->backupZipPath));

        $zip = new \ZipArchive();
        $opened = $zip->open($this->backupZipPath, \ZipArchive::CREATE);
        if ($opened !== true) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Could not open target zip file, error: ' . $opened,
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'zip' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupZipPath),
            ], JSON_PRETTY_PRINT);
            return;
        }

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->backupDirectory));

        $compressedFiles = [];
        $deleteFiles = [];
        $totalSize = 0;
        $totalFiles = 0;

        /** @var \SplFileInfo $file */
        foreach ($rii as $file) {
            if ($file->isDir()) {
                continue;
            }
            $path = $file->getPathname();
            $relativePath = str_replace($this->backupDirectory, '', $path);

            if (is_readable($path) && $zip->addFile($path, $relativePath)) {
                $totalSize += $file->getSize();
                $totalFiles++;
                $compressedFiles[] = $relativePath;
                $deleteFiles[] = $path;
            }
        }

        if (!$zip->close()) {
            http_response_code(501);
            echo json_encode([
                'success' => false,
                'message' => 'Could not close the backup zip.',
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'zip' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupZipPath),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $zipSize = @filesize($this->backupZipPath);
        if ($zipSize < 1024) {
            http_response_code(501);
            echo json_encode([
                'success' => false,
                'message' => 'Backup zip is only ' . $this->formatBytes($zipSize) . ' - something probably went wrong.',
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'zip' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupZipPath),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $this->log('Added ' . count($compressedFiles) . ' files to zip file');

        $deleted = [];
        foreach ($deleteFiles as $deleteFile) {
            // Make sure the file actually exists and is within our backupDirectory
            if (file_exists($deleteFile) && strpos($deleteFile, $this->backupDirectory) !== false) {
                if (unlink($deleteFile)) {
                    $relativePath = str_replace($this->backupDirectory, '', $deleteFile);
                    $deleted[] = $relativePath;
                }
            }
        }
        $this->log('Deleted ' . count($deleted) . ' uncompressed files');

        $this->cleanEmptyFolders($this->backupDirectory);

        $this->log('Created zip file of backup with ' . $totalFiles . ' files.');

        $totalSizeFormatted = $this->formatBytes($totalSize);
        $zipSizeFormatted = $this->formatBytes($zipSize);
        $reductionPercent = number_format(($zipSize - $totalSize) / $totalSize * 100, 0);

        $this->log("Compressed backup from {$totalSizeFormatted} to {$zipSizeFormatted} ({$reductionPercent}% savings)");

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => "Compressed backup from {$totalSizeFormatted} to {$zipSizeFormatted} ({$reductionPercent}% savings)",
            'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
            'zip' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupZipPath),
            'logs' => $this->logs,
        ], JSON_PRETTY_PRINT);
    }

    private function cleanEmptyFolders($path) {
        $empty = true;
        foreach (glob($path . DIRECTORY_SEPARATOR. '*') as $file) {
            $empty &= is_dir($file) && $this->cleanEmptyFolders($file);
        }
        if ($empty) {
            if (rmdir($path)) {
//                 This gets very verbose with little value
//                $relativeDir = str_replace($this->backupDirectory, '', $path);
//                $this->log('Deleted empty backup directory ' . $relativeDir);
            }
            return true;
        }
        return false;
    }

    private function log($msg) {
        $this->logs[] = [
            'timestamp' => time(),
            'message' => $msg,
        ];
    }

    private function formatBytes ($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, \count($units) - 1);

        // Uncomment one of the following alternatives
        $bytes /= 1024 ** $pow;

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
