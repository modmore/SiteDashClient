<?php

// For convenient access to $modx
if (!isset($modx) && isset($object) && isset($object->xpdo)) {
    $modx = $object->xpdo;
}

$corePath = $modx->getOption('sitedashclient.core_path',null,$modx->getOption('core_path').'components/sitedashclient/');
$contentBlocks = $modx->getService('sitedashclient','SiteDashClient',$corePath.'model/sitedashclient/');

if (!$contentBlocks) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'Could not add layouts & fields, SiteDashClient class could not be loaded from ' . $corePath . 'model/sitedashclient/');
    return true;
}

// Check if we got the site key
$siteKey = array_key_exists('site_key', $options) ? $options['site_key'] : false;
if (empty($siteKey)) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'Oops, you did not provide the Site Key. The Site Key is used to identify and authenticate this with the SiteDash platform.');
    return false;
}
$modx->log(modX::LOG_LEVEL_WARN, 'Setting Site Key to: ' . $siteKey);
file_put_contents(MODX_CORE_PATH . 'components/sitedashclient/.sdc_site_key', $siteKey);

// @todo communicate with SiteDash to verify site key, create a pub/private key pair, and to download the public
// key into a .sdc_public_key file.

return true;
