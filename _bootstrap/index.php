<?php

ini_set('display_errors', 1);
/* Get the core config */
if (!file_exists(dirname(dirname(__FILE__)).'/config.core.php')) {
    echo 'ERROR: missing '.dirname(dirname(__FILE__)).'/config.core.php file defining the MODX core path.';
    exit(1);
}

/* Boot up MODX */
echo "Loading modX...\n";
require_once dirname(dirname(__FILE__)).'/config.core.php';
require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx = new modX();
echo "Initializing manager...\n";
$modx->initialize('mgr');
$modx->getService('error','error.modError', '', '');
$buildPath = '../_build';
$componentPath = dirname(dirname(__FILE__));
define('PKG_NAME', 'SiteDashClient');
define('PKG_NAME_LOWER',strtolower(PKG_NAME));

echo "Loading SiteDashClient service\n";

$SiteDashClient = $modx->getService(PKG_NAME_LOWER, PKG_NAME, $componentPath . '/core/components/' . PKG_NAME_LOWER . '/model/' . PKG_NAME_LOWER . '/', array(
    PKG_NAME_LOWER . '.core_path' => $componentPath . '/core/components/' . PKG_NAME_LOWER . '/',
));

echo "Creating bits\n";

$requestUri = $_SERVER['REQUEST_URI'];
$bootstrapPos = strpos($requestUri, '_bootstrap/');
$requestUri = trim(substr($requestUri, 0, $bootstrapPos), '/').'/';
$assets_url = "/{$requestUri}assets/components/" . PKG_NAME_LOWER . '/';

/* Namespace */
if (!createObject('modNamespace', [
    'name' => PKG_NAME_LOWER,
    'path' => $componentPath.'/core/components/' . PKG_NAME_LOWER . '/',
    'assets_path' => $componentPath.'/assets/components/' . PKG_NAME_LOWER . '/',
],'name', false)) {
    echo "Error creating namespace.\n";
}

/* System settings */
if (!createObject('modSystemSetting', [
    'key' => PKG_NAME_LOWER . '.core_path',
    'value' => $componentPath.'/core/components/' . PKG_NAME_LOWER . '/',
    'xtype' => 'textfield',
    'namespace' => PKG_NAME_LOWER,
    'area' => 'Paths',
    'editedon' => time(),
], 'key', false)) {
    echo "Error creating core_path setting.\n";
}

if (!createObject('modSystemSetting', [
  'key' => PKG_NAME_LOWER . '.assets_path',
  'value' => $componentPath.'/assets/components/' . PKG_NAME_LOWER . '/',
  'xtype' => 'textfield',
  'namespace' => PKG_NAME_LOWER,
  'area' => 'Paths',
  'editedon' => time(),
], 'key', false)) {
  echo "Error creating assets_path setting.\n";
}

if (!createObject('modSystemSetting', [
  'key' => PKG_NAME_LOWER . '.assets_url',
  'value' => $assets_url,
  'xtype' => 'textfield',
  'namespace' => PKG_NAME_LOWER,
  'area' => 'Paths',
  'editedon' => time(),
], 'key', false)) {
  echo "Error creating assets_url setting.\n";
}

if (!createObject('modSystemSetting', [
  'key' => PKG_NAME_LOWER . '.allow_user_search',
  'value' => true,
  'xtype' => 'modx-combo-boolean',
  'namespace' => PKG_NAME_LOWER,
  'area' => 'security',
  'editedon' => time(),
], 'key', false)) {
  echo "Error creating allow_user_search setting.\n";
}

// Menu
//
//if (!createObject('modMenu', [
//  'action' => 'index',
//  'namespace' => PKG_NAME_LOWER,
//
//  'text' => PKG_NAME_LOWER . '.menu',
//  'parent' => 'components',
//  'description' => PKG_NAME_LOWER . '.menu_desc',
//  'icon' => 'images/icons/plugin.gif',
//  'menuindex' => '0',
//  'params' => '',
//  'handler' => '',
//], '', true)) {
//  echo "Error creating menu.\n";
//}

//
//if (!createObject('modPlugin', [
//  'name' => 'SiteDashClient',
//  'static' => true,
//  'static_file' => $componentPath.'/_build/elements/plugins/' . PKG_NAME_LOWER . '.plugin.php',
//], 'name', true)) {
//  echo "Error creating SiteDashClient Plugin.\n";
//}
//
//$vcPlugin = $modx->getObject('modPlugin', ['name' => 'SiteDashClient']);
//if ($vcPlugin) {
//  if (!createObject('modPluginEvent', array(
//    'pluginid' => $vcPlugin->get('id'),
//    'event' => 'OnDocFormPrerender',
//    'priority' => 0,
//  ), array('pluginid','event'), false)) {
//    echo "Error creating modPluginEvent.\n";
//  }
//}

$containers = [
];
$manager = $modx->getManager();
foreach($containers as $container) {
  $manager->createObjectContainer($container);
}
echo "Done.";

// Refresh the cache
$modx->cacheManager->refresh();


/**
 * Creates an object.
 *
 * @param string $className
 * @param array $data
 * @param string $primaryField
 * @param bool $update
 * @return bool
 */
function createObject ($className = '', array $data = array(), $primaryField = '', $update = true) {
    global $modx;
    /* @var xPDOObject $object */
    $object = null;

    /* Attempt to get the existing object */
    if (!empty($primaryField)) {
        if (is_array($primaryField)) {
            $condition = array();
            foreach ($primaryField as $key) {
                $condition[$key] = $data[$key];
            }
        }
        else {
            $condition = array($primaryField => $data[$primaryField]);
        }
        $object = $modx->getObject($className, $condition);
        if ($object instanceof $className) {
            if ($update) {
                $object->fromArray($data);
                return $object->save();
            } else {
                $condition = $modx->toJSON($condition);
                echo "Skipping {$className} {$condition}: already exists.\n";
                return true;
            }
        }
    }

    /* Create new object if it doesn't exist */
    if (!$object) {
        $object = $modx->newObject($className);
        $object->fromArray($data, '', true);
        return $object->save();
    }

    return false;
}