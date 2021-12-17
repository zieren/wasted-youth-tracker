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

  protected $totalLimitId = [];

  protected function setUpTestCase(): void {
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    Wasted::initializeForTest(TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS);
    // Delete all users. Tests may create users and crash without cleaning them up.
    $users = Wasted::getUsers();
    foreach ($users as $user) {
      Wasted::removeUser($user);
    }
    // Create default users. Also track total limits per user; many tests need this.
    $this->totalLimitId['u1'] = Wasted::addUser('u1');
    $this->totalLimitId['u2'] = Wasted::addUser('u2');

    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    $this->setErrorHandler();
    // TODO: Consider checking for errors (error_get_last() and DB error) in production code.
    // Errors often go unnoticed.
  }

  protected function setUp(): void {
    parent::setUp();
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::WARNING);
    foreach (Wasted::getUsers() as $user) {
      if ($user != 'u1' && $user != 'u2') {
        Wasted::removeUser($user);
      }
    }
    Wasted::clearForTest();
    Logger::Instance()->setLogLevelThreshold(\Psr\Log\LogLevel::DEBUG);
    Wasted::$now->setTimestamp(1000);
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

  private static function day(): string {
    return getDateString(Wasted::$now);
  }

  private function total($seconds, $user = 'u1'): array {
    return [$this->totalLimitId[$user] => [self::day() => $seconds]];
  }

  private function limit($limitId, $seconds, $user = 'u1'): array {
    return [
        $this->totalLimitId[$user] => [self::day() => $seconds],
        $limitId => [self::day() => $seconds]];
  }

  private function insertActivity($user, $titles): array {
    return Wasted::insertActivity($user, '', $titles);
  }

  private static function slot($fromHour, $fromMinute, $toHour, $toMinute): array {
    $d = clone Wasted::$now;
    return [
        $d->setTime($fromHour, $fromMinute)->getTimestamp(),
        $d->setTime($toHour, $toMinute)->getTimestamp()];
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
        Wasted::queryTimeLeftTodayAllLimits('u1'));
  }

  public function testSmokeTest(): void {
    Wasted::getGlobalConfig();
  }

  public function testTotalTime_SingleWindow_NoLimit(): void {
    $fromTime = clone Wasted::$now;

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        []);

    // A single record amounts to zero.
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(0));

    self::advanceTime(5);
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(5));

    self::advanceTime(6);
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(11));

    // Switch window (no effect, same limit).
    self::advanceTime(7);
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(18));
  }

  public function testTotalTime_singleObservation(): void {
    $fromTime = clone Wasted::$now;
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(0));
    self::advanceTime(5);
    $this->insertActivity('u1', []);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(5));
  }

  public function testTotalTime_TwoWindows_NoLimit(): void {
    $fromTime = clone Wasted::$now;

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $m0 = Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime);
    $this->assertEquals($m0, []);

    // A single record amounts to zero.
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(0));

    // Advance 5 seconds. Still two windows, but same limit, so total time is 5 seconds.
    self::advanceTime(5);
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(5));

    // Same with another 6 seconds.
    self::advanceTime(6);
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(11));

    // Switch to 'window 2'.
    self::advanceTime(7);
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(18));

    self::advanceTime(8);
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(26));
  }

  public function testSetUpLimits(): void {
    $limitId1 = Wasted::addLimit('u1', 'b1');
    $limitId2 = Wasted::addLimit('u1', 'b2');
    $this->assertEquals($limitId2 - $limitId1, 1);

    $classId1 = Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    $this->assertEquals($classId2 - $classId1, 1);

    $classificationId1 = Wasted::addClassification($classId1, 0, '1$');
    $classificationId2 = Wasted::addClassification($classId2, 10, '2$');
    $this->assertEquals($classificationId2 - $classificationId1, 1);

    Wasted::addMapping($classId1, $limitId1);

    // Class 1 mapped to limit 1. All classes mapped to total limit.
    $classification = Wasted::classify('u1', ['window 0', 'window 1', 'window 2']);
    $totalLimitId = $this->totalLimitId['u1'];
    $this->assertEquals($classification, [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId1, [$totalLimitId, $limitId1]),
        self::classification($classId2, [$totalLimitId]),
    ]);

    // Add a second mapping for the same class.
    Wasted::addMapping($classId1, $limitId2);

    // Class 1 is now mapped to limits 1 and 2.
    $classification = Wasted::classify('u1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId1, [$totalLimitId, $limitId1, $limitId2]),
        self::classification($classId2, [$totalLimitId]),
    ]);

    // Add a mapping for the default class.
    Wasted::addMapping(DEFAULT_CLASS_ID, $limitId2);

    // Default class is now mapped to limit 2.
    $classification = Wasted::classify('u1', ['window 0', 'window 1', 'window 2']);
    $this->assertEquals($classification, [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId, $limitId2]),
        self::classification($classId1, [$totalLimitId, $limitId1, $limitId2]),
        self::classification($classId2, [$totalLimitId]),
    ]);

    // Remove mapping of c1 to limit 1.
    $this->assertEquals(Wasted::classify('u1', ['window 1']), [
        self::classification($classId1, [$totalLimitId, $limitId1, $limitId2]),
    ]);
    Wasted::removeMapping($classId1, $limitId1);
    $this->assertEquals(Wasted::classify('u1', ['window 1']), [
        self::classification($classId1, [$totalLimitId, $limitId2]),
    ]);
  }

  public function testTotalTime_SingleWindow_WithLimits(): void {
    // Set up test limits.
    $limitId1 = Wasted::addLimit('u1', 'b1');
    $limitId2 = Wasted::addLimit('u1', 'b2');
    $classId1 = Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    Wasted::addClassification($classId1, 0, '1$');
    Wasted::addClassification($classId2, 10, '2$');
    // b1 <= default, c1
    // b2 <= c2
    Wasted::addMapping(DEFAULT_CLASS_ID, $limitId1);
    Wasted::addMapping($classId1, $limitId1);
    Wasted::addMapping($classId2, $limitId2);

    $fromTime = clone Wasted::$now;

    // No records amount to an empty array. This is different from having records that amount to
    // zero, which makes sense.
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        []);

    // A single record amounts to zero.
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        self::limit($limitId1, 0));

    self::advanceTime(5);
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        self::limit($limitId1, 5));

    self::advanceTime(6);
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        self::limit($limitId1, 11));

    // Switch window. First interval still counts towards previous window/limit.
    self::advanceTime(7);
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        [
            $this->totalLimitId['u1'] => [self::day() => 18],
            $limitId1 => [self::day() => 18],
            $limitId2 => [self::day() => 0]
    ]);
  }

  public function testTotalTime_TwoWindows_WithLimits(): void {
    // Set up test limits.
    $limitId1 = Wasted::addLimit('u1', 'b1');
    $limitId2 = Wasted::addLimit('u1', 'b2');
    $limitId3 = Wasted::addLimit('u1', 'b3');
    $classId1 = Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    $classId3 = Wasted::addClass('c3');
    Wasted::addClassification($classId1, 0, '1$');
    Wasted::addClassification($classId2, 10, '2$');
    Wasted::addClassification($classId3, 20, '3$');
    // b1 <= default, c1
    // b2 <= c2
    // b3 <= c2, c3
    Wasted::addMapping(DEFAULT_CLASS_ID, $limitId1);
    Wasted::addMapping($classId1, $limitId1);
    Wasted::addMapping($classId2, $limitId2);
    Wasted::addMapping($classId2, $limitId3);
    Wasted::addMapping($classId3, $limitId3);

    $fromTime = clone Wasted::$now;

    // No records amount to an empty array.
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        []);

    // Start with a single window. Will not return anything for unused limits.
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->limit($limitId1, 0));

    // Advance 5 seconds and observe second window.
    self::advanceTime(5);
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $totalLimitId = $this->totalLimitId['u1'];
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [self::day() => 5],
        $limitId1 => [self::day() => 5],
        $limitId2 => [self::day() => 0],
        $limitId3 => [self::day() => 0]]);

    // Observe both again after 5 seconds.
    self::advanceTime(5);
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [self::day() => 10],
        $limitId1 => [self::day() => 10],
        $limitId2 => [self::day() => 5],
        $limitId3 => [self::day() => 5]]);

    // Advance 5 seconds and observe 'window 1' only.
    self::advanceTime(5);
    $this->insertActivity('u1', ['window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [self::day() => 15],
        $limitId1 => [self::day() => 15],
        $limitId2 => [self::day() => 10],
        $limitId3 => [self::day() => 10]]);

    // Add 6 seconds and start two windows of class 1.
    self::advanceTime(6);
    $this->insertActivity('u1', ['window 1', 'another window 1']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [self::day() => 21],
        $limitId1 => [self::day() => 21],
        $limitId2 => [self::day() => 10],
        $limitId3 => [self::day() => 10]]);

    // Add 7 seconds and observe both windows of class 1 again.
    self::advanceTime(7);
    $this->insertActivity('u1', ['window 1', 'window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [self::day() => 28],
        $limitId1 => [self::day() => 28],
        $limitId2 => [self::day() => 10],
        $limitId3 => [self::day() => 10]]);

    // Add 8 seconds and observe 'window 2'.
    self::advanceTime(8);
    $this->insertActivity('u1', ['window 2']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [self::day() => 36],
        $limitId1 => [self::day() => 36],
        $limitId2 => [self::day() => 18],
        $limitId3 => [self::day() => 18]]);
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
        $classId1 = Wasted::addClass($class1);
        $classId2 = Wasted::addClass($class2);
        Wasted::addClassification($classId1, 0, '1$');
        Wasted::addClassification($classId2, 10, '2$');
      }

      $fromTime = clone Wasted::$now;
      $toTime = (clone $fromTime)->add(days(1));

      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime),
          []);

      $this->insertActivity('u1', ['window 1']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime),
          [[self::dateTimeString(), 0, $class1, 'window 1']]);

      self::advanceTime(5);
      $this->insertActivity('u1', ['window 1']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime),
          [[self::dateTimeString(), 5, $class1, 'window 1']]);

      self::advanceTime(6);
      $this->insertActivity('u1', ['window 1']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime),
          [[self::dateTimeString(), 11, $class1, 'window 1']]);

      // Switch to different window.
      self::advanceTime(7);
      $dateTimeString1 = self::dateTimeString();
      $this->insertActivity('u1', ['window 2']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString1, 0, $class2, 'window 2']]);

      self::advanceTime(8);
      $dateTimeString2 = self::dateTimeString();
      $this->insertActivity('u1', ['window 2']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString2, 8, $class2, 'window 2'],
          [$dateTimeString1, 18, $class1, 'window 1']]);

      // End timestamp is exclusive.
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $fromTime),
          []);
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
        $classId1 = Wasted::addClass($class1);
        $classId2 = Wasted::addClass($class2);
        Wasted::addClassification($classId1, 0, '1$');
        Wasted::addClassification($classId2, 10, '2$');
      }

      $fromTime = clone Wasted::$now;
      $toTime = (clone $fromTime)->add(days(1));

      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime),
          []);

      $dateTimeString1 = self::dateTimeString();
      $this->insertActivity('u1', ['window 1', 'window 2']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString1, 0, $class1, 'window 1'],
          [$dateTimeString1, 0, $class2, 'window 2']]);

      self::advanceTime(5);
      $dateTimeString1 = self::dateTimeString();
      $this->insertActivity('u1', ['window 1', 'window 2']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString1, 5, $class1, 'window 1'],
          [$dateTimeString1, 5, $class2, 'window 2']]);

      self::advanceTime(6);
      $dateTimeString1 = self::dateTimeString();
      $this->insertActivity('u1', ['window 1', 'window 2']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString1, 11, $class1, 'window 1'],
          [$dateTimeString1, 11, $class2, 'window 2']]);

      // Switch to different windows.
      self::advanceTime(7);
      $dateTimeString1 = self::dateTimeString();
      $this->insertActivity('u1', ['window 11', 'window 2']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString1, 18, $class1, 'window 1'],
          [$dateTimeString1, 18, $class2, 'window 2'],
          [$dateTimeString1, 0, $class1, 'window 11']]);

      self::advanceTime(8);
      $dateTimeString2 = self::dateTimeString();
      $this->insertActivity('u1', ['window 2']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString2, 26, $class2, 'window 2'],
          [$dateTimeString2, 8, $class1, 'window 11'],
          [$dateTimeString1, 18, $class1, 'window 1']]);

      // Switch to window 1.
      self::advanceTime(1);
      $dateTimeString3 = self::dateTimeString();
      $this->insertActivity('u1', ['window 1']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString3, 27, $class2, 'window 2'],
          [$dateTimeString3, 18, $class1, 'window 1'],
          [$dateTimeString2, 8, $class1, 'window 11']]);

      // Order by time spent.
      self::advanceTime(20);
      $dateTimeString4 = self::dateTimeString();
      $this->insertActivity('u1', ['window 42']);
      $this->assertEquals(
          Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
          [$dateTimeString4, 38, $class1, 'window 1'],
          [$dateTimeString4, 0, $class2, 'window 42'],
          [$dateTimeString3, 27, $class2, 'window 2'],
          [$dateTimeString2, 8, $class1, 'window 11']]);
    }
  }

  public function testReplaceEmptyTitle(): void {
    $fromTime = clone Wasted::$now;
    $toTime = (clone $fromTime)->add(days(1));
    $this->insertActivity('u1', ['window 1']);
    self::advanceTime(5);
    $this->insertActivity('u1', ['window 1']);
    self::advanceTime(5);
    $this->insertActivity('u1', ['']);
    $window1LastSeen = self::dateTimeString();
    self::advanceTime(5);
    $this->insertActivity('u1', ['']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(15));

    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
        [self::dateTimeString(), 5, DEFAULT_CLASS_NAME, '(no title)'],
        [$window1LastSeen, 10, DEFAULT_CLASS_NAME, 'window 1']]);
  }

  public function testWeeklyLimit(): void {
    $limitId = Wasted::addLimit('u1', 'b');
    Wasted::addMapping(DEFAULT_CLASS_ID, $limitId);
    $totalLimitId = $this->totalLimitId['u1'];

    // Limits default to zero.
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    // Daily limit is 42 minutes.
    Wasted::setLimitConfig($limitId, 'minutes_day', 42);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);

    // The weekly limit cannot extend the daily limit.
    Wasted::setLimitConfig($limitId, 'minutes_week', 666);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);

    // The weekly limit can shorten the daily limit.
    Wasted::setLimitConfig($limitId, 'minutes_week', 5);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 5 * 60]);

    // The weekly limit can also be zero.
    Wasted::setLimitConfig($limitId, 'minutes_week', 0);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    // Overrides have priority over the weekly limit.
    Wasted::setOverrideMinutes('u1', self::day(), $limitId, 123);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 123 * 60]);
    Wasted::clearOverrides('u1', self::day(), $limitId);

    // Clear the limit.
    Wasted::clearLimitConfig($limitId, 'minutes_week');
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);
  }

  public function testGetAllLimitConfigs(): void {
    $allLimitConfigs =
        [$this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true]];
    // No limits configured except the total.
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);

    $limitId = Wasted::addLimit('u1', 'b');

    // A mapping or config is not required for the limit to be returned for the user.
    $allLimitConfigs[$limitId] = ['name' => 'b', 'is_total' => false];
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);

    // Add mapping, doesn't change result.
    Wasted::addMapping(DEFAULT_CLASS_ID, $limitId);
    $this->assertEqualsIgnoreOrder(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);

    // Add a config.
    Wasted::setLimitConfig($limitId, 'foo', 'bar');
    $allLimitConfigs[$limitId]['foo'] = 'bar';
    $this->assertEqualsIgnoreOrder(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);
  }

  public function testManageLimits(): void {
    $allLimitConfigs =
        [$this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true]];
    // No limits set up except the total.
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);
    // Add a limit but no maping yet.
    $limitId1 = Wasted::addLimit('u1', 'b1');
    // Not returned when user does not match.
    $this->assertEquals(Wasted::getAllLimitConfigs('nobody'), []);
    // Returned when user matches.
    $allLimitConfigs[$limitId1] = ['name' => 'b1', 'is_total' => false];
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);

    // Add a mapping.
    $this->assertEquals(
        Wasted::classify('u1', ['foo']), [
        self::classification(DEFAULT_CLASS_ID, [$this->totalLimitId['u1']])
    ]);
    Wasted::addMapping(DEFAULT_CLASS_ID, $limitId1);
    $this->assertEquals(
        Wasted::classify('u1', ['foo']), [
        self::classification(DEFAULT_CLASS_ID, [$this->totalLimitId['u1'], $limitId1])
    ]);

    // Same behavior:
    // Not returned when user does not match.
    $this->assertEquals(Wasted::getAllLimitConfigs('nobody'), []);
    // Returned when user matches.
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);

    // Add limit config.
    Wasted::setLimitConfig($limitId1, 'foo', 'bar');
    $allLimitConfigs[$limitId1]['foo'] = 'bar';
    $this->assertEqualsIgnoreOrder(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);

    // Remove limit, this cascades to mappings and config.
    Wasted::removeLimit($limitId1);
    unset($allLimitConfigs[$limitId1]);
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);
    $this->assertEquals(
        Wasted::classify('u1', ['foo']), [
        self::classification(DEFAULT_CLASS_ID, [$this->totalLimitId['u1']])
    ]);
  }

  public function testTimeLeftTodayAllLimits_negative(): void {
    $this->insertActivity('u1', ['window 1']);
    self::advanceTime(5);
    $this->insertActivity('u1', ['window 1']);
    self::advanceTime(5);
    $this->insertActivity('u1', []);

    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$this->totalLimitId['u1'] => -10]);
  }

  public function testClassificationWithLimit_multipleUsers(): void {
    $this->assertEquals(
        Wasted::classify('u1', ['title 1']),
        [self::classification(DEFAULT_CLASS_ID, [$this->totalLimitId['u1']])]);

    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 42, '1$');
    $limitId = Wasted::addLimit('u2', 'b1');
    Wasted::addMapping($classId, $limitId);

    // Only the total limit is mapped for user u1. The window is classified and associated with the
    // total limit.

    $this->assertEquals(
        Wasted::classify('u1', ['title 1']),
        [self::classification($classId, [$this->totalLimitId['u1']])]);
  }

  public function testTimeLeftTodayAllLimits_consumeTimeAndClassify(): void {
    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 42, '1$');
    $limitId1 = Wasted::addLimit('u1', 'b1');
    $totalLimitId = $this->totalLimitId['u1'];

    // Limits are listed even when no mapping is present.
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId1 => 0]);

    // Add mapping.
    Wasted::addMapping($classId, $limitId1);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId1 => 0]);

    // Provide 2 minutes.
    Wasted::setLimitConfig($limitId1, 'minutes_day', 2);
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

    self::advanceTime(15);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 1']),
        [$classification1]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -15, $limitId1 => 105]);

    // Add a window that maps to no limit.
    self::advanceTime(15);
    $classification2 = self::classification(DEFAULT_CLASS_ID, [$totalLimitId]);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -30, $limitId1 => 90]);
    self::advanceTime(15);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -45, $limitId1 => 75]);

    // Add a second limit for title 1 with only 1 minute.
    $limitId2 = Wasted::addLimit('u1', 'b2');
    Wasted::addMapping($classId, $limitId2);
    Wasted::setLimitConfig($limitId2, 'minutes_day', 1);
    self::advanceTime(1);
    $classification1['limits'][] = $limitId2;
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 1', 'title 2']),
        [$classification1, $classification2]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -46, $limitId1 => 74, $limitId2 => 14]);
  }

  public function testInsertClassification(): void {
    $classId1 = Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    Wasted::addClassification($classId1, 0, '1$');
    Wasted::addClassification($classId2, 10, '2$');
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
    $fromTime = clone Wasted::$now;
    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 42, '1$');
    $limitId = Wasted::addLimit('u1', 'b1');
    Wasted::addMapping($classId, $limitId);
    $totalLimitId = $this->totalLimitId['u1'];

    $this->assertEquals(
        $this->insertActivity('u1', ['window 1']), [
        self::classification($classId, [$totalLimitId, $limitId])]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    self::advanceTime(1);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['window 1']), [
        self::classification($classId, [$totalLimitId, $limitId])]);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => -1, $limitId => -1]);

    // All windows closed. Bill time to last window.
    self::advanceTime(1);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', []),
        []);

    // Used 2 seconds.
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$totalLimitId => [self::day() => 2], $limitId => [self::day() => 2]]);

    // Time advances.
    self::advanceTime(1);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', []),
        []);

    // Still only used 2 seconds because nothing was open.
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$totalLimitId => [self::day() => 2], $limitId => [self::day() => 2]]);
  }

  public function testTimeSpent_handleNoWindows(): void {
    $fromTime = clone Wasted::$now;
      $toTime = (clone $fromTime)->add(days(1));
    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 0, '1$');

    $this->insertActivity('u1', ['window 1']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['window 1']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['window 1']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['window 1', 'window 2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['window 3']);
    $lastSeenWindow1 = self::dateTimeString();
    self::advanceTime(1);
    $this->insertActivity('u1', ['window 3']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['window 3']);
    self::advanceTime(1);
    $this->insertActivity('u1', []);
    $lastSeenWindow3 = self::dateTimeString();
    self::advanceTime(15);
    $this->insertActivity('u1', []);
    self::advanceTime(1);
    $this->insertActivity('u1', ['window 2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['window 2']);
    $lastSeenWindow2 = self::dateTimeString();

    // "No windows" events are handled correctly for both listing titles and computing time spent.
    $this->assertEquals(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
        [$lastSeenWindow2, 2, DEFAULT_CLASS_NAME, 'window 2'],
        [$lastSeenWindow3, 3, DEFAULT_CLASS_NAME, 'window 3'],
        [$lastSeenWindow1, 4, 'c1', 'window 1']]);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$this->totalLimitId['u1'] => [self::day() => 8]]);
  }

  public function testCaseHandling(): void {
    $fromTime = clone Wasted::$now;
      $toTime = (clone $fromTime)->add(days(1));
    // First title capitalization persists.
    $this->insertActivity('u1', ['TITLE']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['Title']);
    $this->assertEquals(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
        [self::dateTimeString(), 1, DEFAULT_CLASS_NAME, 'TITLE']]);
  }

  public function testDuplicateTitle(): void {
    $fromTime = clone Wasted::$now;
      $toTime = (clone $fromTime)->add(days(1));
    $this->insertActivity('u1', ['cALCULATOR', 'Calculator']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['Calculator', 'Calculator']);
    $lastSeen = self::dateTimeString();
    $timeSpentByTitle = Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime);
    // We can pick any of the matching titles.
    $this->assertEquals(
        true,
        $timeSpentByTitle[0][3] == 'Calculator' || $timeSpentByTitle[0][3] == 'cALCULATOR');
    unset($timeSpentByTitle[0][3]);
    $this->assertEquals(
        $timeSpentByTitle, [[$lastSeen, 1, DEFAULT_CLASS_NAME]]);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(1));
  }

  public function testHandleRequest_invalidRequests(): void {
    foreach (['', "\n123"] as $content) {
      http_response_code(200);
      $this->onFailMessage("content: $content");
      $this->assertEquals(RX::handleRequest($content), '');
      $this->assertEquals(http_response_code(), 400);
    }
  }

  public function testHandleRequest_smokeTest(): void {
    $totalLimitId = $this->totalLimitId['u1'];
    $this->assertEquals(
        RX::handleRequest("u1\n"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 1"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n\n$totalLimitId");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 1"),
        "$totalLimitId;0;-1;-1;;;".TOTAL_LIMIT_NAME."\n\n$totalLimitId");
  }

  public function testHandleRequest_withLimits(): void {
    $classId1 = Wasted::addClass('c1');
    Wasted::addClassification($classId1, 0, '1$');
    $limitId1 = Wasted::addLimit('u1', 'b1');
    Wasted::addMapping($classId1, $limitId1);
    Wasted::setLimitConfig($limitId1, 'minutes_day', 5);
    $totalId = $this->totalLimitId['u1'];
    $totalName = TOTAL_LIMIT_NAME;

    $this->assertEquals(
        RX::handleRequest("u1\n"), // no titles
        "$totalId;0;0;0;;;$totalName\n$limitId1;0;300;300;;;b1\n");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 1"),
        "$totalId;0;0;0;;;$totalName\n$limitId1;0;300;300;;;b1\n\n$totalId,$limitId1");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 1"),
        "$totalId;0;-1;-1;;;$totalName\n$limitId1;0;299;299;;;b1\n\n$totalId,$limitId1");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 1\nfoo"),
        "$totalId;0;-2;-2;;;$totalName\n$limitId1;0;298;298;;;b1\n\n$totalId,$limitId1\n$totalId");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 1\nfoo"),
        "$totalId;0;-3;-3;;;$totalName\n$limitId1;0;297;297;;;b1\n\n$totalId,$limitId1\n$totalId");

    // Flip order.
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\nfoo\ntitle 1"),
        "$totalId;0;-4;-4;;;$totalName\n$limitId1;0;296;296;;;b1\n\n$totalId\n$totalId,$limitId1");

    // Add second limit.
    $classId2 = Wasted::addClass('c2');
    Wasted::addClassification($classId2, 10, '2$');
    $limitId2 = Wasted::addLimit('u1', 'b2');
    Wasted::addMapping($classId1, $limitId2);
    Wasted::addMapping($classId2, $limitId2);
    Wasted::setLimitConfig($limitId2, 'minutes_day', 2);

    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 1\nfoo"),
        "$totalId;0;-5;-5;;;$totalName\n$limitId1;0;295;295;;;b1\n$limitId2;0;115;115;;;b2\n\n".
        "$totalId,$limitId1,$limitId2\n$totalId");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 1\ntitle 2"),
        "$totalId;0;-6;-6;;;$totalName\n$limitId1;0;294;294;;;b1\n$limitId2;0;114;114;;;b2\n\n".
        "$totalId,$limitId1,$limitId2\n$totalId,$limitId2");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 2"),
        "$totalId;0;-7;-7;;;$totalName\n$limitId1;0;293;293;;;b1\n$limitId2;0;113;113;;;b2\n\n$totalId,$limitId2");
    self::advanceTime(1); // This still counts towards b2.
    $this->assertEquals(
        RX::handleRequest("u1\n"),
        "$totalId;0;-8;-8;;;$totalName\n$limitId1;0;293;293;;;b1\n$limitId2;0;112;112;;;b2\n");
    self::advanceTime(1); // Last request had no titles, so no time is added.
    $this->assertEquals(
        RX::handleRequest("u1\n\ntitle 2"),
        "$totalId;0;-8;-8;;;$totalName\n$limitId1;0;293;293;;;b1\n$limitId2;0;112;112;;;b2\n\n$totalId,$limitId2");
  }

  public function testHandleRequest_mappedForOtherUser(): void {
    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 0, '1$');
    $limitId1 = Wasted::addLimit('u1', 'b1');
    Wasted::addMapping($classId, $limitId1);
    Wasted::setLimitConfig($limitId1, 'minutes_day', 1);
    $totalLimitId = $this->totalLimitId['u2'];

    $this->assertEquals(
        RX::handleRequest("u2\n"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u2\n\ntitle 1"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n\n$totalLimitId");
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u2\n\ntitle 1"),
        "$totalLimitId;0;-1;-1;;;".TOTAL_LIMIT_NAME."\n\n$totalLimitId");

    // Now map same class for user u2.
    $limitId2 = Wasted::addLimit('u2', 'b2');
    Wasted::setLimitConfig($limitId2, 'minutes_day', 1);
    Wasted::addMapping($classId, $limitId2);
    self::advanceTime(1);
    $this->assertEquals(
        RX::handleRequest("u2\n\ntitle 1"),
        "$totalLimitId;0;-2;-2;;;".TOTAL_LIMIT_NAME."\n$limitId2;0;58;58;;;b2\n\n$totalLimitId,$limitId2");
  }

  public function testHandleRequest_utf8Conversion(): void {
    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 0, '^...$');
    $limitId = Wasted::addLimit('u1', 'b1');
    Wasted::addMapping($classId, $limitId);
    $totalLimitId = $this->totalLimitId['u1'];

    // This file uses utf8 encoding. The word 'süß' would not match the above RE in utf8 because
    // MySQL's RE library does not support utf8 and would see 5 bytes.
    $this->assertEquals(RX::handleRequest("u1\n\nsüß"),
        "$totalLimitId;0;0;0;;;".TOTAL_LIMIT_NAME."\n$limitId;0;0;0;;;b1\n\n$totalLimitId,$limitId");
  }

  public function testSetOverrideMinutesAndUnlock(): void {
    $limitId = Wasted::addLimit('u1', 'b1');
    Wasted::addMapping(DEFAULT_CLASS_ID, $limitId);
    Wasted::setLimitConfig($limitId, 'minutes_day', 42);
    Wasted::setLimitConfig($limitId, 'locked', 1);
    $totalLimitId = $this->totalLimitId['u1'];

    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    Wasted::setOverrideUnlock('u1', self::dateString(), $limitId);
    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);

    Wasted::setOverrideMinutes('u1', self::dateString(), $limitId, 666);
    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 666 * 60]);

    // Test updating.
    Wasted::setOverrideMinutes('u1', self::dateString(), $limitId, 123);
    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 123 * 60]);

    Wasted::clearOverrides('u1', self::dateString(), $limitId);

    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);

    Wasted::setOverrideUnlock('u1', self::dateString(), $limitId);
    $this->assertEqualsIgnoreOrder(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 42 * 60]);
  }

  public function testConcurrentRequestsAndChangedClassification(): void {
    $fromTime = clone Wasted::$now;
    $toTime = (clone $fromTime)->add(days(1));

    $classId1 = Wasted::addClass('c1');
    Wasted::addClassification($classId1, 0, '1$');
    $limitId1 = Wasted::addLimit('u1', 'b1');
    Wasted::addMapping($classId1, $limitId1);
    $totalLimitId = $this->totalLimitId['u1'];

    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2']),
        [self::classification(DEFAULT_CLASS_ID, [$totalLimitId])]);
    self::advanceTime(1);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2']),
        [self::classification(DEFAULT_CLASS_ID, [$totalLimitId])]);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime, $toTime),
        $this->total(1));

    // Repeating the last call is idempotent.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2']),
        [self::classification(DEFAULT_CLASS_ID, [$totalLimitId])]);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime, $toTime),
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
        $totalLimitId => [self::day() => 1],
        $limitId1 => [self::day() => 0]];
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime, $toTime),
        $timeSpent2and1);

    // Repeating the previous insertion is idempotent.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']),
        $classification2and1);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime, $toTime),
        $timeSpent2and1);

    // Changing the classification rules between concurrent requests causes the second activity
    // record to replace with the first (because class_id is not part of the PK).
    $classId2 = Wasted::addClass('c2');
    Wasted::addClassification($classId2, 10 /* higher priority */, '1$');
    $limitId2 = Wasted::addLimit('u1', 'b2');
    Wasted::addMapping($classId2, $limitId2);
    // Request returns the updated classification.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']), [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId2, [$totalLimitId, $limitId2])]); // changed to c2, which maps to b2
    // Records are updated.
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime, $toTime), [
        $totalLimitId => [self::day() => 1],
        $limitId2 => [self::day() => 0]]);

    // Accumulate time.
    self::advanceTime(1);
    // From now on we accumulate time with the new classification.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']), [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId2, [$totalLimitId, $limitId2])]);
    self::advanceTime(1);
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['title 2', 'title 1']), [
        self::classification(DEFAULT_CLASS_ID, [$totalLimitId]),
        self::classification($classId2, [$totalLimitId, $limitId2])]);

    // Check results.
    $this->assertEquals(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
        [self::dateTimeString(), 3, DEFAULT_CLASS_NAME, 'title 2'],
        [self::dateTimeString(), 2, 'c2', 'title 1']]);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime, $toTime), [
        $totalLimitId => [self::day() => 3],
        $limitId2 => [self::day() => 2]]);
  }

  function testUpdateClassification_noTimeElapsed(): void {
    // Classification is updated when a title is continued, but not after it is concluded.
    $fromTime = clone Wasted::$now;
    $this->insertActivity('u1', ['t1', 't2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1', 't2']);

    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 0, '1$');
    $limitId = Wasted::addLimit('u1', 'l1');
    Wasted::addMapping($classId, $limitId);

    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(1));

    // Time does not need to elapse.
    $this->assertEqualsIgnoreOrder(
        $this->insertActivity('u1', ['t1']), [
        self::classification($classId, [$this->totalLimitId['u1'], $limitId])]);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        // concluded record is not updated
        $this->limit($limitId, 1));
  }

  function testUpdateClassification_timeElapsed(): void {
    // Classification is updated when a title is continued, but not after it is concluded.
    $fromTime = clone Wasted::$now;
    $this->insertActivity('u1', ['t1', 't2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1', 't2']);

    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 0, '1$');
    $limitId = Wasted::addLimit('u1', 'l1');
    Wasted::addMapping($classId, $limitId);

    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(1));

    // Time may elapse.
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1']);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        // concluded record is not updated
        $this->totalLimitId['u1'] => [self::day() => 3],
        $limitId => [self::day() => 3]]);
  }

  function testUmlauts(): void {
    $classId = Wasted::addClass('c');
    // The single '.' should match the 'ä' umlaut. In utf8 this fails because the MySQL RegExp
    // library does not support utf8 and the character is encoded as two bytes.
    Wasted::addClassification($classId, 0, 't.st');
    // Word boundaries should support umlauts. Match any three letter word.
    Wasted::addClassification($classId, 0, '[[:<:]]...[[:>:]]');
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
    $limitId1 = Wasted::addLimit('u1', 'b1');
    $limitId2 = Wasted::addLimit('u2', 'b1');
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), [
        $this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true],
        $limitId1 => ['name' => 'b1', 'is_total' => false]]);
    $this->assertEquals(Wasted::getAllLimitConfigs('u2'), [
        $this->totalLimitId['u2'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true],
        $limitId2 => ['name' => 'b1', 'is_total' => false]]);
    $this->assertEquals(Wasted::getAllLimitConfigs('nobody'), []);
  }

  function testLimitWithUmlauts(): void {
    $limitName = 't' . chr(228) . 'st';
    $limitId = Wasted::addLimit('u1', $limitName);
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), [
        $this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true],
        $limitId => ['name' => $limitName, 'is_total' => false]]);
  }

  function testReclassify(): void {
    $fromTime = clone Wasted::$now;
    $date = self::dateString();
    $totalLimitId = $this->totalLimitId['u1'];

    $this->insertActivity('u1', ['w1', 'w2']);
    $this->insertActivity('u2', ['w1', 'w2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['w1', 'w2']);
    $this->insertActivity('u2', ['w1', 'w2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['w1', 'w2']);
    $this->insertActivity('u2', ['w1', 'w2']);

    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$totalLimitId => [$date => 2]]);

    Wasted::reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        [$totalLimitId => [$date => 2]]);

    // Add classification for w1.
    $classId1 = Wasted::addClass('c1');
    Wasted::addClassification($classId1, 0, '1$');
    $limitId1 = Wasted::addLimit('u1', 'b1');
    Wasted::addMapping($classId1, $limitId1);

    Wasted::reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 2],
        $limitId1 => [$date => 2]]);

    // Add classification for w2.
    $classId2 = Wasted::addClass('c2');
    Wasted::addClassification($classId2, 0, '2$');
    $limitId2 = Wasted::addLimit('u1', 'b2');
    Wasted::addMapping($classId2, $limitId2);

    Wasted::reclassify($fromTime);

    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 2],
        $limitId1 => [$date => 2],
        $limitId2 => [$date => 2]]);

    // Check u2 to ensure reclassification works across users.
    $limitId1_2 = Wasted::addLimit('u2', 'b1');
    $limitId2_2 = Wasted::addLimit('u2', 'b2');
    Wasted::addMapping($classId1, $limitId1_2);
    Wasted::addMapping($classId2, $limitId2_2);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u2', $fromTime), [
        $this->totalLimitId['u2'] => [$date => 2],
        $limitId1_2 => [$date => 2],
        $limitId2_2 => [$date => 2]]);

    // Attempt to mess with the "" placeholder title.
    self::advanceTime(1);
    $this->insertActivity('u1', []);
    self::advanceTime(1);
    $this->insertActivity('u1', []);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 3],
        $limitId1 => [$date => 3],
        $limitId2 => [$date => 3]]);
    Wasted::addClassification($classId1, 666, '()');
    Wasted::reclassify($fromTime);
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 3],
        $limitId1 => [$date => 3]]);

    // Reclassify only a subset. Note that above we have interrupted the flow via "".
    $this->insertActivity('u1', ['w3', 'w2']);
    $fromTime2 = clone Wasted::$now;
    $this->onFailMessage('fromTime2='.$fromTime2->getTimestamp());
    self::advanceTime(1);
    $this->insertActivity('u1', ['w3', 'w2']);
    Wasted::addClassification($classId2, 667, '()'); // match everything
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 4],
        $limitId1 => [$date => 4]]);

    Wasted::reclassify($fromTime2);
    $this->onFailMessage('fromTime2='.$fromTime2->getTimestamp());
    $this->assertEqualsIgnoreOrder(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $totalLimitId => [$date => 4],
        $limitId1 => [$date => 3],
        $limitId2 => [$date => 1]]);
  }

  public function testRemoveClass(): void {
    $classId = Wasted::addClass('c1');
    $limitId = Wasted::addLimit('u1', 'b1');
    Wasted::addMapping($classId, $limitId);
    $classificationId = Wasted::addClassification($classId, 42, '()');
    $totalLimitId = $this->totalLimitId['u1'];

    $this->assertEquals(
        Wasted::classify('u1', ['foo']),
        [['class_id' => $classId, 'limits' => [$totalLimitId, $limitId]]]);
    $this->assertEquals(
        Wasted::getAllClassifications(),
        [$classificationId => ['name' => 'c1', 're' => '()', 'priority' => 42]]);
    $numMappings = count(DB::query(
        'SELECT * FROM mappings WHERE limit_id NOT IN (SELECT total_limit_id FROM users)'));
    $this->assertEquals($numMappings, 1);

    Wasted::removeClass($classId);

    $this->assertEquals(
        Wasted::classify('u1', ['foo']),
        [['class_id' => DEFAULT_CLASS_ID, 'limits' => [$totalLimitId]]]);
    $this->assertEquals(
        Wasted::getAllClassifications(),
        []);
    $numMappings = count(DB::query(
        'SELECT * FROM mappings WHERE limit_id NOT IN (SELECT total_limit_id FROM users)'));
    $this->assertEquals($numMappings, 0);
  }

  public function testRemoveClassReclassifies(): void {
    $classId1 = Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    $classificationId1 = Wasted::addClassification($classId1, 0, '1$');
    $classificationId2 = Wasted::addClassification($classId2, 0, '2$');
    $this->insertActivity('u1', ['t1']);

    $fromTime = clone Wasted::$now;
    $toTime = (clone $fromTime)->add(days(1));
    self::advanceTime(1);
    $fromTime1String = self::dateTimeString();
    $this->insertActivity('u1', ['t2']);
    self::advanceTime(1);
    $fromTime2String = self::dateTimeString();
    $this->insertActivity('u1', ['t3']);

    $this->assertEquals(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
        [$fromTime2String, 1, 'c2', 't2'],
        [$fromTime2String, 0, DEFAULT_CLASS_NAME, 't3'],
        [$fromTime1String, 1, 'c1', 't1']
    ]);
    $this->assertEquals(
        Wasted::getAllClassifications(), [
        $classificationId1 => ['name' => 'c1', 're' => '1$', 'priority' => 0],
        $classificationId2 => ['name' => 'c2', 're' => '2$', 'priority' => 0]
    ]);

    $classId3 = Wasted::addClass('c3');
    $classificationId3 = Wasted::addClassification($classId3, -42, '2$');
    Wasted::removeClass($classId2);

    $this->assertEquals(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime), [
        [$fromTime2String, 1, 'c3', 't2'],
        [$fromTime2String, 0, DEFAULT_CLASS_NAME, 't3'],
        [$fromTime1String, 1, 'c1', 't1']
    ]);
    $this->assertEquals(
        Wasted::getAllClassifications(), [
        $classificationId1 => ['name' => 'c1', 're' => '1$', 'priority' => 0],
        $classificationId3 => ['name' => 'c3', 're' => '2$', 'priority' => -42]
    ]);
  }

  public function testTotalLimit(): void {
    // Use a clean user to avoid interference with optimized test setup.
    $user = 'user'.strval(rand());
    $totalLimitId = Wasted::addUser($user);

    // Start with default class mapped to total limit. This mapping was inserted by the user setup
    // mapping all existing classes to the new user's total limit.
    $this->assertEquals(
        self::queryMappings($user), [
        self::mapping($totalLimitId, DEFAULT_CLASS_ID)
    ]);

    // Added classes are mapped to total limit by the trigger.
    $classId1 = Wasted::addClass('c1');
    $this->assertEquals(
        self::queryMappings($user), [
        self::mapping($totalLimitId, DEFAULT_CLASS_ID),
        self::mapping($totalLimitId, $classId1)
    ]);

    // And again.
    $classId2 = Wasted::addClass('c2');
    $this->assertEquals(
        self::queryMappings($user), [
        self::mapping($totalLimitId, DEFAULT_CLASS_ID),
        self::mapping($totalLimitId, $classId1),
        self::mapping($totalLimitId, $classId2)
    ]);

    // Adding a limit has not effect (yet).
    $limitId1 = Wasted::addLimit($user, 'b1');
    $this->assertEquals(
        self::queryMappings($user), [
        self::mapping($totalLimitId, DEFAULT_CLASS_ID),
        self::mapping($totalLimitId, $classId1),
        self::mapping($totalLimitId, $classId2)
    ]);

    // Mapping a class to the new limit shows up in the result. No change to the default limit's
    // mappings.
    Wasted::addMapping($classId1, $limitId1);
    $this->assertEquals(
        self::queryMappings($user), [
        self::mapping($totalLimitId, DEFAULT_CLASS_ID),
        self::mapping($totalLimitId, $classId1),
        self::mapping($totalLimitId, $classId2),
        self::mapping($limitId1, $classId1)
    ]);

    // Add more classes to exercise the trigger.
    $classId3 = Wasted::addClass('c3');
    $classId4 = Wasted::addClass('c4');
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
    Wasted::removeClass($classId2);
    $this->assertEquals(
        self::queryMappings($user), [
        self::mapping($totalLimitId, DEFAULT_CLASS_ID),
        self::mapping($totalLimitId, $classId1),
        self::mapping($totalLimitId, $classId3),
        self::mapping($totalLimitId, $classId4),
        self::mapping($limitId1, $classId1)
    ]);

    // Removing a mapping has no effect beyond that mapping.
    Wasted::removeMapping($classId1, $limitId1);
    $this->assertEquals(
        self::queryMappings($user), [
        self::mapping($totalLimitId, DEFAULT_CLASS_ID),
        self::mapping($totalLimitId, $classId1),
        self::mapping($totalLimitId, $classId3),
        self::mapping($totalLimitId, $classId4)
    ]);
  }

  public function testRemoveDefaultClass(): void {
    try {
      Wasted::removeClass(DEFAULT_CLASS_ID);
      throw new AssertionError('Should not be able to delete the default class');
    } catch (Exception $e) {
      // expected
    }
  }

  public function testRemoveDefaultClassification(): void {
    try {
      Wasted::removeClassification(DEFAULT_CLASSIFICATION_ID);
      throw new AssertionError('Should not be able to delete the default classification');
    } catch (Exception $e) {
      // expected
    }
  }

  public function testChangeDefaultClassification(): void {
    try {
      Wasted::changeClassification(DEFAULT_CLASSIFICATION_ID, 'foo', 42);
      throw new AssertionError('Should not be able to change the default classification');
    } catch (Exception $e) {
      // expected
    }
  }

  public function testRemoveTriggersWhenRemovingUser(): void {
    // Remember initial number of triggers.
    $n = count(DB::query('SHOW TRIGGERS'));
    $user = 'user'.strval(rand());
    Wasted::addUser($user);
    // We added one trigger...
    $this->assertEquals(count(DB::query('SHOW TRIGGERS')), $n + 1);
    Wasted::removeUser($user);
    // ... and removed it.
    $this->assertEquals(count(DB::query('SHOW TRIGGERS')), $n);
  }

  public function testRenameLimit(): void {
    $limitId = Wasted::addLimit('u1', 'b1');
    Wasted::renameLimit($limitId, 'b2');
    $this->assertEquals(
        Wasted::getAllLimitConfigs('u1'), [
        $this->totalLimitId['u1'] => ['name' => TOTAL_LIMIT_NAME, 'is_total' => true],
        $limitId => ['name' => 'b2', 'is_total' => false]]);
  }

  public function testRenameClass(): void {
    $fromTime = clone Wasted::$now;
    $toTime = (clone $fromTime)->add(days(1));
    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 0, '()');
    Wasted::renameClass($classId, 'c2');
    $this->insertActivity('u1', ['t']);
    $this->assertEquals(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime),
        [[self::dateTimeString(), 0, 'c2', 't']]);
  }

  public function testChangeClassificationAndReclassify(): void {
    $fromTime = clone Wasted::$now;
    $toTime = (clone $fromTime)->add(days(1));
    // Reclassification excludes the specified limit, so advance by 1s.
    self::advanceTime(1);
    $classId = Wasted::addClass('c1');
    $classificationId = Wasted::addClassification($classId, 0, 'nope');
    $this->assertEquals(
        Wasted::getAllClassifications(),
        [$classificationId => ['name' => 'c1', 're' => 'nope', 'priority' => 0]]);
    $this->insertActivity('u1', ['t']);
    $this->assertEquals(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime),
        [[self::dateTimeString(), 0, DEFAULT_CLASS_NAME, 't']]);
    Wasted::changeClassification($classificationId, '()', 42);
    Wasted::reclassify($fromTime);
    $this->assertEquals(
        Wasted::queryTimeSpentByTitle('u1', $fromTime, $toTime),
        [[self::dateTimeString(), 0, 'c1', 't']]);
    $this->assertEquals(
        Wasted::getAllClassifications(),
        [$classificationId => ['name' => 'c1', 're' => '()', 'priority' => 42]]);
  }

  public function testTopUnclassified(): void {
    $fromTime = clone Wasted::$now;
    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 0, '1$');
    $this->insertActivity('u1', ['t1']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1', 't2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1', 't2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t2', 't3']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t2', 't3']);
    self::advanceTime(1);
    $lastSeenT2 = self::dateTimeString();
    $this->insertActivity('u1', ['t3', 't4']);
    self::advanceTime(1);
    $lastSeenT3T4 = self::dateTimeString();
    $this->insertActivity('u1', ['t3', 't4']);

    $toTime = (clone $fromTime)->add(days(1));
    $this->assertEquals(
        Wasted::queryTopUnclassified('u1', $fromTime, $toTime, true, 2), [
        [4, 't2', $lastSeenT2],
        [3, 't3', $lastSeenT3T4]]);

    $this->assertEquals(
        Wasted::queryTopUnclassified('u1', $fromTime, $toTime, true, 3), [
        [4, 't2', $lastSeenT2],
        [3, 't3', $lastSeenT3T4],
        [1, 't4', $lastSeenT3T4]]);

    $this->assertEquals(
        Wasted::queryTopUnclassified('u1', $fromTime, $toTime, false, 3), [
        [3, 't3', $lastSeenT3T4],
        [1, 't4', $lastSeenT3T4],
        [4, 't2', $lastSeenT2]]);

    // Reduce end time.

    // Include t1 only, which is classified.
    $toTime = (clone $fromTime)->add(new DateInterval('PT1S'));
    $this->assertEquals(Wasted::queryTopUnclassified('u1', $fromTime, $toTime, true, 2), []);
    // t2 only starts at 3s because end timestamp is exclusive.
    $toTime->add(new DateInterval('PT1S'));
    $this->assertEquals(Wasted::queryTopUnclassified('u1', $fromTime, $toTime, true, 2), []);
    // Extend end time by another second, this captures t2.
    $toTime->add(new DateInterval('PT1S'));
    $this->assertEquals(Wasted::queryTopUnclassified('u1', $fromTime, $toTime, true, 2), [
        [1, 't2', $toTime->format('Y-m-d H:i:s')],
    ]);
  }

  public function testLimitsToClassesTable(): void {
    Wasted::addLimit('u1', 'b1');
    $limitId2 = Wasted::addLimit('u1', 'b2');
    $limitId3 = Wasted::addLimit('u1', 'b3');
    $limitId4 = Wasted::addLimit('u1', 'b4');
    $limitId5 = Wasted::addLimit('u1', 'b5');
    Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    $classId3 = Wasted::addClass('c3');
    $classId4 = Wasted::addClass('c4');
    Wasted::addMapping($classId2, $limitId2);
    Wasted::addMapping($classId3, $limitId2);
    Wasted::addMapping($classId3, $limitId3);
    Wasted::addMapping($classId3, $limitId4);
    Wasted::addMapping($classId4, $limitId4);
    Wasted::addMapping($classId4, $limitId5);
    Wasted::setLimitConfig($limitId2, 'foo', 'bar');
    Wasted::setLimitConfig($limitId3, 'a', 'b');
    Wasted::setLimitConfig($limitId3, 'c', 'd');

    $this->assertEquals(
        Wasted::getLimitsToClassesTable('u1'), [
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
    $fromTime = clone Wasted::$now;
    $toTime = (clone Wasted::$now)->add(days(1));
    $classId1 = Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    Wasted::addClassification($classId1, 42, '1');
    Wasted::addClassification($classId2, 43, '2');
    $this->insertActivity('u1', ['t1', 't2', 't3']);
    self::advanceTime(2);
    $this->insertActivity('u1', ['t1', 't2', 't3']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t22', 't3', 't4']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t22', 't3', 't4']);
    $this->assertEquals(
        Wasted::getClassesToClassificationTable('u1', $fromTime, $toTime), [
        ['c1', '1', 42, 1, 't1'],
        ['c2', '2', 43, 2, "t2\nt22"]
    ]);
    // Test that priority is considered: "t12" is not a sample for c1, but for c2.
    self::advanceTime(1);
    $classification = $this->insertActivity('u1', ['t12']);
    $this->assertEquals(
        $classification,
        [['class_id' => $classId2, 'limits' => [$this->totalLimitId['u1']]]]);
    $this->assertEquals(
        Wasted::getClassesToClassificationTable('u1', $fromTime, $toTime), [
        ['c1', '1', 42, 1, 't1'],
        ['c2', '2', 43, 3, "t12\nt2\nt22"]
    ]);
    // Test that samples are cropped to 1024 characters. Titles are VARCHAR(256).
    self::advanceTime(1);
    $one255 = str_repeat('1', 255);
    $titles = ['a' . $one255, 'b' . $one255, 'c' . $one255, 'd' . $one255, 'e' . $one255];
    $classification = $this->insertActivity('u1', $titles);
    $samples = substr(implode("\n", $titles), 0, 1021) . '...';
    $this->assertEquals(
        Wasted::getClassesToClassificationTable('u1', $fromTime, $toTime), [
        ['c1', '1', 42, 6, $samples],
        ['c2', '2', 43, 3, "t12\nt2\nt22"]
    ]);
  }

  public function testUserConfig(): void {
    $this->assertEquals([], Wasted::getUserConfig('u1'));
    Wasted::setUserConfig('u1', 'some key', 'some value');
    $this->assertEquals(['some key' => 'some value'], Wasted::getUserConfig('u1'));
    Wasted::setUserConfig('u1', 'foo', 'bar');
    $this->assertEqualsIgnoreOrder(
        ['some key' => 'some value', 'foo' => 'bar'], Wasted::getUserConfig('u1'));
    Wasted::clearUserConfig('u1', 'some key');
    $this->assertEquals(['foo' => 'bar'], Wasted::getUserConfig('u1'));
    $this->assertEquals([], Wasted::getUserConfig('u2'));
  }

  public function testGlobalConfig(): void {
    $this->assertEquals([], Wasted::getGlobalConfig());
    Wasted::setGlobalConfig('some key', 'some value');
    $this->assertEquals(['some key' => 'some value'], Wasted::getGlobalConfig());
    Wasted::setGlobalConfig('foo', 'bar');
    $this->assertEqualsIgnoreOrder(
        ['some key' => 'some value', 'foo' => 'bar'], Wasted::getGlobalConfig());
    Wasted::clearGlobalConfig('some key');
    $this->assertEquals(['foo' => 'bar'], Wasted::getGlobalConfig());
  }

  public function testClientConfig(): void {
    $this->assertEquals([], Wasted::getClientConfig('u1'));
    $this->assertEquals('', Config::handleRequest('u1'));

    Wasted::setGlobalConfig('key', 'global');
    Wasted::setUserConfig('u1', 'key2', 'user');
    Wasted::setUserConfig('u2', 'ignored', 'ignored');
    $this->assertEqualsIgnoreOrder(
        ['key' => 'global', 'key2' => 'user'], Wasted::getClientConfig('u1'));
    $this->assertEqualsIgnoreOrder(
        "key\nglobal\nkey2\nuser", Config::handleRequest('u1'));

    Wasted::setUserConfig('u1', 'key', 'user override');
    $this->assertEqualsIgnoreOrder(
        ['key' => 'user override', 'key2' => 'user'], Wasted::getClientConfig('u1'));
    $this->assertEqualsIgnoreOrder(
        "key\nuser override\nkey2\nuser",
        Config::handleRequest('u1'));
  }

  public function testClientConfig_sortAlphabetically(): void {
    Wasted::setUserConfig('u1', 'foo', 'bar');
    $this->assertEquals("foo\nbar", Config::handleRequest('u1'));

    Wasted::setUserConfig('u1', 'a', 'b');
    Wasted::setUserConfig('u1', 'y', 'z');
    $this->assertEquals("a\nb\nfoo\nbar\ny\nz", Config::handleRequest('u1'));
  }

  public function testQueryAvailableClasses(): void {
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'), []);

    // Empty when no time is configured.
    $limitId1 = Wasted::addLimit('u1', 'b1');
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'), []);

    // Empty when no class exists. (The default class is mapped to the total limit, which is
    // zeroed in the test setup.)
    Wasted::setLimitConfig($limitId1, 'minutes_day', 3);
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'), []);

    // Empty when class is not mapped to a nonzero limit.
    $classId1 = Wasted::addClass('c1');
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'), []);

    // Set the total limit to 10 minutes.
    Wasted::setLimitConfig($this->totalLimitId['u1'], 'minutes_day', 10);
    // This enables the default class, which will henceforth be present, and c1.
    $this->assertEquals(
        Wasted::queryClassesAvailableTodayTable('u1'),
        ['c1', DEFAULT_CLASS_NAME.' (0:10:00)']);

    // Map class c1 to limit 1.
    Wasted::addMapping($classId1, $limitId1);
    $this->assertEquals(
        Wasted::queryClassesAvailableTodayTable('u1'),
        [DEFAULT_CLASS_NAME.' (0:10:00)', 'c1 (0:03:00)']);

    // Remove the default class by mapping it to an otherwise ignored zero limit.
    Wasted::addMapping(DEFAULT_CLASS_ID, Wasted::addLimit('u1', 'l0'));

    // Add another limit that requires unlocking. No change for now.
    $limitId2 = Wasted::addLimit('u1', 'b2');
    Wasted::setLimitConfig($limitId2, 'minutes_day', 2);
    Wasted::setLimitConfig($limitId2, 'locked', 1);
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'), ['c1 (0:03:00)']);

    // Map c1 to the new (zero) limit too. This removes the class from the response.
    Wasted::addMapping($classId1, $limitId2);
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'), []);

    // Unlock the locked limit. It restricts the first limit.
    Wasted::setOverrideUnlock('u1', self::dateString(), $limitId2);
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'), ['c1 (0:02:00)']);

    // Allow time for two classes. Sort by time left.
    $classId2 = Wasted::addClass('c2');
    Wasted::addMapping($classId2, $limitId1);
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'),
        ['c2 (0:03:00)', 'c1 (0:02:00)']);

    // Group by time left.
    $classId3 = Wasted::addClass('c3');
    Wasted::addMapping($classId3, $limitId2);
    $this->assertEquals(Wasted::queryClassesAvailableTodayTable('u1'),
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
    $date = self::dateString();

    $classId1 = Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    $classId3 = Wasted::addClass('c3');
    $classId4 = Wasted::addClass('c4');
    $limitId1 = Wasted::addLimit('u1', 'b1');
    $limitId2 = Wasted::addLimit('u1', 'b2');
    $limitId3 = Wasted::addLimit('u1', 'b3');
    $limitId4 = Wasted::addLimit('u1', 'b4');
    // b1: c1, c2, c3
    // b2: c1, c2
    // b3: c3
    // b4: c4
    Wasted::addMapping($classId1, $limitId1);
    Wasted::addMapping($classId2, $limitId1);
    Wasted::addMapping($classId3, $limitId1);
    Wasted::addMapping($classId1, $limitId2);
    Wasted::addMapping($classId2, $limitId2);
    Wasted::addMapping($classId3, $limitId3);
    Wasted::addMapping($classId4, $limitId4);

    // Add an overlapping mapping for another user.
    $limitId5 = Wasted::addLimit('u2', 'b5');
    Wasted::addMapping($classId1, $limitId5);

    for ($i = 0; $i < 2; $i++) {
      // Query for time limitation only (i.e. no date).
      $this->assertEquals(Wasted::queryOverlappingLimits($limitId1), ['b2', 'b3']);
      $this->assertEquals(Wasted::queryOverlappingLimits($limitId2), ['b1']);
      $this->assertEquals(Wasted::queryOverlappingLimits($limitId3), ['b1']);
      $this->assertEquals(Wasted::queryOverlappingLimits($limitId4), []);

      // Initially require unlock for all. No effect on repeating the above time queries.
      Wasted::setLimitConfig($limitId1, 'locked', '1');
      Wasted::setLimitConfig($limitId2, 'locked', '1');
      Wasted::setLimitConfig($limitId3, 'locked', '1');
      Wasted::setLimitConfig($limitId4, 'locked', '1');
    }

    // Query for unlock limitation.
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId1, $date), ['b2', 'b3']);
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId2, $date), ['b1']);
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId3, $date), ['b1']);
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId4, $date), []);

    // b2 no longer needs unlocking.
    Wasted::setOverrideUnlock('u1', $date, $limitId2);
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId1, $date), ['b3']);

    // Consider date.
    $this->assertEquals(
        Wasted::queryOverlappingLimits($limitId1, '1974-09-29'), ['b2', 'b3']);

    // No more unlock required anywhere.
    Wasted::clearLimitConfig($limitId1, 'locked');
    Wasted::clearLimitConfig($limitId2, 'locked');
    Wasted::clearLimitConfig($limitId3, 'locked');
    Wasted::clearLimitConfig($limitId4, 'locked');

    // Query for unlock limitation.
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId1, $date), []);
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId2, $date), []);
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId3, $date), []);
    $this->assertEquals(Wasted::queryOverlappingLimits($limitId4, $date), []);
  }

  public function testPruneTables(): void {
    $fromTime = clone Wasted::$now;
    $classId = Wasted::addClass('c2');
    Wasted::addClassification($classId, 0, '2$');
    $limitId = Wasted::addLimit('u1', 'l2');
    Wasted::addMapping($classId, $limitId);

    $this->insertActivity('u1', ['t1', 't2']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1', 't2', 't3']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t2', 't3']);

    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->limit($limitId, 2));

    Wasted::pruneTablesAndLogs($fromTime);

    // No change.
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->limit($limitId, 2));

    $fromTime->add(new DateInterval('PT1S')); // 1 second
    Wasted::pruneTablesAndLogs($fromTime);

    // 1 second chopped off.
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
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
    $classId1 = Wasted::addClass('c1');
    $classId2 = Wasted::addClass('c2');
    Wasted::addClassification($classId1, 0, '1$');
    Wasted::addClassification($classId2, 0, '2$');
    $limitId1 = Wasted::addLimit('u1', 'l1');
    $limitId2 = Wasted::addLimit('u1', 'l2');
    Wasted::addMapping($classId1, $limitId1);
    Wasted::addMapping($classId2, $limitId2);

    Wasted::$now = new DateTime('1974-09-29 23:59:50');
    $fromTime = clone Wasted::$now;
    $this->insertActivity('u1', ['t1', 't3']);
    self::advanceTime(5); // 59:59:55
    $this->insertActivity('u1', ['t2', 't3']);
    self::advanceTime(16); // 00:00:11
    $this->insertActivity('u1', ['t1', 't2', 't3']);

    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
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
    $fromTs = Wasted::$now->getTimestamp();
    $this->insertActivity('u1', ['t1']);
    self::advanceTime(10);
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
          Wasted::queryTimeSpentByTitleInternal(
              'u1', $fromTs + $t[0], $fromTs + $t[1], false),
              $expectation);

      $t0 = (new DateTime())->setTimestamp($fromTs + $t[0]);
      $t1 = (new DateTime())->setTimestamp($fromTs + $t[1]);
      $expectation = count($t) == 4 ? [$this->totalLimitId['u1'] => [self::day() => $t[2]]] : [];
      $this->assertEquals(
          Wasted::queryTimeSpentByLimitAndDate('u1', $t0, $t1),
          $expectation);
      }
  }

  public function testSampleInterval(): void {
    $fromTime = clone Wasted::$now;

    // Default is 15s. Grace period is always 30s.
    $this->insertActivity('u1', ['t']);
    self::advanceTime(45);
    $this->insertActivity('u1', ['t']);

    // This yields one interval.
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(45));

    // Exceeding 45s starts a new interval.
    self::advanceTime(46);
    $this->insertActivity('u1', ['t']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(46));

    // Increase sample interval to 30s, i.e. 60s including the grace period.
    Wasted::setUserConfig('u1', 'sample_interval_seconds', 30);
    self::advanceTime(54);
    $this->insertActivity('u1', ['t']);
    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime),
        $this->total(100));
  }

  public function testUserManagement_add_remove_get(): void {
    // Check default users.
    $this->assertEquals(Wasted::getUsers(), ['u1', 'u2']);

    // Create new user that sorts at the end.
    $user = 'user'.strval(rand());
    Wasted::addUser($user);
    $this->assertEquals(Wasted::getUsers(), ['u1', 'u2', $user]);
    // Can't re-add the same user.
    try {
      Wasted::addUser($user);
      throw new AssertionError('Should not be able to create existing user');
    } catch (Exception $e) { // expected
      $s = "Duplicate entry '$user'";
      $this->assertEquals(substr($e->getMessage(), 0, strlen($s)), $s);
      WastedTestBase::$lastDbError = null; // don't fail the test
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
    $classId = Wasted::addClass('c1');
    $this->assertEquals(count($this->queryMappings('u1')), $n + 1);
    Wasted::removeMapping($classId, $this->totalLimitId['u1']);
    $this->assertEquals(count($this->queryMappings('u1')), $n + 1);
  }

  public function testCannotDeleteTotalLimit(): void {
    $allLimitConfigs = Wasted::getAllLimitConfigs('u1');
    Wasted::removeLimit($this->totalLimitId['u1']);
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);
  }

  public function testCannotRenameTotalLimit(): void {
    $allLimitConfigs = Wasted::getAllLimitConfigs('u1');
    Wasted::renameLimit($this->totalLimitId['u1'], 'foobar');
    $this->assertEquals(Wasted::getAllLimitConfigs('u1'), $allLimitConfigs);
  }

  public function testDuplicateMapping(): void {
    $classId = Wasted::addClass('c1');
    try {
      Wasted::addMapping($classId, $this->totalLimitId['u1']);
      throw new AssertionError('Should not be able to add duplicate mapping');
    } catch (Exception $e) {
      // expected
      $s = 'Duplicate entry';
      $this->assertEquals(substr($e->getMessage(), 0, strlen($s)), $s);
      WastedTestBase::$lastDbError = null; // don't fail the test
    }
  }

  public function testInvalidRegEx(): void {
    $classId = Wasted::addClass('c1');
    try {
      Wasted::addClassification($classId, 0, '*');
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
    $fromTime = clone Wasted::$now;
    $fromTimeString1 = self::dateTimeString();
    $this->assertEquals(Wasted::queryTitleSequence('u1', $fromTime, Wasted::$now), []);

    $classId = Wasted::addClass('c2');
    Wasted::addClassification($classId, 0, '2$');

    $this->insertActivity('u1', ['t1']);
    self::advanceTime(1);
    $this->insertActivity('u1', ['t1']);
    self::advanceTime(1);
    $fromTimeString2 = self::dateTimeString();
    $this->insertActivity('u1', ['t1', 't2']);
    // Query a range that will only include the from_ts, not the to_ts.
    $fromTime2 = (clone Wasted::$now)->sub(days(1));
    $toTimeString = self::dateTimeString();
    $this->assertEquals(
        Wasted::queryTitleSequence(
            // Just barely exclude t2. It would otherwise be included even though it has a zero
            // duration.
            'u1', $fromTime2, (clone Wasted::$now)->sub(new DateInterval('PT1S'))),
        [
            [$fromTimeString1, $toTimeString, DEFAULT_CLASS_NAME, 't1']
        ]);
    // This will now include t2.
    self::advanceTime(1);
    $toTimeString = self::dateTimeString();
    $fromTime3 = (clone Wasted::$now)->sub(days(1));
    $this->insertActivity('u1', ['t1', 't2']);

    $this->assertEquals(
        Wasted::queryTitleSequence('u1', $fromTime, Wasted::$now),
        [
            [$fromTimeString1, $toTimeString, DEFAULT_CLASS_NAME, 't1'],
            [$fromTimeString2, $toTimeString, 'c2', 't2']
        ]);

    $this->assertEquals(
        Wasted::queryTitleSequence('u1', $fromTime3, Wasted::$now),
        [
            [$fromTimeString1, $toTimeString, DEFAULT_CLASS_NAME, 't1'],
            [$fromTimeString2, $toTimeString, 'c2', 't2']
        ]);
  }

  public function testQueryTitleSequence_handleEmptyTitle(): void {
    $fromTime = clone Wasted::$now;
    $fromTimeString = self::dateTimeString();
    $this->insertActivity('u1', ['t1']);
    self::advanceTime(1);
    $toTimeString = self::dateTimeString();
    $this->insertActivity('u1', []);
    self::advanceTime(1);
    $this->insertActivity('u1', []);
    $this->assertEquals(
        Wasted::queryTitleSequence('u1', $fromTime, Wasted::$now),
        [[$fromTimeString, $toTimeString, DEFAULT_CLASS_NAME, 't1']]);
  }

  public function testOnDeleteCascade_deleteLimit(): void {
    $classId = Wasted::addClass('c');
    $limitId = Wasted::addLimit('u1', 'foo');
    Wasted::setLimitConfig($limitId, 'a', 'b');
    Wasted::addMapping($classId, $limitId);
    Wasted::setOverrideUnlock('u1', self::day(), $limitId);

    foreach ([[2, 1, 1], [1, 0, 0]] as $expected) {
      $this->onFailMessage('expected: ' . implode(', ', $expected));
      // The total limit is always included.
      $this->assertEquals(count(Wasted::getAllLimitConfigs('u1')), $expected[0]);
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

      Wasted::removeLimit($limitId);
    }
  }

  public function testSlotsSpecToEpochSlotsOrError(): void {
    $this->assertEquals(Wasted::slotsSpecToEpochSlotsOrError(''), []);
    $this->assertEquals(
        Wasted::slotsSpecToEpochSlotsOrError('11-13:30'), [
            self::slot(11, 0, 13, 30)]);
    $this->assertEquals(
        Wasted::slotsSpecToEpochSlotsOrError('11- 13:30, 2:01pm-4:15pm'), [
            self::slot(11, 0, 13, 30),
            self::slot(14, 1, 16, 15)]);
    $this->assertEquals(
        Wasted::slotsSpecToEpochSlotsOrError(' 11 - 13:30 , 2:01pm-4:15pm  ,  20:00-20:42'), [
            self::slot(11, 0, 13, 30),
            self::slot(14, 1, 16, 15),
            self::slot(20, 0, 20, 42)]);
    $this->assertEquals(
        Wasted::slotsSpecToEpochSlotsOrError('0-1, 1:00-2, 20-21:00, 22:00  -  23:59  '), [
            self::slot(0, 0, 1, 0),
            self::slot(1, 0, 2, 0),
            self::slot(20, 0, 21, 0),
            self::slot(22, 0, 23, 59)]);
    $this->assertEquals(
        Wasted::slotsSpecToEpochSlotsOrError('7:30-12p, 12a-1'), [
            self::slot(0, 0, 1, 0),
            self::slot(7, 30, 12, 0)]);

    $this->assertEquals(
        Wasted::slotsSpecToEpochSlotsOrError('1-2, 20-30'),
        "Invalid time slot: '20-30'");
    $this->assertEquals(
        Wasted::slotsSpecToEpochSlotsOrError('1-2, 17:60 - 18:10'),
        "Invalid time slot: '17:60 - 18:10'");
    $this->assertEquals(
        Wasted::slotsSpecToEpochSlotsOrError('1-2, 17:a - 18:10'),
        "Invalid time slot: '17:a - 18:10'");
  }

  public function testApplySlots(): void {
    // Empty slots string -> zero time.
    $timeLeft = new TimeLeft(false, 42);
    Wasted::applySlots('', $timeLeft);
    $this->assertEquals($timeLeft, self::timeLeft(0, 0, [], []));

    // Configure slots.
    $slots = '8-9, 12-14, 20-21:30';

    // Before first slot, total limited by slots.
    Wasted::$now->setTime(6, 30);
    $timeLeft = new TimeLeft(false, 24 * 60 * 60);
    Wasted::applySlots($slots, $timeLeft);
    $this->assertEquals(
        $timeLeft, self::timeLeft(0, 4.5 * 60 * 60, [], self::slot(8, 0, 9, 0)));

    // Before first slot, total limited by mintues.
    Wasted::$now->setTime(6, 30);
    $timeLeft = new TimeLeft(false, 42);
    Wasted::applySlots($slots, $timeLeft);
    $this->assertEquals(
        $timeLeft, self::timeLeft(0, 42, [], self::slot(8, 0, 9, 0)));

    // Within a slot, total limtied by slots.
    Wasted::$now->setTime(13, 0);
    $timeLeft = new TimeLeft(false, 9999);
    Wasted::applySlots($slots, $timeLeft);
    $this->assertEquals(
        $timeLeft,
        self::timeLeft(
            60 * 60, 2.5 * 60 * 60,
            self::slot(12, 0, 14, 0),
            self::slot(20, 0, 21, 30)));

    // Within last slot, total limited by slots.
    Wasted::$now->setTime(21, 0);
    $timeLeft = new TimeLeft(false, 9999);
    Wasted::applySlots($slots, $timeLeft);
    $this->assertEquals(
        $timeLeft, self::timeLeft(30 * 60, 30 * 60, self::slot(20, 0, 21, 30), []));

    // Between two slots, total limited by slots.
    Wasted::$now->setTime(11, 0);
    $timeLeft = new TimeLeft(false, 99999);
    Wasted::applySlots($slots, $timeLeft);
    $this->assertEquals(
        $timeLeft, self::timeLeft(0, 3.5 * 60 * 60, [], self::slot(12, 0, 14, 0)));

    // After last slot, total limited by slots.
    Wasted::$now->setTime(23, 0);
    $timeLeft = new TimeLeft(false, 9999);
    Wasted::applySlots($slots, $timeLeft);
    $this->assertEquals($timeLeft, self::timeLeft(0, 0, [], []));
  }

  public function testHandleNullInOverrideSlots(): void {
    $totalLimitId = $this->totalLimitId['u1'];
    $limitId = Wasted::addLimit('u1', 'L1');
    Wasted::setLimitConfig($limitId, 'minutes_day', '1');
    // 23-23 should result in zero time left.
    Wasted::setLimitConfig($limitId, 'times', '23-23');
    Wasted::setOverrideMinutes('u1', self::dateString(), $limitId, 2);
    $this->assertEquals(
        $this->queryTimeLeftTodayAllLimitsOnlyCurrentSeconds(),
        [$totalLimitId => 0, $limitId => 0]);
  }

  public function testEffectiveLimitation(): void {
    $totalLimitId = $this->totalLimitId['u1'];
    // Nothing configured: zero time
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(0, 0, [], [])],
        true);

    // Configure minutes only.
    Wasted::setLimitConfig($totalLimitId, 'minutes_day', '2');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(120, 120, [], [])],
        true);

    // Configure slots only.
    Wasted::clearLimitConfig($totalLimitId, 'minutes_day');
    Wasted::setLimitConfig($totalLimitId, 'times', '10-11');
    Wasted::$now->setTime(10, 59);
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(60, 60, self::slot(10, 0, 11, 0), [])],
        true);

    // Add an upcoming slot.
    Wasted::setLimitConfig(
        $totalLimitId, 'times', '10-11, 12-13');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(
            60, 61 * 60, self::slot(10, 0, 11, 0), self::slot(12, 0, 13, 0))],
        true);

    // Configure both minutes and slots, total is limited by minutes.
    Wasted::setLimitConfig($totalLimitId, 'minutes_day', '3');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(
            60, 180, self::slot(10, 0, 11, 0), self::slot(12, 0, 13, 0))],
        true);

    // Configure both minutes and slots, total is limited by slots.
    Wasted::setLimitConfig($totalLimitId, 'minutes_day', '99');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1'),
        [$totalLimitId => self::timeLeft(
            60, 61 * 60, self::slot(10, 0, 11, 0), self::slot(12, 0, 13, 0))],
        true);
  }

  public function testTimesAndMinutesOverrides(): void {
    Wasted::$now->setTime(9, 0)->getTimestamp();
    $limitId = $this->totalLimitId['u1'];
    $dow = strtolower(Wasted::$now->format('D'));
    // default, day-of-week, override
    $slots = [self::slot(10, 0, 11, 0), self::slot(11, 0, 12, 0), self::slot(12, 0, 13, 0)];
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
        Wasted::setLimitConfig($limitId, 'times', '10-11');
      }
      if ($i & 2) {
        Wasted::setLimitConfig($limitId, "times_$dow", '11-12');
      }
      if ($i & 4) {
        Wasted::setOverrideSlots('u1', self::dateString(), $limitId, '12-13');
      }
      $timeLeft = Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId];
      if ($cases[$i] >= 0) {
        $this->assertEquals($timeLeft->nextSlot, $slots[$cases[$i]]);
      } else {
        $this->assertEquals($timeLeft->nextSlot, []);
      }
      Wasted::clearLimitConfig($limitId, 'times');
      Wasted::clearLimitConfig($limitId, "times_$dow");
      Wasted::clearOverrides('u1', self::dateString(), $limitId);
    }

    // Test minutes.
    for ($i = 0; $i < count($cases); $i++) {
      $this->onFailMessage("minutes, case: $i");
      if ($i & 1) {
        Wasted::setLimitConfig($limitId, 'minutes_day', '1');
      }
      if ($i & 2) {
        Wasted::setLimitConfig($limitId, "minutes_$dow", '2');
      }
      if ($i & 4) {
        Wasted::setOverrideMinutes('u1', self::dateString(), $limitId, '3');
      }
      $timeLeft = Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId];
      if ($cases[$i] >= 0) {
        $this->assertEquals($timeLeft->currentSeconds, 60 * $minutes[$cases[$i]]);
      } else {
        $this->assertEquals($timeLeft->currentSeconds, 0);
      }
      Wasted::clearLimitConfig($limitId, 'minutes_day');
      Wasted::clearLimitConfig($limitId, "minutes_$dow");
      Wasted::clearOverrides('u1', self::dateString(), $limitId);
    }
  }

  public function testTotalTimeLeft(): void {
    Wasted::$now->setTime(9, 0)->getTimestamp();
    $limitId = $this->totalLimitId['u1'];
    // Restore default cleared in setup.
    Wasted::setLimitConfig($limitId, 'minutes_day', '1440');

    $seconds = (24 - 9) * 60 * 60;
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, [], []));

    Wasted::setLimitConfig($limitId, 'minutes_day', '42');
    $seconds = 42 * 60;
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, [], []));

    Wasted::setLimitConfig($limitId, 'minutes_week', '41');
    $seconds = 41 * 60;
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, [], []));

    Wasted::setLimitConfig($limitId, 'times', '8-9:40');
    $seconds = 40 * 60;
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, self::slot(8, 0, 9, 40), []));
  }

  public function testOverrideWithEmptySlots(): void {
    $limitId = $this->totalLimitId['u1'];
    Wasted::setLimitConfig($limitId, 'minutes_day', '1');

    $seconds = 60;
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, [], []));

    Wasted::setOverrideSlots('u1', self::dateString(), $limitId, '');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft(0, 0, [], []));
  }

  public function testInvalidSlotSpec(): void {
    $limitId = $this->totalLimitId['u1'];
    $invalidSlotsList = ['invalid', '1-3, 2-4', '23-24:01', '23-25:00', '1:77-2', '2-1'];
    foreach ($invalidSlotsList as $slots) {
      $this->onFailMessage("slot spec: $slots");
      try {
        Wasted::setLimitConfig($limitId, 'times', $slots);
        throw new AssertionError('Should not be able to set invalid slot');
      } catch (Exception $e) {
        // expected
      }
      foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $dow) {
        $this->onFailMessage("slot spec: $slots; day of week: $dow");
        try {
          Wasted::setLimitConfig($limitId, "times_$dow", $slots);
          throw new AssertionError('Should not be able to set invalid slot');
        } catch (Exception $e) {
          // expected
        }
      }
      try {
        Wasted::setOverrideSlots('u1', self::dateString(), $limitId, $slots);
        throw new AssertionError('Should not be able to set invalid slot');
      } catch (Exception $e) {
        // expected
      }
    }
    // Don't trigger for non-matching keys.
    $this->onFailMessage(null);
    Wasted::setLimitConfig($limitId, 'times_foo', 'invalid');
  }

  public function test24hSlot(): void {
    Wasted::$now->setTime(0, 0);
    $limitId = $this->totalLimitId['u1'];
    $seconds = 24 * 60 * 60;

    Wasted::setLimitConfig($limitId, 'times', '0-24');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, self::slot(0, 0, 24, 0), []));

    Wasted::setLimitConfig($limitId, 'times', '0-12a');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, self::slot(0, 0, 24, 0), []));

    Wasted::setLimitConfig($limitId, 'times', '12a-12a');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft($seconds, $seconds, self::slot(0, 0, 24, 0), []));

    $seconds = 12 * 60 * 60;
    Wasted::setLimitConfig($limitId, 'times', '12p-12a');
    $this->assertEquals(
        Wasted::queryTimeLeftTodayAllLimits('u1')[$limitId],
        self::timeLeft(0, $seconds, [], self::slot(12, 0, 24, 0)));
  }

  public function testLimitUnseenBeforeDayMarker(): void {
    $fromTime = clone Wasted::$now;
    $day1 = getDateString($fromTime);

    // Use limits L1 and Total on day 2, but only Total on day 1.
    $limitId = Wasted::addLimit('u1', 'L1');
    $classId = Wasted::addClass('c1');
    Wasted::addClassification($classId, 0, '1$');
    Wasted::addMapping($classId, $limitId);
    Wasted::insertActivity('u1', '', ['-> Total']);
    self::advanceTime(1);
    Wasted::insertActivity('u1', '', ['-> Total']);

    Wasted::$now->add(days(1));
    $day2 = self::day();

    Wasted::insertActivity('u1', '', ['-> Total', '-> L1']);
    self::advanceTime(2);
    Wasted::insertActivity('u1', '', ['-> Total', '-> L1']);

    $this->assertEquals(
        Wasted::queryTimeSpentByLimitAndDate('u1', $fromTime), [
        $this->totalLimitId['u1'] => [$day1 => 1, $day2 => 2],
        $limitId => [$day2 => 2]]);
  }

  // TODO: Test new toTime argument in queryTimeSpentByLimitAndDate (and others).
  // TODO: Test getClassesToClassificationTable.
  // TODO: Test queryOverrides.
  // TODO: Test other recent changes.
  // TODO: Test that the locked flag is returned and minutes are set correctly in that case.

}

(new WastedTest())->run();
// TODO: Consider writing a test case that follows a representative sequence of events.
