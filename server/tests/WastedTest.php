<?php

declare(strict_types=1);
ini_set('assert.exception', '1');

require_once '../common/base.php'; // For class loader.
require_once 'TestCase.php'; // Must initialize Logger before...
require_once '../common/common.php'; // ... Logger is used here.
require_once 'config_tests.php';
require_once '../common/db.class.php';
require_once '../rx/RX.php';
require_once '../cfg/Config.php';

final class WastedTest extends WastedTestBase {

  /** @var Wasted */
  protected $wasted;

  protected function setUpTestCase(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->wasted = Wasted::createForTest(
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
    $this->wasted->clearAllForTest();
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    $this->mockTime = 1000;
  }

  private function classification($id, $limits) {
    $c = ['class_id' => $id, 'budgets' => $limits];
    return $c;
  }

  private function mapping($limitId, $classId) {
    return ['budget_id' => strval($limitId), 'class_id' => strval($classId)];
  }

  private function queryMappings() {
    return DB::query('SELECT budget_id, class_id FROM mappings ORDER BY budget_id, class_id');
  }

  public function testSmokeTest(): void {
    $this->wasted->getGlobalConfig();
  }

  public function testTotalTime_SingleWindow_NoBudget(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        []);

    // A single record amounts to zero.
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 0]]);

    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 5]]);

    $this->mockTime += 6;
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 11]]);

    // Switch window (no effect, same budget).
    $this->mockTime += 7;
    $this->wasted->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 18]]);
  }

  public function testTotalTime_TwoWindows_NoBudget(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $m0 = $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime);
    $this->assertEquals($m0, []);

    // A single record amounts to zero.
    $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 0]]);

    // Advance 5 seconds. Still two windows, but same budget, so total time is 5 seconds.
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 5]]);

    // Same with another 6 seconds.
    $this->mockTime += 6;
    $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 11]]);

    // Switch to 'window 2'.
    $this->mockTime += 7;
    $this->wasted->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 18]]);

    $this->mockTime += 8;
    $this->wasted->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 26]]);
  }

  public function testSetUpBudgets(): void {
    $limitId1 = $this->wasted->addLimit('user_1', 'b1');
    $limitId2 = $this->wasted->addLimit('user_1', 'b2');
    $this->assertEquals($limitId2 - $limitId1, 1);

    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $this->assertEquals($classId2 - $classId1, 1);

    $classificationId1 = $this->wasted->addClassification($classId1, 0, '1$');
    $classificationId2 = $this->wasted->addClassification($classId2, 10, '2$');
    $this->assertEquals($classificationId2 - $classificationId1, 1);

    $this->wasted->addMapping($classId1, $limitId1);

    // Class 1 mapped to budget 1, other classes are not assigned to any budget.
    $classification = $this->wasted->classify('user_1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId1, [$limitId1]),
        $this->classification($classId2, [0]),
    ]);

    // Add a second mapping for the same class.
    $this->wasted->addMapping($classId1, $limitId2);

    // Class 1 is now mapped to budgets 1 and 2.
    $classification = $this->wasted->classify('user_1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId1, [$limitId1, $limitId2]),
        $this->classification($classId2, [0]),
    ]);

    // Add a mapping for the default class.
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId2);

    // Default class is now mapped to budget 2.
    $classification = $this->wasted->classify('user_1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        $this->classification(DEFAULT_CLASS_ID, [$limitId2]),
        $this->classification($classId1, [$limitId1, $limitId2]),
        $this->classification($classId2, [0]),
    ]);

    // Remove mapping.
    $this->assertEquals($this->wasted->classify('user_1', ['window 1']), [
        $this->classification($classId1, [$limitId1, $limitId2]),
    ]);
    $this->wasted->removeMapping($classId1, $limitId1);
    $this->assertEquals($this->wasted->classify('user_1', ['window 1']), [
        $this->classification($classId1, [$limitId2]),
    ]);
  }

  public function testTotalTime_SingleWindow_WithBudgets(): void {
    // Set up test budgets.
    $limitId1 = $this->wasted->addLimit('user_1', 'b1');
    $limitId2 = $this->wasted->addLimit('user_1', 'b2');
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId1, 0, '1$');
    $this->wasted->addClassification($classId2, 10, '2$');
    // b1 <= default, c1
    // b2 <= c2
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId1);
    $this->wasted->addMapping($classId1, $limitId1);
    $this->wasted->addMapping($classId2, $limitId2);

    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        []);

    // A single record amounts to zero.
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [$limitId1 => ['1970-01-01' => 0]]);

    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [$limitId1 => ['1970-01-01' => 5]]);

    $this->mockTime += 6;
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [$limitId1 => ['1970-01-01' => 11]]);

    // Switch window. First interval still counts towards previous window/budget.
    $this->mockTime += 7;
    $this->wasted->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [
            $limitId1 => ['1970-01-01' => 18],
            $limitId2 => ['1970-01-01' => 0]
    ]);
  }

  public function testTotalTime_TwoWindows_WithBudgets(): void {
    // Set up test budgets.
    $limitId1 = $this->wasted->addLimit('user_1', 'b1');
    $limitId2 = $this->wasted->addLimit('user_1', 'b2');
    $limitId3 = $this->wasted->addLimit('user_1', 'b3');
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $classId3 = $this->wasted->addClass('c3');
    $this->wasted->addClassification($classId1, 0, '1$');
    $this->wasted->addClassification($classId2, 10, '2$');
    $this->wasted->addClassification($classId3, 20, '3$');
    // b1 <= default, c1
    // b2 <= c2
    // b3 <= c2, c3
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId1);
    $this->wasted->addMapping($classId1, $limitId1);
    $this->wasted->addMapping($classId2, $limitId2);
    $this->wasted->addMapping($classId2, $limitId3);
    $this->wasted->addMapping($classId3, $limitId3);

    $fromTime = $this->newDateTime();

    // No records amount to an empty array.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        []);

    // Start with a single window. Will not return anything for unused budgets.
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        [$limitId1 => ['1970-01-01' => 0]]);

    // Advance 5 seconds and observe second window.
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $limitId1 => ['1970-01-01' => 5],
        $limitId2 => ['1970-01-01' => 0],
        $limitId3 => ['1970-01-01' => 0]]);

    // Observe both again after 5 seconds.
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $limitId1 => ['1970-01-01' => 10],
        $limitId2 => ['1970-01-01' => 5],
        $limitId3 => ['1970-01-01' => 5]]);

    // Advance 5 seconds and observe 'window 1' only.
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $limitId1 => ['1970-01-01' => 15],
        $limitId2 => ['1970-01-01' => 10],
        $limitId3 => ['1970-01-01' => 10]]);

    // Add 6 seconds and start two windows of class 1.
    $this->mockTime += 6;
    $this->wasted->insertWindowTitles('user_1', ['window 1', 'another window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $limitId1 => ['1970-01-01' => 21],
        $limitId2 => ['1970-01-01' => 10],
        $limitId3 => ['1970-01-01' => 10]]);

    // Add 7 seconds and observe both windows of class 1 again.
    $this->mockTime += 7;
    $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $limitId1 => ['1970-01-01' => 28],
        $limitId2 => ['1970-01-01' => 10],
        $limitId3 => ['1970-01-01' => 10]]);

    // Add 8 seconds and observe 'window 2'.
    $this->mockTime += 8;
    $this->wasted->insertWindowTitles('user_1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime), [
        $limitId1 => ['1970-01-01' => 36],
        $limitId2 => ['1970-01-01' => 18],
        $limitId3 => ['1970-01-01' => 18]]);
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
        $classId1 = $this->wasted->addClass($class1);
        $classId2 = $this->wasted->addClass($class2);
        $this->wasted->addClassification($classId1, 0, '1$');
        $this->wasted->addClassification($classId2, 10, '2$');
      }

      $fromTime = $this->newDateTime();

      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime),
          []);

      $this->wasted->insertWindowTitles('user_1', ['window 1']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 0, $class1, 'window 1']]);

      $this->mockTime += 5;
      $this->wasted->insertWindowTitles('user_1', ['window 1']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 5, $class1, 'window 1']]);

      $this->mockTime += 6;
      $this->wasted->insertWindowTitles('user_1', ['window 1']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime),
          [[$this->dateTimeString(), 11, $class1, 'window 1']]);

      // Switch to different window.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class2, 'window 2']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString2 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
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
        $classId1 = $this->wasted->addClass($class1);
        $classId2 = $this->wasted->addClass($class2);
        $this->wasted->addClassification($classId1, 0, '1$');
        $this->wasted->addClassification($classId2, 10, '2$');
      }

      $fromTime = $this->newDateTime();

      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime),
          []);

      $dateTimeString1 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 0, $class1, 'window 1'],
          [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 5;
      $dateTimeString1 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 5, $class1, 'window 1'],
          [$dateTimeString1, 5, $class2, 'window 2']]);

      $this->mockTime += 6;
      $dateTimeString1 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 11, $class1, 'window 1'],
          [$dateTimeString1, 11, $class2, 'window 2']]);

      // Switch to different windows.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 11', 'window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString1, 18, $class2, 'window 2'],
          [$dateTimeString1, 0, $class1, 'window 11']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString2, 26, $class2, 'window 2'],
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class1, 'window 11']]);

      // Switch to window 1.
      $this->mockTime += 1;
      $dateTimeString3 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 1']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString3, 27, $class2, 'window 2'],
          [$dateTimeString3, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class1, 'window 11']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString4 = $this->dateTimeString();
      $this->wasted->insertWindowTitles('user_1', ['window 42']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
          [$dateTimeString4, 38, $class1, 'window 1'],
          [$dateTimeString3, 27, $class2, 'window 2'],
          [$dateTimeString2, 8, $class1, 'window 11'],
          [$dateTimeString4, 0, $class2, 'window 42']]);
    }
  }

  public function testReplaceEmptyTitle(): void {
    $fromTime = $this->newDateTime();
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['']);
    $window1LastSeen = $this->dateTimeString();
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('user_1', $fromTime),
        ['' => ['1970-01-01' => 15]]);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByTitle('user_1', $fromTime), [
            [$window1LastSeen, 10, DEFAULT_CLASS_NAME, 'window 1'],
            [$this->dateTimeString(), 5, DEFAULT_CLASS_NAME, '(no title)']]);
  }

  public function testWeeklyLimit(): void {
    $limitId = $this->wasted->addLimit('u', 'b');
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId);

    // Budgets default to zero.
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u'),
        [$limitId => 0]);

    // Daily limit is 42 minutes.
    $this->wasted->setBudgetConfig($limitId, 'daily_limit_minutes_default', 42);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u'),
        [$limitId => 42 * 60]);

    // The weekly limit cannot extend the daily limit.
    $this->wasted->setBudgetConfig($limitId, 'weekly_limit_minutes', 666);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u'),
        [$limitId => 42 * 60]);

    // The weekly limit can shorten the daily limit.
    $this->wasted->setBudgetConfig($limitId, 'weekly_limit_minutes', 5);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u'),
        [$limitId => 5 * 60]);

    // The weekly limit can also be zero.
    $this->wasted->setBudgetConfig($limitId, 'weekly_limit_minutes', 0);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u'),
        [$limitId => 0]);

    // Clear the limit.
    $this->wasted->clearBudgetConfig($limitId, 'weekly_limit_minutes');
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u'),
        [$limitId => 42 * 60]);
  }

  public function testGetAllBudgetConfigs(): void {
    // No budgets configured.
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('u'),
        []);

    $limitId = $this->wasted->addLimit('u', 'b');

    // A mapping is not required for the budget to be returned for the user.
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('u'),
        [$limitId => ['name' => 'b']]);

    // Add mapping, doesn't change result.
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->getAllBudgetConfigs('u'),
        [$limitId => ['name' => 'b']]);

    // Add a config.
    $this->wasted->setBudgetConfig($limitId, 'foo', 'bar');
    $this->assertEqualsIgnoreOrder(
        $this->wasted->getAllBudgetConfigs('u'),
        [$limitId => ['foo' => 'bar', 'name' => 'b']]);
  }

  public function testManageBudgets(): void {
    // No budgets set up.
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('u1'),
        []);
    // Add a budget but no maping yet.
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    // Not returned when user does not match.
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('nobody'),
        []);
    // Returned when user matches.
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('u1'),
        [$limitId1 => ['name' => 'b1']]);

    // Add a mapping.
    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']), [
            $this->classification(DEFAULT_CLASS_ID, [0])
            ]);
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId1);
    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']), [
            $this->classification(DEFAULT_CLASS_ID, [$limitId1])
            ]);

    // Same behavior:
    // Not returned when user does not match.
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('nobody'),
        []);
    // Returned when user matches.
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('u1'),
        [$limitId1 => ['name' => 'b1']]);

    // Add budget config.
    $this->wasted->setBudgetConfig($limitId1, 'foo', 'bar');
    $this->assertEqualsIgnoreOrder(
        $this->wasted->getAllBudgetConfigs('u1'),
        [$limitId1 => ['name' => 'b1', 'foo' => 'bar']]);

    // Remove budget, this cascades to mappings and config.
    $this->wasted->removeBudget($limitId1);
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('u1'),
        []);
    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']), [
            $this->classification(DEFAULT_CLASS_ID, [0])
            ]);
  }

  public function testTimeLeftTodayAllBudgets_negative(): void {
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', ['window 1']);
    $this->mockTime += 5;
    $this->wasted->insertWindowTitles('user_1', []);

    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('user_1'),
        ['' => -10]);
  }

  public function testClassificationWithBudget_multipleUsers(): void {
    $this->assertEquals(
        $this->wasted->classify('u1', ['title 1']),
        [$this->classification(DEFAULT_CLASS_ID, [0])]);

    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 42, '1$');
    $limitId = $this->wasted->addLimit('u2', 'b1');
    $this->wasted->addMapping($classId, $limitId);

    // No budget is mapped for user u1. The window is classified, but no budget is associated.

    $this->assertEquals(
        $this->wasted->classify('u1', ['title 1']),
        [$this->classification($classId, [0])]);
  }

  public function testTimeLeftTodayAllBudgets_consumeTimeAndClassify(): void {
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 42, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');

    // Budget is listed even when no mapping is present.
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId1 => 0]);

    // Add mapping.
    $this->wasted->addMapping($classId, $limitId1);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId1 => 0]);

    // Provide 2 minutes.
    $this->wasted->setBudgetConfig($limitId1, 'daily_limit_minutes_default', 2);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId1 => 120]);

    // Start consuming time.
    $classification1 = $this->classification($classId, [$limitId1]);

    $this->assertEquals(
        $this->wasted->insertWindowTitles('u1', ['title 1']),
        [$classification1]);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId1 => 120]);

    $this->mockTime += 15;
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 1']),
        [$classification1]);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId1 => 105]);

    // Add a window that maps to no budget.
    $this->mockTime += 15;
    $classification2 = $this->classification(DEFAULT_CLASS_ID, [0]);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [null => 0, $limitId1 => 90]);
    $this->mockTime += 15;
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [null => -15, $limitId1 => 75]);

    // Add a second budget for title 1 with only 1 minute.
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId, $limitId2);
    $this->wasted->setBudgetConfig($limitId2, 'daily_limit_minutes_default', 1);
    $this->mockTime += 1;
    $classification1['budgets'][] = $limitId2;
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [null => -16, $limitId1 => 74, $limitId2 => 14]);
  }

  public function testInsertClassification(): void {
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId1, 0, '1$');
    $this->wasted->addClassification($classId2, 10, '2$');

    // Single window.
    $this->assertEquals(
        $this->wasted->insertWindowTitles('u1', ['title 2']), [
            $this->classification($classId2, [0])]);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [null => 0]);

    // Two windows.
    $this->assertEquals(
        $this->wasted->insertWindowTitles('u1', ['title 1', 'title 2']), [
            $this->classification($classId1, [0]),
            $this->classification($classId2, [0])]);

    // No window at all.
    $this->assertEquals(
        $this->wasted->insertWindowTitles('u1', []), []);

    // Time did not advance.
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [null => 0]);
  }

  public function testNoWindowsOpen(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 42, '1$');
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId, $limitId);

    $this->assertEquals(
        $this->wasted->insertWindowTitles('u1', ['window 1']), [
            $this->classification($classId, [$limitId])]);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId => 0]);

    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['window 1']), [
            $this->classification($classId, [$limitId])]);
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId => -1]);

    // All windows closed. Bill time to last window.
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', []),
        []);

    // Used 2 seconds.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        [$limitId => ['1970-01-01' => 2]]);

    // Time advances.
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', []),
        []);

    // Still only used 2 seconds because nothing was open.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        [$limitId => ['1970-01-01' => 2]]);
  }

  public function testTimeSpent_handleNoWindows(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '1$');

    $this->wasted->insertWindowTitles('u1', ['window 1']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['window 1']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['window 1']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['window 1', 'window 2']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['window 3']);
    $lastSeenWindow1 = $this->dateTimeString();
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['window 3']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['window 3']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', []);
    $lastSeenWindow3 = $this->dateTimeString();
    $this->mockTime += 15;
    $this->wasted->insertWindowTitles('u1', []);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['window 2']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['window 2']);
    $lastSeenWindow2 = $this->dateTimeString();

    // "No windows" events are handled correctly for both listing titles and computing time spent.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
            [$lastSeenWindow1, 4, 'c1', 'window 1'],
            [$lastSeenWindow3, 3, DEFAULT_CLASS_NAME, 'window 3'],
            [$lastSeenWindow2, 2, DEFAULT_CLASS_NAME, 'window 2']]);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => ['1970-01-01' => 8]]);
  }

  public function testSameTitle(): void {
    $fromTime = $this->newDateTime();
    $this->wasted->insertWindowTitles('u1', ['Calculator', 'Calculator']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['Calculator', 'Calculator']);
    $lastSeen = $this->dateTimeString();
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
            [$lastSeen, 1, DEFAULT_CLASS_NAME, 'Calculator']]);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => ['1970-01-01' => 1]]);
  }

  public function testHandleRequest_invalidRequests(): void {
    foreach (['', "\n123"] as $content) {
      $this->onFailMessage("content: $content");
      $this->assertEquals(
          explode("\n", RX::handleRequest($this->wasted, $content), 1)[0],
          "error\nInvalid request");
    }
  }

  public function testHandleRequest_smokeTest(): void {
    $this->assertEquals(
        RX::handleRequest($this->wasted, 'u1'),
        '');
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 1"),
        "0:0:no_budget\n\n0");
  }

  public function testHandleRequest_withBudgets(): void {
    $classId1 = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId1, 0, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId1, $limitId1);
    $this->wasted->setBudgetConfig($limitId1, 'daily_limit_minutes_default', 5);

    $this->assertEquals(
        RX::handleRequest($this->wasted, 'u1'),
        $limitId1 . ":300:b1\n");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 1"),
        $limitId1 . ":300:b1\n\n$limitId1");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 1"),
        $limitId1 . ":299:b1\n\n$limitId1");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 1\nfoo"),
        "0:0:no_budget\n$limitId1:298:b1\n\n$limitId1\n0");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 1\nfoo"),
        "0:-1:no_budget\n$limitId1:297:b1\n\n$limitId1\n0");

    // Flip order.
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\nfoo\ntitle 1"),
        "0:-2:no_budget\n$limitId1:296:b1\n\n0\n" . $limitId1);

    // Add second budget.
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId2, 10, '2$');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId1, $limitId2);
    $this->wasted->addMapping($classId2, $limitId2);
    $this->wasted->setBudgetConfig($limitId2, 'daily_limit_minutes_default', 2);

    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 1\nfoo"),
        "0:-3:no_budget\n$limitId1:295:b1\n$limitId2:115:b2\n\n$limitId1,$limitId2\n0");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 1\ntitle 2"),
        "0:-4:no_budget\n$limitId1:294:b1\n$limitId2:114:b2\n\n"
        . "$limitId1,$limitId2\n$limitId2");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 2"),
        "0:-4:no_budget\n$limitId1:293:b1\n$limitId2:113:b2\n\n$limitId2");
    $this->mockTime++; // This still counts towards b2.
    $this->assertEquals(
        RX::handleRequest($this->wasted, 'u1'),
        "0:-4:no_budget\n$limitId1:293:b1\n$limitId2:112:b2\n");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\ntitle 2"),
        "0:-4:no_budget\n$limitId1:293:b1\n$limitId2:112:b2\n\n$limitId2");
  }

  public function testHandleRequest_mappedForOtherUser(): void {
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId, $limitId1);
    $this->wasted->setBudgetConfig($limitId1, 'daily_limit_minutes_default', 1);

    $this->assertEquals(RX::handleRequest($this->wasted, 'u2'), '');
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u2\ntitle 1"),
        "0:0:no_budget\n\n0");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u2\ntitle 1"),
        "0:-1:no_budget\n\n0");

    // Now map same class for user u2.
    $limitId2 = $this->wasted->addLimit('u2', 'b2');
    $this->wasted->setBudgetConfig($limitId2, 'daily_limit_minutes_default', 1);
    $this->wasted->addMapping($classId, $limitId2);
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u2\ntitle 1"),
        "$limitId2:58:b2\n\n$limitId2");
  }

  public function testHandleRequest_utf8Conversion(): void {
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '^...$');
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId, $limitId);

    // This file uses utf8 encoding. The word 'süß' would not match the above RE in utf8 because
    // MySQL's RE library does not support utf8 and would see 5 bytes.
    $this->assertEquals(RX::handleRequest($this->wasted, "u1\nsüß"),
        $limitId . ":0:b1\n\n" . $limitId);
  }

  public function testSetOverrideMinutesAndUnlock(): void {
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId);
    $this->wasted->setBudgetConfig($limitId, 'daily_limit_minutes_default', 42);
    $this->wasted->setBudgetConfig($limitId, 'require_unlock', 1);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId => 0]);

    $this->wasted->setOverrideUnlock('u1', $this->dateString(), $limitId);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId => 42 * 60]);

    $this->wasted->setOverrideMinutes('u1', $this->dateString(), $limitId, 666);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId => 666 * 60]);

    // Test updating.
    $this->wasted->setOverrideMinutes('u1', $this->dateString(), $limitId, 123);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId => 123 * 60]);

    $this->wasted->clearOverrides('u1', $this->dateString(), $limitId);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId => 0]);

    $this->wasted->setOverrideUnlock('u1', $this->dateString(), $limitId);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeLeftTodayAllBudgets('u1'),
        [$limitId => 42 * 60]);
  }

  public function testConcurrentRequests(): void {
    $fromTime = $this->newDateTime();

    $classId1 = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId1, 0, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId1, $limitId1);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 2']),
        [$this->classification(DEFAULT_CLASS_ID, [0])]);
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 2']),
        [$this->classification(DEFAULT_CLASS_ID, [0])]);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => ['1970-01-01' => 1]]);

    // Repeating the last call is idempotent.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 2']),
        [$this->classification(DEFAULT_CLASS_ID, [0])]);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => ['1970-01-01' => 1]]);

    // Add a title that matches the budget, but don't elapse time for it yet. This will extend the
    // title from the previous call.
    $classification2and1 = [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId1, [$limitId1])];
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 2', 'title 1']),
        $classification2and1);
    $timeSpent2and1 = [
        '' => ['1970-01-01' => 1],
        $limitId1 => ['1970-01-01' => 0]];
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        $timeSpent2and1);

    // Repeating the previous insertion is idempotent.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 2', 'title 1']),
        $classification2and1);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        $timeSpent2and1);

    // Changing the classification rules between concurrent requests causes the second activity
    // record to collide with the first (because class_id is not part of the PK) and be ignored.
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId2, 10 /* higher priority */, '1$');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId2, $limitId2);
    // Request does return the updated classification...
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 2', 'title 1']), [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId2, [$limitId2])]); // changed to c2, which maps to b2
    // ... but records retain the old one.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
        '' => ['1970-01-01' => 1],
        $limitId1 => ['1970-01-01' => 0]]);

    // Accumulate time.
    $this->mockTime++;
    // Only now; previous requests had the same timestamp.
    $lastC1DateTimeString = $this->dateTimeString();
    // From now on we accumulate time with the new classification.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 2', 'title 1']), [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId2, [$limitId2])]);
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['title 2', 'title 1']), [
        $this->classification(DEFAULT_CLASS_ID, [0]),
        $this->classification($classId2, [$limitId2])]);
    // One more second to ensure order in the assertion below.
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['title 2', 'title 1']);

    // Check results.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
            [$this->dateTimeString(), 4, DEFAULT_CLASS_NAME, 'title 2'],
            [$this->dateTimeString(), 2, 'c2', 'title 1'],
            [$lastC1DateTimeString, 1, 'c1', 'title 1']]);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
        '' => ['1970-01-01' => 4],
        $limitId1 => ['1970-01-01' => 1],
        $limitId2 => ['1970-01-01' => 2]]);
  }

  function testUmlauts(): void {
    $classId = $this->wasted->addClass('c');
    // The single '.' should match the 'ä' umlaut. In utf8 this fails because the MySQL RegExp
    // library does not support utf8 and the character is encoded as two bytes.
    $this->wasted->addClassification($classId, 0, 't.st');
    // Word boundaries should support umlauts. Match any three letter word.
    $this->wasted->addClassification($classId, 0, '[[:<:]]...[[:>:]]');

    // This file uses utf8. Insert an 'ä' (&auml;) character in latin1 encoding.
    // https://cs.stanford.edu/people/miles/iso8859.html
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['t' . chr(228) .'st']),
        [$this->classification($classId, [0])]);

    // Test second classification rule for the word 'süß'.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->insertWindowTitles('u1', ['x s' . chr(252) . chr(223) . ' x']),
        [$this->classification($classId, [0])]);
  }

  function testGetUsers(): void {
    $this->assertEquals($this->wasted->getUsers(), []);
    $this->wasted->addLimit('u1', 'b1');
    $this->assertEquals($this->wasted->getUsers(), ['u1']);
    $this->wasted->addLimit('u2', 'b2');
    $this->assertEquals($this->wasted->getUsers(), ['u1', 'u2']);
  }

  function testSameBudgetName(): void {
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $limitId2 = $this->wasted->addLimit('u2', 'b1');
    $this->assertEquals($this->wasted->getAllBudgetConfigs('u1'),
        [$limitId1 => ['name' => 'b1']]);
    $this->assertEquals($this->wasted->getAllBudgetConfigs('u2'),
        [$limitId2 => ['name' => 'b1']]);
    $this->assertEquals($this->wasted->getAllBudgetConfigs('nobody'), []);
  }

  function testBudgetWithUmlauts(): void {
    $limitName = 't' . chr(228) .'st';
    $limitId = $this->wasted->addLimit('u1', $limitName);
    $this->assertEquals($this->wasted->getAllBudgetConfigs('u1'),
        [$limitId => ['name' => $limitName]]);
  }

  function testReclassify(): void {
    $fromTime = $this->newDateTime();
    $date = $this->dateString();
    $this->wasted->insertWindowTitles('u1', ['w1', 'w2']);
    $this->wasted->insertWindowTitles('u2', ['w1', 'w2']);
    $this->mockTime++;
    $fromTime2 = $this->newDateTime();
    $this->wasted->insertWindowTitles('u1', ['w1', 'w2']);
    $this->wasted->insertWindowTitles('u2', ['w1', 'w2']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['w1', 'w2']);
    $this->wasted->insertWindowTitles('u2', ['w1', 'w2']);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => [$date => 2]]);

    $this->wasted->reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime),
        ['' => [$date => 2]]);

    // Add classification for w1.
    $classId1 = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId1, 0, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId1, $limitId1);

    $this->wasted->reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
            '' => [$date => 2],
            $limitId1 => [$date => 2]]);

    // Add classification for w2.
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId2, 0, '2$');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId2, $limitId2);

    $this->wasted->reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
            $limitId1 => [$date => 2],
            $limitId2 => [$date => 2]]);

    // Check u2 to ensure reclassification works across users.
    $limitId1_2 = $this->wasted->addLimit('u2', 'b1');
    $limitId2_2 = $this->wasted->addLimit('u2', 'b2');
    $this->wasted->addMapping($classId1, $limitId1_2);
    $this->wasted->addMapping($classId2, $limitId2_2);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u2', $fromTime), [
            $limitId1_2 => [$date => 2],
            $limitId2_2 => [$date => 2]]);

    // Attempt to mess with the "" placeholder title.
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', []);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', []);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
            $limitId1 => [$date => 3],
            $limitId2 => [$date => 3]]);
    $this->wasted->addClassification($classId1, 666, '()');
    $this->wasted->reclassify($fromTime);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
            $limitId1 => [$date => 3]]);

    // Reclassify only a subset.
    $this->wasted->addClassification($classId2, 667, '()');
    $this->wasted->reclassify($fromTime2);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByBudgetAndDate('u1', $fromTime), [
            $limitId1 => [$date => 1],
            $limitId2 => [$date => 2]]);
  }

  public function testRemoveClass(): void {
    $classId = $this->wasted->addClass('c1');
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId, $limitId);
    $classificationId = $this->wasted->addClassification($classId, 42, '()');

    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']),
        [['class_id' => $classId, 'budgets' => [$limitId]]]);
    $this->assertEquals(
        $this->wasted->getAllClassifications(),
        [$classificationId => ['name' => 'c1', 're' => '()', 'priority' => 42]]);
    $this->assertEquals(
        count(DB::query('SELECT * FROM mappings')),
        1);

    $this->wasted->removeClass($classId);

    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']),
        [['class_id' => DEFAULT_CLASS_ID, 'budgets' => [0]]]);
    $this->assertEquals(
        $this->wasted->getAllClassifications(),
        []);
    $this->assertEquals(
        count(DB::query('SELECT * FROM mappings')),
        0);
  }

  public function testRemoveClassReclassifies(): void {
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $classificationId1 = $this->wasted->addClassification($classId1, 0, '1$');
    $classificationId2 = $this->wasted->addClassification($classId2, 0, '2$');
    $this->wasted->insertWindowTitles('u1', ['t1']);

    $fromTime = $this->newDateTime();
    $this->mockTime++;
    $fromTime1String = $this->dateTimeString();
    $this->wasted->insertWindowTitles('u1', ['t2']);
    $this->mockTime++;
    $fromTime2String = $this->dateTimeString();
    $this->wasted->insertWindowTitles('u1', ['t3']);

    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime),  [
            [$fromTime1String, 1, 'c1', 't1'],
            [$fromTime2String, 1, 'c2', 't2'],
            [$fromTime2String, 0, DEFAULT_CLASS_NAME, 't3']
        ]);
    $this->assertEquals(
        $this->wasted->getAllClassifications(), [
            $classificationId1 => ['name' => 'c1', 're' => '1$', 'priority' => 0],
            $classificationId2 => ['name' => 'c2', 're' => '2$', 'priority' => 0]
        ]);

    $classId3 = $this->wasted->addClass('c3');
    $classificationId3 = $this->wasted->addClassification($classId3, -42, '2$');
    $this->wasted->removeClass($classId2);

    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime),  [
            [$fromTime1String, 1, 'c1', 't1'],
            [$fromTime2String, 1, 'c3', 't2'],
            [$fromTime2String, 0, DEFAULT_CLASS_NAME, 't3']
        ]);
    $this->assertEquals(
        $this->wasted->getAllClassifications(), [
            $classificationId1 => ['name' => 'c1', 're' => '1$', 'priority' => 0],
            $classificationId3 => ['name' => 'c3', 're' => '2$', 'priority' => -42]
        ]);
  }

  public function testTotalBudget(): void {
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId1, $limitId1);
    $this->assertEquals(
        $this->queryMappings(),
        [$this->mapping($limitId1, $classId1)]);
    $classId3 = $this->wasted->addClass('c3');
    $classId4 = $this->wasted->addClass('c4');
    $this->wasted->setTotalBudget('u1', $limitId1);
    $this->assertEquals(
        $this->queryMappings(), [
            $this->mapping($limitId1, DEFAULT_CLASS_ID),
            $this->mapping($limitId1, $classId1),
            $this->mapping($limitId1, $classId2),
            $this->mapping($limitId1, $classId3),
            $this->mapping($limitId1, $classId4),
            ]);
    $classId5 = $this->wasted->addClass('c5');
    $this->assertEquals(
        $this->queryMappings(), [
            $this->mapping($limitId1, DEFAULT_CLASS_ID),
            $this->mapping($limitId1, $classId1),
            $this->mapping($limitId1, $classId2),
            $this->mapping($limitId1, $classId3),
            $this->mapping($limitId1, $classId4),
            $this->mapping($limitId1, $classId5),
            ]);

    DB::query('TRUNCATE TABLE mappings');
    $this->assertEquals(
        $this->queryMappings(),
        []);
    $this->wasted->setTotalBudget('u1', $limitId2);
    $this->assertEquals(
        $this->queryMappings(), [
            $this->mapping($limitId2, DEFAULT_CLASS_ID),
            $this->mapping($limitId2, $classId1),
            $this->mapping($limitId2, $classId2),
            $this->mapping($limitId2, $classId3),
            $this->mapping($limitId2, $classId4),
            $this->mapping($limitId2, $classId5),
            ]);
    $classId6 = $this->wasted->addClass('c6');
    $this->assertEquals(
        $this->queryMappings(), [
            $this->mapping($limitId2, DEFAULT_CLASS_ID),
            $this->mapping($limitId2, $classId1),
            $this->mapping($limitId2, $classId2),
            $this->mapping($limitId2, $classId3),
            $this->mapping($limitId2, $classId4),
            $this->mapping($limitId2, $classId5),
            $this->mapping($limitId2, $classId6),
            ]);

    $this->wasted->removeClass($classId2);
    $this->wasted->removeClass($classId3);
    $this->wasted->removeClass($classId4);
    $this->wasted->removeClass($classId5);
    $this->wasted->removeClass($classId6);
    // Configure b1 for u2.
    $this->wasted->setTotalbudget('u2', $limitId1);

    $this->assertEquals(
        $this->queryMappings(), [
            $this->mapping($limitId1, DEFAULT_CLASS_ID),
            $this->mapping($limitId1, $classId1),
            $this->mapping($limitId2, DEFAULT_CLASS_ID),
            $this->mapping($limitId2, $classId1),
            ]);

    $classId7 = $this->wasted->addClass('c7');
    $this->assertEquals(
        $this->queryMappings(), [
            $this->mapping($limitId1, DEFAULT_CLASS_ID),
            $this->mapping($limitId1, $classId1),
            $this->mapping($limitId1, $classId7),
            $this->mapping($limitId2, DEFAULT_CLASS_ID),
            $this->mapping($limitId2, $classId1),
            $this->mapping($limitId2, $classId7),
            ]);

    // Removing a total budget will make the trigger fail from now on. But if a trigger fails alone
    // in the forest and nobody is there to hear it, does it make a sound? No!
    $this->wasted->removeBudget($limitId1);
    $classId8 = $this->wasted->addClass('c8');
    $this->assertEquals(
        $this->queryMappings(), [
            $this->mapping($limitId2, DEFAULT_CLASS_ID),
            $this->mapping($limitId2, $classId1),
            $this->mapping($limitId2, $classId7),
            $this->mapping($limitId2, $classId8),
            ]);

    // Remove the total budget setting.
    $this->wasted->unsetTotalBudget('u1');
    $this->wasted->addClass('c9');
    $this->assertEquals(
        $this->queryMappings(), [
            $this->mapping($limitId2, DEFAULT_CLASS_ID),
            $this->mapping($limitId2, $classId1),
            $this->mapping($limitId2, $classId7),
            $this->mapping($limitId2, $classId8),
            ]);
  }

  public function testRemoveDefaultClass(): void {
    try {
      $this->wasted->removeClass(DEFAULT_CLASS_ID);
      throw new AssertionError('Should not be able to delete the default class');
    } catch (Exception $e) {
      // expected
    }
  }

  public function testRemoveDefaultClassification(): void {
    try {
      $this->wasted->removeClassification(DEFAULT_CLASSIFICATION_ID);
      throw new AssertionError('Should not be able to delete the default classification');
    } catch (Exception $e) {
      // expected
    }
  }

  public function testChangeDefaultClassification(): void {
    try {
      $this->wasted->changeClassification(DEFAULT_CLASSIFICATION_ID, 'foo', 42);
      throw new AssertionError('Should not be able to change the default classification');
    } catch (Exception $e) {
      // expected
    }
  }

  public function testRemoveTriggersForTest(): void {
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->setTotalBudget('u1', $limitId);
    $this->wasted->clearAllForTest();

    $this->assertEquals(count(DB::query('SELECT * FROM budgets')), 0);
    $this->assertEquals(count(DB::query('SHOW TRIGGERS')), 0);
  }

  public function testRenameBudget(): void {
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->renameBudget($limitId, 'b2');
    $this->assertEquals(
        $this->wasted->getAllBudgetConfigs('u1'),
        [$limitId => ['name' => 'b2']]);

  }

  public function testRenameClass(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '()');
    $this->wasted->renameClass($classId, 'c2');
    $this->wasted->insertWindowTitles('u1', ['t']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
        [[$this->dateTimeString(), 0, 'c2', 't']]);
  }

  public function testChangeClassification(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c1');
    $classificationId = $this->wasted->addClassification($classId, 0, 'nope');
    $this->assertEquals(
        $this->wasted->getAllClassifications(),
        [$classificationId => ['name' => 'c1', 're' => 'nope', 'priority' => 0]]);
    $this->wasted->insertWindowTitles('u1', ['t']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
        [[$this->dateTimeString(), 0, DEFAULT_CLASS_NAME, 't']]);
    $this->wasted->changeClassification($classificationId, '()', 42);
    $this->wasted->reclassify($fromTime);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
        [[$this->dateTimeString(), 0, 'c1', 't']]);
    $this->assertEquals(
        $this->wasted->getAllClassifications(),
        [$classificationId => ['name' => 'c1', 're' => '()', 'priority' => 42]]);
  }

  public function testTopUnclassified(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '1$');
    $this->wasted->insertWindowTitles('u1', ['t1']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['t1']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['t1', 't2']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['t1', 't2']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['t2', 't3']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['t2', 't3']);
    $this->mockTime++;
    $lastSeenT2 = $this->dateTimeString();
    $this->wasted->insertWindowTitles('u1', ['t3', 't4']);
    $this->mockTime++;
    $lastSeenT3T4 = $this->dateTimeString();
    $this->wasted->insertWindowTitles('u1', ['t3', 't4']);

    $this->assertEquals(
        $this->wasted->queryTopUnclassified('u1', $fromTime, true, 2), [
        [4, 't2', $lastSeenT2],
        [3, 't3', $lastSeenT3T4]]);

    $this->assertEquals(
        $this->wasted->queryTopUnclassified('u1', $fromTime, true, 3), [
        [4, 't2', $lastSeenT2],
        [3, 't3', $lastSeenT3T4],
        [1, 't4', $lastSeenT3T4]]);

    $this->assertEquals(
        $this->wasted->queryTopUnclassified('u1', $fromTime, false, 3), [
        [3, 't3', $lastSeenT3T4],
        [1, 't4', $lastSeenT3T4],
        [4, 't2', $lastSeenT2]]);
  }

  public function testBudgetsToClassesTable(): void {
    $this->wasted->addLimit('u1', 'b1');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $limitId3 = $this->wasted->addLimit('u1', 'b3');
    $limitId4 = $this->wasted->addLimit('u1', 'b4');
    $limitId5 = $this->wasted->addLimit('u1', 'b5');
    $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $classId3 = $this->wasted->addClass('c3');
    $classId4 = $this->wasted->addClass('c4');
    $this->wasted->addMapping($classId2, $limitId2);
    $this->wasted->addMapping($classId3, $limitId2);
    $this->wasted->addMapping($classId3, $limitId3);
    $this->wasted->addMapping($classId3, $limitId4);
    $this->wasted->addMapping($classId4, $limitId4);
    $this->wasted->addMapping($classId4, $limitId5);

    $this->assertEquals(
        $this->wasted->getBudgetsToClassesTable('u1'), [
            ['b2', 'c2', ''],
            ['b2', 'c3', 'b3, b4'],
            ['b3', 'c3', 'b2, b4'],
            ['b4', 'c3', 'b2, b3'],
            ['b4', 'c4', 'b5'],
            ['b5', 'c4', 'b4']
        ]);
  }

  public function testClassesToClassificationTable(): void {
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId1, 42, '1');
    $this->wasted->addClassification($classId2, 43, '2');
    $this->wasted->insertWindowTitles('u1', ['t1', 't2', 't3']);
    $this->mockTime += 2;
    $this->wasted->insertWindowTitles('u1', ['t1', 't2', 't3']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['t22', 't3', 't4']);
    $this->mockTime++;
    $this->wasted->insertWindowTitles('u1', ['t22', 't3', 't4']);
    $this->assertEquals(
        $this->wasted->getClassesToClassificationTable(), [
            ['c1', '1', 42, 1, 't1'],
            ['c2', '2', 43, 2, "t2\nt22"]
        ]);
    // Test that priority is considered: "t12" is not a sample for c1, but for c2.
    $this->mockTime++;
    $classification = $this->wasted->insertWindowTitles('u1', ['t12']);
    $this->assertEquals($classification, [['class_id' => $classId2, 'budgets' => [0]]]);
    $this->assertEquals(
        $this->wasted->getClassesToClassificationTable(), [
            ['c1', '1', 42, 1, 't1'],
            ['c2', '2', 43, 3, "t12\nt2\nt22"]
        ]);
    // Test that samples are cropped to 1024 characters. Titles are VARCHAR(256).
    $this->mockTime++;
    $one255 = str_repeat('1', 255);
    $titles = ['a' . $one255, 'b' . $one255, 'c' . $one255, 'd' . $one255, 'e' . $one255];
    $classification = $this->wasted->insertWindowTitles('u1', $titles);
    $samples = substr(implode("\n", $titles), 0, 1021) . '...';
    $this->assertEquals(
        $this->wasted->getClassesToClassificationTable(), [
            ['c1', '1', 42, 6, $samples],
            ['c2', '2', 43, 3, "t12\nt2\nt22"]
        ]);
  }

  public function testUserConfig(): void {
    $this->assertEquals([], $this->wasted->getUserConfig('u1'));
    $this->wasted->setUserConfig('u1', 'some key', 'some value');
    $this->assertEquals(['some key' => 'some value'], $this->wasted->getUserConfig('u1'));
    $this->wasted->setUserConfig('u1', 'foo', 'bar');
    $this->assertEqualsIgnoreOrder(
        ['some key' => 'some value', 'foo' => 'bar'], $this->wasted->getUserConfig('u1'));
    $this->wasted->clearUserConfig('u1', 'some key');
    $this->assertEquals(['foo' => 'bar'], $this->wasted->getUserConfig('u1'));
    $this->assertEquals([], $this->wasted->getUserConfig('u2'));
  }

  public function testGlobalConfig(): void {
    $this->assertEquals([], $this->wasted->getGlobalConfig());
    $this->wasted->setGlobalConfig('some key', 'some value');
    $this->assertEquals(['some key' => 'some value'], $this->wasted->getGlobalConfig());
    $this->wasted->setGlobalConfig('foo', 'bar');
    $this->assertEqualsIgnoreOrder(
        ['some key' => 'some value', 'foo' => 'bar'], $this->wasted->getGlobalConfig());
    $this->wasted->clearGlobalConfig('some key');
    $this->assertEquals(['foo' => 'bar'], $this->wasted->getGlobalConfig());
  }

  public function testClientConfig(): void {
    $this->assertEquals([], $this->wasted->getClientConfig('u1'));
    $this->assertEquals('-*- cfg -*-', Config::handleRequest($this->wasted, 'u1'));

    $this->wasted->setGlobalConfig('key', 'global');
    $this->wasted->setUserConfig('u1', 'key2', 'user');
    $this->wasted->setUserConfig('u2', 'ignored', 'ignored');
    $this->assertEquals(['key' => 'global', 'key2' => 'user'], $this->wasted->getClientConfig('u1'));
    $this->assertEquals(
        "-*- cfg -*-\nkey\nglobal\nkey2\nuser",
        Config::handleRequest($this->wasted, 'u1'));

    $this->wasted->setUserConfig('u1', 'key', 'user override');
    $this->assertEquals(
        ['key' => 'user override', 'key2' => 'user'],
        $this->wasted->getClientConfig('u1'));
    $this->assertEquals(
        "-*- cfg -*-\nkey\nuser override\nkey2\nuser",
        Config::handleRequest($this->wasted, 'u1'));
  }

  public function testClientConfig_sortAlphabetically(): void {
    $this->assertEquals('-*- cfg -*-', Config::handleRequest($this->wasted, 'u1'));
    $this->wasted->setUserConfig('u1', 'foo', 'bar');
    $this->assertEquals("-*- cfg -*-\nfoo\nbar", Config::handleRequest($this->wasted, 'u1'));

    $this->wasted->setUserConfig('u1', 'a', 'b');
    $this->wasted->setUserConfig('u1', 'y', 'z');
    $this->assertEquals(
        "-*- cfg -*-\na\nb\nfoo\nbar\ny\nz", Config::handleRequest($this->wasted, 'u1'));
  }

  public function testQueryAvailableClasses(): void {
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Empty when no time is configured.
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Empty when no class exists.
    $this->wasted->setBudgetConfig($limitId1, 'daily_limit_minutes_default', 3);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Empty when no class is mapped.
    $classId1 = $this->wasted->addClass('c1');
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Simple case: one class, one budget.
    $this->wasted->addMapping($classId1, $limitId1);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), ['c1 (0:03:00)']);

    // Add another budget that requires unlocking. No change for now.
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->setBudgetConfig($limitId2, 'daily_limit_minutes_default', 2);
    $this->wasted->setBudgetConfig($limitId2, 'require_unlock', 1);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), ['c1 (0:03:00)']);

    // Map the class to the new budget too. This removes the class from the response.
    $this->wasted->addMapping($classId1, $limitId2);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Unlock the locked budget. It restricts the first budget.
    $this->wasted->setOverrideUnlock('u1', $this->dateString(), $limitId2);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), ['c1 (0:02:00)']);

    // Allow time for two classes. Sort by time left.
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addMapping($classId2, $limitId1);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'),
        ['c2 (0:03:00)', 'c1 (0:02:00)']);

    // Group by time left.
    $classId3 = $this->wasted->addClass('c3');
    $this->wasted->addMapping($classId3, $limitId2);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'),
        ['c2 (0:03:00)', 'c1', 'c3 (0:02:00)']);
  }

  public function testSecondsToHHMMSS(): void {
    $this->assertEquals(secondsToHHMMSS(0), '0:00:00');
    foreach ([false, true] as $minus) {
      $f = $minus ? -1 : 1;
      $s = $minus ? '-' : '';
      $this->assertEquals(secondsToHHMMSS(1 * $f), $s . '0:00:01');
      $this->assertEquals(secondsToHHMMSS(60 * $f), $s . '0:01:00');
      $this->assertEquals(secondsToHHMMSS(3600 * $f), $s . '1:00:00');
      $this->assertEquals(secondsToHHMMSS(24 * 3600 * $f), $s . '24:00:00');
      $this->assertEquals(secondsToHHMMSS((24 * 3600 + 64) * $f), $s . '24:01:04');
    }
  }

  public function testQueryOverlappingBudgets(): void {
    $date = $this->dateString();

    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $classId3 = $this->wasted->addClass('c3');
    $classId4 = $this->wasted->addClass('c4');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $limitId3 = $this->wasted->addLimit('u1', 'b3');
    $limitId4 = $this->wasted->addLimit('u1', 'b4');
    // b1: c1, c2, c3
    // b2: c1, c2
    // b3: c3
    // b4: c4
    $this->wasted->addMapping($classId1, $limitId1);
    $this->wasted->addMapping($classId2, $limitId1);
    $this->wasted->addMapping($classId3, $limitId1);
    $this->wasted->addMapping($classId1, $limitId2);
    $this->wasted->addMapping($classId2, $limitId2);
    $this->wasted->addMapping($classId3, $limitId3);
    $this->wasted->addMapping($classId4, $limitId4);

    // Add an overlapping mapping for another user.
    $limitId5 = $this->wasted->addLimit('u2', 'b5');
    $this->wasted->addMapping($classId1, $limitId5);

    for ($i = 0; $i < 2; $i++) {
      // Query for time limitation only (i.e. no date).
      $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId1), ['b2', 'b3']);
      $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId2), ['b1']);
      $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId3), ['b1']);
      $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId4), []);

      // Initially require unlock for all. No effect on repeating the above time queries.
      $this->wasted->setBudgetConfig($limitId1, 'require_unlock', '1');
      $this->wasted->setBudgetConfig($limitId2, 'require_unlock', '1');
      $this->wasted->setBudgetConfig($limitId3, 'require_unlock', '1');
      $this->wasted->setBudgetConfig($limitId4, 'require_unlock', '1');
    }

    // Query for unlock limitation.
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId1, $date), ['b2', 'b3']);
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId2, $date), ['b1']);
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId3, $date), ['b1']);
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId4, $date), []);

    // b2 no longer needs unlocking.
    $this->wasted->setOverrideUnlock('u1', $date, $limitId2);
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId1, $date), ['b3']);

    // Consider date.
    $this->assertEquals(
        $this->wasted->queryOverlappingBudgets($limitId1, '1974-09-29'), ['b2', 'b3']);

    // No more unlock required anywhere.
    $this->wasted->clearBudgetConfig($limitId1, 'require_unlock');
    $this->wasted->clearBudgetConfig($limitId2, 'require_unlock');
    $this->wasted->clearBudgetConfig($limitId3, 'require_unlock');
    $this->wasted->clearBudgetConfig($limitId4, 'require_unlock');

    // Query for unlock limitation.
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId1, $date), []);
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId2, $date), []);
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId3, $date), []);
    $this->assertEquals($this->wasted->queryOverlappingBudgets($limitId4, $date), []);
  }
}

(new WastedTest())->run();
// TODO: Consider writing a test case that follows a representative sequence of events.
