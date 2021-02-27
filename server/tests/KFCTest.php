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

  private function dateTimeString() {
    return $this->newDateTime()->format('Y-m-d H:i:s');
  }

  public function testSmokeTest(): void {
    $this->db->getGlobalConfig();
  }

  public function testTotalTime_SingleWindow_NoBudget(): void {
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

  public function testTotalTime_TwoWindows_NoBudget(): void {
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
    $this->assertEquals($m2, ['' => ['1970-01-01' => 5]]);

    // Add 10 seconds.
    $this->mockTime += 10;
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $m3 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m3, ['' => ['1970-01-01' => 15]]);

    // Add 7 seconds for 'window 2'.
    $this->mockTime += 7;
    $this->db->insertWindowTitles('user_1', ['window 2'], 0);
    $m3 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m3, ['' => ['1970-01-01' => 22]]);
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

    // Class 1 mapped to budget 1, other classes are not assigned to any budget.
    $classification = $this->db->classify(['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        ['class_id' => DEFAULT_CLASS_ID, 'class_name' => DEFAULT_CLASS_NAME, 'budget_ids' => []],
        ['class_id' => $classId1, 'class_name' => 'c1', 'budget_ids' => [$budgetId1]],
        ['class_id' => $classId2, 'class_name' => 'c2', 'budget_ids' => []],
    ]);

    // Add a second mapping for the same class.
    $this->db->addMapping('user_1', $classId1, $budgetId2);

    // Class 1 is now mapped to budgets 1 and 2.
    $classification = $this->db->classify(['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        ['class_id' => DEFAULT_CLASS_ID, 'class_name' => DEFAULT_CLASS_NAME, 'budget_ids' => []],
        ['class_id' => $classId1, 'class_name' => 'c1', 'budget_ids' => [$budgetId1, $budgetId2]],
        ['class_id' => $classId2, 'class_name' => 'c2', 'budget_ids' => []],
    ]);

    // Add a mapping for the default class.
    $this->db->addMapping('user_1', DEFAULT_CLASS_ID, $budgetId2);

    // Default class is now mapped to budget 2.
    $classification = $this->db->classify(['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        ['class_id' => DEFAULT_CLASS_ID, 'class_name' => DEFAULT_CLASS_NAME,
            'budget_ids' => [$budgetId2]],
        ['class_id' => $classId1, 'class_name' => 'c1', 'budget_ids' => [$budgetId1, $budgetId2]],
        ['class_id' => $classId2, 'class_name' => 'c2', 'budget_ids' => []],
    ]);
  }

  public function testTotalTime_TwoWindows_WithBudgets(): void {
    // Set up test budgets.
    $budgetId1 = $this->db->addBudget('b1');
    $budgetId2 = $this->db->addBudget('b2');
    $budgetId3 = $this->db->addBudget('b3');
    $classId1 = $this->db->addClass('c1');
    $classId2 = $this->db->addClass('c2');
    $classId3 = $this->db->addClass('c3');
    $this->db->addClassification($classId1, 0, '1$');
    $this->db->addClassification($classId2, 10, '2$');
    $this->db->addClassification($classId3, 20, '3$');
    // b1 <= default, c1
    // b2 <= c2
    // b3 <= c2, c3
    $this->db->addMapping('user_1', DEFAULT_CLASS_ID, $budgetId1);
    $this->db->addMapping('user_1', $classId1, $budgetId1);
    $this->db->addMapping('user_1', $classId2, $budgetId2);
    $this->db->addMapping('user_1', $classId2, $budgetId3);
    $this->db->addMapping('user_1', $classId3, $budgetId3);

    $fromTime = $this->newDateTime();

    // No records amount to an empty array.
    $m0 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m0, []);

    // A single record amounts to zero.
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $m1 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m1, [
        $budgetId1 => ['1970-01-01' => 0],
        $budgetId2 => ['1970-01-01' => 0],
        $budgetId3 => ['1970-01-01' => 0],
        ]);

    // Add 5 seconds.
    $this->mockTime += 5;
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $m2 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m2, [
        $budgetId1 => ['1970-01-01' => 5],
        $budgetId2 => ['1970-01-01' => 5],
        $budgetId3 => ['1970-01-01' => 5],
        ]);

    // Add 5 seconds of 'window 1' only.
    $this->mockTime += 5;
    $this->db->insertWindowTitles('user_1', ['window 1'], 0);
    $m2 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m2, [
        $budgetId1 => ['1970-01-01' => 10],
        $budgetId2 => ['1970-01-01' => 5],
        $budgetId3 => ['1970-01-01' => 5],
        ]);

    // Add 5 seconds of two windows of class 1.
    $this->mockTime += 5;
    $this->db->insertWindowTitles('user_1', ['window 1', 'another window 1'], 0);
    $m2 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m2, [
        $budgetId1 => ['1970-01-01' => 15],
        $budgetId2 => ['1970-01-01' => 5],
        $budgetId3 => ['1970-01-01' => 5],
        ]);

    // Add 10 seconds.
    $this->mockTime += 10;
    $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $m3 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m3, [
        $budgetId1 => ['1970-01-01' => 25],
        $budgetId2 => ['1970-01-01' => 15],
        $budgetId3 => ['1970-01-01' => 15],
        ]);

    // Add 7 seconds for 'window 2'.
    $this->mockTime += 7;
    $this->db->insertWindowTitles('user_1', ['window 2'], 0);
    $m4 = $this->db->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m4, [
        $budgetId1 => ['1970-01-01' => 25],
        $budgetId2 => ['1970-01-01' => 22],
        $budgetId3 => ['1970-01-01' => 22],
        ]);
  }

  public function testTimeSpentByTitle_singleWindow(): void {
    // First test single class case (default class), then two different classes.
    $class1 = DEFAULT_CLASS_NAME;
    $class2 = DEFAULT_CLASS_NAME;
    for ($i = 0; $i < 2; $i++) {
      $this->onFailMessage("i=$i");
      if ($i == 1) {
        // Set up test classes.
        $class1 = 'c1';
        $class2 = 'c2';
        $classId1 = $this->db->addClass($class1);
        $classId2 = $this->db->addClass($class2);
        $this->db->addClassification($classId1, 0, '1$');
        $this->db->addClassification($classId2, 10, '2$');
      }

      $fromTime = $this->newDateTime();

      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime),
          []);

      $this->db->insertWindowTitles('user_1', ['window 1'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 0, $class1, 'window 1']]);

      $this->mockTime += 5;
      $this->db->insertWindowTitles('user_1', ['window 1'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 5, $class1, 'window 1']]);

      $this->mockTime += 6;
      $this->db->insertWindowTitles('user_1', ['window 1'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 11, $class1, 'window 1']]);

      // Switch to different window.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->db->insertWindowTitles('user_1', ['window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 18, $class1, 'window 1'],
              [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->db->insertWindowTitles('user_1', ['window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 18, $class1, 'window 1'],
              [$dateTimeString2, 8, $class2, 'window 2']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString2 = $this->dateTimeString();
      $this->db->insertWindowTitles('user_1', ['window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString2, 28, $class2, 'window 2'],
              [$dateTimeString1, 18, $class1, 'window 1']]);
    }
  }

  public function IGNORE_testTimeSpentByTitle_multipleWindows(): void {
    // First test single class case (default class), then two different classes.
    $class1 = DEFAULT_CLASS_NAME;
    $class2 = DEFAULT_CLASS_NAME;
    for ($i = 0; $i < 2; $i++) {
      if ($i == 1) {
        // Set up test classes.
        $class1 = 'c1';
        $class2 = 'c2';
        $classId1 = $this->db->addClass($class1);
        $classId2 = $this->db->addClass($class2);
        $this->db->addClassification($classId1, 0, '1$');
        $this->db->addClassification($classId2, 10, '2$');
      }

      $fromTime = $this->newDateTime();

      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime),
          []);

      $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime),
          []);

      $this->mockTime += 5;
      $dateTimeString1 = $this->dateTimeString();
      $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 5, $class1, 'window 1'],
              [$dateTimeString1, 5, $class2, 'window 2']]);

      $this->mockTime += 6;
      $dateTimeString1 = $this->dateTimeString();
      $this->db->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 11, $class1, 'window 1'],
              [$dateTimeString1, 11, $class2, 'window 2']]);

      // Switch to different windows.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->db->insertWindowTitles('user_1', ['window 11', 'window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 18, $class1, 'window 2'],
              [$dateTimeString1, 18, $class2, 'window 1']]);
      // TODO/PUWIL: window 11 should (?) be listed w/ 7 seconds. Does this match the accounting?
/*


      $this->mockTime += 8;
      $dateTimeString2 = $this->newDateTime()->format('Y-m-d H:i:s');
      $this->db->insertWindowTitles('user_1', ['window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 18, $class1, 'window 1'],
              [$dateTimeString2, 8, $class2, 'window 2']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString2 = $this->newDateTime()->format('Y-m-d H:i:s');
      $this->db->insertWindowTitles('user_1', ['window 2'], 0);
      $this->assertEquals(
          $this->db->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString2, 28, $class2, 'window 2'],
              [$dateTimeString1, 18, $class1, 'window 1']]);
 */
    }
  }

  public function IGNORE_testCountIntervalsTowardsPreviousTitle(): void {
    // Set up test budgets.
    $budgetId1 = $this->db->addBudget('b1');
    $budgetId2 = $this->db->addBudget('b2');
    $budgetId3 = $this->db->addBudget('b3');
    $classId1 = $this->db->addClass('c1');
    $classId2 = $this->db->addClass('c2');
    $classId3 = $this->db->addClass('c3');
    $this->db->addClassification($classId1, 0, '1$');
    $this->db->addClassification($classId2, 10, '2$');
    $this->db->addClassification($classId3, 20, '3$');
    // b1 <= default, c1
    // b2 <= c2
    // b3 <= c2, c3
    $this->db->addMapping('user_1', DEFAULT_CLASS_ID, $budgetId1);
    $this->db->addMapping('user_1', $classId1, $budgetId1);
    $this->db->addMapping('user_1', $classId2, $budgetId2);
    $this->db->addMapping('user_1', $classId2, $budgetId3);
    $this->db->addMapping('user_1', $classId3, $budgetId3);
  }

}

(new KFCTest())->run();
