<?php
ini_set('display_errors', 1);

$siteKey = $argv[1];
if (empty($siteKey)) {
    echo "Provide the key to verify as CLI argument.\n";
    exit(1);
}
$secure = false;
$host = $argv[2] ?? '';
if (empty($host))  {
    echo "Provide the server hostname to verify as second argument.\n";
    exit(1);
}
$server = $argv[3] ?? 'http://sitedashboard.local/';
$assetsUrl = '/SiteDashClient/assets/';
$url = $server . '/api/site/' . $siteKey . '/authenticate?domain=' . urlencode($host) . '&assets_url=' . urlencode($assetsUrl);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $secure);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $secure ? 2 : false);
$response = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($info['http_code'] !== 200) {
    $message = (is_array($data) && isset($data['data']) && isset($data['data']['message'])) ? $data['data']['message'] : '-';

    echo "Failed to verify with code {$info['http_code']} and message: {$message}.\n";
    exit(1);
}

if (!is_array($data) || !array_key_exists('data', $data)) {
    echo "Unexpected response; not an array or incorrect format: {$response}.\n";
    exit(1);
}

echo "Successfully verified.\n";

if (file_put_contents(dirname(__DIR__) . '/core/components/sitedashclient/.sdc_site_key', $siteKey)) {
    echo "- Saved site key to .sdc_site_key file\n";
}

if (array_key_exists('public_key', $data['data'])) {
    if (file_put_contents(dirname(__DIR__) . '/core/components/sitedashclient/.sdc_public_key', $data['data']['public_key'])) {
        echo "- Saved received public key to .sdc_public_key\n";
    }
}
else {
    echo "- Verified, but no public key received. This is expected on upgrades or when already verified.\n";
}

exit(0);
