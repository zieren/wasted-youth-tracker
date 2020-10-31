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

function get(&$value, $default = null) {
  return isset($value) ? $value : $default;
}
