<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';

function logDbQueryErrorInTest($params) {
  KFCTestBase::$lastDbError = $params;
  logDbQueryError($params);
}

class KFCTestBase extends TestCase {

  public static $lastDbError = null;
  protected $mockTime; // epoch seconds, reset to 1000 before each test

  /**
   * This must happen after KFC instantiation: KFC also sets a DB error handler, and we need to
   * override it here.
   */
  protected function setErrorHandler(): void {
    DB::$error_handler = 'logDbQueryErrorInTest';
  }

  protected function tearDown(): void {
    if (KFCTestBase::$lastDbError) {
      $message = "DB error: " . dumpArrayToString(KFCTestBase::$lastDbError);
      KFCTestBase::$lastDbError = null;
      throw new Exception($message);
    }
  }

  protected function mockTime() {
    return $this->mockTime;
  }

  protected function newDateTime() {
    $d = new DateTime();
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
