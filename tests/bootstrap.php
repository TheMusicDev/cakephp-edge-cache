<?php
declare(strict_types=1);

/**
 * Test bootstrap for the plugin: a tiny CakePHP app (tests/test_app) with just this plugin loaded.
 * The plugin has no database, so no connection is configured.
 */

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Utility\Security;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
define('ROOT', dirname(__DIR__));
define('CAKE_CORE_INCLUDE_PATH', ROOT . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);
define('TESTS', ROOT . DS . 'tests' . DS);
define('APP', ROOT . DS . 'tests' . DS . 'test_app' . DS);
define('APP_DIR', 'src');
define('WEBROOT_DIR', 'webroot');
define('WWW_ROOT', APP . 'webroot' . DS);
define('TMP', sys_get_temp_dir() . DS . 'edge-cache-tests' . DS);
define('CONFIG', APP . 'config' . DS);
define('CACHE', TMP . 'cache' . DS);
define('LOGS', TMP . 'logs' . DS);

foreach ([TMP, CACHE, LOGS] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

require ROOT . '/vendor/autoload.php';
require CORE_PATH . 'config' . DS . 'bootstrap.php';
require CAKE . 'functions.php';

Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'UTF-8',
    'base' => false,
    'baseUrl' => false,
    'dir' => 'src',
    'webroot' => 'webroot',
    'wwwRoot' => WWW_ROOT,
    'fullBaseUrl' => 'http://localhost',
]);
Configure::write('debug', true);
Configure::write('Security.salt', 'edge-cache-tests-only-salt-0000000000000000000000000000');
Security::setSalt('edge-cache-tests-only-salt-0000000000000000000000000000');

Cache::setConfig([
    '_cake_translations_' => ['engine' => 'Array', 'prefix' => 'ts_cake_trans_'],
    '_cake_model_' => ['engine' => 'Array', 'prefix' => 'ts_cake_model_'],
]);
