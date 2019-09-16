<?php

/**
 * @param string $filename The name of the file.
 * @return string The file's content
 * @by splittingred
 */
function getSnippetContent($filename = '') {
    $o = file_get_contents($filename);
    $o = str_replace('<?php','',$o);
    $o = str_replace('?>','',$o);
    $o = trim($o);
    return $o;
}

$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$tstart = $mtime;
set_time_limit(0);

if (!defined('MOREPROVIDER_BUILD')) {
    /* define version */
    define('PKG_NAME', 'SiteDashClient');
    define('PKG_NAME_LOWER', strtolower(PKG_NAME));
    define('PKG_VERSION', '1.1.2');
    define('PKG_RELEASE', 'pl');

    /* load modx */
    require_once dirname(dirname(__FILE__)) . '/config.core.php';
    require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
    $modx= new modX();
    $modx->initialize('mgr');
    $modx->setLogLevel(modX::LOG_LEVEL_INFO);
    $modx->setLogTarget('ECHO');


    echo '<pre>';
    flush();
    $targetDirectory = dirname(dirname(__FILE__)) . '/_packages/';
}
else {
    $targetDirectory = MOREPROVIDER_BUILD_TARGET;
}

$root = dirname(dirname(__FILE__)).'/';
$sources= array (
    'root' => $root,
    'build' => $root .'_build/',
    'resolvers' => $root . '_build/resolvers/',
    'validators' => $root . '_build/validators/',
    'data' => $root . '_build/data/',
    'source_core' => $root.'core/components/'.PKG_NAME_LOWER,
    'source_assets' => $root.'assets/components/'.PKG_NAME_LOWER,
    'snippets' => $root.'_build/elements/snippets/',
    'plugins' => $root.'_build/elements/plugins/',
    'lexicon' => $root . 'core/components/'.PKG_NAME_LOWER.'/lexicon/',
    'docs' => $root.'core/components/'.PKG_NAME_LOWER.'/docs/',
    'model' => $root.'core/components/'.PKG_NAME_LOWER.'/model/',
);

$modx->loadClass('transport.modPackageBuilder','',false, true);
$builder = new modPackageBuilder($modx);
$builder->directory = $targetDirectory;
$builder->createPackage(PKG_NAME_LOWER,PKG_VERSION,PKG_RELEASE);
$builder->registerNamespace(PKG_NAME_LOWER,false,true,'{core_path}components/'.PKG_NAME_LOWER.'/','{assets_path}components/'.PKG_NAME_LOWER.'/');
$modx->getService('lexicon','modLexicon');

if (file_exists($sources['source_core'] . '/.sdc_public_key')) {
    $modx->log(modX::LOG_LEVEL_INFO, 'Public key file found; moving to root before packaging...');
    rename($sources['source_core'] . '/.sdc_public_key', $sources['root'] . '.sdc_public_key');
}
if (file_exists($sources['source_core'] . '/.sdc_site_key')) {
    $modx->log(modX::LOG_LEVEL_INFO, 'Site key file found; moving to root before packaging...');
    rename($sources['source_core'] . '/.sdc_site_key', $sources['root'] . '.sdc_site_key');
}


/* create category */
$category= $modx->newObject('modCategory');
$category->set('id',1);
$category->set('category',PKG_NAME);

///* Snippets */
//$snippets = include $sources['data'].'transport.snippets.php';
//if (is_array($snippets)) {
//    $category->addMany($snippets,'Snippets');
//    $modx->log(modX::LOG_LEVEL_INFO,'Packaged in '.count($snippets).' snippets.'); flush(); ob_flush();
//}
//else {
//    $modx->log(modX::LOG_LEVEL_FATAL,'Adding snippets failed.');
//}
//unset($snippets);

/* add plugins */
//$plugins = include $sources['data'].'transport.plugins.php';
//if (is_array($plugins)) {
//  $category->addMany($plugins,'Plugins');
//  $modx->log(modX::LOG_LEVEL_INFO,'Packaged in '.count($plugins).' plugins.'); flush();
//}
//else {
//  $modx->log(modX::LOG_LEVEL_FATAL,'Adding plugins failed.');
//}
//unset($plugins);

/* create category vehicle */
$attr = array(
    xPDOTransport::UNIQUE_KEY => 'category',
    xPDOTransport::PRESERVE_KEYS => false,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::RELATED_OBJECTS => false,
    xPDOTransport::ABORT_INSTALL_ON_VEHICLE_FAIL => true,
);
//  xPDOTransport::RELATED_OBJECT_ATTRIBUTES => array (
//    'Snippets' => array(
//      xPDOTransport::PRESERVE_KEYS => false,
//      xPDOTransport::UPDATE_OBJECT => true,
//      xPDOTransport::UNIQUE_KEY => 'name',
//    ),
//    'Plugins' => array(
//      xPDOTransport::PRESERVE_KEYS => true,
//      xPDOTransport::UPDATE_OBJECT => true,
//      xPDOTransport::UNIQUE_KEY => 'name',
//      xPDOTransport::RELATED_OBJECTS => true,
//      xPDOTransport::RELATED_OBJECT_ATTRIBUTES => array (
//        'PluginEvents' => array(
//          xPDOTransport::PRESERVE_KEYS => true,
//          xPDOTransport::UPDATE_OBJECT => false,
//          xPDOTransport::UNIQUE_KEY => array('pluginid','event'),
//        ),
//      ),
//    ),
//  ),
//);
$vehicle = $builder->createVehicle($category,$attr);

$modx->log(modX::LOG_LEVEL_INFO,'Packaged in category.'); flush();



/* Settings */
$settings = include $sources['data'].'transport.settings.php';
$attributes= array(
    xPDOTransport::UNIQUE_KEY => 'key',
    xPDOTransport::PRESERVE_KEYS => true,
    xPDOTransport::UPDATE_OBJECT => false,
);
if (!is_array($settings)) { $modx->log(modX::LOG_LEVEL_FATAL,'Adding settings failed.'); }
foreach ($settings as $setting) {
    $vehicle = $builder->createVehicle($setting,$attributes);
    $builder->putVehicle($vehicle);
}
$modx->log(modX::LOG_LEVEL_INFO,'Packaged in '.count($settings).' system settings.'); flush();
unset($settings,$setting,$attributes);



/* Actions */
//$modx->log(modX::LOG_LEVEL_INFO,'Packaging in actions...');
//$menu = include $sources['data'].'transport.actions.php';
//if (empty($menu)) $modx->log(modX::LOG_LEVEL_ERROR,'Could not package in actions.');
//$vehicle= $builder->createVehicle($menu,array (
//  xPDOTransport::PRESERVE_KEYS => true,
//  xPDOTransport::UPDATE_OBJECT => true,
//  xPDOTransport::UNIQUE_KEY => 'text',
//  xPDOTransport::ABORT_INSTALL_ON_VEHICLE_FAIL => true,
//));
//$modx->log(modX::LOG_LEVEL_INFO,'Adding in PHP resolvers...');
//$builder->putVehicle($vehicle);
//unset($menu);



/* Resolvers */
$vehicle->validate('php', array(
    'source' => $sources['validators'] . 'requirements.script.php'
));
$vehicle->resolve('file',array(
    'source' => $sources['source_core'],
    'target' => "return MODX_CORE_PATH . 'components/';",
));
$vehicle->resolve('file',array(
    'source' => $sources['source_assets'],
    'target' => "return MODX_ASSETS_PATH . 'components/';",
));
$vehicle->resolve('php', array(
    'source' => $sources['resolvers'] . 'sitekey.resolver.php'
));

$modx->log(modX::LOG_LEVEL_INFO,'Packaged in resolvers.'); flush();
$builder->putVehicle($vehicle);

/* now pack in the license file, readme and setup options */
$builder->setPackageAttributes(array(
    'license' => file_get_contents($sources['docs'] . 'license.txt'),
    'readme' => file_get_contents($sources['docs'] . 'readme.txt'),
    'changelog' => file_get_contents($sources['docs'] . 'changelog.txt'),
    'setup-options' => array(
      'source' => $sources['build'].'setup.options.php',
    ),
    'requires' => array(
        'modx' => '>=2.5.0',
    ),
));
$modx->log(modX::LOG_LEVEL_INFO,'Packaged in package attributes.'); flush();

$modx->log(modX::LOG_LEVEL_INFO,'Packing...'); flush();
$builder->pack();

if (file_exists($sources['root'] . '.sdc_public_key')) {
    $modx->log(modX::LOG_LEVEL_INFO, 'Moving public key file back to original position...');
    rename($sources['root'] . '.sdc_public_key', $sources['source_core'] . '/.sdc_public_key');
}
if (file_exists($sources['root'] . '.sdc_site_key')) {
    $modx->log(modX::LOG_LEVEL_INFO, 'Moving site key file back to original position...');
    rename($sources['root'] . '.sdc_site_key', $sources['source_core'] . '/.sdc_site_key');
}

$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$tend = $mtime;
$totalTime = ($tend - $tstart);
$totalTime = sprintf("%2.4f s", $totalTime);

$modx->log(modX::LOG_LEVEL_INFO,"Package Built. Execution time: {$totalTime}");