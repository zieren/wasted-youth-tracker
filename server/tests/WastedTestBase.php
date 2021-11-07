<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';

function logDbQueryErrorInTest($params) {
  WastedTestBase::$lastDbError = $params;
  logDbQueryError($params);
}

class WastedTestBase extends TestCase {

  public static $lastDbError = null;
  protected $mockTime; // epoch seconds, reset to 1000 before each test

  /**
   * This must happen after Wasted instantiation: Wasted also sets a DB error handler, and we need
   * to override it here.
   */
  protected function setErrorHandler(): void {
    DB::$error_handler = 'logDbQueryErrorInTest';
  }

  protected function setUp(): void {
    WastedTestBase::$lastDbError = null;
  }

  protected function tearDown(): void {
    if (WastedTestBase::$lastDbError) {
      $message = "DB error: " . dumpArrayToString(WastedTestBase::$lastDbError);
      throw new Exception($message);
    }
  }

  protected function mockTime() {
    return $this->mockTime;
  }

  protected function newDateTime() {
    $d = new DateTime();
    // Specifying the timestamp on creation sets TZ=UTC, which clashes with the server code.
    $d->setTimestamp($this->mockTime);
    return $d;
  }

  protected function dateString() {
    return $this->newDateTime()->format('Y-m-d');
  }

  protected function dateTimeString() {
    return $this->newDateTime()->format('Y-m-d H:i:s');
  }
}
