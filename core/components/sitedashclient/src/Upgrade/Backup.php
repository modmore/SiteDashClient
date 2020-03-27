<?php

namespace modmore\SiteDashClient\Upgrade;

use modmore\SiteDashClient\CommandInterface;
use modmore\SiteDashClient\Communication\Pusher;
use modmore\SiteDashClient\Communication\Result;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Backup implements CommandInterface {
    protected $modx;
    protected $files = [];
    protected $targetDirectory;
    /**
     * @var Pusher|null
     */
    private $pusher;

    public function __construct(\modX $modx, $pusher = null)
    {
        $this->modx = $modx;
        $this->pusher = $pusher;

        $this->files = [
            MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php',
            MODX_MANAGER_PATH . 'config.core.php',
            MODX_CONNECTORS_PATH . 'config.core.php',
            MODX_BASE_PATH . 'config.core.php',
            MODX_BASE_PATH . 'index.php',
        ];

        $optionalFiles = [
            MODX_BASE_PATH . '.htaccess',
        ];
        foreach ($optionalFiles as $file) {
            if (file_exists($file)) {
                $this->files[] = $file;
            }
        }

        $this->targetDirectory = MODX_CORE_PATH . 'export/backup_' . date('Y-m-d-His') . '_' . rand(0,9999999) . '/';
    }

    public function run()
    {
        if (!function_exists('proc_open')) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'The proc_open() function is disabled on your server. This is required for the backup to run.',
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->targetDirectory)
            ], JSON_PRETTY_PRINT);
            return;
        }
        if (!function_exists('proc_get_status')) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'The proc_get_status() function is disabled on your server. This is required to allow the status of the backup to be checked.',
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->targetDirectory)
            ], JSON_PRETTY_PRINT);
            return;
        }

        if (!$this->createDirectory($this->targetDirectory)) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Could not create the backup directory ' . str_replace(MODX_CORE_PATH, '{core_path}', $this->targetDirectory),
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->targetDirectory)
            ], JSON_PRETTY_PRINT);
            return;
        }

        // If a push result was requested, send an ack response and continue processing
        if ($this->pusher) {
            $this->pusher->acknowledge();
        }

        $result = new Result($this->pusher);

        /**
         * Include the config file to access the database information
         *
         * @var $database_type
         * @var $database_server
         * @var $database_user
         * @var $database_password
         * @var $dbase
         */
        include MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';

        $database_password = str_replace("'", '\'', $database_password);
        $password_parameter = '';
        if ($database_password !== '') {
            $password_parameter = "-p'{$database_password}'";
        }

        $targetFile = $this->targetDirectory . $dbase . '.sql';
        $finder = new ExecutableFinder();
        $mysqldump = $finder->find('mysqldump');
        if ($mysqldump === null) {
            $mysqldump = $this->modx->getOption('sitedashclient.mysqldump_binary', null, 'mysqldump', true);
        }
        $cmd = [$mysqldump, "-u{$database_user}", $password_parameter, "-h {$database_server}", $dbase];

        $excludes = ['modSession'];
        foreach ($excludes as $exclude) {
            $table = trim($this->modx->getTableName($exclude, false), '`');
            $cmd[] = "--ignore-table=\"{$dbase}.{$table}\"";
        }

        $cmd = implode(' ', $cmd) . " > {$targetFile}";

        $backupProcess = new Process($cmd);
        $backupProcess->setTimeout(120);
        $backupProcess->setIdleTimeout(120);


        try {
            $backupProcess->run();
        }
        catch (\Exception $e) {
            $msg = $e->getMessage();
            $msg = str_replace($password_parameter, '-p\'<PASS>\'', $msg);
            $trace = $e->getTraceAsString();
            $trace = str_replace($password_parameter, '-p\'<PASS>\'', $trace);

            $result(503, [
                'success' => false,
                'message' => 'Received an error trying to run mysqlbackup: ' . $msg,
                'binary' => $mysqldump,
                'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->targetDirectory),
                'output' => $trace,
            ]);
            return;
        }
        $output = $backupProcess->getErrorOutput() . ' ' . $backupProcess->getOutput();
        $output = str_replace($password_parameter, '-p\'<PASS>\'', $output);
        if (!$backupProcess->isSuccessful()) {
            $code = $backupProcess->getExitCode();
            if ($code === 127) {
                $result(503, [
                    'success' => false,
                    'message' => 'Could not find the mysqldump program on your server; please configure the sitedashclient.mysqldump_binary system setting to point to mysqldump to create backups.',
                    'binary' => $mysqldump,
                    'directory' => str_replace(MODX_CORE_PATH, '{core_path}', $this->targetDirectory),
                    'output' => $output,
                ]);
                return;
            }

            $result(503, [
                'success' => false,
                'message' => 'Received exit code ' . $code . ' trying to create a database backup using ' . $mysqldump . ' with message: ' . $output,
                'output' => $output,
                'return' => $code,
            ]);
            return;
        }

        $backupSize = filesize($targetFile);
        if ($backupSize < 150 * 1024) { // a clean install is ~ 200kb, so we ask for at least 150
            $result(503, [
                'success' => false,
                'message' => 'While the backup with ' . $mysqldump . ' did not indicate an error, the mysql backup is only ' . number_format($backupSize / 1024, 0) . 'kb in size, so it probably failed.',
                'output' => $output,
                'return' => $backupProcess->getExitCode(),
            ]);
            return;
        }

        foreach ($this->files as $source) {
            $target = $this->targetDirectory . 'files/';
            $target .= str_replace([MODX_CORE_PATH, MODX_BASE_PATH], ['core/', ''], $source);

            if (!file_exists($source)) {
                $this->modx->log(\modX::LOG_LEVEL_ERROR, '[SiteDashClient] Could not backup file '. $source . ' - it does not exist.');
                continue;
            }

            if (!$this->createDirectory(dirname($target))) {
                $this->modx->log(\modX::LOG_LEVEL_ERROR, '[SiteDashClient] Could not backup file '. $source . ' - tried creating target directory ' . $target . ' but failed.');
                continue;
            }

            if (!copy($source, $target)) {
                $this->modx->log(\modX::LOG_LEVEL_ERROR, '[SiteDashClient] Could not backup file '. $source . ' - copy failed.');
            }
        }

        $result(200, [
            'success' => true,
            'directory' => str_replace(MODX_CORE_PATH, '', $this->targetDirectory),
        ]);
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
}