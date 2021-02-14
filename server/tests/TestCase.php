<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once('../common/base.php');

define('TESTS_LOG', 'tests_log.txt');

if (file_exists(TESTS_LOG)) {
  unlink(TESTS_LOG);
}

Logger::initializeForTest(TESTS_LOG);

// TODO: Configure DB error handler to surface errors in test.

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
    echo 'Line ' . $caller['line'] . ': Equality assertion failed:'
        . '<br><span style="background-color: yellow">';
    var_dump($actual);
    echo '</span><br><span style="background-color: lightgreen">';
    var_dump($expected);
    echo '</span>';
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
        if (error_get_last()) {
          ob_start();
          var_dump(error_get_last());
          error_clear_last();
          throw new Exception("Silent error: " . ob_get_clean());
        }
        $this->passedTests += 1;
      } catch (Throwable $e) {
        $this->failedTests[$test] = $e;
      }
    }
    echo '<hr>Tests passed: ' . $this->passedTests . '<hr>';
    if ($this->failedTests) {
      echo 'TESTS FAILED: ' . count($this->failedTests) . '<hr>';
      foreach ($this->failedTests as $test => $e) {
        echo $test . ': ' . $e->getMessage() . '<hr>';
      }
    } else {
      echo '<b style="background-color: lightgreen">ALL TESTS PASSED</b><hr>';
    }
    echo 'Runtime: ' . round(microtime(true) - $this->startTime, 2) . 's<hr>';

    Logger::Instance()->info('----- tearDown');
    $this->tearDownTestCase();
    $this->dumpAndClearLog();
  }

}
