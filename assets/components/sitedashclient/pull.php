<?php
/**
 * @var modX $modx
 */

$siteKey = array_key_exists('HTTP_X_SITEDASH_SITEKEY', $_SERVER) && !empty($_SERVER['HTTP_X_SITEDASH_SITEKEY']) ? $_SERVER['HTTP_X_SITEDASH_SITEKEY'] : false;
$signature = array_key_exists('HTTP_X_SITEDASH_SIGNATURE', $_SERVER) && !empty($_SERVER['HTTP_X_SITEDASH_SIGNATURE']) ? $_SERVER['HTTP_X_SITEDASH_SIGNATURE'] : false;

// Make sure we have the site key and signature before even bothering continuing
if (!$siteKey || !$signature) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Missing authentication.']);
    @session_write_close();
    exit();
}

// Load up MODX
define ('MODX_REQP', false);
require_once dirname(dirname(dirname(__DIR__))) . '/config.core.php';
require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx = new modX();
$modx->initialize('mgr');
$modx->getService('error','error.modError', '', '');

// Get the SiteDashClient service class
$corePath = $modx->getOption('sitedashclient.core_path',null,$modx->getOption('core_path').'components/sitedashclient/') . 'model/sitedashclient/';
/** @var SiteDashClient $sdc */
$sdc = $modx->getService('sitedashclient', 'SiteDashClient', $corePath);

if (!($sdc instanceof SiteDashClient)) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[SiteDashClient pull] Unable to load SiteDashClient service');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Couldn\'t load service.']);
    @session_write_close();
    exit();
}

if (!$sdc->isValidRequest($siteKey, $signature, $_POST)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid authentication.']);
    @session_write_close();
    exit();
}

// Make sure the params are sanitized
$params = $modx::sanitize($_POST);

switch ($params['request']) {
    case 'system':
    case 'system/refresh':
        $dataCommand = new \modmore\SiteDashClient\Refresh($modx, $params);
        $dataCommand->run();
        break;

    case 'errorlog':
        $errorLog = new \modmore\SiteDashClient\DownloadErrorLog($modx);
        $errorLog->run();
        break;

    case 'system/databasecheck':
        $cmd = new \modmore\SiteDashClient\System\DatabaseCheck($modx);
        $cmd->run();
        break;

    case 'system/repairtable':
        $cmd = new \modmore\SiteDashClient\System\RepairTable($modx, $params['params']);
        $cmd->run();
        break;

    case 'system/files':
        $cmd = new \modmore\SiteDashClient\System\Files($modx, $params['params']);
        $cmd->run();
        break;

    case 'system/clearcache':
        $force = isset($params['params']['force']) && !empty($params['params']['force']) ? (bool)$params['params']['force'] : '';
        $cmd = new \modmore\SiteDashClient\System\ClearCache($modx, $force);
        $cmd->run();
        break;

    case 'system/status':
        $cmd = new \modmore\SiteDashClient\System\Status($modx);
        $cmd->run();
        break;

    case 'package/update':
        $package = isset($params['params']['package']) && !empty($params['params']['package']) ? (string)$params['params']['package'] : '';
        $cmd = new \modmore\SiteDashClient\Package\Update($modx, $package);
        $cmd->run();
        break;


    case 'upgrade/backup':
        $cmd = new \modmore\SiteDashClient\Upgrade\Backup($modx);
        $cmd->run();
        break;

    case 'upgrade/execute':
        $backupDir = isset($params['params']['backup']) && !empty($params['params']['backup']) ? (string)$params['params']['backup'] : '';
        $targetVersion = isset($params['params']['target_version']) && !empty($params['params']['target_version']) ? (string)$params['params']['target_version'] : false;
        $nightly = isset($params['params']['nightly']) ? (bool)$params['params']['nightly'] : false;
        $cmd = new \modmore\SiteDashClient\Upgrade\Execute($modx, $backupDir, $targetVersion, $nightly);
        $cmd->run();
        break;

    case 'upgrade/compress':
        $backupDir = isset($params['params']['backup']) && !empty($params['params']['backup']) ? (string)$params['params']['backup'] : '';
        $cmd = new \modmore\SiteDashClient\Upgrade\Compress($modx, $backupDir);
        $cmd->run();
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Unsupported request.'
        ], JSON_PRETTY_PRINT);
        break;
}
@session_write_close();
exit();
