<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';

// TODO: This should maybe inherit from a common base test class.

DB::$dbName = TEST_DB_NAME;
DB::$user = TEST_DB_USER;
DB::$password = TEST_DB_PASS;
DB::$param_char = '|';

final class KFCSlowTest extends TestCase {

  protected function setUp(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    DB::query('SET FOREIGN_KEY_CHECKS = 0');
    $rows = DB::query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = |s', DB::$dbName);
    foreach ($rows as $row) {
      DB::query('DROP TABLE `' . $row['table_name'] . '`');
    }
    DB::query('SET FOREIGN_KEY_CHECKS = 1');
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
  }

  public function testCreateTables(): void {
    KFC::createForTest(TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS, "time", true);
  }

}

(new KFCSlowTest())->run();
