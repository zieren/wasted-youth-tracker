<?php

// Internal constants.
define('PHP_MIN_VERSION', '7.3');
define('LOG_DIR', 'logs');
define('CONFIG_PHP', 'config.php');
define('CONFIG_DEFAULT_FILENAME', 'default.cfg');
define('KFC_SERVER_HEADING', 'Kids Freedom & Control 0.0.0');

function autoloader($class) {
  include str_replace('\\', '/', $class) . '.php';
}

spl_autoload_register('autoloader');

function checkRequirements() {
  $unmet = array();
  if (version_compare(PHP_VERSION, PHP_MIN_VERSION) < 0) {
    $unmet[] = 'PHP version ' . PHP_MIN_VERSION . ' is required, but this is ' . PHP_VERSION . '.';
  }
  if (!function_exists('mysqli_connect')) {
    $unmet[] = 'The mysqli extension is missing.';
  }
  if (!$unmet) {
    return;
  }
  echo '<p><b>Please follow these steps to complete the installation:</b></p>'
  . '<ul><li>' . implode('</li><li>', $unmet) . '</li></ul><hr />';
  throw new Exception(implode($unmet));
}

checkRequirements();

require_once CONFIG_PHP;

// Logger must be initialized before used in kfcFatalErrorHandler; see
// http://stackoverflow.com/questions/4242534/php-shutdown-cant-write-files
Logger::Instance();

function kfcErrorHandler($errno, $errstr, $errfile, $errline, $errcontext) {
  Logger::Instance()->critical('Error ' . $errno . ': ' . $errstr . ' -- ' . $errfile . ':' . $errline);
  return false;  // continue with built-in error handling
}

set_error_handler('kfcErrorHandler');

function kfcFatalErrorHandler() {
  $error = error_get_last();
  if ($error && $error['type'] === E_ERROR) {
    Logger::Instance()->critical('Error: ' . json_encode($error));
  }
}

register_shutdown_function('kfcFatalErrorHandler');

// TODO: Unused?
function minutesToMillis($minutes) {
  return $minutes * 60 * 1000;
}

// TODO: Unused?
function daysToMillis($days) {
  return $days * 24 * 60 * 60 * 1000;
}

function get(&$value, $default = null) {
  return isset($value) ? $value : $default;
}
