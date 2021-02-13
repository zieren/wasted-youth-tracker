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

  private $failedTests = array();
  private $passedTests = 0;
  private $db;

  public function testSmokeTest(): void {
  }

  private function setUp(): void {
    $this->db = Database::createForTest(TEST_DB_SERVER, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS);
  }

  private function tearDown(): void {
    if (file_exists(TESTS_LOG)) {
      echo '<pre>' . file_get_contents(TESTS_LOG) . "</pre>";
    } else {
      echo 'No log output.<hr>';
    }
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

}

(new DatabaseTest())->run();
