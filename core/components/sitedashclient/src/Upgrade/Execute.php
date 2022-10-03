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
    protected $logFileName = '';
    private $logs = [];
    private $targetVersion;

    public function __construct(modX $modx, $backupDir, $targetVersion, $nightly)
    {
        $this->modx = $modx;
        $this->logFileName = 'sitedash_upgrade_' . date('Y-m-d_His') . '.log';
        $this->targetVersion = $targetVersion;

        $this->modx->setLogLevel(modX::LOG_LEVEL_INFO);
        $this->modx->setLogTarget([
            'target' => 'FILE',
            'options' => [
                'filename' => $this->logFileName,
            ]
        ]);
        $this->modx->log(modX::LOG_LEVEL_INFO, 'Initialising remote SiteDash upgrade');

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
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Failed to resolve backup directory ' . $this->backupDirectory);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'The provided backup directory does not exist.',
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory)
            ], JSON_PRETTY_PRINT);
            return;
        }

        if ($this->downloadUrl === '') {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Failed to resolve download url from SiteDash request');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Download URL not provided.',
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory)
            ], JSON_PRETTY_PRINT);
            return;
        }

        if (!class_exists(ZipArchive::class)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Zip extension not installed.');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'PHP is compiled without the Zip extension; the upgrade wont be able of unzipping the MODX download.',
            ], JSON_PRETTY_PRINT);
            return;
        }

        $this->log('Backup of the database and changed files can be found in ' . str_replace(MODX_CORE_PATH, '{core_path}', rtrim($this->backupDirectory, '/')));
        $this->log('Verbose upgrade log can be found in {core_path}logs/' . $this->logFileName);

        $configuredExecutable = trim((string)$this->modx->getOption('sitedashclient.php_binary', null, ''));
        if (!empty($configuredExecutable) && stripos($configuredExecutable, 'php') !== false) {
            $phpExecutable = $configuredExecutable;
            $this->log('Using user-configured PHP binary `' . $configuredExecutable . '`', modX::LOG_LEVEL_WARN);
        }
        else {
            $phpBinaryFinder = new PhpExecutableFinder();
            $phpExecutable = $phpBinaryFinder->find();
            if (!$phpExecutable) {
                $phpExecutable = 'php';
                $this->log('Could not find PHP executable; falling back to default `php`', modX::LOG_LEVEL_WARN);
            }
            else {
                $this->log('Using automatically detected `' . $phpExecutable . '`', modX::LOG_LEVEL_INFO);
            }
        }
        $this->log('Testing PHP executable: `' . $phpExecutable . ' --version`', modX::LOG_LEVEL_INFO);

        $process = new Process([$phpExecutable, '--version']);
        $process->setTimeout(10);
        $process->setIdleTimeout(10);
        try {
            $process->run();
        } catch (Exception $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, get_class($e) . ' attempting to check PHP version: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Received an error trying to run command "' . $process->getCommandLine() . '": ' . $e->getMessage(),
                'output' => $e->getTraceAsString(),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $output = $process->getOutput();

        $this->log('PHP Version check: ' . $output, modX::LOG_LEVEL_INFO);

        if (!$process->isSuccessful()) {
            http_response_code(503);
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'PHP version check unsuccessful');
            echo json_encode([
                'success' => false,
                'message' => 'Received an error checking the PHP version with command: ' . $process->getCommandLine(),
                'output' => $output . ' // ' . $process->getErrorOutput(),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        // Make sure we get an expected PHP version
        if (preg_match("/PHP (\d+.\d+.\d+)/i", $output, $matches)) {
            $v = $matches[1] ?? '';
            $min = version_compare($this->targetVersion, '3.0.0-dev1', '>=')
                ? '7.2.0'
                : '5.6.0';
            if (!$v || version_compare($v, $min, '<')) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, 'PHP version check returned version  "' . $v . '" which is not valid or older than the required minimum of ' . $min);
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'message' => 'Unable to run PHP in command line mode, `' . $process->getCommandLine() . '` returned version number "' . $v . '" which is either invalid or below the minimum required to install MODX ('.$min.').',
                    'output' => $output . ' // ' . $process->getErrorOutput(),
                    'logs' => $this->logs,
                    'errcode' => 'php-invalid-or-eol',
                ], JSON_PRETTY_PRINT);
                return;
            }
        }
        else {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'PHP version check did not return the PHP version, suggests an invalid binary.');
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Unable to run PHP in command line mode, `' . $process->getCommandLine() . '` did not return valid output.',
                'output' => $output . ' // ' . $process->getErrorOutput(),
                'logs' => $this->logs,
                'errcode' => 'php-no-bin',
            ], JSON_PRETTY_PRINT);
            return;
        }

        $this->modx->log(modX::LOG_LEVEL_INFO, 'Pre-install checks successful.');

        $downloadTarget = $this->backupDirectory . 'download/';
        $this->createDirectory($downloadTarget);
        $zipTarget = $downloadTarget . 'modx.zip';
        $this->log('Downloading MODX from ' . $this->downloadUrl);

        $this->modx->log(modX::LOG_LEVEL_INFO, 'Downloading MODX from ' . $this->downloadUrl . ' to ' . $downloadTarget);

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
            $this->log('Downloading through configured proxy "' . $proxyHost . '"', modX::LOG_LEVEL_INFO);
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
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Download failed');
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
        $this->modx->log(modX::LOG_LEVEL_INFO, 'Download complete, unzipping with ZipArchive...');

        $zip = new ZipArchive();
        if ($zip->open($zipTarget) !== true) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Failed opening ' . $zipTarget . ' with ZipArchive');
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
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Failed extracting ' . $zipTarget . ' to ' . $downloadTarget . ' with ZipArchive');
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
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Failed to find root folder in the zip or ' . $downloadTarget . $rootFolder . ' is not a directory');
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

        $this->log('Moving files out of download directory... ', modX::LOG_LEVEL_INFO);

        $rootFolder .= '/';
        $files = array_diff(scandir($downloadTarget . $rootFolder, SCANDIR_SORT_ASCENDING), ['.', '..']);

        foreach ($files as $dlFile) {
            if (!rename($downloadTarget . $rootFolder . $dlFile, $downloadTarget . $dlFile)) {
                $this->log('Failed moving ' . $downloadTarget . $rootFolder . $dlFile . ' => ' . $downloadTarget . $dlFile, modX::LOG_LEVEL_ERROR);
            }
        }

        if (!rmdir($downloadTarget . $rootFolder)) {
            $this->log('Failed removing directory ' . $downloadTarget . $rootFolder, modX::LOG_LEVEL_ERROR);
        }

        $this->log('Processing files... ', modX::LOG_LEVEL_INFO);

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
                    $backupPath = $this->backupDirectory . 'files/' . str_replace([MODX_CORE_PATH, MODX_BASE_PATH], ['core/', ''], $targetPath);
                    $this->createDirectory(dirname($backupPath));

                    if (copy($targetPath, $backupPath)) {
                        $backupFiles[] = $dlPathClean;
                        $this->modx->log(modX::LOG_LEVEL_INFO, 'Backed up original ' . $targetPath);
                    }
                    else {
                        $this->log('Could not copy file to backup: ' . $targetPath, modX::LOG_LEVEL_ERROR);
                    }

                    // Handle the file - skip it, or copy it
                    if ($this->shouldFileBeSkipped($dlPathClean)) {
                        $this->modx->log(modX::LOG_LEVEL_INFO, 'Skipped file ' . $dlPathClean);
                        $skippedFiles[] = $dlPathClean;
                        unlink($dlPath);
                    }
                    elseif (copy($dlPath, $targetPath)) {
                        $this->modx->log(modX::LOG_LEVEL_INFO, 'Overwrite file ' . $targetPath . ' from ' . $dlPathClean);
                        $overwrittenFiles[] = $dlPathClean;
                        unlink($dlPath);
                    }
                    else {
                        $this->log('Failed overwriting ' . $targetPath . ' with contents of downloaded ' . $dlPathClean, modX::LOG_LEVEL_ERROR);
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
                    $this->modx->log(modX::LOG_LEVEL_INFO, 'Created file ' . $targetPath . ' from ' . $dlPathClean);
                    $createdFiles[] = $dlPathClean;
                    unlink($dlPath);
                }
                else {
                    $this->log('Failed writing ' . $targetPath . ' with contents of downloaded ' . $dlPathClean, modX::LOG_LEVEL_ERROR);
                }
            }
        }

        if (count($backupFiles) > 0) {
            $this->log('Backed up ' . count($backupFiles) . ' file(s)');
        }
        if (count($skippedFiles) > 0) {
            $this->log('Skipped ' . count($skippedFiles) . ' file(s)');
        }
        if (count($overwrittenFiles) > 0) {
            $this->log('Overwritten ' . count($overwrittenFiles) . ' file(s)');
        }
        if (count($createdFiles) > 0) {
            $this->log('Created ' . count($createdFiles) . ' new file(s)');
        }

        $this->log('Completed pre-processing files and backing up files that changed.', modX::LOG_LEVEL_INFO);
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

        $this->modx->log(modX::LOG_LEVEL_INFO, 'Created setup XML: ' . file_get_contents($this->backupDirectory . 'modx-setup.xml'));

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
        $setupProcess->setTimeout(90);

        $this->log('Running setup with command: ' . $setupProcess->getCommandLine(), modX::LOG_LEVEL_INFO);

        try {
            $setupProcess->run();
        } catch (Exception $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, get_class($e) . ' running setup: ' . $e->getMessage() . ' // '. $e->getTraceAsString());
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

        $output = $setupProcess->getOutput();
        $this->log('Setup result: ' . $output, modX::LOG_LEVEL_INFO);

        if ($setupProcess->isSuccessful() && strpos($output, 'Installation finished in') === 0) {
            $this->modx->log(modX::LOG_LEVEL_INFO, 'Setup appears to have been successful.');
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'output' => $output,
                'return' => $setupProcess->getExitCode(),
                'backupDirectory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->backupDirectory),
                'downloadUrl' => $this->downloadUrl,
                'modxDownload' => str_replace(MODX_CORE_PATH, '{core_path}', $zipTarget),
                'logs' => $this->logs,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $errorOutput = $setupProcess->getErrorOutput();
        $this->modx->log(modX::LOG_LEVEL_ERROR, 'Setup appears to have failed with exit code ' . $setupProcess->getExitCode());
        http_response_code(501);
        echo json_encode([
            'success' => false,
            'message' => 'Failed running the setup: ' . $output . ' ' . $errorOutput . ' ( code ' . $setupProcess->getExitCode() . ')',
            'output' => $output,
            'error_output' => $errorOutput,
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

    private function log($msg, $logToMODX = 0) {
        $this->logs[] = [
            'timestamp' => time(),
            'message' => $msg,
        ];
        if ($logToMODX > 0) {
            $this->modx->log($logToMODX, $msg);
        }
    }

    private function shouldFileBeSkipped($filePath)
    {
        return strpos($filePath, 'config.core.php') !== false;
    }
}
