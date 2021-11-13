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

  protected $totalLimitId = [];

  protected function setUpTestCase(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->wasted = Wasted::createForTest(
        TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS, function() {
          return $this->mockTime();
        });
    // Delete all users. Tests may create users and crash without cleaning them up.
    $users = $this->wasted->getUsers();
    foreach ($users as $user) {
      $this->wasted->removeUser($user);
    }
    // Create default users. Also track total limits per user; many tests need this.
    $this->totalLimitId['u1'] = $this->wasted->addUser('u1');
    $this->totalLimitId['u2'] = $this->wasted->addUser('u2');

    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    $this->setErrorHandler();
    // TODO: Consider checking for errors (error_get_last() and DB error) in production code.
    // Errors often go unnoticed.
  }

  protected function setUp(): void {
    parent::setUp();
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    $this->wasted->clearForTest();
    // Most tests are easier to read if we count total time down from 0 instead of from 24.
    $this->wasted->clearLimitConfig($this->totalLimitId['u1'], 'minutes_day');
    $this->wasted->clearLimitConfig($this->totalLimitId['u2'], 'minutes_day');
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    $this->mockTime = 1000;
  }

  private static function classification($id, $limits): array {
    $c = ['class_id' => $id, 'limits' => $limits];
    return $c;
  }

  private static function mapping($limitId, $classId): array {
    return ['limit_id' => strval($limitId), 'class_id' => strval($classId)];
  }

  private static function queryMappings($user): array {
    return DB::query('
        SELECT limit_id, class_id
        FROM mappings
        JOIN limits ON limit_id = id
        WHERE user = %s
        ORDER BY limit_id, class_id',
        $user);
  }

  private function total($seconds, $user = 'u1'): array {
    return [$this->totalLimitId[$user] => ['1970-01-01' => $seconds]];
  }

  private function limit($limitId, $seconds, $user = 'u1'): array {
    return [
        $this->totalLimitId[$user] => ['1970-01-01' => $seconds],
        $limitId => ['1970-01-01' => $seconds]];
  }

  private function insertActivity($user, $titles): array {
    return $this->wasted->insertActivity($user, '', $titles);
  }

  private static function slot($now, $fromHour, $fromMinute, $toHour, $toMinute): array {
    return [(clone $now)->setTime($fromHour, $fromMinute)->getTimestamp(),
        (clone $now)->setTime($toHour, $toMinute)->getTimestamp()];
  }

  private static function timeLeft($currentSeconds, $totalSeconds, $currentSlot, $nextSlot) {
    $timeLeft = new TimeLeft(false, 0);
    $timeLeft->currentSeconds = $currentSeconds;
    $timeLeft->totalSeconds = $totalSeconds;
    $timeLeft->currentSlot = $currentSlot;
    $timeLeft->nextSlot = $nextSlot;
    return $timeLeft;
  }

  private function queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(): array {
    return array_map(
        function ($timeLeft) { return $timeLeft->currentSeconds; },
        $this->wasted->queryTimeLeftTodayAllLimits('u1'));
  }

  public function testSmokeTest(): void {
    $this->wasted->getGlobalConfig();
  }

  public function testTotalTime_SingleWindow_NoLimit(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        []);

    // A single record amounts to zero.
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(0));

    $this->mockTime += 5;
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(5));

    $this->mockTime += 6;
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(11));

    // Switch window (no effect, same limit).
    $this->mockTime += 7;
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(18));
  }

  public function testTotalTime_singleObservation(): void {
    $fromTime = $this->newDateTime();
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(0));
    $this->mockTime += 5;
    $this->insertActivity('u1', []);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(5));
  }

  public function testTotalTime_TwoWindows_NoLimit(): void {
    $fromTime = $this->newDateTime();

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $m0 = $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime);
    $this->assertEquals($m0, []);

    // A single record amounts to zero.
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(0));

    // Advance 5 seconds. Still two windows, but same limit, so total time is 5 seconds.
    $this->mockTime += 5;
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(5));

    // Same with another 6 seconds.
    $this->mockTime += 6;
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(11));

    // Switch to 'window 2'.
    $this->mockTime += 7;
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(18));

    $this->mockTime += 8;
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(26));
  }

  public function testSetUpLimits(): void {
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->assertEquals($limitId2 - $limitId1, 1);

    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $this->assertEquals($classId2 - $classId1, 1);

    $classificationId1 = $this->wasted->addClassification($classId1, 0, '1$');
    $classificationId2 = $this->wasted->addClassification($classId2, 10, '2$');
    $this->assertEquals($classificationId2 - $classificationId1, 1);

    $this->wasted->addMapping($classId1, $limitId1);

    // Class 1 mapped to limit 1. All classes mapped to total limit.
    $classification = $this->wasted->classify('u1', ['window 0', 'window 1', 'window 2']);
    $totalLimitId = $this->totalLimitId['u1'];
    $this->assertEquals($classification, [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId1, [$totalLimitId, $limitId1]),
        self::classification($classId2, [$totalLimitId]),
    ]);

    // Add a second mapping for the same class.
    $this->wasted->addMapping($classId1, $limitId2);

    // Class 1 is now mapped to limits 1 and 2.
    $classification = $this->wasted->classify('u1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId1, [$totalLimitId, $limitId1, $limitId2]),
        self::classification($classId2, [$totalLimitId]),
    ]);

    // Add a mapping for the default class.
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId2);

    // Default class is now mapped to limit 2.
    $classification = $this->wasted->classify('u1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId, $limitId2]),
        self::classification($classId1, [$totalLimitId, $limitId1, $limitId2]),
        self::classification($classId2, [$totalLimitId]),
    ]);

    // Remove mapping of c1 to limit 1.
    $this->assertEquals($this->wasted->classify('u1', ['window 1']), [
        self::classification($classId1, [$totalLimitId, $limitId1, $limitId2]),
    ]);
    $this->wasted->removeMapping($classId1, $limitId1);
    $this->assertEquals($this->wasted->classify('u1', ['window 1']), [
        self::classification($classId1, [$totalLimitId, $limitId2]),
    ]);
  }

  public function testTotalTime_SingleWindow_WithLimits(): void {
    // Set up test limits.
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
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
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        []);

    // A single record amounts to zero.
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        self::limit($limitId1, 0));

    $this->mockTime += 5;
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        self::limit($limitId1, 5));

    $this->mockTime += 6;
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        self::limit($limitId1, 11));

    // Switch window. First interval still counts towards previous window/limit.
    $this->mockTime += 7;
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        [
            $this->totalLimitId['u1'] => ['1970-01-01' => 18],
            $limitId1 => ['1970-01-01' => 18],
            $limitId2 => ['1970-01-01' => 0]
    ]);
  }

  public function testTotalTime_TwoWindows_WithLimits(): void {
    // Set up test limits.
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $limitId3 = $this->wasted->addLimit('u1', 'b3');
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
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        []);

    // Start with a single window. Will not return anything for unused limits.
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->limit($limitId1, 0));

    // Advance 5 seconds and observe second window.
    $this->mockTime += 5;
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $totalLimitId = $this->totalLimitId['u1'];
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => ['1970-01-01' => 5],
        $limitId1 => ['1970-01-01' => 5],
        $limitId2 => ['1970-01-01' => 0],
        $limitId3 => ['1970-01-01' => 0]]);

    // Observe both again after 5 seconds.
    $this->mockTime += 5;
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => ['1970-01-01' => 10],
        $limitId1 => ['1970-01-01' => 10],
        $limitId2 => ['1970-01-01' => 5],
        $limitId3 => ['1970-01-01' => 5]]);

    // Advance 5 seconds and observe 'window 1' only.
    $this->mockTime += 5;
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => ['1970-01-01' => 15],
        $limitId1 => ['1970-01-01' => 15],
        $limitId2 => ['1970-01-01' => 10],
        $limitId3 => ['1970-01-01' => 10]]);

    // Add 6 seconds and start two windows of class 1.
    $this->mockTime += 6;
    $this->insertActivity('u1', ['window 1', 'another window 1']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => ['1970-01-01' => 21],
        $limitId1 => ['1970-01-01' => 21],
        $limitId2 => ['1970-01-01' => 10],
        $limitId3 => ['1970-01-01' => 10]]);

    // Add 7 seconds and observe both windows of class 1 again.
    $this->mockTime += 7;
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => ['1970-01-01' => 28],
        $limitId1 => ['1970-01-01' => 28],
        $limitId2 => ['1970-01-01' => 10],
        $limitId3 => ['1970-01-01' => 10]]);

    // Add 8 seconds and observe 'window 2'.
    $this->mockTime += 8;
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => ['1970-01-01' => 36],
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
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
          []);

      $this->insertActivity('u1', ['window 1']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
          [[$this->dateTimeString(), 0, $class1, 'window 1']]);

      $this->mockTime += 5;
      $this->insertActivity('u1', ['window 1']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
          [[$this->dateTimeString(), 5, $class1, 'window 1']]);

      $this->mockTime += 6;
      $this->insertActivity('u1', ['window 1']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
          [[$this->dateTimeString(), 11, $class1, 'window 1']]);

      // Switch to different window.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class2, 'window 2']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString2 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
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
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
          []);

      $dateTimeString1 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString1, 0, $class1, 'window 1'],
          [$dateTimeString1, 0, $class2, 'window 2']]);

      $this->mockTime += 5;
      $dateTimeString1 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString1, 5, $class1, 'window 1'],
          [$dateTimeString1, 5, $class2, 'window 2']]);

      $this->mockTime += 6;
      $dateTimeString1 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 1', 'window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString1, 11, $class1, 'window 1'],
          [$dateTimeString1, 11, $class2, 'window 2']]);

      // Switch to different windows.
      $this->mockTime += 7;
      $dateTimeString1 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 11', 'window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString1, 18, $class2, 'window 2'],
          [$dateTimeString1, 0, $class1, 'window 11']]);

      $this->mockTime += 8;
      $dateTimeString2 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 2']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString2, 26, $class2, 'window 2'],
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class1, 'window 11']]);

      // Switch to window 1.
      $this->mockTime += 1;
      $dateTimeString3 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 1']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString3, 27, $class2, 'window 2'],
          [$dateTimeString3, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class1, 'window 11']]);

      // Order by time spent.
      $this->mockTime += 20;
      $dateTimeString4 = $this->dateTimeString();
      $this->insertActivity('u1', ['window 42']);
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
          [$dateTimeString4, 38, $class1, 'window 1'],
          [$dateTimeString3, 27, $class2, 'window 2'],
          [$dateTimeString2, 8, $class1, 'window 11'],
          [$dateTimeString4, 0, $class2, 'window 42']]);
    }
  }

  public function testReplaceEmptyTitle(): void {
    $fromTime = $this->newDateTime();
    $this->insertActivity('u1', ['window 1']);
    $this->mockTime += 5;
    $this->insertActivity('u1', ['window 1']);
    $this->mockTime += 5;
    $this->insertActivity('u1', ['']);
    $window1LastSeen = $this->dateTimeString();
    $this->mockTime += 5;
    $this->insertActivity('u1', ['']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(15));

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
        [$window1LastSeen, 10, DEFAULT_CLASS_NAME, 'window 1'],
        [$this->dateTimeString(), 5, DEFAULT_CLASS_NAME, '(no title)']]);
  }

  public function testWeeklyLimit(): void {
    $limitId = $this->wasted->addLimit('u1', 'b');
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId);
    $totalLimitId = $this->totalLimitId['u1'];

    // Limits default to zero.
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    // Daily limit is 42 minutes.
    $this->wasted->setLimitConfig($limitId, 'minutes_day', 42);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);

    // The weekly limit cannot extend the daily limit.
    $this->wasted->setLimitConfig($limitId, 'minutes_week', 666);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);

    // The weekly limit can shorten the daily limit.
    $this->wasted->setLimitConfig($limitId, 'minutes_week', 5);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 5 * 60]);

    // The weekly limit can also be zero.
    $this->wasted->setLimitConfig($limitId, 'minutes_week', 0);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    // Clear the limit.
    $this->wasted->clearLimitConfig($limitId, 'minutes_week');
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);
  }

  public function testGetAllLimitConfigs(): void {
    $allLimitConfigs =
        [$this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true]];
    // No limits configured except the total.
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);

    $limitId = $this->wasted->addLimit('u1', 'b');

    // A mapping is not required for the limit to be returned for the user.
    $allLimitConfigs[$limitId] = ['name' => 'b', 'is_total' => false];
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);

    // Add mapping, doesn't change result.
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId);
    $this->assertEqualsIgnoreOrder($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);

    // Add a config.
    $this->wasted->setLimitConfig($limitId, 'foo', 'bar');
    $allLimitConfigs[$limitId]['foo'] = 'bar';
    $this->assertEqualsIgnoreOrder($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);
  }

  public function testManageLimits(): void {
    $allLimitConfigs =
        [$this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true]];
    // No limits set up except the total.
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);
    // Add a limit but no maping yet.
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    // Not returned when user does not match.
    $this->assertEquals($this->wasted->getAllLimitConfigs('nobody'), []);
    // Returned when user matches.
    $allLimitConfigs[$limitId1] = ['name' => 'b1', 'is_total' => false];
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);

    // Add a mapping.
    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']), [
        self::classification(DEFAULT_CLASS_ID, [$this->totalLimitId['u1']])
    ]);
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId1);
    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']), [
        self::classification(DEFAULT_CLASS_ID, [$this->totalLimitId['u1'], $limitId1])
    ]);

    // Same behavior:
    // Not returned when user does not match.
    $this->assertEquals($this->wasted->getAllLimitConfigs('nobody'), []);
    // Returned when user matches.
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);

    // Add limit config.
    $this->wasted->setLimitConfig($limitId1, 'foo', 'bar');
    $allLimitConfigs[$limitId1]['foo'] = 'bar';
    $this->assertEqualsIgnoreOrder($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);

    // Remove limit, this cascades to mappings and config.
    $this->wasted->removeLimit($limitId1);
    unset($allLimitConfigs[$limitId1]);
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);
    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']), [
        self::classification(DEFAULT_CLASS_ID, [$this->totalLimitId['u1']])
    ]);
  }

  public function testTimeLeftTodayAllLimits_negative(): void {
    $this->insertActivity('u1', ['window 1']);
    $this->mockTime += 5;
    $this->insertActivity('u1', ['window 1']);
    $this->mockTime += 5;
    $this->insertActivity('u1', []);

    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$this->totalLimitId['u1'] => -10]);
  }

  public function testClassificationWithLimit_multipleUsers(): void {
    $this->assertEquals(
        $this->wasted->classify('u1', ['title 1']),
        [self::classification(DEFAULT_CLASS_ID, [$this->totalLimitId['u1']])]);

    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 42, '1$');
    $limitId = $this->wasted->addLimit('u2', 'b1');
    $this->wasted->addMapping($classId, $limitId);

    // Only the total limit is mapped for user u1. The window is classified and associated with the
    // total limit.

    $this->assertEquals(
        $this->wasted->classify('u1', ['title 1']),
        [self::classification($classId, [$this->totalLimitId['u1']])]);
  }

  public function testTimeLeftTodayAllLimits_consumeTimeAndClassify(): void {
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 42, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $totalLimitId = $this->totalLimitId['u1'];

    // Limits are listed even when no mapping is present.
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId1 => 0]);

    // Add mapping.
    $this->wasted->addMapping($classId, $limitId1);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId1 => 0]);

    // Provide 2 minutes.
    $this->wasted->setLimitConfig($limitId1, 'minutes_day', 2);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId1 => 120]);

    // Start consuming time.
    $classification1 = self::classification($classId, [$totalLimitId, $limitId1]);

    $this->assertEquals(
        $this->insertActivity('u1', ['title 1']),
        [$classification1]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId1 => 120]);

    $this->mockTime += 15;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 1']),
        [$classification1]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -15, $limitId1 => 105]);

    // Add a window that maps to no limit.
    $this->mockTime += 15;
    $classification2 = self::classification(DEFAULT_CLASS_ID, [$totalLimitId]);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -30, $limitId1 => 90]);
    $this->mockTime += 15;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -45, $limitId1 => 75]);

    // Add a second limit for title 1 with only 1 minute.
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId, $limitId2);
    $this->wasted->setLimitConfig($limitId2, 'minutes_day', 1);
    $this->mockTime += 1;
    $classification1['limits'][] = $limitId2;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -46, $limitId1 => 74, $limitId2 => 14]);
  }

  public function testInsertClassification(): void {
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId1, 0, '1$');
    $this->wasted->addClassification($classId2, 10, '2$');
    $totalLimitId = $this->totalLimitId['u1'];

    // Single window.
    $this->assertEquals(
        $this->insertActivity('u1', ['title 2']), [
        self::classification($classId2, [$totalLimitId])]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0]);

    // Two windows.
    $this->assertEquals(
        $this->insertActivity('u1', ['title 1', 'title 2']), [
        self::classification($classId1, [$totalLimitId]),
        self::classification($classId2, [$totalLimitId])]);

    // No window at all.
    $this->assertEquals(
        $this->insertActivity('u1', []), []);

    // Time did not advance.
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0]);
  }

  public function testNoWindowsOpen(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 42, '1$');
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId, $limitId);
    $totalLimitId = $this->totalLimitId['u1'];

    $this->assertEquals(
        $this->insertActivity('u1', ['window 1']), [
        self::classification($classId, [$totalLimitId, $limitId])]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['window 1']), [
        self::classification($classId, [$totalLimitId, $limitId])]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -1, $limitId => -1]);

    // All windows closed. Bill time to last window.
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', []),
        []);

    // Used 2 seconds.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$totalLimitId => ['1970-01-01' => 2], $limitId => ['1970-01-01' => 2]]);

    // Time advances.
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', []),
        []);

    // Still only used 2 seconds because nothing was open.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$totalLimitId => ['1970-01-01' => 2], $limitId => ['1970-01-01' => 2]]);
  }

  public function testTimeSpent_handleNoWindows(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '1$');

    $this->insertActivity('u1', ['window 1']);
    $this->mockTime++;
    $this->insertActivity('u1', ['window 1']);
    $this->mockTime++;
    $this->insertActivity('u1', ['window 1']);
    $this->mockTime++;
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->mockTime++;
    $this->insertActivity('u1', ['window 3']);
    $lastSeenWindow1 = $this->dateTimeString();
    $this->mockTime++;
    $this->insertActivity('u1', ['window 3']);
    $this->mockTime++;
    $this->insertActivity('u1', ['window 3']);
    $this->mockTime++;
    $this->insertActivity('u1', []);
    $lastSeenWindow3 = $this->dateTimeString();
    $this->mockTime += 15;
    $this->insertActivity('u1', []);
    $this->mockTime++;
    $this->insertActivity('u1', ['window 2']);
    $this->mockTime++;
    $this->insertActivity('u1', ['window 2']);
    $lastSeenWindow2 = $this->dateTimeString();

    // "No windows" events are handled correctly for both listing titles and computing time spent.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
        [$lastSeenWindow1, 4, 'c1', 'window 1'],
        [$lastSeenWindow3, 3, DEFAULT_CLASS_NAME, 'window 3'],
        [$lastSeenWindow2, 2, DEFAULT_CLASS_NAME, 'window 2']]);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$this->totalLimitId['u1'] => ['1970-01-01' => 8]]);
  }

  public function testCaseHandling(): void {
    $fromTime = $this->newDateTime();
    // First title capitalization persists.
    $this->insertActivity('u1', ['TITLE']);
    $this->mockTime++;
    $this->insertActivity('u1', ['Title']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
        [$this->dateTimeString(), 1, DEFAULT_CLASS_NAME, 'TITLE']]);
  }

  public function testDuplicateTitle(): void {
    $fromTime = $this->newDateTime();
    $this->insertActivity('u1', ['cALCULATOR', 'Calculator']);
    $this->mockTime++;
    $this->insertActivity('u1', ['Calculator', 'Calculator']);
    $lastSeen = $this->dateTimeString();
    $timeSpentByTitle = $this->wasted->queryTimeSpentByTitle('u1', $fromTime);
    // We can pick any of the matching titles.
    $this->assertEquals(
        true,
        $timeSpentByTitle[0][3] == 'Calculator' || $timeSpentByTitle[0][3] == 'cALCULATOR');
    unset($timeSpentByTitle[0][3]);
    $this->assertEquals(
        $timeSpentByTitle, [[$lastSeen, 1, DEFAULT_CLASS_NAME]]);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(1));
  }

  public function testHandleRequest_invalidRequests(): void {
    foreach (['', "\n123"] as $content) {
      http_response_code(200);
      $this->onFailMessage("content: $content");
      $this->assertEquals(RX::handleRequest($this->wasted, $content), '');
      $this->assertEquals(http_response_code(), 400);
    }
  }

  public function testHandleRequest_smokeTest(): void {
    $totalLimitId = $this->totalLimitId['u1'];
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 1"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n\n$totalLimitId");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 1"),
        "$totalLimitId;0;-1;-1;;;".TOTAL_LIMIT_NAME."\n\n$totalLimitId");
  }

  public function testHandleRequest_withLimits(): void {
    $classId1 = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId1, 0, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId1, $limitId1);
    $this->wasted->setLimitConfig($limitId1, 'minutes_day', 5);
    $totalId = $this->totalLimitId['u1'];
    $totalName = TOTAL_LIMIT_NAME;

    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n"), // no titles
        "$totalId;0;0;0;;;$totalName\n$limitId1;0;300;300;;;b1\n");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 1"),
        "$totalId;0;0;0;;;$totalName\n$limitId1;0;300;300;;;b1\n\n$totalId,$limitId1");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 1"),
        "$totalId;0;-1;-1;;;$totalName\n$limitId1;0;299;299;;;b1\n\n$totalId,$limitId1");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 1\nfoo"),
        "$totalId;0;-2;-2;;;$totalName\n$limitId1;0;298;298;;;b1\n\n$totalId,$limitId1\n$totalId");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 1\nfoo"),
        "$totalId;0;-3;-3;;;$totalName\n$limitId1;0;297;297;;;b1\n\n$totalId,$limitId1\n$totalId");

    // Flip order.
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\nfoo\ntitle 1"),
        "$totalId;0;-4;-4;;;$totalName\n$limitId1;0;296;296;;;b1\n\n$totalId\n$totalId,$limitId1");

    // Add second limit.
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId2, 10, '2$');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId1, $limitId2);
    $this->wasted->addMapping($classId2, $limitId2);
    $this->wasted->setLimitConfig($limitId2, 'minutes_day', 2);

    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 1\nfoo"),
        "$totalId;0;-5;-5;;;$totalName\n$limitId1;0;295;295;;;b1\n$limitId2;0;115;115;;;b2\n\n".
        "$totalId,$limitId1,$limitId2\n$totalId");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 1\ntitle 2"),
        "$totalId;0;-6;-6;;;$totalName\n$limitId1;0;294;294;;;b1\n$limitId2;0;114;114;;;b2\n\n".
        "$totalId,$limitId1,$limitId2\n$totalId,$limitId2");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 2"),
        "$totalId;0;-7;-7;;;$totalName\n$limitId1;0;293;293;;;b1\n$limitId2;0;113;113;;;b2\n\n$totalId,$limitId2");
    $this->mockTime++; // This still counts towards b2.
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n"),
        "$totalId;0;-8;-8;;;$totalName\n$limitId1;0;293;293;;;b1\n$limitId2;0;112;112;;;b2\n");
    $this->mockTime++; // Last request had no titles, so no time is added.
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u1\n\ntitle 2"),
        "$totalId;0;-8;-8;;;$totalName\n$limitId1;0;293;293;;;b1\n$limitId2;0;112;112;;;b2\n\n$totalId,$limitId2");
  }

  public function testHandleRequest_mappedForOtherUser(): void {
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId, $limitId1);
    $this->wasted->setLimitConfig($limitId1, 'minutes_day', 1);
    $totalLimitId = $this->totalLimitId['u2'];

    $this->assertEquals(
        RX::handleRequest($this->wasted, "u2\n"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u2\n\ntitle 1"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n\n$totalLimitId");
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u2\n\ntitle 1"),
        "$totalLimitId;0;-1;-1;;;".TOTAL_LIMIT_NAME."\n\n$totalLimitId");

    // Now map same class for user u2.
    $limitId2 = $this->wasted->addLimit('u2', 'b2');
    $this->wasted->setLimitConfig($limitId2, 'minutes_day', 1);
    $this->wasted->addMapping($classId, $limitId2);
    $this->mockTime++;
    $this->assertEquals(
        RX::handleRequest($this->wasted, "u2\n\ntitle 1"),
        "$totalLimitId;0;-2;-2;;;".TOTAL_LIMIT_NAME."\n$limitId2;0;58;58;;;b2\n\n$totalLimitId,$limitId2");
  }

  public function testHandleRequest_utf8Conversion(): void {
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '^...$');
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId, $limitId);
    $totalLimitId = $this->totalLimitId['u1'];

    // This file uses utf8 encoding. The word 'süß' would not match the above RE in utf8 because
    // MySQL's RE library does not support utf8 and would see 5 bytes.
    $this->assertEquals(RX::handleRequest($this->wasted, "u1\n\nsüß"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n$limitId;0;0;0;;;b1\n\n$totalLimitId,$limitId");
  }

  public function testSetOverrideMinutesAndUnlock(): void {
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $limitId);
    $this->wasted->setLimitConfig($limitId, 'minutes_day', 42);
    $this->wasted->setLimitConfig($limitId, 'locked', 1);
    $totalLimitId = $this->totalLimitId['u1'];

    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    $this->wasted->setOverrideUnlock('u1', $this->dateString(), $limitId);
    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);

    $this->wasted->setOverrideMinutes('u1', $this->dateString(), $limitId, 666);
    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 666 * 60]);

    // Test updating.
    $this->wasted->setOverrideMinutes('u1', $this->dateString(), $limitId, 123);
    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 123 * 60]);

    $this->wasted->clearOverrides('u1', $this->dateString(), $limitId);

    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    $this->wasted->setOverrideUnlock('u1', $this->dateString(), $limitId);
    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);
  }

  public function testConcurrentRequestsAndChangedClassification(): void {
    $fromTime = $this->newDateTime();

    $classId1 = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId1, 0, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId1, $limitId1);
    $totalLimitId = $this->totalLimitId['u1'];

    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2']),
        [self::classification(DEFAULT_CLASS_ID, [$totalLimitId])]);
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2']),
        [self::classification(DEFAULT_CLASS_ID, [$totalLimitId])]);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(1));

    // Repeating the last call is idempotent.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2']),
        [self::classification(DEFAULT_CLASS_ID, [$totalLimitId])]);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(1));

    // Add a title that matches the limit, but don't elapse time for it yet. This will extend the
    // title from the previous call.
    $classification2and1 = [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId1, [$totalLimitId, $limitId1])];
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']),
        $classification2and1);
    $timeSpent2and1 = [
        $totalLimitId => ['1970-01-01' => 1],
        $limitId1 => ['1970-01-01' => 0]];
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $timeSpent2and1);

    // Repeating the previous insertion is idempotent.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']),
        $classification2and1);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $timeSpent2and1);

    // Changing the classification rules between concurrent requests causes the second activity
    // record to replace with the first (because class_id is not part of the PK).
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId2, 10 /* higher priority */, '1$');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId2, $limitId2);
    // Request returns the updated classification.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']), [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId2, [$totalLimitId, $limitId2])]); // changed to c2, which maps to b2
    // Records are updated.
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => ['1970-01-01' => 1],
        $limitId2 => ['1970-01-01' => 0]]);

    // Accumulate time.
    $this->mockTime++;
    // From now on we accumulate time with the new classification.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']), [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId2, [$totalLimitId, $limitId2])]);
    $this->mockTime++;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']), [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId2, [$totalLimitId, $limitId2])]);

    // Check results.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
        [$this->dateTimeString(), 3, DEFAULT_CLASS_NAME, 'title 2'],
        [$this->dateTimeString(), 2, 'c2', 'title 1']]);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => ['1970-01-01' => 3],
        $limitId2 => ['1970-01-01' => 2]]);
  }

  function testUpdateClassification_noTimeElapsed(): void {
    // Classification is updated when a title is continued, but not after it is concluded.
    $fromTime = $this->newDateTime();
    $this->insertActivity('u1', ['t1', 't2']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t1', 't2']);

    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '1$');
    $limitId = $this->wasted->addLimit('u1', 'l1');
    $this->wasted->addMapping($classId, $limitId);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(1));

    // Time does not need to elapse.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['t1']), [
        self::classification($classId, [$this->totalLimitId['u1'], $limitId])]);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        // concluded record is not updated
        $this->limit($limitId, 1));
  }

  function testUpdateClassification_timeElapsed(): void {
    // Classification is updated when a title is continued, but not after it is concluded.
    $fromTime = $this->newDateTime();
    $this->insertActivity('u1', ['t1', 't2']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t1', 't2']);

    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '1$');
    $limitId = $this->wasted->addLimit('u1', 'l1');
    $this->wasted->addMapping($classId, $limitId);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(1));

    // Time may elapse.
    $this->mockTime++;
    $this->insertActivity('u1', ['t1']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t1']);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        // concluded record is not updated
        $this->totalLimitId['u1'] => ['1970-01-01' => 3],
        $limitId => ['1970-01-01' => 3]]);
  }

  function testUmlauts(): void {
    $classId = $this->wasted->addClass('c');
    // The single '.' should match the 'ä' umlaut. In utf8 this fails because the MySQL RegExp
    // library does not support utf8 and the character is encoded as two bytes.
    $this->wasted->addClassification($classId, 0, 't.st');
    // Word boundaries should support umlauts. Match any three letter word.
    $this->wasted->addClassification($classId, 0, '[[:<:]]...[[:>:]]');
    $totalLimitId = $this->totalLimitId['u1'];

    // This file uses utf8. Insert an 'ä' (&auml;) character in latin1 encoding.
    // https://cs.stanford.edu/people/miles/iso8859.html
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['t' . chr(228) . 'st']),
        [self::classification($classId, [$totalLimitId])]);

    // Test second classification rule for the word 'süß'.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['x s' . chr(252) . chr(223) . ' x']),
        [self::classification($classId, [$totalLimitId])]);
  }

  function testSameLimitName(): void {
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $limitId2 = $this->wasted->addLimit('u2', 'b1');
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), [
        $this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true],
        $limitId1 => ['name' => 'b1', 'is_total' => false]]);
    $this->assertEquals($this->wasted->getAllLimitConfigs('u2'), [
        $this->totalLimitId['u2'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true],
        $limitId2 => ['name' => 'b1', 'is_total' => false]]);
    $this->assertEquals($this->wasted->getAllLimitConfigs('nobody'), []);
  }

  function testLimitWithUmlauts(): void {
    $limitName = 't' . chr(228) . 'st';
    $limitId = $this->wasted->addLimit('u1', $limitName);
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), [
        $this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true],
        $limitId => ['name' => $limitName, 'is_total' => false]]);
  }

  function testReclassify(): void {
    $fromTime = $this->newDateTime();
    $date = $this->dateString();
    $totalLimitId = $this->totalLimitId['u1'];

    $this->insertActivity('u1', ['w1', 'w2']);
    $this->insertActivity('u2', ['w1', 'w2']);
    $this->mockTime++;
    $this->newDateTime();
    $this->insertActivity('u1', ['w1', 'w2']);
    $this->insertActivity('u2', ['w1', 'w2']);
    $this->mockTime++;
    $this->insertActivity('u1', ['w1', 'w2']);
    $this->insertActivity('u2', ['w1', 'w2']);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$totalLimitId => [$date => 2]]);

    $this->wasted->reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$totalLimitId => [$date => 2]]);

    // Add classification for w1.
    $classId1 = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId1, 0, '1$');
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId1, $limitId1);

    $this->wasted->reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 2],
        $limitId1 => [$date => 2]]);

    // Add classification for w2.
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId2, 0, '2$');
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->addMapping($classId2, $limitId2);

    $this->wasted->reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 2],
        $limitId1 => [$date => 2],
        $limitId2 => [$date => 2]]);

    // Check u2 to ensure reclassification works across users.
    $limitId1_2 = $this->wasted->addLimit('u2', 'b1');
    $limitId2_2 = $this->wasted->addLimit('u2', 'b2');
    $this->wasted->addMapping($classId1, $limitId1_2);
    $this->wasted->addMapping($classId2, $limitId2_2);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u2', $fromTime), [
        $this->totalLimitId['u2'] => [$date => 2],
        $limitId1_2 => [$date => 2],
        $limitId2_2 => [$date => 2]]);

    // Attempt to mess with the "" placeholder title.
    $this->mockTime++;
    $this->insertActivity('u1', []);
    $this->mockTime++;
    $this->insertActivity('u1', []);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 3],
        $limitId1 => [$date => 3],
        $limitId2 => [$date => 3]]);
    $this->wasted->addClassification($classId1, 666, '()');
    $this->wasted->reclassify($fromTime);
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 3],
        $limitId1 => [$date => 3]]);

    // Reclassify only a subset. Note that above we have interrupted the flow via "".
    $this->insertActivity('u1', ['w3', 'w2']);
    $fromTime2 = $this->newDateTime();
    $this->onFailMessage('fromTime2='.$fromTime2->getTimestamp());
    $this->mockTime++;
    $this->insertActivity('u1', ['w3', 'w2']);
    $this->wasted->addClassification($classId2, 667, '()'); // match everything
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 4],
        $limitId1 => [$date => 4]]);

    $this->wasted->reclassify($fromTime2);
    $this->onFailMessage('fromTime2='.$fromTime2->getTimestamp());
    $this->assertEqualsIgnoreOrder(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 4],
        $limitId1 => [$date => 3],
        $limitId2 => [$date => 1]]);
  }

  public function testRemoveClass(): void {
    $classId = $this->wasted->addClass('c1');
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->addMapping($classId, $limitId);
    $classificationId = $this->wasted->addClassification($classId, 42, '()');
    $totalLimitId = $this->totalLimitId['u1'];

    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']),
        [['class_id' => $classId, 'limits' => [$totalLimitId, $limitId]]]);
    $this->assertEquals(
        $this->wasted->getAllClassifications(),
        [$classificationId => ['name' => 'c1', 're' => '()', 'priority' => 42]]);
    $numMappings = count(DB::query(
        'SELECT * FROM mappings WHERE limit_id NOT IN (SELECT total_limit_id FROM users)'));
    $this->assertEquals($numMappings, 1);

    $this->wasted->removeClass($classId);

    $this->assertEquals(
        $this->wasted->classify('u1', ['foo']),
        [['class_id' => DEFAULT_CLASS_ID, 'limits' => [$totalLimitId]]]);
    $this->assertEquals(
        $this->wasted->getAllClassifications(),
        []);
    $numMappings = count(DB::query(
        'SELECT * FROM mappings WHERE limit_id NOT IN (SELECT total_limit_id FROM users)'));
    $this->assertEquals($numMappings, 0);
  }

  public function testRemoveClassReclassifies(): void {
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $classificationId1 = $this->wasted->addClassification($classId1, 0, '1$');
    $classificationId2 = $this->wasted->addClassification($classId2, 0, '2$');
    $this->insertActivity('u1', ['t1']);

    $fromTime = $this->newDateTime();
    $this->mockTime++;
    $fromTime1String = $this->dateTimeString();
    $this->insertActivity('u1', ['t2']);
    $this->mockTime++;
    $fromTime2String = $this->dateTimeString();
    $this->insertActivity('u1', ['t3']);

    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
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
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime), [
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

  public function testTotalLimit(): void {
    // Use a clean user to avoid interference with optimized test setup.
    $user = 'user'.strval(rand());
    $totalLimitId = $this->wasted->addUser($user);

    try {
      // Start with default class mapped to total limit. This mapping was inserted by the user setup
      // mapping all existing classes to the new user's total limit.
      $this->assertEquals(
          self::queryMappings($user), [
          self::mapping($totalLimitId, DEFAULT_CLASS_ID)
      ]);

      // Added classes are mapped to total limit by the trigger.
      $classId1 = $this->wasted->addClass('c1');
      $this->assertEquals(
          self::queryMappings($user), [
          self::mapping($totalLimitId, DEFAULT_CLASS_ID),
          self::mapping($totalLimitId, $classId1)
      ]);

      // And again.
      $classId2 = $this->wasted->addClass('c2');
      $this->assertEquals(
          self::queryMappings($user), [
          self::mapping($totalLimitId, DEFAULT_CLASS_ID),
          self::mapping($totalLimitId, $classId1),
          self::mapping($totalLimitId, $classId2)
      ]);

      // Adding a limit has not effect (yet).
      $limitId1 = $this->wasted->addLimit($user, 'b1');
      $this->assertEquals(
          self::queryMappings($user), [
          self::mapping($totalLimitId, DEFAULT_CLASS_ID),
          self::mapping($totalLimitId, $classId1),
          self::mapping($totalLimitId, $classId2)
      ]);

      // Mapping a class to the new limit shows up in the result. No change to the default limit's
      // mappings.
      $this->wasted->addMapping($classId1, $limitId1);
      $this->assertEquals(
          self::queryMappings($user), [
          self::mapping($totalLimitId, DEFAULT_CLASS_ID),
          self::mapping($totalLimitId, $classId1),
          self::mapping($totalLimitId, $classId2),
          self::mapping($limitId1, $classId1)
      ]);

      // Add more classes to exercise the trigger.
      $classId3 = $this->wasted->addClass('c3');
      $classId4 = $this->wasted->addClass('c4');
      $this->assertEquals(
          self::queryMappings($user), [
          self::mapping($totalLimitId, DEFAULT_CLASS_ID),
          self::mapping($totalLimitId, $classId1),
          self::mapping($totalLimitId, $classId2),
          self::mapping($totalLimitId, $classId3),
          self::mapping($totalLimitId, $classId4),
          self::mapping($limitId1, $classId1)
      ]);

      // Remove a class to exercise ON DELETE CASCADE in mappings table.
      $this->wasted->removeClass($classId2);
      $this->assertEquals(
          self::queryMappings($user), [
          self::mapping($totalLimitId, DEFAULT_CLASS_ID),
          self::mapping($totalLimitId, $classId1),
          self::mapping($totalLimitId, $classId3),
          self::mapping($totalLimitId, $classId4),
          self::mapping($limitId1, $classId1)
      ]);

      // Removing a mapping has no effect beyond that mapping.
      $this->wasted->removeMapping($classId1, $limitId1);
      $this->assertEquals(
          self::queryMappings($user), [
          self::mapping($totalLimitId, DEFAULT_CLASS_ID),
          self::mapping($totalLimitId, $classId1),
          self::mapping($totalLimitId, $classId3),
          self::mapping($totalLimitId, $classId4)
      ]);
    } finally {
      $this->wasted->removeUser($user);
    }
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

  public function testRemoveTriggersWhenRemovingUser(): void {
    // Remember initial number of triggers.
    $n = count(DB::query('SHOW TRIGGERS'));
    $user = 'user'.strval(rand());
    $this->wasted->addUser($user);
    // We added one trigger...
    try {
      $this->assertEquals(count(DB::query('SHOW TRIGGERS')), $n + 1);
    } finally {
      $this->wasted->removeUser($user);
    }
    // ... and removed it.
    $this->assertEquals(count(DB::query('SHOW TRIGGERS')), $n);
  }

  public function testRenameLimit(): void {
    $limitId = $this->wasted->addLimit('u1', 'b1');
    $this->wasted->renameLimit($limitId, 'b2');
    $this->assertEquals(
        $this->wasted->getAllLimitConfigs('u1'), [
        $this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true],
        $limitId => ['name' => 'b2', 'is_total' => false]]);
  }

  public function testRenameClass(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c1');
    $this->wasted->addClassification($classId, 0, '()');
    $this->wasted->renameClass($classId, 'c2');
    $this->insertActivity('u1', ['t']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByTitle('u1', $fromTime),
        [[$this->dateTimeString(), 0, 'c2', 't']]);
  }

  public function testChangeClassificationAndReclassify(): void {
    $fromTime = $this->newDateTime();
    // Reclassification excludes the specified limit, so advance by 1s.
    $this->mockTime++;
    $classId = $this->wasted->addClass('c1');
    $classificationId = $this->wasted->addClassification($classId, 0, 'nope');
    $this->assertEquals(
        $this->wasted->getAllClassifications(),
        [$classificationId => ['name' => 'c1', 're' => 'nope', 'priority' => 0]]);
    $this->insertActivity('u1', ['t']);
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
    $this->insertActivity('u1', ['t1']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t1']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t1', 't2']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t1', 't2']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t2', 't3']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t2', 't3']);
    $this->mockTime++;
    $lastSeenT2 = $this->dateTimeString();
    $this->insertActivity('u1', ['t3', 't4']);
    $this->mockTime++;
    $lastSeenT3T4 = $this->dateTimeString();
    $this->insertActivity('u1', ['t3', 't4']);

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

  public function testLimitsToClassesTable(): void {
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
    $this->wasted->setLimitConfig($limitId2, 'foo', 'bar');
    $this->wasted->setLimitConfig($limitId3, 'a', 'b');
    $this->wasted->setLimitConfig($limitId3, 'c', 'd');

    $this->assertEquals(
        $this->wasted->getLimitsToClassesTable('u1'), [
        ['b2', 'c2', '', 'foo=bar'],
        ['b2', 'c3', 'b3, b4', 'foo=bar'],
        ['b3', 'c3', 'b2, b4', 'a=b, c=d'],
        ['b4', 'c3', 'b2, b3', ''],
        ['b4', 'c4', 'b5', ''],
        ['b5', 'c4', 'b4', ''],
        ['Total', 'c1', '', ''],
        ['Total', DEFAULT_CLASS_NAME, '', '']
    ]);
  }

  public function testClassesToClassificationTable(): void {
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId1, 42, '1');
    $this->wasted->addClassification($classId2, 43, '2');
    $this->insertActivity('u1', ['t1', 't2', 't3']);
    $this->mockTime += 2;
    $this->insertActivity('u1', ['t1', 't2', 't3']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t22', 't3', 't4']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t22', 't3', 't4']);
    $this->assertEquals(
        $this->wasted->getClassesToClassificationTable(), [
        ['c1', '1', 42, 1, 't1'],
        ['c2', '2', 43, 2, "t2\nt22"]
    ]);
    // Test that priority is considered: "t12" is not a sample for c1, but for c2.
    $this->mockTime++;
    $classification = $this->insertActivity('u1', ['t12']);
    $this->assertEquals(
        $classification,
        [['class_id' => $classId2, 'limits' => [$this->totalLimitId['u1']]]]);
    $this->assertEquals(
        $this->wasted->getClassesToClassificationTable(), [
        ['c1', '1', 42, 1, 't1'],
        ['c2', '2', 43, 3, "t12\nt2\nt22"]
    ]);
    // Test that samples are cropped to 1024 characters. Titles are VARCHAR(256).
    $this->mockTime++;
    $one255 = str_repeat('1', 255);
    $titles = ['a' . $one255, 'b' . $one255, 'c' . $one255, 'd' . $one255, 'e' . $one255];
    $classification = $this->insertActivity('u1', $titles);
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
    $this->assertEquals('', Config::handleRequest($this->wasted, 'u1'));

    $this->wasted->setGlobalConfig('key', 'global');
    $this->wasted->setUserConfig('u1', 'key2', 'user');
    $this->wasted->setUserConfig('u2', 'ignored', 'ignored');
    $this->assertEqualsIgnoreOrder(
        ['key' => 'global', 'key2' => 'user'], $this->wasted->getClientConfig('u1'));
    $this->assertEqualsIgnoreOrder(
        "key\nglobal\nkey2\nuser", Config::handleRequest($this->wasted, 'u1'));

    $this->wasted->setUserConfig('u1', 'key', 'user override');
    $this->assertEqualsIgnoreOrder(
        ['key' => 'user override', 'key2' => 'user'], $this->wasted->getClientConfig('u1'));
    $this->assertEqualsIgnoreOrder(
        "key\nuser override\nkey2\nuser",
        Config::handleRequest($this->wasted, 'u1'));
  }

  public function testClientConfig_sortAlphabetically(): void {
    $this->wasted->setUserConfig('u1', 'foo', 'bar');
    $this->assertEquals("foo\nbar", Config::handleRequest($this->wasted, 'u1'));

    $this->wasted->setUserConfig('u1', 'a', 'b');
    $this->wasted->setUserConfig('u1', 'y', 'z');
    $this->assertEquals("a\nb\nfoo\nbar\ny\nz", Config::handleRequest($this->wasted, 'u1'));
  }

  public function testQueryAvailableClasses(): void {
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Empty when no time is configured.
    $limitId1 = $this->wasted->addLimit('u1', 'b1');
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Empty when no class exists. (The default class is mapped to the total limit, which is
    // zeroed in the test setup.)
    $this->wasted->setLimitConfig($limitId1, 'minutes_day', 3);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Empty when class is not mapped to a nonzero limit.
    $classId1 = $this->wasted->addClass('c1');
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Set the total limit to 10 minutes.
    $this->wasted->setLimitConfig($this->totalLimitId['u1'], 'minutes_day', 10);
    // This enables the default class, which will henceforth be present, and c1.
    $this->assertEquals(
        $this->wasted->queryClassesAvailableTodayTable('u1'),
        ['c1', DEFAULT_CLASS_NAME.' (0:10:00)']);

    // Map class c1 to limit 1.
    $this->wasted->addMapping($classId1, $limitId1);
    $this->assertEquals(
        $this->wasted->queryClassesAvailableTodayTable('u1'),
        [DEFAULT_CLASS_NAME.' (0:10:00)', 'c1 (0:03:00)']);

    // Remove the default class by mapping it to an otherwise ignored zero limit.
    $this->wasted->addMapping(DEFAULT_CLASS_ID, $this->wasted->addLimit('u1', 'l0'));

    // Add another limit that requires unlocking. No change for now.
    $limitId2 = $this->wasted->addLimit('u1', 'b2');
    $this->wasted->setLimitConfig($limitId2, 'minutes_day', 2);
    $this->wasted->setLimitConfig($limitId2, 'locked', 1);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), ['c1 (0:03:00)']);

    // Map c1 to the new (zero) limit too. This removes the class from the response.
    $this->wasted->addMapping($classId1, $limitId2);
    $this->assertEquals($this->wasted->queryClassesAvailableTodayTable('u1'), []);

    // Unlock the locked limit. It restricts the first limit.
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

  public function testQueryOverlappingLimits(): void {
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
      $this->assertEquals($this->wasted->queryOverlappingLimits($limitId1), ['b2', 'b3']);
      $this->assertEquals($this->wasted->queryOverlappingLimits($limitId2), ['b1']);
      $this->assertEquals($this->wasted->queryOverlappingLimits($limitId3), ['b1']);
      $this->assertEquals($this->wasted->queryOverlappingLimits($limitId4), []);

      // Initially require unlock for all. No effect on repeating the above time queries.
      $this->wasted->setLimitConfig($limitId1, 'locked', '1');
      $this->wasted->setLimitConfig($limitId2, 'locked', '1');
      $this->wasted->setLimitConfig($limitId3, 'locked', '1');
      $this->wasted->setLimitConfig($limitId4, 'locked', '1');
    }

    // Query for unlock limitation.
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId1, $date), ['b2', 'b3']);
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId2, $date), ['b1']);
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId3, $date), ['b1']);
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId4, $date), []);

    // b2 no longer needs unlocking.
    $this->wasted->setOverrideUnlock('u1', $date, $limitId2);
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId1, $date), ['b3']);

    // Consider date.
    $this->assertEquals(
        $this->wasted->queryOverlappingLimits($limitId1, '1974-09-29'), ['b2', 'b3']);

    // No more unlock required anywhere.
    $this->wasted->clearLimitConfig($limitId1, 'locked');
    $this->wasted->clearLimitConfig($limitId2, 'locked');
    $this->wasted->clearLimitConfig($limitId3, 'locked');
    $this->wasted->clearLimitConfig($limitId4, 'locked');

    // Query for unlock limitation.
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId1, $date), []);
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId2, $date), []);
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId3, $date), []);
    $this->assertEquals($this->wasted->queryOverlappingLimits($limitId4, $date), []);
  }

  public function testPruneTables(): void {
    $fromTime = $this->newDateTime();
    $classId = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId, 0, '2$');
    $limitId = $this->wasted->addLimit('u1', 'l2');
    $this->wasted->addMapping($classId, $limitId);

    $this->insertActivity('u1', ['t1', 't2']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t1', 't2', 't3']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t2', 't3']);

    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->limit($limitId, 2));

    $this->wasted->pruneTables($fromTime);

    // No change.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->limit($limitId, 2));

    $fromTime->add(new DateInterval('PT1S'));
    $this->wasted->pruneTables($fromTime);

    // 1 second chopped off.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->limit($limitId, 1));
  }

  public function testGetOrCreate(): void {
    $a = [];
    $v = &getOrCreate($a, 'k', 42);
    $this->assertEquals($a, ['k' => 42]);
    $v++;
    $this->assertEquals($a, ['k' => 43]);

    $b = &getOrCreate(getOrCreate($a, 'k2', []), 'kk', 23);
    $b++;
    $this->assertEqualsIgnoreOrder($a, ['k' => 43, 'k2' => ['kk' => 24]]);
  }

  public function testSpanMultipleDays(): void {
    $classId1 = $this->wasted->addClass('c1');
    $classId2 = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId1, 0, '1$');
    $this->wasted->addClassification($classId2, 0, '2$');
    $limitId1 = $this->wasted->addLimit('u1', 'l1');
    $limitId2 = $this->wasted->addLimit('u1', 'l2');
    $this->wasted->addMapping($classId1, $limitId1);
    $this->wasted->addMapping($classId2, $limitId2);

    $fromTime = new DateTime('1974-09-29 23:59:50');
    $this->mockTime = $fromTime->getTimestamp();
    $this->insertActivity('u1', ['t1', 't3']);
    $this->mockTime += 5; // 59:59:55
    $this->insertActivity('u1', ['t2', 't3']);
    $this->mockTime += 16; // 00:00:11
    $this->insertActivity('u1', ['t1', 't2', 't3']);

    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime), [
            $this->totalLimitId['u1'] => ['1974-09-29' => 10, '1974-09-30' => 11],
            $limitId1 => ['1974-09-29' => 5, '1974-09-30' => 0],
            $limitId2 => ['1974-09-29' => 5, '1974-09-30' => 11]]);
  }

  private static function timeSpent($title, $sumS, $tsLastSeen) {
    return [
        'title' => $title,
        'name' => DEFAULT_CLASS_NAME,
        'sum_s' => strval($sumS),
        'ts_last_seen' => strval($tsLastSeen)];
  }

  public function testOverlaps(): void {
    $fromTs = $this->mockTime;
    $this->insertActivity('u1', ['t1']);
    $this->mockTime += 10;
    $this->insertActivity('u1', ['t1']);

    // [from, to, time, last seen]
    $tests = [
        // t0 matches
        [0, 10, 10, 10],
        [0, 9, 9, 9],
        [0, 11, 10, 10],

        // t1 matches
        [-1, 10, 10, 10],
        [1, 10, 9, 10],

        // start enclosed
        [-2, 5, 5, 5],
        [-2, 10, 10, 10],
        [-2, 15, 10, 10],

        // end enclosed
        [2, 9, 7, 9],
        [2, 10, 8, 10],
        [2, 11, 8, 10],

        // both enclosed
        [-3, 13, 10, 10],

        // no overlap
        [-4, -2],
        [12, 14],
    ];
    foreach ($tests as $t) {
      $this->onFailMessage(implode(', ', $t));
      $expectation = count($t) == 4 ? [self::timeSpent('t1', $t[2], $fromTs + $t[3])] : [];
      $this->assertEquals(
          $this->wasted->queryTimeSpentByTitleInternal(
              'u1', $fromTs + $t[0], $fromTs + $t[1], false),
              $expectation);

      $t0 = (new DateTime())->setTimestamp($fromTs + $t[0]);
      $t1 = (new DateTime())->setTimestamp($fromTs + $t[1]);
      $expectation = count($t) == 4 ? [$this->totalLimitId['u1'] => ['1970-01-01' => $t[2]]] : [];
      $this->assertEquals(
          $this->wasted->queryTimeSpentByLimitAndDate('u1', $t0, $t1),
          $expectation);
      }
  }

  public function testSampleInterval(): void {
    $fromTime = $this->newDateTime();

    // Default is 15s. Grace period is always 30s.
    $this->insertActivity('u1', ['t']);
    $this->mockTime += 45;
    $this->insertActivity('u1', ['t']);

    // This yields one interval.
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(45));

    // Exceeding 45s starts a new interval.
    $this->mockTime += 46;
    $this->insertActivity('u1', ['t']);
    $this->mockTime += 1;
    $this->insertActivity('u1', ['t']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(46));

    // Increase sample interval to 30s, i.e. 60s including the grace period.
    $this->wasted->setUserConfig('u1', 'sample_interval_seconds', 30);
    $this->mockTime += 54;
    $this->insertActivity('u1', ['t']);
    $this->assertEquals(
        $this->wasted->queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(100));
  }

  public function testUserManagement_add_remove_get(): void {
    // Check default users.
    $this->assertEquals($this->wasted->getUsers(), ['u1', 'u2']);

    // Create new user that sorts at the end.
    $user = 'user'.strval(rand());
    try {
      $this->wasted->addUser($user);
      $this->assertEquals($this->wasted->getUsers(), ['u1', 'u2', $user]);
      // Can't re-add the same user.
      try {
        $this->wasted->addUser($user);
        throw new AssertionError('Should not be able to create existing user');
      } catch (Exception $e) { // expected
        $s = "Duplicate entry '$user'";
        $this->assertEquals(substr($e->getMessage(), 0, strlen($s)), $s);
        WastedTestBase::$lastDbError = null; // don't fail the test
      }
    } finally {
      $this->wasted->removeUser($user);
    }
  }

  public function testUnknownUser(): void {
    $user = 'user'.strval(rand());
    try {
      $this->insertActivity($user, ['t1']);
      throw new AssertionError('Should not be able to report activity as nonexisting user');
    } catch (Exception $e) {
      // expected
      $s = 'Cannot add or update a child row: a foreign key constraint fails';
      $this->assertEquals(substr($e->getMessage(), 0, strlen($s)), $s);
      WastedTestBase::$lastDbError = null; // don't fail the test
    }
  }

  public function testCannotDeleteMappingToTotalLimit(): void {
    $n = count($this->queryMappings('u1'));
    $classId = $this->wasted->addClass('c1');
    $this->assertEquals(count($this->queryMappings('u1')), $n + 1);
    $this->wasted->removeMapping($classId, $this->totalLimitId['u1']);
    $this->assertEquals(count($this->queryMappings('u1')), $n + 1);
  }

  public function testCannotDeleteTotalLimit(): void {
    $allLimitConfigs = $this->wasted->getAllLimitConfigs('u1');
    $this->wasted->removeLimit($this->totalLimitId['u1']);
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);
  }

  public function testCannotRenameTotalLimit(): void {
    $allLimitConfigs = $this->wasted->getAllLimitConfigs('u1');
    $this->wasted->renameLimit($this->totalLimitId['u1'], 'foobar');
    $this->assertEquals($this->wasted->getAllLimitConfigs('u1'), $allLimitConfigs);
  }

  public function testDuplicateMapping(): void {
    $classId = $this->wasted->addClass('c1');
    try {
      $this->wasted->addMapping($classId, $this->totalLimitId['u1']);
      throw new AssertionError('Should not be able to add duplicate mapping');
    } catch (Exception $e) {
      // expected
      $s = 'Duplicate entry';
      $this->assertEquals(substr($e->getMessage(), 0, strlen($s)), $s);
      WastedTestBase::$lastDbError = null; // don't fail the test
    }
  }

  public function testInvalidRegEx(): void {
    $classId = $this->wasted->addClass('c1');
    try {
      $this->wasted->addClassification($classId, 0, '*');
      throw new AssertionError('Should not be able to add invalid RegEx');
    } catch (Exception $e) {
      // expected
      $s = "Got error '";
      $this->assertEquals(substr($e->getMessage(), 0, strlen($s)), $s);
      $s = "' from regexp";
      $this->assertEquals(substr($e->getMessage(), -strlen($s)), $s);
      WastedTestBase::$lastDbError = null; // don't fail the test
    }
  }

  public function testQueryTitleSequence(): void {
    $fromTime = $this->newDateTime();
    $fromTimeString1 = $this->dateTimeString();
    $this->assertEquals($this->wasted->queryTitleSequence('u1', $fromTime), []);

    $classId = $this->wasted->addClass('c2');
    $this->wasted->addClassification($classId, 0, '2$');

    $this->insertActivity('u1', ['t1']);
    $this->mockTime++;
    $this->insertActivity('u1', ['t1']);
    $this->mockTime++;
    $fromTimeString2 = $this->dateTimeString();
    $this->insertActivity('u1', ['t1', 't2']);
    // A range that will only include the to_ts, not the from_ts.
    // The end timestamp is exclusive, so this will not include t2.
    $fromTime2 = $this->newDateTime()->sub(new DateInterval('P1D'));
    $this->mockTime++;
    // This will now include t2.
    $fromTime3 = $this->newDateTime()->sub(new DateInterval('P1D'));
    $toTimeString = $this->dateTimeString();
    $this->insertActivity('u1', ['t1', 't2']);

    $this->assertEquals(
        $this->wasted->queryTitleSequence('u1', $fromTime),
        [
            [$fromTimeString1, $toTimeString, DEFAULT_CLASS_NAME, 't1'],
            [$fromTimeString2, $toTimeString, 'c2', 't2']
        ]);

    $this->assertEquals(
        $this->wasted->queryTitleSequence('u1', $fromTime2),
        [
            [$fromTimeString1, $toTimeString, DEFAULT_CLASS_NAME, 't1']
        ]);

    $this->assertEquals(
        $this->wasted->queryTitleSequence('u1', $fromTime3),
        [
            [$fromTimeString1, $toTimeString, DEFAULT_CLASS_NAME, 't1'],
            [$fromTimeString2, $toTimeString, 'c2', 't2']
        ]);
  }

  public function testOnDeleteCascade_deleteLimit(): void {
    $classId = $this->wasted->addClass('c');
    $limitId = $this->wasted->addLimit('u1', 'foo');
    $this->wasted->setLimitConfig($limitId, 'a', 'b');
    $this->wasted->addMapping($classId, $limitId);
    $this->wasted->setOverrideUnlock('u1', '1970-01-01', $limitId);

    foreach ([[2, 1, 1], [1, 0, 0]] as $expected) {
      $this->onFailMessage('expected: ' . implode(', ', $expected));
      // The total limit is always included.
      $this->assertEquals(count($this->wasted->getAllLimitConfigs('u1')), $expected[0]);
      $n = intval(
          DB::query(
              'SELECT COUNT(*) AS n FROM mappings WHERE limit_id = %i',
              $limitId)
          [0]['n']);
      $this->assertEquals($n, $expected[1]);
      $n = intval(
          DB::query(
              'SELECT COUNT(*) AS n FROM overrides WHERE limit_id = %i',
              $limitId)
          [0]['n']);
      $this->assertEquals($n, $expected[2]);

      $this->wasted->removeLimit($limitId);
    }
  }

  public function testGetSlotsOrError(): void {
    $now = $this->newDateTime();
    $this->assertEquals(Wasted::getSlotsOrError($now, ''), []);
    $this->assertEquals(
        Wasted::getSlotsOrError($now, '11-13:30'), [
            self::slot($now, 11, 0, 13, 30)]);
    $this->assertEquals(
        Wasted::getSlotsOrError($now, '11-13:30, 2:01pm-4:15pm'), [
            self::slot($now, 11, 0, 13, 30),
            self::slot($now, 14, 1, 16, 15)]);
    $this->assertEquals(
        Wasted::getSlotsOrError($now, '11-13:30, 2:01pm-4:15pm  ,  20:00-20:42'), [
            self::slot($now, 11, 0, 13, 30),
            self::slot($now, 14, 1, 16, 15),
            self::slot($now, 20, 0, 20, 42)]);
    $this->assertEquals(
        Wasted::getSlotsOrError($now, '0-1, 1:00-2, 20-21:00, 22:00-23:59'), [
            self::slot($now, 0, 0, 1, 0),
            self::slot($now, 1, 0, 2, 0),
            self::slot($now, 20, 0, 21, 0),
            self::slot($now, 22, 0, 23, 59)]);
    $this->assertEquals(
        Wasted::getSlotsOrError($now, '7:30-12p, 12a-1'), [
            self::slot($now, 0, 0, 1, 0),
            self::slot($now, 7, 30, 12, 0)]);

    $this->assertEquals(
        Wasted::getSlotsOrError($now, '1-2, 20-30'),
        "Invalid time slot: '20-30'");
    $this->assertEquals(
        Wasted::getSlotsOrError($now, '1-2, 17:60 - 18:10'),
        "Invalid time slot: '17:60 - 18:10'");
    $this->assertEquals(
        Wasted::getSlotsOrError($now, '1-2, 17:a - 18:10'),
        "Invalid time slot: '17:a - 18:10'");
  }

  public function testApplySlots(): void {
    $now = $this->newDateTime();

    // Empty slots string -> zero time.
    $timeLeft = new TimeLeft(false, 42);
    Wasted::applySlots($now, '', $timeLeft);
    $this->assertEquals($timeLeft, self::timeLeft(0, 0, [], []));

    // Configure slots.
    $slots = '8-9, 12-14, 20-21:30';

    // Before first slot, total limited by slots.
    $now->setTime(6, 30);
    $timeLeft = new TimeLeft(false, 24 * 60 * 60);
    Wasted::applySlots($now, $slots, $timeLeft);
    $this->assertEquals(
        $timeLeft, self::timeLeft(0, 4.5 * 60 * 60, [], self::slot($now, 8, 0, 9, 0)));

    // Before first slot, total limited by mintues.
    $now->setTime(6, 30);
    $timeLeft = new TimeLeft(false, 42);
    Wasted::applySlots($now, $slots, $timeLeft);
    $this->assertEquals(
        $timeLeft, self::timeLeft(0, 42, [], self::slot($now, 8, 0, 9, 0)));

    // Within a slot, total limtied by slots.
    $now->setTime(13, 0);
    $timeLeft = new TimeLeft(false, 9999);
    Wasted::applySlots($now, $slots, $timeLeft);
    $this->assertEquals(
        $timeLeft,
        self::timeLeft(
            60 * 60, 2.5 * 60 * 60,
            self::slot($now, 12, 0, 14, 0),
            self::slot($now, 20, 0, 21, 30)));

    // Within last slot, total limited by slots.
    $now->setTime(21, 0);
    $timeLeft = new TimeLeft(false, 9999);
    Wasted::applySlots($now, $slots, $timeLeft);
    $this->assertEquals(
        $timeLeft, self::timeLeft(30 * 60, 30 * 60, self::slot($now, 20, 0, 21, 30), []));

    // Between two slots, total limited by slots.
    $now->setTime(11, 0);
    $timeLeft = new TimeLeft(false, 99999);
    Wasted::applySlots($now, $slots, $timeLeft);
    $this->assertEquals(
        $timeLeft, self::timeLeft(0, 3.5 * 60 * 60, [], self::slot($now, 12, 0, 14, 0)));

    // After last slot, total limited by slots.
    $now->setTime(23, 0);
    $timeLeft = new TimeLeft(false, 9999);
    Wasted::applySlots($now, $slots, $timeLeft);
    $this->assertEquals($timeLeft, self::timeLeft(0, 0, [], []));
  }

  public function testHandleNullInOverrideSlots(): void {
    $totalLimitId = $this->totalLimitId['u1'];
    $limitId = $this->wasted->addLimit('u1', 'L1');
    $this->wasted->setLimitConfig($limitId, 'minutes_day', '1');
    // 23-23 should result in zero time left.
    $this->wasted->setLimitConfig($limitId, 'times', '23-23');
    $this->wasted->setOverrideMinutes('u1', $this->dateString(), $limitId, 2);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);
  }

  public function testEffectiveLimitation(): void {
    $totalLimitId = $this->totalLimitId['u1'];
    // Nothing configured: zero time
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(0, 0, [], [])],
        true);

    // Configure minutes only.
    $this->wasted->setLimitConfig($totalLimitId, 'minutes_day', '2');
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(120, 120, [], [])],
        true);

    // Configure slots only.
    $this->wasted->clearLimitConfig($totalLimitId, 'minutes_day');
    $this->wasted->setLimitConfig($totalLimitId, 'times', '10-11');
    $now = $this->newDateTime()->setTime(10, 59);
    $this->mockTime = $now->getTimestamp();
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(60, 60, self::slot($now, 10, 0, 11, 0), [])],
        true);

    // Add an upcoming slot.
    $this->wasted->setLimitConfig(
        $totalLimitId, 'times', '10-11, 12-13');
    $this->mockTime = $now->getTimestamp();
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(
            60, 61 * 60, self::slot($now, 10, 0, 11, 0), self::slot($now, 12, 0, 13, 0))],
        true);

    // Configure both minutes and slots, total is limited by minutes.
    $this->wasted->setLimitConfig($totalLimitId, 'minutes_day', '3');
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(
            60, 180, self::slot($now, 10, 0, 11, 0), self::slot($now, 12, 0, 13, 0))],
        true);

    // Configure both minutes and slots, total is limited by slots.
    $this->wasted->setLimitConfig($totalLimitId, 'minutes_day', '99');
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(
            60, 61 * 60, self::slot($now, 10, 0, 11, 0), self::slot($now, 12, 0, 13, 0))],
        true);
  }

  public function testTimesAndMinutesOverrides(): void {
    $now = $this->newDateTime();
    $this->mockTime = $now->setTime(9, 0)->getTimestamp();
    $limitId = $this->totalLimitId['u1'];
    $dow = strtolower($now->format('D'));
    // default, day-of-week, override
    $slots = [
        self::slot($now, 10, 0, 11, 0),
        self::slot($now, 11, 0, 12, 0),
        self::slot($now, 12, 0, 13, 0)];
    $minutes = [1, 2, 3];
    // pattern: override day-of-week default
    // value is index in $slots/$minutes
    $cases = [
        -1, // 000
        0, // 001
        1, // 010
        1, // 011
        2, // 100
        2, // 101
        2, // 110
        2, // 111
    ];

    // Test slots.
    for ($i = 0; $i < count($cases); $i++) {
      $this->onFailMessage("slots, case: $i");
      if ($i & 1) {
        $this->wasted->setLimitConfig($limitId, 'times', '10-11');
      }
      if ($i & 2) {
        $this->wasted->setLimitConfig($limitId, "times_$dow", '11-12');
      }
      if ($i & 4) {
        $this->wasted->setOverrideSlots('u1', $this->dateString(), $limitId, '12-13');
      }
      $timeLeft = $this->wasted->queryTimeLeftTodayAllLimits('u1')[$limitId];
      if ($cases[$i] >= 0) {
        $this->assertEquals($timeLeft->nextSlot, $slots[$cases[$i]]);
      } else {
        $this->assertEquals($timeLeft->nextSlot, []);
      }
      $this->wasted->clearLimitConfig($limitId, 'times');
      $this->wasted->clearLimitConfig($limitId, "times_$dow");
      $this->wasted->clearOverrides('u1', $this->dateString(), $limitId);
    }

    // Test minutes.
    for ($i = 0; $i < count($cases); $i++) {
      $this->onFailMessage("minutes, case: $i");
      if ($i & 1) {
        $this->wasted->setLimitConfig($limitId, 'minutes_day', '1');
      }
      if ($i & 2) {
        $this->wasted->setLimitConfig($limitId, "minutes_$dow", '2');
      }
      if ($i & 4) {
        $this->wasted->setOverrideMinutes('u1', $this->dateString(), $limitId, '3');
      }
      $timeLeft = $this->wasted->queryTimeLeftTodayAllLimits('u1')[$limitId];
      if ($cases[$i] >= 0) {
        $this->assertEquals($timeLeft->currentSeconds, 60 * $minutes[$cases[$i]]);
      } else {
        $this->assertEquals($timeLeft->currentSeconds, 0);
      }
      $this->wasted->clearLimitConfig($limitId, 'minutes_day');
      $this->wasted->clearLimitConfig($limitId, "minutes_$dow");
      $this->wasted->clearOverrides('u1', $this->dateString(), $limitId);
    }
  }

  public function testTotalTimeLeft(): void {
    $now = $this->newDateTime();
    $this->mockTime = $now->setTime(9, 0)->getTimestamp();
    $limitId = $this->totalLimitId['u1'];
    // restore default cleared in setup
    $this->wasted->setLimitConfig($limitId, 'minutes_day', '1440');

    $seconds = (24 - 9) * 60 * 60;
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, [], []));

    $this->wasted->setLimitConfig($limitId, 'minutes_day', '42');
    $seconds = 42 * 60;
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, [], []));

    $this->wasted->setLimitConfig($limitId, 'minutes_week', '41');
    $seconds = 41 * 60;
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, [], []));

    $this->wasted->setLimitConfig($limitId, 'times', '8-9:40');
    $seconds = 40 * 60;
    $this->assertEquals(
        $this->wasted->queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, self::slot($now, 8, 0, 9, 40), []));
  }

  // TODO: Test other recent changes.
  // TODO: Test invalid slot spec handling.
  // TODO: Test that the locked flag is returned and minutes are set correctly in that case.

  // TODO: Special case slot config -> validate.

}

(new WastedTest())->run();
// TODO: Consider writing a test case that follows a representative sequence of events.
