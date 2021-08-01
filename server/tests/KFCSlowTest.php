<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';

DB::$dbName = TEST_DB_NAME;
DB::$user = TEST_DB_USER;
DB::$password = TEST_DB_PASS;

function logDbQueryInSetUp($params) {
  Logger::Instance()->debug('DB query: ' . str_replace("\r\n", '', $params['query']));
}

final class KFCSlowTest extends KFCTestBase {

  protected function setUp(): void {
    DB::$success_handler = 'logDbQueryInSetUp';
    DB::query('SET FOREIGN_KEY_CHECKS = 0');
    $rows = DB::query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = %s', DB::$dbName);
    foreach ($rows as $row) {
      DB::query('DROP TABLE `' . $row['table_name'] . '`');
    }
    DB::query('SET FOREIGN_KEY_CHECKS = 1');
  }

  public function testCreateTablesWorksAndIsIdempotent(): void {
    for ($i = 0; $i < 2; $i++) {
      $this->onFailMessage("i=$i");
      Wasted::createForTest(TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS, $this->mockTime, true);
      $this->setErrorHandler();

      $classification = DB::query('SELECT * FROM classification');
      $this->assertEquals($classification, [[
          'id' => '1',
          'class_id' => strval(DEFAULT_CLASS_ID),
          'priority' => '-2147483648',
          're' => '()']]);

      $classes = DB::query('SELECT * FROM classes');
      $this->assertEquals($classes, [[
          'id' => strval(DEFAULT_CLASS_ID),
          'name' => DEFAULT_CLASS_NAME]]);
    }
  }

}

(new KFCSlowTest())->run();
