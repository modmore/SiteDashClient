<?php
/**
 * @var modX $modx
 */

$siteKey = array_key_exists('HTTP_X_SITEDASH_SITEKEY', $_SERVER) && !empty($_SERVER['HTTP_X_SITEDASH_SITEKEY']) ? $_SERVER['HTTP_X_SITEDASH_SITEKEY'] : false;
$signature = array_key_exists('HTTP_X_SITEDASH_SIGNATURE', $_SERVER) && !empty($_SERVER['HTTP_X_SITEDASH_SIGNATURE']) ? $_SERVER['HTTP_X_SITEDASH_SIGNATURE'] : false;

// Make sure we have the site key and signature before even bothering continuing
if (!$siteKey || !$signature) {
    http_response_code(401);
    echo json_encode(['success' => false, 'data' => ['message' => 'Missing authentication.']]);
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
    echo json_encode(['success' => false, 'data' => ['message' => 'Couldn\'t load service.']]);
    @session_write_close();
    exit();
}

if (!$sdc->isValidRequest($siteKey, $signature, $_POST)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'data' => ['message' => 'Invalid authentication.']]);
    @session_write_close();
    exit();
}

// Make sure the params are sanitized
$params = $modx::sanitize($_POST);

switch ($params['request']) {
    case 'system':
        // Create our data class and run it
        $dataCommand = new \modmore\SiteDashClient\LoadSystemData($modx, $params);
        $data = $dataCommand->run();

        // Output the requested info
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data,
        ], JSON_PRETTY_PRINT);
        break;

    case 'errorlog':
        $errorLog = new \modmore\SiteDashClient\DownloadErrorLog($modx, $params);
        $errorLog->run();
        break;

}
@session_write_close();
exit();



