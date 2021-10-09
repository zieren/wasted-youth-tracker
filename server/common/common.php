<?php

// Internal constants.
define('PHP_MIN_VERSION', '7.3');
define('WASTED_SERVER_HEADING', 'Wasted Youth Tracker 0.0.0-5');
define('DEFAULT_CLASS_NAME', 'default_class');
define('LIMIT_TO_ZERO_NAME', 'limit_to_zero');
define('DEFAULT_CLASS_ID', 1);
define('DEFAULT_CLASSIFICATION_ID', 1);
define('MYSQL_SIGNED_BIGINT_MAX', '9223372036854775807'); // 2^63-1; text to support PHP 32 bit
define('MYSQL_SIGNED_INT_MIN', -2147483648);
define('LOG_PATTERN', '/log_(\d\d\d\d-\d\d-\d\d)\.txt/');  // for pruning log files

require_once 'base.php';
require_once 'config.php';

// Logger must be initialized before used in wastedFatalErrorHandler; see
// http://stackoverflow.com/questions/4242534
Logger::Instance();

function wastedErrorHandler($errno, $errstr, $errfile, $errline) {
  Logger::Instance()->critical('Error ' . $errno . ': ' . $errstr . ' @ ' . $errfile . ':' . $errline);
  return false; // continue with built-in error handling
}

set_error_handler('wastedErrorHandler');

function wastedFatalErrorHandler() {
  $error = error_get_last();
  if ($error && $error['type'] === E_ERROR) {
    Logger::Instance()->critical('Error: ' . json_encode($error));
  }
}

register_shutdown_function('wastedFatalErrorHandler');

// TODO: Check for missed use cases for these helpers. isset() is reportedly much faster.

/** Returns the mapped value (possibly null) if $key exists, else $default. */
function getOrDefault($array, $key, $default = null) {
  if (array_key_exists($key, $array)) {
    return $array[$key];
  }
  return $default;
}

/**
 * Returns a reference to the mapped value (possibly null) for $key, setting $default if $key is
 * unmapped.
 */
function &getOrCreate(&$array, $key, $default) {
  if (!array_key_exists($key, $array)) {
    $array[$key] = $default;
  }
  return $array[$key];
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
 * Maps limit IDs in the input array to names, given the configs obtained from
 * Wasted::getAllLimitConfigs().
 */
function limitIdsToNames($ids, $configs) {
  $names = [];
  foreach ($ids as $id) {
    $names[$id] = isset($configs[$id]) ? $configs[$id]['name'] : LIMIT_TO_ZERO_NAME;
  }
  return $names;
}

/**
 * Returns a map of limit ID to name, given the configs obtained from Wasted::getAllLimitConfigs().
 * The mapping for the zero limit (key NULL) is included.
 */
function getLimitIdToNameMap($configs) {
  $idToName = ['' => LIMIT_TO_ZERO_NAME];
  foreach ($configs as $id => $config) {
    $idToName[$id] = $config['name'];
  }
  return $idToName;
}
