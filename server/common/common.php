<?php

// Internal constants.
define('PHP_MIN_VERSION', '7.3');
define('KFC_SERVER_HEADING', 'Wasted Youth Tracker 0.0.0');
define('DEFAULT_CLASS_NAME', 'default_class');
define('DEFAULT_CLASS_ID', 1);
define('DEFAULT_CLASSIFICATION_ID', 1);
define('MYSQL_SIGNED_BIGINT_MAX', '9223372036854775807'); // 2^63-1; text to support PHP 32 bit
define('MYSQL_SIGNED_INT_MIN', -2147483648);

require_once 'base.php';
require_once 'config.php';

// Logger must be initialized before used in kfcFatalErrorHandler; see
// http://stackoverflow.com/questions/4242534
Logger::Instance();

function kfcErrorHandler($errno, $errstr, $errfile, $errline) {
  Logger::Instance()->critical('Error ' . $errno . ': ' . $errstr . ' @ ' . $errfile . ':' . $errline);
  return false; // continue with built-in error handling
}

set_error_handler('kfcErrorHandler');

function kfcFatalErrorHandler() {
  $error = error_get_last();
  if ($error && $error['type'] === E_ERROR) {
    Logger::Instance()->critical('Error: ' . json_encode($error));
  }
}

register_shutdown_function('kfcFatalErrorHandler');

/** Returns the mapped value (possibly null) if $key exists, else $default. */
function getOrDefault($array, $key, $default = null) {
  if (array_key_exists($key, $array)) {
    return $array[$key];
  }
  return $default;
}

/** Calls var_dump to convert the specified array into a string. */
function dumpArrayToString($a) {
  ob_start();
  var_dump($a);
  return ob_get_clean();
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

function secondsToHHMMSS($seconds) {
  // Don't limit hours to 23. The value might be 24h or, even worse, more.
  $sign = $seconds < 0 ? '-' : '';
  $seconds = abs($seconds);
  $hours = strval(intval($seconds / 3600));
  return $sign . $hours . gmdate(':i:s', $seconds);
}

/**
 * Maps budget IDs in the input array to names, given the configs obtained from
 * KFC::getAllBudgetConfigs().
 */
function budgetIdsToNames($ids, $configs) {
  $names = [];
  foreach ($ids as $id) {
    $names[$id] = isset($configs[$id]) ? $configs[$id]['name'] : 'no_budget';
  }
  return $names;
}

/** Returns a map of budget ID to name. */
function getBudgetIdToNameMap($configs) {
  $idToName = ['' => 'no_budget'];
  foreach ($configs as $id => $config) {
    $idToName[$id] = $config['name'];
  }
  return $idToName;
}
