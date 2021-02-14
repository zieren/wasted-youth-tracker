<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once('../common/base.php');

define('TESTS_LOG', 'tests_log.txt');

if (file_exists(TESTS_LOG)) {
  unlink(TESTS_LOG);
}

Logger::initializeForTest(TESTS_LOG);

require_once '../common/common.php';
require_once '../common/Database.php';

require_once 'config_tests.php';

final class DatabaseTest {

  private $failedTests = [];
  private $passedTests = 0;
  private $db;
  private $mockTime = 1000; // epoch seconds

  public function testSmokeTest(): void {
    $this->db->getGlobalConfig();
  }

  private function setUp(): void {
    $this->dropAllTables();
    $this->db = Database::createForTest(
        TEST_DB_SERVER, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS,
        function() { return $this->mockTime(); });
  }

  private function mockTime() {
    return $this->mockTime;
  }

  private function newDateTime() {
    $d = new DateTime();
    $d->setTimestamp($this->mockTime);
    return $d;
  }

  private function dropAllTables(): void {
    $mysqli = new mysqli(TEST_DB_SERVER, TEST_DB_USER, TEST_DB_PASS, TEST_DB_NAME);
    assert(!$mysqli->connect_errno && $mysqli->select_db(TEST_DB_NAME));
    assert($mysqli->query('SET FOREIGN_KEY_CHECKS = 0'));
    $result = $mysqli->query(
        'SELECT table_name FROM information_schema.tables'
        . ' WHERE table_schema = "' . TEST_DB_NAME . '"');
    while ($row = $result->fetch_assoc()) {
      assert($mysqli->query('DROP TABLE IF EXISTS ' . $row['table_name']));
    }
    assert($mysqli->query('SET FOREIGN_KEY_CHECKS = 1'));
    assert($mysqli->close());
  }

  private function tearDown(): void {
    if (file_exists(TESTS_LOG)) {
      echo '<pre>' . file_get_contents(TESTS_LOG) . "</pre>";
    } else {
      echo 'No log output.<hr>';
    }
  }

  private function assertEquals($actual, $expected) {
    if ($actual === $expected) {
      return;
    }

    $bt = debug_backtrace();
    $caller = array_shift($bt);

    ob_start();
    echo 'Line ' . $caller['line'] . ': Equality assertion failed: ';
    var_dump($actual);
    echo ' !== ';
    var_dump($expected);
    throw new AssertionError(ob_get_clean());
  }

  public function run(): void {
    Logger::Instance()->info('----- setUp');
    $this->setUp();

    $tests = array_filter(get_class_methods(get_class($this)), function($k) {
      return !substr_compare($k, "test", 0, 4);
    });
    foreach ($tests as $test) {
      $method = new ReflectionMethod(get_class($this), $test);
      try {
        Logger::Instance()->info('----- ' . $test);
        $method->invoke($this);
        $this->passedTests += 1;
      } catch (AssertionError $e) {
        $this->failedTests[$test] = $e;
      }
    }
    echo "Tests passed: " . $this->passedTests . "<hr>";
    if ($this->failedTests) {
      echo "TESTS FAILED: " . count($this->failedTests) . "<hr>";
      foreach ($this->failedTests as $test => $e) {
        echo $test . ": " . $e->getMessage() . "<hr>";
      }
    } else {
      echo "<b>ALL TESTS PASSED</b><hr>";
    }

    Logger::Instance()->info('----- tearDown');
    $this->tearDown();
  }

  public function testTotalTimeSingleWindowNoBudget(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $m0 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m0, []);

    // A single record amounts to zero.
    $this->db->insertWindowTitles('user_1', ['window 1'], 0);
    $m1 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m1, ['' => ['1970-01-01' => 0]]);

    // Add 5 seconds.
    $this->mockTime += 5;
    $this->db->insertWindowTitles('user_1', ['window 1'], 0);
    $m2 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m2, ['' => ['1970-01-01' => 5]]);

    // Add 10 seconds.
    $this->mockTime += 10;
    $this->db->insertWindowTitles('user_1', ['window 1'], 0);
    $m3 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m3, ['' => ['1970-01-01' => 15]]);
  }

  public function testTotalTimeTwoWindowsNoBudget(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $m0 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m0, []);

    // A single record amounts to zero.
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $m1 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m1, ['' => ['1970-01-01' => 0]]);

    // Add 5 seconds.
    $this->mockTime += 5;
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $m2 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m2, ['' => ['1970-01-01' => 10]]);

    // Add 10 seconds.
    $this->mockTime += 10;
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $m3 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m3, ['' => ['1970-01-01' => 30]]);
  }

}

(new DatabaseTest())->run();
