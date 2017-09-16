<?php

switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        $siteKeyFile = MODX_CORE_PATH . 'components/sitedashclient/.sdc_site_key';
        $key = false;
        if (file_exists($siteKeyFile)) {
            $key = @file_get_contents($siteKeyFile);
        }

        if (empty($key)) {
            return '<label for="sdc-site-key">Site Key</label>
                <input type="text" name="site_key" id="sdc-site-key" value="">';
        }
    break;
    default:
    case xPDOTransport::ACTION_UNINSTALL:
        $output = '';
    break;
}

return '';
