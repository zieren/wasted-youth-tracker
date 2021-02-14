<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php';

require_once 'TestCase.php';

require_once '../common/common.php';
require_once '../common/Database.php';

require_once 'config_tests.php';

final class DatabaseTest extends TestCase {

  private $db;
  private $mockTime = 1000; // epoch seconds

  protected function setUpTestCase(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->db = Database::createForTest(
        TEST_DB_SERVER, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS,
            function() { return $this->mockTime(); });
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
  }

  protected function setUp(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->db->clearAllForTest();
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
  }

  private function mockTime() {
    return $this->mockTime;
  }

  private function newDateTime() {
    $d = new DateTime();
    $d->setTimestamp($this->mockTime);
    return $d;
  }

  public function testSmokeTest(): void {
    $this->db->getGlobalConfig();
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
