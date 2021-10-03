<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once('../common/base.php');

define('TESTS_LOG', 'tests_log.txt');
if (file_exists(TESTS_LOG)) {
  unlink(TESTS_LOG);
}
Logger::initializeForTest(TESTS_LOG);

abstract class TestCase {

  private $failedTests = [];
  private $passedTests = 0;
  private $startTime = 0;
  private $onFailMessage = [];

  /** Set while running a test. */
  private $test = "";

  protected function setUpTestCase(): void {
  }

  protected function setUp(): void {
  }

  protected function tearDown(): void {
  }

  protected function tearDownTestCase(): void {
  }

  private function dumpAndClearLog(): void {
    if (file_exists(TESTS_LOG)) {
      echo '<pre>' . file_get_contents(TESTS_LOG) . "</pre>";
    } else {
      echo 'No log output.';
    }
  }

  protected function onFailMessage($message): void {
    $this->onFailMessage[$this->test] = $message;
  }

  protected function assertEquals($actual, $expected): void {
    if ($actual === $expected) {
      return;
    }

    // Walk stack from the top to find the invocation of the test method.
    $bt = debug_backtrace();
    $line = 'unknown';
    for ($i = count($bt) - 1; $i >= 0; $i--) {
      if ($bt[$i]['function'] == 'invoke') {
        // 'line' is the caller's line, so subtract 2.
        $line = $bt[$i - 2]['line'];
        break;
      }
    }

    ob_start();
    echo "Line $line: Equality assertion failed:"
        . '<table><tr><td style="vertical-align: top;"><pre>';
    var_dump($actual);
    echo '</pre></td><td style="background-color: lightgreen; vertical-align: top;"><pre>';
    var_dump($expected);
    echo '</pre></td></tr></table>';
    throw new AssertionError(ob_get_clean());
  }

  protected function assertEqualsIgnoreOrder($actual, $expected): void {
    $this->sortArrays($actual);
    $this->sortArrays($expected);
    $this->assertEquals($actual, $expected);
  }

  private function sortArrays(&$a): void {
    if (is_array($a)) {
      ksort($a);
      foreach ($a as &$v) {
        $this->sortArrays($v);
      }
    }
  }

  public function run(): void {
    $this->startTime = microtime(true);
    Logger::Instance()->info('----- setUpTestCase');
    $this->setUpTestCase();

    $filter = '/'.(filter_input(INPUT_GET, 'filter', FILTER_SANITIZE_STRING) ?? '').'/';
    $tests = array_filter(get_class_methods(get_class($this)), function($k) use ($filter) {
      return !substr_compare($k, 'test', 0, 4) && preg_match($filter, $k);
    });
    foreach ($tests as $this->test) {
      $method = new ReflectionMethod(get_class($this), $this->test);
      try {
        Logger::Instance()->info('----- ' . $this->test);
        $this->setUp();
        $method->invoke($this);
        $this->tearDown();
        if (error_get_last()) {
          $message = 'Silent error: ' . dumpArrayToString(error_get_last());
          error_clear_last();
          throw new Exception($message);
        }
        $this->passedTests += 1;
      } catch (Throwable $e) {
        $this->failedTests[$this->test] = $e;
      }
    }
    echo '<hr>Tests passed: ' . $this->passedTests . '<hr>';
    if ($this->failedTests) {
      echo 'TESTS FAILED: ' . count($this->failedTests) . '<hr>';
      foreach ($this->failedTests as $test => $e) {
        $message = $e->getMessage();
        if (isset($this->onFailMessage[$test])) {
          $message = '[' . $this->onFailMessage[$test] . '] ' . $message;
        }
        echo "<b><a href=\"?filter=^$test$\">$test:</a></b> $message<hr>";
      }
    } else {
      echo '<b style="background-color: lightgreen">ALL TESTS PASSED</b><hr>';
    }
    echo 'Runtime: ' . round(microtime(true) - $this->startTime, 2) . 's<hr>';

    Logger::Instance()->info('----- tearDownTestCase');
    $this->tearDownTestCase();
    $this->dumpAndClearLog();
  }

}
