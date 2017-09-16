<?php
/**
 * @var modX $modx
 * @var array $scriptProperties
 */

$corePath = $modx->getOption('sitedashclient.core_path', null, $modx->getOption('core_path') . 'components/sitedashclient/') . 'model/sitedashclient/';
$sdc = $modx->getService('sitedashclient', 'SiteDashClient', $corePath);

if (!($sdc instanceof SiteDashClient)) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[SiteDashClient plugin] Unable to load SiteDashClient service');
    return;
}