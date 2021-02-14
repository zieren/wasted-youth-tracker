<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once('../common/base.php');

define('TESTS_LOG', 'tests_log.txt');

if (file_exists(TESTS_LOG)) {
  unlink(TESTS_LOG);
}

Logger::initializeForTest(TESTS_LOG);

class TestCase {

  private $failedTests = [];
  private $passedTests = 0;
  private $startTime = 0;

  protected function setUpTestCase(): void {
  }

  protected function setUp(): void {
  }

  protected function tearDown(): void {
  }

  protected function tearDownTestCase(): void {
  }

  private function dumpAndClearLog() {
    if (file_exists(TESTS_LOG)) {
      echo '<pre>' . file_get_contents(TESTS_LOG) . "</pre>";
    } else {
      echo 'No log output.';
    }
  }

  protected function assertEquals($actual, $expected) {
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
    $this->startTime = microtime(true);
    Logger::Instance()->info('----- setUp');
    $this->setUpTestCase();

    $tests = array_filter(get_class_methods(get_class($this)), function($k) {
      return !substr_compare($k, "test", 0, 4);
    });
    foreach ($tests as $test) {
      $method = new ReflectionMethod(get_class($this), $test);
      try {
        Logger::Instance()->info('----- ' . $test);
        $this->setUp();
        $method->invoke($this);
        $this->tearDown();
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
    echo 'Runtime: ' . (microtime(true) - $this->startTime) . 's<hr>';

    Logger::Instance()->info('----- tearDown');
    $this->tearDownTestCase();
    $this->dumpAndClearLog();
  }

}
