<?php

namespace modmore\SiteDashClient\Package;

use modmore\SiteDashClient\CommandInterface;

class Revert implements CommandInterface
{
    protected $modx;
    protected $packageSignature = '';
    protected $log = [];
    /** @var \modTransportPackage */
    protected $package;
    /** @var \modTransportProvider */
    protected $provider;

    public function __construct(\modX $modx, $signature = '')
    {
        $this->modx = $modx;
        $this->packageSignature = $signature;
    }

    public function run()
    {
        $logs = [];
        $this->modx->setLogLevel(\modX::LOG_LEVEL_INFO);
        $this->modx->setLogTarget([
            'target' => 'ARRAY_EXTENDED',
            'options' => [
                'var' => &$logs,
            ]
        ]);
        try {
            $this->getVersionToRevert();
            $this->revert();
        } catch (\Exception $e) {
            $this->_copyLogs($logs);
            $this->log('[ERROR] ' . $e->getMessage());
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'log' => $this->log,
            ]);
            exit();
        }

        $this->_copyLogs($logs);
        $return = [
            'success' => true,
            'signature' => $this->_signature,
            'log' => $this->log,
        ];

        http_response_code(200);
        echo json_encode($return, JSON_PRETTY_PRINT);
    }

    protected function getVersionToRevert() {
        if ($this->packageSignature === '') {
            throw new \RuntimeException('Empty package name');
        }

        $this->package = $this->modx->getObject('transport.modTransportPackage', [
            'signature' => $this->packageSignature,
        ]);

        if (!$this->package) {
            throw new \RuntimeException('Package not found.');
        }
        $this->log('Found package ' . $this->package->get('signature'));

        $this->provider =& $this->package->getOne('Provider');
        if (!$this->provider) {
            throw new \RuntimeException('Package does not have an associated package provider; can\'t update.');
        }
    }

    protected function install()
    {
        $this->log('Uninstalling ' . $this->package->get('signature') . '...');
        $reverted = $this->package->uninstall([
            \xPDOTransport::PREEXISTING_MODE => \xPDOTransport::RESTORE_PREEXISTING,
        ]);
        $this->modx->cacheManager->refresh(array($this->modx->getOption('cache_packages_key', null, 'packages') => array()));
        $this->modx->cacheManager->refresh();

        if (!$reverted) {
            throw new \RuntimeException('Failed to uninstall package');
        }

        $this->log('Uninstallation of latest version successful!');

        $this->modx->invokeEvent('OnPackageUninstall', array(
            'package' => $this->package,
        ));
    }

    protected function log($msg) {
        $this->log[] = [
            'timestamp' => time(),
            'message' => $msg,
        ];
    }

    /**
     * Copies logs received from the MODX log handler into the log format the server expects
     *
     * @param $logs
     */
    private function _copyLogs(array $logs)
    {
        foreach ($logs as $log) {
            $msg = $log['msg'];
            $msg = str_replace([MODX_CORE_PATH, MODX_BASE_PATH], ['{core_path}', '{base_path}'], $msg);
            $this->log("[{$log['level']}] {$msg}");
        }
    }
}
