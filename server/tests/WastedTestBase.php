<?php

// TODO: Figure out how to get this to not choke on the Pi.
// declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';

/**
 * Logs the database query error in the test and sets it in the WastedTestBase class.
 *
 * @param array $params The parameters of the database query error.
 * @return void
 */
function logDbQueryErrorInTest(array $params): void {
  WastedTestBase::$lastDbError = $params;
  logDbQueryError($params);
}

class WastedTestBase extends TestCase {

  public static ?array $lastDbError = null;

  /**
   * Sets the custom database error handler for the WastedTestBase class.
   *
   * @return void
   */
  protected function setErrorHandler(): void {
    DB::$error_handler = 'logDbQueryErrorInTest';
  }

  protected function setUp(): void {
    WastedTestBase::$lastDbError = null;
  }

  protected function tearDown(): void {
    if (WastedTestBase::$lastDbError !== null) {
      $message = "DB error: " . dumpArrayToString(WastedTestBase::$lastDbError);
      throw new Exception($message);
    }
  }

  /**
   * Advances the time by the specified number of seconds.
   *
   * @param int $seconds The number of seconds to advance the time.
   * @return void
   */
  protected static function advanceTime(int $seconds): void {
    Wasted::$now->modify("+{$seconds} seconds");
  }

  /**
   * Returns the current date string in the format 'Y-m-d'.
   *
   * @return string The current date string.
   */
  protected static function dateString(): string {
    return Wasted::$now->format('Y-m-d');
  }

  /**
   * Returns the current date and time string in the format 'Y-m-d H:i:s'.
   *
   * @return string The current date and time string.
   */
  protected static function dateTimeString(): string {
    return Wasted::$now->format('Y-m-d H:i:s');
  }
}
