<?php

// TODO: Figure out how to get this to not choke on the Pi.
// declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';

function logDbQueryErrorInTest($params)
{
  WastedTestBase::$lastDbError = $params;
  logDbQueryError($params);
}

class WastedTestBase extends TestCase
{
  public static $lastDbError = null;

  /**
   * This must happen after Wasted instantiation: Wasted also sets a DB error handler, and we need
   * to override it here.
   */
  protected function setErrorHandler(): void
  {
    DB::$error_handler = 'logDbQueryErrorInTest';
  }

  protected function setUp(): void
  {
    WastedTestBase::$lastDbError = null;
  }

  protected function tearDown(): void
  {
    if (WastedTestBase::$lastDbError) {
      $message = "DB error: " . dumpArrayToString(WastedTestBase::$lastDbError);
      throw new Exception($message);
    }
  }

  protected static function advanceTime($seconds): void
  {
    Wasted::$now->setTimestamp(Wasted::$now->getTimestamp() + $seconds);
  }

  protected static function dateString(): string
  {
    return Wasted::$now->format('Y-m-d');
  }

  protected static function dateTimeString(): string
  {
    return Wasted::$now->format('Y-m-d H:i:s');
  }
}
