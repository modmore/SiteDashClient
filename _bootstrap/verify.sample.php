<?php

/**
 * Enter the site key here. And adjust the server and client host as needed.
 */
$options = [
    'site_key' => '',
    'site_dash_server' => 'https://sitedash.dev/',
    'client_host' => 'client.dev',
];

ini_set('display_errors', 1);
/* Get the core config */
if (!file_exists(dirname(__FILE__, 2) . '/config.core.php')) {
    echo 'ERROR: missing '. dirname(__FILE__, 2) . '/config.core.php file defining the MODX core path.';
    exit(1);
}

/* Boot up MODX */
echo "Loading modX...\n";
require_once dirname(__FILE__, 2) . '/config.core.php';
require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx = new modX();
echo "Initializing manager...\n";
$modx->initialize('mgr');
$modx->getService('error','error.modError', '', '');

// Check if we got the site key
$siteKey = array_key_exists('site_key', $options) ? $options['site_key'] : false;
if (empty($siteKey)) {
    echo 'Oops, you did not provide the Site Key. The Site Key is used to identify and authenticate this with the SiteDash platform.' . "\n";
    return false;
}
echo 'Verifying Site Key "' . $siteKey . '"' . "\n";

// Authenticate the siteKey
$server = array_key_exists('site_dash_server', $options) ? $options['site_dash_server'] : false;
if (!$server) {
    echo "Please enter a valid SiteDash Server URL.\n";
    return false;
}

$url = $server . '/api/site/' . $siteKey . '/authenticate?domain=' . urlencode($options['client_host']) . '&assets_url=' . urlencode($modx->getOption('assets_url'));

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // False for dev!
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
$response = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($info['http_code'] !== 200) {
    $message = (is_array($data) && isset($data['data']) && isset($data['data']['message'])) ? $data['data']['message'] : '-';
    echo 'Could not verify your Site Key. Received HTTP Code ' . $info['http_code'] . ' with message: ' . $message . "\n";
    return false;
}

if (!is_array($data) || !array_key_exists('data', $data)) {
    echo 'Received unexpected response: ' . htmlentities($response) . ' /// ' . print_r($data, true) . "\n";
    return false;
}

$clientCorePath = $modx->getOption('sitedashclient.core_path');

// Store the site key
echo 'Verification was successful, saving your site key to: ' . $clientCorePath . "\n";
file_put_contents($clientCorePath . '.sdc_site_key', $siteKey);
echo $siteKey;
if (array_key_exists('public_key', $data['data'])) {
    echo 'Saving new Public Key from SiteDash to: ' . $clientCorePath . "\n";
    echo $data['data']['public_key'];
    file_put_contents($clientCorePath . '.sdc_public_key', $data['data']['public_key']);
} else {
    echo 'Note: no public key was received. This is expected when you\'re upgrading the SiteDash Client on an existing site that was already verified. If you no longer have the public key and run into verification issues, please try creating a new site in the SiteDash dashboard, or contact support for help.' . "\n";
}

return true;
