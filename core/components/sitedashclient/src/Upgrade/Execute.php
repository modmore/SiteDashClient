<?php

namespace modmore\SiteDashClient\Upgrade;

use DOMDocument;
use Exception;
use modmore\SiteDashClient\CommandInterface;
use modX;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

class Execute implements CommandInterface {
    protected $modx;
    protected $files = [];
    protected $backupDirectory;
    protected $downloadUrl = '';
    private $logs = [];

    public function __construct(modX $modx, $backupDir, $targetVersion, $nightly)
    {
        $this->modx = $modx;

        $backupDir = preg_replace(array("/\.*[\/|\\\]/i", "/[\/|\\\]+/i"), array('/', '/'), $backupDir);
        $this->backupDirectory = MODX_CORE_PATH . rtrim($backupDir, '/') . '/';
        if ($nightly) {
            $this->downloadUrl = 'https://modx.s3.amazonaws.com/releases/nightlies/' . $targetVersion . '.zip';
        }
        else {
            $this->downloadUrl = 'https://modx.com/download/direct/modx-' . urlencode($targetVersion) . '.zip';
        }
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

        if ($this->downloadUrl === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Download URL not provided.',
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory)
            ], JSON_PRETTY_PRINT);
            return;
        }

        if (!class_exists(ZipArchive::class)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'PHP is compiled without the Zip extension; the upgrade wont be able of unzipping the MODX download.',
            ], JSON_PRETTY_PRINT);
            return;
        }

        $this->log('Need to revert the upgrade? A backup of the database and files are stored in: ' . str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory));

        $phpBinaryFinder = new PhpExecutableFinder();
        $phpExecutable = $phpBinaryFinder->find();
        if (!$phpExecutable) {
            $configuredExecutable = (string)$this->modx->getOption('sitedashclient.php_binary', null, '');
            if (!empty($configuredExecutable) && stripos($configuredExecutable, 'php') !== false) {
                $phpExecutable = trim($configuredExecutable);
                $this->log('Could not find PHP executable; falling back to configured binary `' . $configuredExecutable . '`');
            }
            else {
                $this->log('Could not find PHP executable; falling back to default `php`');
                $phpExecutable = 'php';
            }
        }
        $this->log('Testing PHP executable: `' . $phpExecutable . ' --version`');

        $process = new Process([$phpExecutable, '--version']);
        $process->setTimeout(120);
        $process->setIdleTimeout(120);
        try {
            $process->run();
        } catch (Exception $e) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Received an error trying to run command "' . $process->getCommandLine() . '": ' . $e->getMessage(),
                'output' => $e->getTraceAsString(),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        if (!$process->isSuccessful()) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Received an error checking the PHP version with command: ' . $process->getCommandLine(),
                'output' => $process->getOutput() . '/' . $process->getErrorOutput(),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $this->log('PHP Version: ' . $process->getOutput());

        $downloadTarget = $this->backupDirectory . 'download/';
        $this->createDirectory($downloadTarget);
        $zipTarget = $downloadTarget . 'modx.zip';
        $this->log('Downloading MODX from ' . $this->downloadUrl);

        $fp = fopen ($zipTarget, 'w+');
        $ch = curl_init($this->downloadUrl);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        /**
         * Support proxies if configured in MODX
         */
        $proxyHost = $this->modx->getOption('proxy_host');
        if (!empty($proxyHost)) {
            $this->log('Downloading through configured proxy "' . $proxyHost . '"');
            curl_setopt($ch, CURLOPT_PROXY, $proxyHost);
            $proxyPort = $this->modx->getOption('proxy_port',null,'');
            if (!empty($proxyPort)) {
                curl_setopt($ch, CURLOPT_PROXYPORT, $proxyPort);
            }
            $proxyUserpwd = $this->modx->getOption('proxy_username',null,'');
            if (!empty($proxyUserpwd)) {
                $proxyAuthType = $this->modx->getOption('proxy_auth_type',null,'BASIC');
                $proxyAuthType = $proxyAuthType === 'NTLM' ? CURLAUTH_NTLM : CURLAUTH_BASIC;
                curl_setopt($ch, CURLOPT_PROXYAUTH, $proxyAuthType);

                $proxyPassword = $this->modx->getOption('proxy_password',null,'');
                if (!empty($proxyPassword)) {
                    $proxyUserpwd .= ':' . $proxyPassword;
                }
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyUserpwd);
            }
        }

        /**
         * Do the download
         */
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        if (!file_exists($zipTarget) || filesize($zipTarget) < (5 * 1024 * 1024)) { // a zip file < 5mb is definitely not valid
            http_response_code(501);
            echo json_encode([
                'success' => false,
                'message' => 'Failed downloading MODX zip.',
                'backupDirectory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'downloadUrl' => $this->downloadUrl,
                'modxDownload' => str_replace(MODX_CORE_PATH, '{core_path}', $zipTarget),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $this->log('MODX downloaded!');
        $this->log('Unzipping download...');

        $zip = new ZipArchive();
        if ($zip->open($zipTarget) !== true) {
            http_response_code(501);
            echo json_encode([
                'success' => false,
                'message' => 'Failed opening MODX zip file.',
                'backupDirectory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'downloadUrl' => $this->downloadUrl,
                'modxDownload' => str_replace(MODX_CORE_PATH, '{core_path}', $zipTarget),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);

            return;
        }

        if (!$zip->extractTo($downloadTarget)) {
            http_response_code(501);
            echo json_encode([
                'success' => false,
                'message' => 'Failed unzipping MODX zip.',
                'backupDirectory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'downloadUrl' => $this->downloadUrl,
                'modxDownload' => str_replace(MODX_CORE_PATH, '{core_path}', $zipTarget),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        unlink($zipTarget);

        $this->log('Unzipped files, pre-processing files... ');

        // Find the root folder - these are named `modx-2.6.5-pl` for example inside the zip
        $rootFolder = false;
        $rootFolderCandidates = array_diff(scandir($downloadTarget, SCANDIR_SORT_ASCENDING), ['.', '..']);
        foreach ($rootFolderCandidates as $candidate) {
            if (is_dir($downloadTarget . $candidate) && strpos($candidate, 'modx-') !== false) {
                $rootFolder = $candidate;
                break;
            }
        }
        if (empty($rootFolder) || !is_dir($downloadTarget . $rootFolder)) {
            http_response_code(501);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to locate root folder from zip; found "' . $rootFolder . '" but is not a directory',
                'backupDirectory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'downloadUrl' => $this->downloadUrl,
                'modxDownload' => str_replace(MODX_CORE_PATH, '{core_path}', $zipTarget),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $rootFolder .= '/';
        $files = array_diff(scandir($downloadTarget . $rootFolder, SCANDIR_SORT_ASCENDING), ['.', '..']);

        foreach ($files as $dlFile) {
            if (!rename($downloadTarget . $rootFolder . $dlFile, $downloadTarget . $dlFile)) {
                $this->log('Failed moving ' . $downloadTarget . $rootFolder . $dlFile . ' => ' . $downloadTarget . $dlFile);
            }
        }

        if (!rmdir($downloadTarget . $rootFolder)) {
            $this->log('Failed removing directory ' . $downloadTarget . $rootFolder);
        }

        $this->log('Processing files... ');

        $backupFiles = [];
        $overwrittenFiles = [];
        $createdFiles = [];
        $skippedFiles = [];

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($downloadTarget));

        /** @var SplFileInfo $dlFile */
        foreach ($rii as $dlFile) {
            if ($dlFile->isDir()) {
                continue;
            }
            $dlPath = $dlFile->getPathname();
            $dlPathClean = str_replace($downloadTarget, '', $dlPath);
            $dlPathParts = explode('/', $dlPathClean);
            $dlPathFirstPart = $dlPathParts[0];
            array_shift($dlPathParts);
            $dlPathSecondPart = implode('/', $dlPathParts);
            switch ($dlPathFirstPart) {
                case 'manager':
                    $targetPath = MODX_MANAGER_PATH . $dlPathSecondPart;
                    break;
                case 'core':
                    $targetPath = MODX_CORE_PATH . $dlPathSecondPart;
                    break;
                case 'connectors':
                    $targetPath = MODX_CONNECTORS_PATH . $dlPathSecondPart;
                    break;
                case 'setup':
                    $targetPath = MODX_BASE_PATH . 'setup/' . $dlPathSecondPart;
                    break;
                default:
                    $targetPath = MODX_BASE_PATH . $dlPathClean;
                    break;
            }

            if (file_exists($targetPath)) {
                $targetHash = hash_file('sha256', $targetPath);
                $dlHash = hash_file('sha256', $dlPath);
                if ($targetHash !== $dlHash) {
                    $backupFiles[] = $dlPathClean;
                    $backupPath = $this->backupDirectory . 'files/' . str_replace([MODX_CORE_PATH, MODX_BASE_PATH], ['core/', ''], $targetPath);
                    $this->createDirectory(dirname($backupPath));
                    if (!copy($targetPath, $backupPath)) {
                        $this->log('Could not copy file to backup: ' . $targetPath);
                    }

                    // Handle the file - skip it, or copy it
                    if ($this->shouldFileBeSkipped($dlPathClean)) {
                        $skippedFiles[] = $dlPathClean;
                        unlink($dlPath);
                    }
                    elseif (copy($dlPath, $targetPath)) {
                        $overwrittenFiles[] = $dlPathClean;
                        unlink($dlPath);
                    }
                    else {
                        $this->log('Failed overwriting ' . $targetPath . ' with contents of downloaded ' . $dlPathClean);
                    }
                }
                // downloaded + target file are the same
                else {
                    unlink($dlPath);
                }
            }
            // If the file doesn't currently exist, write it
            else {
                $this->createDirectory(dirname($targetPath));
                if (copy($dlPath, $targetPath)) {
                    $createdFiles[] = $dlPathClean;
                }
                else {
                    $this->log('Failed writing ' . $targetPath . ' with contents of downloaded ' . $dlPathClean);
                }
            }
        }

        if (count($backupFiles) > 0) {
            $this->log('Backed up files: ' . implode(' | ', $backupFiles));
        }
        if (count($skippedFiles) > 0) {
            $this->log('Skipped overwriting files: ' . implode(' | ', $skippedFiles));
        }
        if (count($overwrittenFiles) > 0) {
            $this->log('Overwritten files: ' . implode(' | ', $overwrittenFiles));
        }
        if (count($createdFiles) > 0) {
            $this->log('Created files: ' . implode(' | ', $createdFiles));
        }

        $this->log('Completed pre-processing files and backing up files that changed.');
        $this->log('Creating configuration file for running setup...');

        $config = array(
            'inplace' => 1,
            'unpacked' => 0,
            'language' => $this->modx->getOption('manager_language'),
            'core_path' => MODX_CORE_PATH,
            'remove_setup_directory' => true
        );
        $xml = new DOMDocument('1.0', 'utf-8');
        $modx = $xml->createElement('modx');
        foreach ($config as $key => $value) {
            $modx->appendChild($xml->createElement($key, $value));
        }
        $xml->appendChild($modx);

        $configFile = $this->backupDirectory . 'modx-setup.xml';
        $fh = fopen($configFile, 'wb+');
        fwrite($fh, $xml->saveXML());
        fclose($fh);

        $tz = date_default_timezone_get();
        $wd = MODX_BASE_PATH;
        $corePath = MODX_CORE_PATH;
        $configKey = MODX_CONFIG_KEY;

        $setupProcess = new Process([
            $phpExecutable,
            "-d date.timezone={$tz}",
            "{$wd}setup/index.php",
            '--installmode=upgrade',
            "--config={$configFile}",
            "--core_path={$corePath}",
            "--config_key={$configKey}",
        ]);


        $this->log('Running setup with command: ' . $setupProcess->getCommandLine());

        try {
            $setupProcess->run();
        } catch (Exception $e) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Received an error trying to run command "' . $setupProcess->getCommandLine() . '": ' . $e->getMessage(),
                'output' => $e->getTraceAsString(),
                'backupDirectory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'downloadUrl' => $this->downloadUrl,
                'modxDownload' => str_replace(MODX_CORE_PATH, '{core_path}', $zipTarget),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }
        if ($setupProcess->isSuccessful()) {
            $this->log('Successfully executed the setup. ' . $setupProcess->getOutput());
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'output' => $setupProcess->getOutput(),
                'return' => $setupProcess->getExitCode(),
                'backupDirectory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'downloadUrl' => $this->downloadUrl,
                'modxDownload' => str_replace(MODX_CORE_PATH, '{core_path}', $zipTarget),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        http_response_code(501);
        echo json_encode([
            'success' => false,
            'message' => 'Received exit code ' . $setupProcess->getExitCode() . ' running the setup with error: ' . $setupProcess->getErrorOutput(),
            'output' => $setupProcess->getOutput(),
            'error_output' => $setupProcess->getErrorOutput(),
            'backupDirectory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
            'downloadUrl' => $this->downloadUrl,
            'modxDownload' => str_replace(MODX_CORE_PATH, '{core_path}', $zipTarget),
            'logs' => $this->logs,
        ], JSON_PRETTY_PRINT);
    }

    private function createDirectory($target)
    {
        if (file_exists($target) && is_dir($target)) {
            return true;
        }
        if (!mkdir($target, 0755, true) && !is_dir($target)) {
            return false;
        }
        return true;
    }

    private function log($msg) {
        $this->logs[] = [
            'timestamp' => time(),
            'message' => $msg,
        ];
    }

    private function shouldFileBeSkipped($filePath)
    {
        return strpos($filePath, 'config.core.php') !== false;
    }
}