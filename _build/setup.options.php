<?php

switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        $siteKeyFile = MODX_CORE_PATH . 'components/sitedashclient/.sdc_site_key';
        $key = '';
        if (file_exists($siteKeyFile)) {
            $key = @file_get_contents($siteKeyFile);
        }
        $key = htmlentities($key);

        $output = '<label for="sdc-site-key">Site Key</label>
            <input type="text" name="site_key" id="sdc-site-key" value="' . $key . '">';

        $output .= '<label for="sdc-sitedash-server">SiteDash Server</label>
            <input type="text" name="site_dash_server" id="sdc-sitedash-server" value="https://sitedash.app/">';

        return $output;
    break;
    default:
    case xPDOTransport::ACTION_UNINSTALL:
        $output = '';
    break;
}

return '';
