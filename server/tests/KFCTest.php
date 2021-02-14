<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';

function logDbQueryErrorInTest($params) {
  KFCTest::$lastDbError = $params;
  logDbQueryError($params);
}

final class KFCTest extends TestCase {

  public static $lastDbError = null;
  private $db;
  private $mockTime; // epoch seconds, reset to 1000 before each test

  protected function setUpTestCase(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->db = KFC::createForTest(
            TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS, function() {
          return $this->mockTime();
        });
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    // Instantiation of KFC above sets a DB error handler. Override it here.
    DB::$error_handler = 'logDbQueryErrorInTest';
    // TODO: Consider checking for errors (error_get_last() and DB error) in production code.
    // Errors often go unnoticed.
  }

  protected function setUp(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->db->clearAllForTest();
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    $this->mockTime = 1000;
  }

  protected function tearDown(): void {
    if (KFCTest::$lastDbError) {
      $message = "DB error: " . arrayToString(KFCTest::$lastDbError);
      KFCTest::$lastDbError = null;
      throw new Exception($message);
    }
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

    // Add 7 seconds for 'window 2'.
    $this->mockTime += 7;
    $this->db->insertWindowTitles('user_1', ['window 2'], 0);
    $m3 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m3, ['' => ['1970-01-01' => 37]]);
  }

  public function testSetUpBudgets(): void {
    $budgetId1 = $this->db->addBudget('b1');
    $budgetId2 = $this->db->addBudget('b2');
    $this->assertEquals($budgetId2 - $budgetId1, 1);

    $classId1 = $this->db->addClass('c1');
    $classId2 = $this->db->addClass('c2');
    $this->assertEquals($classId2 - $classId1, 1);

    $classificationId1 = $this->db->addClassification($classId1, 0, '1$');
    $classificationId2 = $this->db->addClassification($classId2, 10, '2$');
    $this->assertEquals($classificationId2 - $classificationId1, 1);

    $this->db->addMapping('user_1', $classId1, $budgetId1);

    $classification = $this->db->classify(['window 0', 'window 1', 'window 2']);

    $this->assertEquals($classification, [
        ['class_id' => 1, 'class_name' => DEFAULT_CLASS_NAME, 'budget_ids' => []],
        ['class_id' => $classId1, 'class_name' => 'c1', 'budget_ids' => [$budgetId1]],
        ['class_id' => $classId2, 'class_name' => 'c2', 'budget_ids' => []],
    ]);

    // TODO: Split this up.
    // Add a second mapping for the same class.
    /*$this->db->addMapping('user_1', $classId1, $budgetId2);

    $classification = $this->db->classify(['window 0', 'window 1', 'window 2']);

    // TODO: This fails because classes can only be mapped to one budget (via PK restriction).
    // Do we want to allow mapping them to multiple budgets? I think yes.
    // Before we do that and fix this test though: Catch DB errors and surface them.

    $this->assertEquals($classification, [
        ['class_id' => 1, 'class_name' => DEFAULT_CLASS_NAME, 'budget_ids' => []],
        ['class_id' => $classId1, 'class_name' => 'c1', 'budget_ids' => [$budgetId1, $budgetId2]],
        ['class_id' => $classId2, 'class_name' => 'c2', 'budget_ids' => []],
    ]);*/
  }

  public function testTimeSpentByTitle(): void {
    $fromTime = $this->newDateTime();
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $this->mockTime += 5;
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $this->mockTime += 6;
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $timeByTitle = $this->db->queryTimeSpentByTitle('user_1', $fromTime);
    var_dump($timeByTitle);
  }

}

(new KFCTest())->run();
