<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';

final class KFCTest extends KFCTestBase {
  protected $kfc;

  protected function setUpTestCase(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->kfc = KFC::createForTest(
            TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS, function() {
          return $this->mockTime();
        });
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    $this->setErrorHandler();
    // TODO: Consider checking for errors (error_get_last() and DB error) in production code.
    // Errors often go unnoticed.
  }

  protected function setUp(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->kfc->clearAllForTest();
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    $this->mockTime = 1000;
  }

  public function testSmokeTest(): void {
    $this->kfc->getGlobalConfig();
  }

  public function testTotalTime_SingleWindow_NoBudget(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        []);

    // A single record amounts to zero.
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 0]]);

    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 5]]);

    $this->mockTime += 6;
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 11]]);

    // Switch window (no effect, same budget).
    $this->mockTime += 7;
    $this->kfc->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 18]]);
  }

  public function testTotalTime_TwoWindows_NoBudget(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $m0 = $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null);
    $this->assertEquals($m0, []);

    // A single record amounts to zero.
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 0]]);

    // Advance 5 seconds. Still two windows, but same budget, so total time is 5 seconds.
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 5]]);

    // Same with another 6 seconds.
    $this->mockTime += 6;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 11]]);

    // Switch to 'window 2'.
    $this->mockTime += 7;
    $this->kfc->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 18]]);

    $this->mockTime += 8;
    $this->kfc->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 26]]);
  }

  public function testSetUpBudgets(): void {
    $budgetId1 = $this->kfc->addBudget('b1');
    $budgetId2 = $this->kfc->addBudget('b2');
    $this->assertEquals($budgetId2 - $budgetId1, 1);

    $classId1 = $this->kfc->addClass('c1');
    $classId2 = $this->kfc->addClass('c2');
    $this->assertEquals($classId2 - $classId1, 1);

    $classificationId1 = $this->kfc->addClassification($classId1, 0, '1$');
    $classificationId2 = $this->kfc->addClassification($classId2, 10, '2$');
    $this->assertEquals($classificationId2 - $classificationId1, 1);

    $this->kfc->addMapping('user_1', $classId1, $budgetId1);

    // Class 1 mapped to budget 1, other classes are not assigned to any budget.
    $classification = $this->kfc->classify(['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        ['class_id' => DEFAULT_CLASS_ID, 'class_name' => DEFAULT_CLASS_NAME, 'budget_ids' => []],
        ['class_id' => $classId1, 'class_name' => 'c1', 'budget_ids' => [$budgetId1]],
        ['class_id' => $classId2, 'class_name' => 'c2', 'budget_ids' => []],
    ]);

    // Add a second mapping for the same class.
    $this->kfc->addMapping('user_1', $classId1, $budgetId2);

    // Class 1 is now mapped to budgets 1 and 2.
    $classification = $this->kfc->classify(['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        ['class_id' => DEFAULT_CLASS_ID, 'class_name' => DEFAULT_CLASS_NAME, 'budget_ids' => []],
        ['class_id' => $classId1, 'class_name' => 'c1', 'budget_ids' => [$budgetId1, $budgetId2]],
        ['class_id' => $classId2, 'class_name' => 'c2', 'budget_ids' => []],
    ]);

    // Add a mapping for the default class.
    $this->kfc->addMapping('user_1', DEFAULT_CLASS_ID, $budgetId2);

    // Default class is now mapped to budget 2.
    $classification = $this->kfc->classify(['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        ['class_id' => DEFAULT_CLASS_ID, 'class_name' => DEFAULT_CLASS_NAME,
            'budget_ids' => [$budgetId2]],
        ['class_id' => $classId1, 'class_name' => 'c1', 'budget_ids' => [$budgetId1, $budgetId2]],
        ['class_id' => $classId2, 'class_name' => 'c2', 'budget_ids' => []],
    ]);
  }

  public function testTotalTime_SingleWindow_WithBudgets(): void {
    // Set up test budgets.
    $budgetId1 = $this->kfc->addBudget('b1');
    $budgetId2 = $this->kfc->addBudget('b2');
    $classId1 = $this->kfc->addClass('c1');
    $classId2 = $this->kfc->addClass('c2');
    $this->kfc->addClassification($classId1, 0, '1$');
    $this->kfc->addClassification($classId2, 10, '2$');
    // b1 <= default, c1
    // b2 <= c2
    $this->kfc->addMapping('user_1', DEFAULT_CLASS_ID, $budgetId1);
    $this->kfc->addMapping('user_1', $classId1, $budgetId1);
    $this->kfc->addMapping('user_1', $classId2, $budgetId2);

    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        []);

    // A single record amounts to zero.
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        [$budgetId1 => ['1970-01-01' => 0]]);

    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        [$budgetId1 => ['1970-01-01' => 5]]);

    $this->mockTime += 6;
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        [$budgetId1 => ['1970-01-01' => 11]]);

    // Switch window. First interval still counts towards previous window/budget.
    $this->mockTime += 7;
    $this->kfc->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        [
            $budgetId1 => ['1970-01-01' => 18],
            $budgetId2 => ['1970-01-01' => 0]
        ]);
  }

  public function testTotalTime_TwoWindows_WithBudgets(): void {
    // Set up test budgets.
    $budgetId1 = $this->kfc->addBudget('b1');
    $budgetId2 = $this->kfc->addBudget('b2');
    $budgetId3 = $this->kfc->addBudget('b3');
    $classId1 = $this->kfc->addClass('c1');
    $classId2 = $this->kfc->addClass('c2');
    $classId3 = $this->kfc->addClass('c3');
    $this->kfc->addClassification($classId1, 0, '1$');
    $this->kfc->addClassification($classId2, 10, '2$');
    $this->kfc->addClassification($classId3, 20, '3$');
    // b1 <= default, c1
    // b2 <= c2
    // b3 <= c2, c3
    $this->kfc->addMapping('user_1', DEFAULT_CLASS_ID, $budgetId1);
    $this->kfc->addMapping('user_1', $classId1, $budgetId1);
    $this->kfc->addMapping('user_1', $classId2, $budgetId2);
    $this->kfc->addMapping('user_1', $classId2, $budgetId3);
    $this->kfc->addMapping('user_1', $classId3, $budgetId3);

    $fromTime = $this->newDateTime();

    // No records amount to an empty array.
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        []);

    // Start with a single window. Will not return anything for unused budgets.
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        [$budgetId1 => ['1970-01-01' => 0]]);

    // Advance 5 seconds and observe second window.
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null), [
            $budgetId1 => ['1970-01-01' => 5],
            $budgetId2 => ['1970-01-01' => 0],
            $budgetId3 => ['1970-01-01' => 0]]);

    // Observe both again after 5 seconds.
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null), [
            $budgetId1 => ['1970-01-01' => 10],
            $budgetId2 => ['1970-01-01' => 5],
            $budgetId3 => ['1970-01-01' => 5]]);

    // Advance 5 seconds and observe 'window 1' only.
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null), [
            $budgetId1 => ['1970-01-01' => 15],
            $budgetId2 => ['1970-01-01' => 10],
            $budgetId3 => ['1970-01-01' => 10]]);

    // Add 6 seconds and start two windows of class 1.
    $this->mockTime += 6;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'another window 1']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null), [
            $budgetId1 => ['1970-01-01' => 21],
            $budgetId2 => ['1970-01-01' => 10],
            $budgetId3 => ['1970-01-01' => 10]]);

    // Add 7 seconds and observe both windows of class 1 again.
    $this->mockTime += 7;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null), [
            $budgetId1 => ['1970-01-01' => 28],
            $budgetId2 => ['1970-01-01' => 10],
            $budgetId3 => ['1970-01-01' => 10]]);

    // Add 8 seconds and observe 'window 2'.
    $this->mockTime += 8;
    $this->kfc->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null), [
            $budgetId1 => ['1970-01-01' => 36],
            $budgetId2 => ['1970-01-01' => 18],
            $budgetId3 => ['1970-01-01' => 18]]);
  }

  public function testTimeSpentByTitle_singleWindow(): void {
    // First test single class case (default class), then two different classes.
    $class1 = DEFAULT_CLASS_NAME;
    $class2 = DEFAULT_CLASS_NAME;
    for ($i = 0; $i < 2; $i++) {
      $this->onFailMessage("i=$i");
      if ($i == 1) {
        $this->tearDown();
        $this->setUp();
        // Set up test classes.
        $class1 = 'c1';
        $class2 = 'c2';
        $classId1 = $this->kfc->addClass($class1);
        $classId2 = $this->kfc->addClass($class2);
        $this->kfc->addClassification($classId1, 0, '1$');
        $this->kfc->addClassification($classId2, 10, '2$');
      }

      $fromTime = $this->newDateTime();

      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime),
          []);

      $this->kfc->insertWindowTitles('user_1', ['window 1']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 0, $class1, 'window 1']]);

      $this->mockTime += 5;
      $this->kfc->insertWindowTitles('user_1', ['window 1']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 5, $class1, 'window 1']]);

      $this->mockTime += 6;
      $this->kfc->insertWindowTitles('user_1', ['window 1']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 11, $class1, 'window 1']]);

      // Switch to different window.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 2']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 18, $class1, 'window 1'],
              [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 2']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 18, $class1, 'window 1'],
              [$dateTimeString2, 8, $class2, 'window 2']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString2 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 2']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString2, 28, $class2, 'window 2'],
              [$dateTimeString1, 18, $class1, 'window 1']]);
    }
  }

  public function testTimeSpentByTitle_multipleWindows(): void {
    // First test single class case (default class), then two different classes.
    $class1 = DEFAULT_CLASS_NAME;
    $class2 = DEFAULT_CLASS_NAME;
    for ($i = 0; $i < 2; $i++) {
      $this->onFailMessage("i=$i");
      if ($i == 1) {
        $this->tearDown();
        $this->setUp();
        // Set up test classes.
        $class1 = 'c1';
        $class2 = 'c2';
        $classId1 = $this->kfc->addClass($class1);
        $classId2 = $this->kfc->addClass($class2);
        $this->kfc->addClassification($classId1, 0, '1$');
        $this->kfc->addClassification($classId2, 10, '2$');
      }

      $fromTime = $this->newDateTime();

      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime),
          []);

      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 0, $class1, 'window 1'],
              [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 5;
      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 5, $class1, 'window 1'],
              [$dateTimeString1, 5, $class2, 'window 2']]);

      $this->mockTime += 6;
      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 11, $class1, 'window 1'],
              [$dateTimeString1, 11, $class2, 'window 2']]);

      // Switch to different windows.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 11', 'window 2']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString1, 18, $class1, 'window 1'],
              [$dateTimeString1, 18, $class2, 'window 2'],
              [$dateTimeString1, 0, $class1, 'window 11']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 2']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString2, 26, $class2, 'window 2'],
              [$dateTimeString1, 18, $class1, 'window 1'],
              [$dateTimeString2, 8, $class1, 'window 11']]);

      // Switch to window 1.
      $this->mockTime += 1;
      $dateTimeString3 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 1']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString3, 27, $class2, 'window 2'],
              [$dateTimeString3, 18, $class1, 'window 1'],
              [$dateTimeString2, 8, $class1, 'window 11']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString4 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 42']);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
              [$dateTimeString4, 38, $class1, 'window 1'],
              [$dateTimeString3, 27, $class2, 'window 2'],
              [$dateTimeString2, 8, $class1, 'window 11'],
              [$dateTimeString4, 0, $class2, 'window 42']]);
    }
  }

  public function testIgnoreEmptyTitle(): void {
    $fromTime = $this->newDateTime();
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['']);

    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 10]]);

    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['']);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime, null),
        ['' => ['1970-01-01' => 10]]);
  }

  public function testTimeLeftTodayAllBudgets_negative(): void {
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1']);
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['']);

    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('user_1'),
        ['' => -10]);
  }

  public function testZeroWeeklyLimit(): void {
    $budgetId = $this->kfc->addBudget('b');
    $this->kfc->addMapping('u', DEFAULT_CLASS_ID, $budgetId);

    // Budgets default to zero.
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u'),
        [$budgetId => 0]);

    // Daily limit is 42 minutes.
    $this->kfc->setBudgetConfig($budgetId, 'daily_limit_minutes_default', 42);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u'),
        [$budgetId => 42 * 60]);

    // The weekly limit cannot extend the daily limit.
    $this->kfc->setBudgetConfig($budgetId, 'weekly_limit_minutes', 666);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u'),
        [$budgetId => 42 * 60]);

    // The weekly limit can shorten the daily limit.
    $this->kfc->setBudgetConfig($budgetId, 'weekly_limit_minutes', 5);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u'),
        [$budgetId => 5 * 60]);

    // The weekly limit can also be zero.
    $this->kfc->setBudgetConfig($budgetId, 'weekly_limit_minutes', 0);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u'),
        [$budgetId => 0]);

    // Clear the limit.
    $this->kfc->clearBudgetConfig($budgetId, 'weekly_limit_minutes');
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u'),
        [$budgetId => 42 * 60]);
  }

  public function testGetAllBudgetConfigs(): void {
    // No budgets configured.
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs('u'),
        []);

    $budgetId = $this->kfc->addBudget('b');

    // A mapping is required for the budget to be returned for the user.
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs('u'),
        []);
    $this->kfc->addMapping('u', DEFAULT_CLASS_ID, $budgetId);
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs('u'),
        [$budgetId => ['name' => 'b']]);

    // Add a config.
    $this->kfc->setBudgetConfig($budgetId, 'foo', 'bar');
    $this->assertEqualsIgnoreOrder(
        $this->kfc->getAllBudgetConfigs('u'),
        [$budgetId => ['foo' => 'bar', 'name' => 'b']]);
  }

  public function testManageBudgets(): void {
    // No budgets set up.
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs(),
        []);
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs('u1'),
        []);
    // Add a budget but no maping yet.
    $budgetId1 = $this->kfc->addBudget('b1');
    // Returned when user restriction is absent.
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs(),
        [$budgetId1 => ['name' => 'b1']]);
    // Not returned when user restriction is present.
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs('u1'),
        []);

    // Add a mapping.
    $this->assertEqualsIgnoreOrder(
        $this->kfc->classify(['foo']), [[
            'class_id' => DEFAULT_CLASS_ID,
            'class_name' => DEFAULT_CLASS_NAME,
            'budget_ids' => [],
            ]]);
    $this->kfc->addMapping('u1', DEFAULT_CLASS_ID, $budgetId1);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->classify(['foo']), [[
            'class_id' => DEFAULT_CLASS_ID,
            'class_name' => DEFAULT_CLASS_NAME,
            'budget_ids' => [$budgetId1],
            ]]);
    // Returned when user restriction is absent.
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs(),
        [$budgetId1 => ['name' => 'b1']]);
    // Now also returned when user restriction is present.
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs('u1'),
        [$budgetId1 => ['name' => 'b1']]);
    // But not for another user.
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs('u2'),
        []);

    // Add budget config.
    $this->kfc->setBudgetConfig($budgetId1, 'foo', 'bar');
    $this->assertEqualsIgnoreOrder(
        $this->kfc->getAllBudgetConfigs(),
        [$budgetId1 => ['name' => 'b1', 'foo' => 'bar']]);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->getAllBudgetConfigs('u1'),
        [$budgetId1 => ['name' => 'b1', 'foo' => 'bar']]);

    // Remove budget, this cascades to mappings and config.
    $this->kfc->removeBudget('b1');
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs(),
        []);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->classify(['foo']), [[
            'class_id' => DEFAULT_CLASS_ID,
            'class_name' => DEFAULT_CLASS_NAME,
            'budget_ids' => [],
            ]]);
  }

}

(new KFCTest())->run();
