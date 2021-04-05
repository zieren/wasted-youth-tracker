<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';
require_once '../rx/RX.php';

final class KFCTest extends KFCTestBase {

  /** @var KFC */
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

  private function classification($id, $budgets) {
    $c = ['class_id' => $id, 'budgets' => $budgets];
    return $c;
  }

  public function testSmokeTest(): void {
    $this->kfc->getGlobalConfig();
  }

  public function testTotalTime_SingleWindow_NoBudget(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        []);

    // A single record amounts to zero.
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 0]]);

    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 5]]);

    $this->mockTime += 6;
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 11]]);

    // Switch window (no effect, same budget).
    $this->mockTime += 7;
    $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 18]]);
  }

  public function testTotalTime_TwoWindows_NoBudget(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $m0 = $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime);
    $this->assertEquals($m0, []);

    // A single record amounts to zero.
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 0]]);

    // Advance 5 seconds. Still two windows, but same budget, so total time is 5 seconds.
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 5]]);

    // Same with another 6 seconds.
    $this->mockTime += 6;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 11]]);

    // Switch to 'window 2'.
    $this->mockTime += 7;
    $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 18]]);

    $this->mockTime += 8;
    $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
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
    $classification = $this->kfc->classify('user_1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId1, [$budgetId1]),
        $this->classification($classId2, [0]),
    ]);

    // Add a second mapping for the same class.
    $this->kfc->addMapping('user_1', $classId1, $budgetId2);

    // Class 1 is now mapped to budgets 1 and 2.
    $classification = $this->kfc->classify('user_1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId1, [$budgetId1, $budgetId2]),
        $this->classification($classId2, [0]),
    ]);

    // Add a mapping for the default class.
    $this->kfc->addMapping('user_1', DEFAULT_CLASS_ID, $budgetId2);

    // Default class is now mapped to budget 2.
    $classification = $this->kfc->classify('user_1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        $this->classification(DEFAULT_CLASS_ID, [$budgetId2]),
        $this->classification($classId1, [$budgetId1, $budgetId2]),
        $this->classification($classId2, [0]),
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
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        []);

    // A single record amounts to zero.
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [$budgetId1 => ['1970-01-01' => 0]]);

    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [$budgetId1 => ['1970-01-01' => 5]]);

    $this->mockTime += 6;
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [$budgetId1 => ['1970-01-01' => 11]]);

    // Switch window. First interval still counts towards previous window/budget.
    $this->mockTime += 7;
    $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
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
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        []);

    // Start with a single window. Will not return anything for unused budgets.
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [$budgetId1 => ['1970-01-01' => 0]]);

    // Advance 5 seconds and observe second window.
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $budgetId1 => ['1970-01-01' => 5],
        $budgetId2 => ['1970-01-01' => 0],
        $budgetId3 => ['1970-01-01' => 0]]);

    // Observe both again after 5 seconds.
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $budgetId1 => ['1970-01-01' => 10],
        $budgetId2 => ['1970-01-01' => 5],
        $budgetId3 => ['1970-01-01' => 5]]);

    // Advance 5 seconds and observe 'window 1' only.
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $budgetId1 => ['1970-01-01' => 15],
        $budgetId2 => ['1970-01-01' => 10],
        $budgetId3 => ['1970-01-01' => 10]]);

    // Add 6 seconds and start two windows of class 1.
    $this->mockTime += 6;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'another window 1'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $budgetId1 => ['1970-01-01' => 21],
        $budgetId2 => ['1970-01-01' => 10],
        $budgetId3 => ['1970-01-01' => 10]]);

    // Add 7 seconds and observe both windows of class 1 again.
    $this->mockTime += 7;
    $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $budgetId1 => ['1970-01-01' => 28],
        $budgetId2 => ['1970-01-01' => 10],
        $budgetId3 => ['1970-01-01' => 10]]);

    // Add 8 seconds and observe 'window 2'.
    $this->mockTime += 8;
    $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
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

      $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 0, $class1, 'window 1']]);

      $this->mockTime += 5;
      $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 5, $class1, 'window 1']]);

      $this->mockTime += 6;
      $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 11, $class1, 'window 1']]);

      // Switch to different window.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class2, 'window 2']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString2 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
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
      $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 0, $class1, 'window 1'],
          [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 5;
      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 5, $class1, 'window 1'],
          [$dateTimeString1, 5, $class2, 'window 2']]);

      $this->mockTime += 6;
      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 1', 'window 2'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 11, $class1, 'window 1'],
          [$dateTimeString1, 11, $class2, 'window 2']]);

      // Switch to different windows.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 11', 'window 2'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString1, 18, $class2, 'window 2'],
          [$dateTimeString1, 0, $class1, 'window 11']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 2'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString2, 26, $class2, 'window 2'],
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class1, 'window 11']]);

      // Switch to window 1.
      $this->mockTime += 1;
      $dateTimeString3 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString3, 27, $class2, 'window 2'],
          [$dateTimeString3, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class1, 'window 11']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString4 = $this->dateTimeString();
      $this->kfc->insertWindowTitles('user_1', ['window 42'], 0);
      $this->assertEquals(
          $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString4, 38, $class1, 'window 1'],
          [$dateTimeString3, 27, $class2, 'window 2'],
          [$dateTimeString2, 8, $class1, 'window 11'],
          [$dateTimeString4, 0, $class2, 'window 42']]);
    }
  }

  public function testReplaceEmptyTitle(): void {
    $fromTime = $this->newDateTime();
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', [''], 0);
    $window1LastSeen = $this->dateTimeString();
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', [''], 0);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 15]]);

    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByTitle('user_1', $fromTime), [
            [$window1LastSeen, 10, DEFAULT_CLASS_NAME, 'window 1'],
            [$this->dateTimeString(), 5, DEFAULT_CLASS_NAME, '(no title)']]);
  }

  public function testWeeklyLimit(): void {
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
    $this->assertEqualsIgnoreOrder(
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
    $this->assertEquals(
        $this->kfc->classify('u1', ['foo']), [
            $this->classification(DEFAULT_CLASS_ID, [0])
            ]);
    $this->kfc->addMapping('u1', DEFAULT_CLASS_ID, $budgetId1);
    $this->assertEquals(
        $this->kfc->classify('u1', ['foo']), [
            $this->classification(DEFAULT_CLASS_ID, [$budgetId1])
            ]);
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
    $this->kfc->removeBudget($budgetId1);
    $this->assertEquals(
        $this->kfc->getAllBudgetConfigs(),
        []);
    $this->assertEquals(
        $this->kfc->classify('u1', ['foo']), [
            $this->classification(DEFAULT_CLASS_ID, [0])
            ]);
  }

  public function testTimeLeftTodayAllBudgets_negative(): void {
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', ['window 1'], 0);
    $this->mockTime += 5;
    $this->kfc->insertWindowTitles('user_1', [], -1);

    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('user_1'),
        ['' => -10]);
  }

  public function testClassificationWithBudget_multipleUsers(): void {
    $this->assertEquals(
        $this->kfc->classify('u1', ['title 1']),
        [$this->classification(DEFAULT_CLASS_ID, [0])]);

    $classId = $this->kfc->addClass('c1');
    $this->kfc->addClassification($classId, 42, '1$');
    $budgetId = $this->kfc->addBudget('b1');
    $this->kfc->addMapping('u2', $classId, $budgetId);

    // No budget is mapped for user u1. The window is classified, but no budget is associated.

    $this->assertEquals(
        $this->kfc->classify('u1', ['title 1']),
        [$this->classification($classId, [0])]);
  }

  public function testTimeLeftTodayAllBudgets_consumeTimeAndClassify(): void {
    $classId = $this->kfc->addClass('c1');
    $this->kfc->addClassification($classId, 42, '1$');
    $budgetId1 = $this->kfc->addBudget('b1');

    // No mapping yet to this budget for this user.
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        []);

    // Once the budget has at least one mapping it is listed.
    $this->kfc->addMapping('u1', $classId, $budgetId1);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId1 => 0]);

    // Provide 2 minutes.
    $this->kfc->setBudgetConfig($budgetId1, 'daily_limit_minutes_default', 2);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId1 => 120]);

    // Start consuming time.
    $classification1 = $this->classification($classId, [$budgetId1]);

    $this->assertEquals(
        $this->kfc->insertWindowTitles('u1', ['title 1'], 0),
        [$classification1]);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId1 => 120]);

    $this->mockTime += 15;
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 1'], 0),
        [$classification1]);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId1 => 105]);

    // Add a window that maps to no budget.
    $this->mockTime += 15;
    $classification2 = $this->classification(DEFAULT_CLASS_ID, [0]);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 1', 'title 2'], 0),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [null => 0, $budgetId1 => 90]);
    $this->mockTime += 15;
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 1', 'title 2'], 0),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [null => -15, $budgetId1 => 75]);

    // Add a second budget for title 1 with only 1 minute.
    $budgetId2 = $this->kfc->addBudget('b2');
    $this->kfc->addMapping('u1', $classId, $budgetId2);
    $this->kfc->setBudgetConfig($budgetId2, 'daily_limit_minutes_default', 1);
    $this->mockTime += 1;
    $classification1['budgets'][] = $budgetId2;
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 1', 'title 2'], 0),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [null => -16, $budgetId1 => 74, $budgetId2 => 14]);
  }

  public function testInsertClassification(): void {
    $classId1 = $this->kfc->addClass('c1');
    $classId2 = $this->kfc->addClass('c2');
    $this->kfc->addClassification($classId1, 0, '1$');
    $this->kfc->addClassification($classId2, 10, '2$');

    // Single window, with focus.
    $this->assertEquals(
        $this->kfc->insertWindowTitles('u1', ['title 2'], 0), [
            $this->classification($classId2, [0])]);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [null => 0]);

    // Single window, no focus.
    $this->assertEquals(
        $this->kfc->insertWindowTitles('u1', ['title 2'], -1), [
            $this->classification($classId2, [0])]);

    // Two windows, with focus.
    $this->assertEquals(
        $this->kfc->insertWindowTitles('u1', ['title 1', 'title 2'], 0), [
            $this->classification($classId1, [0]),
            $this->classification($classId2, [0])]);

    // Two windows, no focus.
    $this->assertEquals(
        $this->kfc->insertWindowTitles('u1', ['title 1', 'title 2'], -1), [
            $this->classification($classId1, [0]),
            $this->classification($classId2, [0])]);

    // No window at all.
    $this->assertEquals(
        $this->kfc->insertWindowTitles('u1', [], -1), []);

    // Time did not advance.
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [null => 0]);
  }

  public function testNoWindowsOpen(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->kfc->addClass('c1');
    $this->kfc->addClassification($classId, 42, '1$');
    $budgetId = $this->kfc->addBudget('b1');
    $this->kfc->addMapping('u1', $classId, $budgetId);

    $this->assertEquals(
        $this->kfc->insertWindowTitles('u1', ['window 1'], 0), [
            $this->classification($classId, [$budgetId])]);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId => 0]);

    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['window 1'], 0), [
            $this->classification($classId, [$budgetId])]);
    $this->assertEquals(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId => -1]);

    // All windows closed. Bill time to last window.
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', [], -1),
        []);

    // Used 2 seconds.
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        [$budgetId => ['1970-01-01' => 2]]);

    // Time advances.
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', [], -1),
        []);

    // Still only used 2 seconds because nothing was open.
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        [$budgetId => ['1970-01-01' => 2]]);
  }

  public function testTimeSpent_handleNoWindows(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->kfc->addClass('c1');
    $this->kfc->addClassification($classId, 0, '1$');

    $this->kfc->insertWindowTitles('u1', ['window 1'], 0);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['window 1'], 0);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['window 1'], 0);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['window 1', 'window 2'], 0);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['window 3'], 0);
    $lastSeenWindow1 = $this->dateTimeString();
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['window 3'], 0);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['window 3'], -1);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', [], -1);
    $lastSeenWindow3 = $this->dateTimeString();
    $this->mockTime += 15;
    $this->kfc->insertWindowTitles('u1', [], -1);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['window 2'], -1);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['window 2'], -1);
    $lastSeenWindow2 = $this->dateTimeString();

    // "No windows" events are handled correctly for both listing titles and computing time spent.
    $this->assertEquals(
        $this->kfc->queryTimeSpentByTitle('u1', $fromTime), [
            [$lastSeenWindow1, 4, 'c1', 'window 1'],
            [$lastSeenWindow3, 3, DEFAULT_CLASS_NAME, 'window 3'],
            [$lastSeenWindow2, 2, DEFAULT_CLASS_NAME, 'window 2']]);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => ['1970-01-01' => 8]]);
  }

  public function testSameTitle(): void {
    $fromTime = $this->newDateTime();
    $this->kfc->insertWindowTitles('u1', ['Calculator', 'Calculator'], 0);
    $this->mockTime++;
    $this->kfc->insertWindowTitles('u1', ['Calculator', 'Calculator'], 0);
    $lastSeen = $this->dateTimeString();
    $this->assertEquals(
        $this->kfc->queryTimeSpentByTitle('u1', $fromTime), [
            [$lastSeen, 1, DEFAULT_CLASS_NAME, 'Calculator']]);
    $this->assertEquals(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => ['1970-01-01' => 1]]);
  }

  public function testHandleRequest_invalidRequests(): void {
    foreach (['', "u1\nfoo", "\n123"] as $content) {
      $this->onFailMessage("content: $content");
      $this->assertEquals(
          explode("\n", RX::handleRequest($content, $this->kfc), 1)[0],
          "error\nInvalid request");
    }
  }

  public function testHandleRequest_smokeTest(): void {
    $this->assertEquals(
        RX::handleRequest("u1\n-1", $this->kfc),
        '');
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 1", $this->kfc),
        "0:0:no_budget\n\n0");
  }

  public function testHandleRequest_withBudgets(): void {
    $classId1 = $this->kfc->addClass('c1');
    $this->kfc->addClassification($classId1, 0, '1$');
    $budgetId1 = $this->kfc->addBudget('b1');
    $this->kfc->addMapping('u1', $classId1, $budgetId1);
    $this->kfc->setBudgetConfig($budgetId1, 'daily_limit_minutes_default', 5);

    $this->assertEquals(
        RX::handleRequest("u1\n-1", $this->kfc),
        $budgetId1 . ":300:b1\n");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 1", $this->kfc),
        $budgetId1 . ":300:b1\n\n$budgetId1");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 1", $this->kfc),
        $budgetId1 . ":299:b1\n\n$budgetId1");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 1\nfoo", $this->kfc),
        "0:0:no_budget\n$budgetId1:298:b1\n\n$budgetId1\n0");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 1\nfoo", $this->kfc),
        "0:-1:no_budget\n$budgetId1:297:b1\n\n$budgetId1\n0");

    // Flip order.
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\nfoo\ntitle 1", $this->kfc),
        "0:-2:no_budget\n$budgetId1:296:b1\n\n0\n" . $budgetId1);

    // Add second budget.
    $classId2 = $this->kfc->addClass('c2');
    $this->kfc->addClassification($classId2, 10, '2$');
    $budgetId2 = $this->kfc->addBudget('b2');
    $this->kfc->addMapping('u1', $classId1, $budgetId2);
    $this->kfc->addMapping('u1', $classId2, $budgetId2);
    $this->kfc->setBudgetConfig($budgetId2, 'daily_limit_minutes_default', 2);

    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 1\nfoo", $this->kfc),
        "0:-3:no_budget\n$budgetId1:295:b1\n$budgetId2:115:b2\n\n$budgetId1,$budgetId2\n0");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 1\ntitle 2", $this->kfc),
        "0:-4:no_budget\n$budgetId1:294:b1\n$budgetId2:114:b2\n\n"
        . "$budgetId1,$budgetId2\n$budgetId2");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 2", $this->kfc),
        "0:-4:no_budget\n$budgetId1:293:b1\n$budgetId2:113:b2\n\n$budgetId2");
    $this->mockTime++; // This still counts towards b2.
    $this->assertEquals(
        RX::handleRequest("u1\n-1", $this->kfc),
        "0:-4:no_budget\n$budgetId1:293:b1\n$budgetId2:112:b2\n");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u1\n0\ntitle 2", $this->kfc),
        "0:-4:no_budget\n$budgetId1:293:b1\n$budgetId2:112:b2\n\n$budgetId2");
  }

  public function testHandleRequest_mappedForOtherUser(): void {
    $classId = $this->kfc->addClass('c1');
    $this->kfc->addClassification($classId, 0, '1$');
    $budgetId = $this->kfc->addBudget('b1');
    $this->kfc->addMapping('u1', $classId, $budgetId);
    $this->kfc->setBudgetConfig($budgetId, 'daily_limit_minutes_default', 1);

    $this->assertEquals(RX::handleRequest("u2\n-1", $this->kfc), '');
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u2\n0\ntitle 1", $this->kfc),
        "0:0:no_budget\n\n0");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u2\n0\ntitle 1", $this->kfc),
        "0:-1:no_budget\n\n0");

    // Now map for user u2.
    $this->kfc->addMapping('u2', $classId, $budgetId);
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest("u2\n0\ntitle 1", $this->kfc),
        "$budgetId:58:b1\n\n$budgetId");
  }

  public function testSetOverrideMinutesAndUnlock(): void {
    $budgetId = $this->kfc->addBudget('b1');
    $this->kfc->addMapping('u1', DEFAULT_CLASS_ID, $budgetId);
    $this->kfc->setBudgetConfig($budgetId, 'daily_limit_minutes_default', 42);
    $this->kfc->setBudgetConfig($budgetId, 'require_unlock', 1);

    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId => 0]);

    $this->kfc->setOverrideUnlock('u1', $this->dateString(), $budgetId);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId => 42 * 60]);

    $this->kfc->setOverrideMinutes('u1', $this->dateString(), $budgetId, 666);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId => 666 * 60]);

    // Test updating.
    $this->kfc->setOverrideMinutes('u1', $this->dateString(), $budgetId, 123);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId => 123 * 60]);

    $this->kfc->clearOverride('u1', $this->dateString(), $budgetId);

    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId => 0]);

    $this->kfc->setOverrideUnlock('u1', $this->dateString(), $budgetId);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeLeftTodayAllBudgets('u1'),
        [$budgetId => 42 * 60]);
  }

  public function testConcurrentRequests(): void {
    $fromTime = $this->newDateTime();

    $classId1 = $this->kfc->addClass('c1');
    $this->kfc->addClassification($classId1, 0, '1$');
    $budgetId1 = $this->kfc->addBudget('b1');
    $this->kfc->addMapping('u1', $classId1, $budgetId1);

    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 2'], 0),
        [$this->classification(DEFAULT_CLASS_ID, [0])]);
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 2'], 0),
        [$this->classification(DEFAULT_CLASS_ID, [0])]);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => ['1970-01-01' => 1]]);

    // Repeating the last call is idempotent.
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 2'], 0),
        [$this->classification(DEFAULT_CLASS_ID, [0])]);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => ['1970-01-01' => 1]]);

    // Add a title that matches the budget, but don't elapse time for it yet. This will extend the
    // title from the previous call.
    $classification2and1 = [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId1, [$budgetId1])];
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 2', 'title 1'], 0),
        $classification2and1);
    $timeSpent2and1 = [
        '' => ['1970-01-01' => 1],
        $budgetId1 => ['1970-01-01' => 0]];
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        $timeSpent2and1);

    // Repeating the previous insertion is idempotent.
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 2', 'title 1'], 0),
        $classification2and1);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        $timeSpent2and1);

    // Changing the classification rules between concurrent reqeusts creates a second activity
    // record that differs only in class_id (which is part of the PK).
    $classId2 = $this->kfc->addClass('c2');
    $this->kfc->addClassification($classId2, 10 /* higher priority */, '1$');
    $budgetId2 = $this->kfc->addBudget('b2');
    $this->kfc->addMapping('u1', $classId2, $budgetId2);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 2', 'title 1'], 0), [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId2, [$budgetId2])]); // changed to c2, which maps to b2

    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
        '' => ['1970-01-01' => 1],
        $budgetId1 => ['1970-01-01' => 0],
        $budgetId2 => ['1970-01-01' => 0]]);

    // Accumulate time.
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->kfc->insertWindowTitles('u1', ['title 2', 'title 1'], 0), [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId2, [$budgetId2])]);

    // Check results.
    $this->assertEquals(
        $this->kfc->queryTimeSpentByTitle('u1', $fromTime), [
            [$this->dateTimeString(), 2, DEFAULT_CLASS_NAME, 'title 2'],
            [$this->dateTimeString(), 1, 'c1', 'title 1'],
            [$this->dateTimeString(), 1, 'c2', 'title 1']]);
    $this->assertEqualsIgnoreOrder(
        $this->kfc->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
        '' => ['1970-01-01' => 2],
        $budgetId1 => ['1970-01-01' => 1],
        $budgetId2 => ['1970-01-01' => 1]]);
  }

  // TODO: Consider writing a test case that follows a representative sequence of events.
}

(new KFCTest())->run();
