<?php

// Internal constants.
define('PHP_MIN_VERSION', '7.3');
define('LOG_DIR', '../logs');
define('CONFIG_PHP', 'config.php');
define('KFC_SERVER_HEADING', 'Kids Freedom & Control 0.0.0');
define('DEFAULT_BUDGET_NAME', 'default_budget'); // TODO: Remove!?
define('DEFAULT_CLASS_NAME', 'default_class');

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

/** Returns the specified value if it is set, or $default otherwise. */
function get(&$value, $default = null) {
  return isset($value) ? $value : $default;
}

/**
 * Returns a DateTime pointing to the start of the week (currently always Monday 0:00) in which
 * $date lies.
 */
function getWeekStart($date) {
  // TODO: This assumes the week starts on Monday.
  $dayOfWeek = ($date->format('w') + 6) % 7; // 0 = Sun
  $weekStart = clone $date;
  $weekStart->sub(new DateInterval('P' . $dayOfWeek . 'D'));
  $weekStart->setTime(0, 0);
  return $weekStart;
}

/** Returns a DateTime set to 0:00 of today. */
function getTodayStart() {
  return (new DateTime())->setTime(0, 0);
}

/** Returns the date from the specified DateTime, in YYYY-MM-DD format. */
function getDateString($dateTime) {
  return $dateTime->format('Y-m-d');
}

/**
 * Maps budget IDs in the input array to names, given the configs obtained from
 * Database::getAllBudgetConfigs().
 */
function budgetIdsToNames($ids, $configs) {
  $names = array();
  foreach ($ids as $id) {
    $names[$id] = isset($configs[$id]) ? $configs[$id]['name'] : "no_budget";
  }
  return $names;
}