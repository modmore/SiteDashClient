<?php

// For convenient access to $modx
if (!isset($modx) && isset($object) && isset($object->xpdo)) {
    $modx = $object->xpdo;
}

$c = $modx->newQuery('transport.modTransportPackage');
$c->where(['package_name' => 'SiteDash Client']);
foreach ($modx->getIterator('transport.modTransportPackage', $c) as $package) {
    $modx->log(modX::LOG_LEVEL_INFO, 'Fixed package name for ' . $package->get('signature') . ' from "SiteDash Client" to "SiteDashClient"');
    $package->set('package_name', 'SiteDashClient');
    $package->save();
}

return true;
