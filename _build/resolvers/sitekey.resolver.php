<?php

// For convenient access to $modx
if (!isset($modx) && isset($object) && isset($object->xpdo)) {
    $modx = $object->xpdo;
}

// Check if we got the site key
$siteKey = array_key_exists('site_key', $options) ? $options['site_key'] : false;
if (empty($siteKey)) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'Oops, you did not provide the Site Key. The Site Key is used to identify and authenticate this with the SiteDash platform.');
    return false;
}
$modx->log(modX::LOG_LEVEL_WARN, 'Verifying Site Key "' . $siteKey . '"');

// Authenticate the siteKey
$server = array_key_exists('site_dash_server', $options) ? $options['site_dash_server'] : false;
if (!$server) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'Please enter a valid SiteDash Server URL.');
    return false;
}

$url = $server . '/api/site/' . $siteKey . '/authenticate?domain=' . urlencode($modx->getOption('http_host')) . '&assets_url=' . urlencode($modx->getOption('assets_url'));

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
$response = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($info['http_code'] !== 200) {
    $message = (is_array($data) && isset($data['data']) && isset($data['data']['message'])) ? $data['data']['message'] : '-';
    $modx->log(modX::LOG_LEVEL_ERROR, 'Could not verify your Site Key. Received HTTP Code ' . $info['http_code'] . ' with message: ' . $message);
    return false;
}

if (!is_array($data) || !array_key_exists('data', $data)) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'Received unexpected response: ' . htmlentities($response) . ' /// ' . print_r($data, true));
    return false;
}

// Store the site key
$modx->log(modX::LOG_LEVEL_INFO, 'Verification was successful, saving your site key...');
file_put_contents(MODX_CORE_PATH . 'components/sitedashclient/.sdc_site_key', $siteKey);
if (array_key_exists('public_key', $data['data'])) {
    $modx->log(modX::LOG_LEVEL_INFO, 'Saving new Public Key from SiteDash...');
    file_put_contents(MODX_CORE_PATH . 'components/sitedashclient/.sdc_public_key', $data['data']['public_key']);
}
else {
    $modx->log(modX::LOG_LEVEL_WARN, 'Note: no public key was received. This is expected when you\'re upgrading the SiteDash Client on an existing site that was already verified. If you no longer have the public key and run into verification issues, please try creating a new site in the SiteDash dashboard, or contact support for help.');
}

return true;
