<?php

require_once 'common.php';
require_once 'db.class.php';

define('CHARSET_AND_COLLATION', 'latin1 COLLATE latin1_german1_ci');

// We use MySQL's RegExp library, which does not support utf8. So we have to fall back to latin1.
// See here: https://github.com/zieren/kids-freedom-control/issues/33
define('CREATE_TABLE_SUFFIX', 'CHARACTER SET ' . CHARSET_AND_COLLATION . ' ');
// Background for utf8 support (which we can't use):
// - https://stackoverflow.com/questions/54885178
// - https://dba.stackexchange.com/questions/76788
// - needs 8.0+: 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
// General charset info:
// - https://dev.mysql.com/doc/refman/8.0/en/charset-table.html
// - https://stackoverflow.com/questions/1049728/

DB::$success_handler = 'logDbQuery';
DB::$error_handler = 'logDbQueryError';
DB::$throw_exception_on_error = true;

function logDbQuery($params) {
  Logger::Instance()->debug(
      'DB query ['.$params['runtime'].'ms]: '.str_replace("\r\n", '', $params['query']));
}

function logDbQueryError($params) {
  Logger::Instance()->error('DB query: '.str_replace("\r\n", '', $params['query']));
  Logger::Instance()->error('DB error: '.$params['error']);
}

class Wasted {
  /**
   * Public for testing.
   *
   * @var DateTime
   */
  public static $now;

  /** Initializes the singleton for use in production, using DB config from config.php. */
  public static function initialize($initializeTables = false): void {
    self::initializeInternal(DB_NAME, DB_USER, DB_PASS, $initializeTables);
  }

  /** Creates a new instance for use in tests. */
  public static function initializeForTest($dbName, $dbUser, $dbPass): void {
    self::initializeInternal($dbName, $dbUser, $dbPass, true);
  }

  /**
   * Clear all data except for users and total limits. Users should be deleted explicitly if needed,
   * since their re-creation is expensive (because of CREATE TRIGGER).
   *
   * Note that the default minutes limit of 1d for the total limit is also deleted. This is
   * intended; most tests are easier to read if we count total time down from 0 instead of from 24.
   */
  public static function clearForTest(): void {
    foreach (DB::tableList() as $table) {
      if ($table == 'limits') {
        DB::query('DELETE FROM limits WHERE id NOT IN (SELECT total_limit_id FROM users)');
      } elseif ($table == 'users') {
        // Keep users.
      } else {
        DB::query("DELETE FROM `$table`");
      }
    }
    self::insertDefaultRows();
  }

  /** Connects to the database, or exits on error. */
  private static function initializeInternal($dbName, $dbUser, $dbPass, $initializeTables): void {
    self::$now = new DateTime();
    DB::$dbName = $dbName;
    DB::$user = $dbUser;
    DB::$password = $dbPass;
    DB::$encoding = 'latin1'; // aka iso-8859-1
    DB::query('SET NAMES ' . CHARSET_AND_COLLATION); // for 'SET @foo = "bar"'
    if ($initializeTables) {
      self::initializeTables();
    }
    $globalConfig = self::getGlobalConfig();
    // Configure global logger.
    // TODO: Report a proper error if we crash here because we weren't initialized
    // (e.g. new install and never visited the admin page).
    Logger::Instance()->setLogLevelThreshold(
        strtolower(getOrDefault($globalConfig, 'log_level', 'debug')));
  }

  // ---------- TABLE MANAGEMENT ----------

  private static function initializeTables(): void {
    DB::query('SET default_storage_engine=INNODB');
    DB::query(
        'CREATE TABLE IF NOT EXISTS users ('
        . 'id VARCHAR(32) NOT NULL, '
        . 'total_limit_id INT DEFAULT NULL, ' // can't be FK because 'id' is already FK in 'limits'
        . 'last_error VARCHAR(10240) DEFAULT "", '
        . 'acked_error CHAR(15) DEFAULT "", ' // date and time: '20211020 113720'
        . 'PRIMARY KEY (id) '
        . ') '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS classes ('
        . 'id INT NOT NULL AUTO_INCREMENT, '
        . 'name VARCHAR(256) NOT NULL, '
        . 'PRIMARY KEY (id) '
        . ') '
        . CREATE_TABLE_SUFFIX);
    // activity.class_id is not part of the PK because:
    // 1. The same activity at the same time can only be one class. It doesn't make sense to allow
    //    different classes and thus reduce invariants that could make other code simpler.
    // 2. It simplifies reclassification.
    DB::query(
        'CREATE TABLE IF NOT EXISTS activity ('
        . 'user VARCHAR(32) NOT NULL, '
        . 'seq INT UNSIGNED NOT NULL, '
        . 'from_ts BIGINT NOT NULL, '
        . 'to_ts BIGINT NOT NULL, '
        . 'class_id INT NOT NULL, '
        . 'title VARCHAR(256) NOT NULL, '
        . 'PRIMARY KEY (user, title, from_ts), '
        . 'FOREIGN KEY (user) REFERENCES users(id) ON DELETE CASCADE, '
        . 'FOREIGN KEY (class_id) REFERENCES classes(id) ' // must reclassify before delete
        . ') '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS classification ('
        . 'id INT NOT NULL AUTO_INCREMENT, '
        . 'class_id INT NOT NULL, '
        . 'priority INT NOT NULL, '
        . 're VARCHAR(1024) NOT NULL, '
        . 'PRIMARY KEY (id), '
        . 'FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE '
        . ') '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS limits ('
        . 'id INT NOT NULL AUTO_INCREMENT, '
        . 'user VARCHAR(32) NOT NULL, '
        . 'name VARCHAR(256) NOT NULL, '
        . 'PRIMARY KEY (id), '
        . 'FOREIGN KEY (user) REFERENCES users(id) ON DELETE CASCADE '
        . ') '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS mappings ('
        . 'class_id INT NOT NULL, '
        . 'limit_id INT NOT NULL, '
        . 'PRIMARY KEY (class_id, limit_id), '
        . 'FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE, '
        . 'FOREIGN KEY (limit_id) REFERENCES limits(id) ON DELETE CASCADE '
        . ') '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS limit_config ('
        . 'limit_id INT NOT NULL, '
        . 'k VARCHAR(100) NOT NULL, '
        . 'v VARCHAR(200) NOT NULL, '
        . 'PRIMARY KEY (limit_id, k), '
        . 'FOREIGN KEY (limit_id) REFERENCES limits(id) ON DELETE CASCADE '
        . ') '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS user_config ('
        . 'user VARCHAR(32) NOT NULL, '
        . 'k VARCHAR(100) NOT NULL, '
        . 'v VARCHAR(200) NOT NULL, '
        . 'PRIMARY KEY (user, k), '
        . 'FOREIGN KEY (user) REFERENCES users(id) ON DELETE CASCADE '
        . ') '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS global_config ('
        . 'k VARCHAR(100) NOT NULL, '
        . 'v VARCHAR(200) NOT NULL, '
        . 'PRIMARY KEY (k) '
        . ') '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS overrides ('
        . 'user VARCHAR(32) NOT NULL, '
        . 'date DATE NOT NULL, '
        . 'limit_id INT NOT NULL, '
        . 'unlocked BOOL, '
        . 'minutes INT, '
        . 'slots VARCHAR(200), '
        . 'PRIMARY KEY (user, date, limit_id), '
        . 'FOREIGN KEY (user) REFERENCES users(id) ON DELETE CASCADE, '
        . 'FOREIGN KEY (limit_id) REFERENCES limits(id) ON DELETE CASCADE '
        . ') '
        . CREATE_TABLE_SUFFIX);
    self::insertDefaultRows();
  }

  private static function insertDefaultRows(): void {
    // Set up a default class that matches everything with the lowest priority, to catch any title
    // that doesn't match a user specified class. This allows the code to assume that any title will
    // be classified.
    DB::insertIgnore('classes', ['id' => DEFAULT_CLASS_ID, 'name' => DEFAULT_CLASS_NAME]);
    DB::insertIgnore('classification', [
        'id' => DEFAULT_CLASSIFICATION_ID,
        'class_id' => DEFAULT_CLASS_ID,
        'priority' => MYSQL_SIGNED_INT_MIN,
        're' => '()']); // RE can't be ''
  }

  /** Delete all activity of all users, and all server log files, prior to DateTime $date. */
  public static function pruneTables($date): void {
    $pruneTimestamp = $date->getTimestamp();
    Logger::Instance()->notice('prune timestamp: '.$pruneTimestamp);
    DB::delete('activity', 'to_ts < %i', $pruneTimestamp);
    Logger::Instance()->notice('tables pruned up to '.$date->format(DateTimeInterface::ATOM));

    // Delete log files. This depends on KLogger's default log file name pattern.
    $logfiles = scandir(LOG_DIR);
    $matches = null;
    foreach ($logfiles as $f) {
      if (preg_match(LOG_PATTERN, $f, $matches)) {
        $fileDate = new DateTime();
        $fileDate->setTimestamp(strtotime($matches[1]));
        // Be conservative: We assume 00:00:00 on the file date, but write until 24h later.
        $fileDate->add(new DateInterval('P1D'));
        if ($fileDate->getTimestamp() < $pruneTimestamp) {
          unlink(LOG_DIR.'/'.$f);
          Logger::Instance()->notice('log file deleted: '.$f);
        }
      }
    }
  }

  // ---------- LIMIT/CLASS QUERIES ----------

  public static function addLimit($user, $limitName): int {
    DB::insert('limits', ['user' => $user, 'name' => $limitName]);
    return intval(DB::insertId());
  }

  public static function removeLimit($limitId): void {
    // It shouldn't be possible to specifiy the total limit ID here in the first place. Easiest
    // solution is to do nothing.
    DB::delete('limits', 'id = %i AND id NOT IN (SELECT total_limit_id FROM users)', $limitId);
  }

  public static function renameLimit($limitId, $newName): void {
    DB::update('limits',
        ['name' => $newName],
        'id = %s AND id NOT IN (SELECT total_limit_id FROM users)',
        $limitId);
  }

  public static function addClass($className): int {
    DB::insert('classes', ['name' => $className]);
    return intval(DB::insertId());
  }

  public static function removeClass($classId): void {
    if ($classId == DEFAULT_CLASS_ID) {
      self::throwException('Cannot delete default class "' . DEFAULT_CLASS_NAME . '"');
    }
    // Delete classifications first to avoid new titles (and their classification) racing for
    // insertion against the class deletion.
    DB::delete('classification', 'class_id = %i', $classId);

    self::reclassifyForRemoval($classId);
    DB::delete('classes', 'id = %i', $classId);
  }

  public static function renameClass($classId, $newName): void {
    // There should be no harm in renaming the default class.
    DB::update('classes', ['name' => $newName], 'id = %s', $classId);
  }

  public static function addClassification($classId, $priority, $regEx): int {
    DB::query("SELECT 'test' REGEXP %s", $regEx); // validation
    DB::insert('classification', [
        'class_id' => $classId,
        'priority' => $priority,
        're' => $regEx,
        ]);
    return intval(DB::insertId());
  }

  public static function removeClassification($classificationId): void {
    if ($classificationId == DEFAULT_CLASSIFICATION_ID) {
      self::throwException('Cannot delete default classification');
    }
    DB::delete('classification', 'id = %i', $classificationId);
  }

  public static function changeClassification($classificationId, $newRegEx, $newPriority): void {
    if ($classificationId == DEFAULT_CLASSIFICATION_ID) {
      self::throwException('Cannot change default classification');
    }
    DB::query("SELECT 'test' REGEXP %s", $newRegEx); // validation
    DB::update(
        'classification',
        ['re' => $newRegEx, 'priority' => $newPriority],
        'id = %s', $classificationId);
  }

  public static function addMapping($classId, $limitId): void {
    // This will fail when trying to add a mapping for the total limit, because the trigger and
    // the initial setup ensure that all classes are already mapped to it.
    DB::insert('mappings', ['class_id' => $classId, 'limit_id' => $limitId]);
  }

  public static function removeMapping($classId, $limitId): void {
    // It shouldn't be possible to specifiy the total limit ID here in the first place. Easiest
    // solution is to do nothing.
    DB::delete(
        'mappings',
        'class_id = %i AND limit_id = %i AND limit_id NOT IN (SELECT total_limit_id FROM users)',
        $classId, $limitId);
  }

  private static function getTotalLimitTriggerName($user) {
    return "total_limit_$user";
  }

  /** Set up the specified new user and create their 'Total' limit. Returns that limit's ID. */
  public static function addUser($user): int {
    DB::insert('users', ['id' => $user]);
    $limitId = self::addLimit($user, TOTAL_LIMIT_NAME);
    DB::update('users', ['total_limit_id' => $limitId], 'id = %s', $user);
    // Use 25h to cover for the one day when DST changes backward. We wouldn't want to kill programs
    // at 23:00 on that day. Since leap seconds don't happen on the same day they're covered too.
    self::setLimitConfig($limitId, 'minutes_day', 25 * 60);

    // Map all existing classes to the total limit and install a trigger that adds each newly
    // added class to this limit.
    $triggerName = self::getTotalLimitTriggerName($user);
    DB::query("
        CREATE TRIGGER `$triggerName` AFTER INSERT ON classes
          FOR EACH ROW
          INSERT INTO mappings
          SET limit_id = %i, class_id = NEW.id",
        $limitId);
    // INSERT IGNORE is just paranoia: A mapping could have been added right now, i.e. after
    // creation of the trigger.
    DB::query('
        INSERT IGNORE INTO mappings (limit_id, class_id)
          SELECT %i AS limit_id, classes.id AS class_id
          FROM classes',
        $limitId);
    return $limitId;
  }

  public static function removeUser($user): void {
    DB::delete('users', 'id = %s', $user);
    $triggerName = self::getTotalLimitTriggerName($user);
    DB::query("DROP TRIGGER `$triggerName`");
  }

  /**
   * Returns an array the size of $titles that contains, at the corresponding position, an array
   * with keys 'class_id' and 'limits'. The latter is again an array and contains the list of
   * limit IDs to which the class_id maps. This will always contain at least the user's total limit.
   */
  public static function classify($user, $titles): array {
    /* We would need to select the highest priority for each title. I don't know how to do that
     * in a way that is ultimately more elegant than repeated queries. Some background:
     * cf. https://dba.stackexchange.com/questions/24327/
    $query = 'SELECT title FROM (';
    foreach (array_keys($titles) as $i) {
      if ($query) {
        $query .= ' UNION ALL ';
      }
      $query .= 'SELECT %s_'.$i.' AS title';
    }
    $query .= ') titles ';
    */

    $classifications = [];
    foreach ($titles as $title) {
      $rows = DB::query(
          'SELECT classification.id, limit_id FROM (
            SELECT classes.id, classes.name
            FROM classification
            JOIN classes ON classes.id = classification.class_id
            WHERE %s1 REGEXP re
            ORDER BY priority DESC
            LIMIT 1) AS classification
          LEFT JOIN (
            SELECT class_id, limit_id
            FROM mappings
            JOIN limits ON mappings.limit_id = limits.id
            WHERE user = %s0
          ) user_mappings ON classification.id = user_mappings.class_id
          ORDER BY limit_id',
          $user, $title);
      if (!$rows) { // This should never happen, the default class catches all.
        self::throwException("Failed to classify '$title' (default class missing?)");
      }

      $classification = [];
      $classification['class_id'] = intval($rows[0]['id']);
      $classification['limits'] = [];
      foreach ($rows as $row) {
        $classification['limits'][] = intval($row['limit_id']);
      }
      $classifications[] = $classification;
    }
    return $classifications;
  }

  /** Sets the specified limit config. Time slots are checked for validity. */
  public static function setLimitConfig($limitId, $key, $value): void {
    if (preg_match('/^times(_(mon|tue|wed|thu|fri|sat|sun))?$/', $key)) {
      self::checkSlotsString($value);
    }
    DB::insertUpdate('limit_config', ['limit_id' => $limitId, 'k' => $key, 'v' => $value]);
  }

  /** Clears the specified limit config. */
  public static function clearLimitConfig($limitId, $key): void {
    DB::delete('limit_config', 'limit_id = %s AND k = %s', $limitId, $key);
  }

  private static function checkSlotsString($slotsString): void {
    $e = self::slotsSpecToEpochSlotsOrError($slotsString);
    if (is_string($e)) {
      self::throwException($e);
    }
  }

  /**
   * Returns configs of all limits for the specified user. Returns a 2D array
   * $configs[$limitId][$key] = $value. The array is sorted by limit ID.
   *
   * Two synthetic configs are injected: 'name' for the limit name, and 'is_total', which is true
   * for the single total limit.
   */
  public static function getAllLimitConfigs($user): array {
    $rows = DB::query('
        SELECT limits.id, name, k, v, total_limit_id
          FROM limit_config
          RIGHT JOIN limits ON limit_config.limit_id = limits.id
          LEFT JOIN users ON users.total_limit_id = limits.id
          WHERE user = %s
          ORDER BY id, k',
        $user);
    $configs = [];
    foreach ($rows as $row) {
      $limitId = $row['id'];
      if (!array_key_exists($limitId, $configs)) {
        $configs[$limitId] = [];
      }
      if ($row['k']) { // May be absent due to RIGHT JOIN if limit has no config at all.
        $configs[$limitId][$row['k']] = $row['v'];
      }
      $configs[$limitId]['name'] = $row['name'];
      $configs[$limitId]['is_total'] = $row['total_limit_id'] ? true : false;
    }
    ksort($configs);
    return $configs;
  }

  /**
   * Returns a table listing all limits, their classes and their configs. For each class, all other
   * limits affecting this class are also listed ("" if there are none).
   *
   * The total limit is omitted, except when a class is mapped only to this limit.
   */
  public static function getLimitsToClassesTable($user): array {
    $rows = DB::query(
        'SELECT
           limits.name as lim,
           classes.name AS class,
           CASE WHEN n = 1 THEN "" ELSE other_limits END,
           cfg
         FROM (
           SELECT
             limit_id,
             class_id,
             GROUP_CONCAT(other_limit_name ORDER BY other_limit_name SEPARATOR ", ") AS other_limits,
             n
           FROM (
             SELECT
               limit_id,
               user_mappings_extended.class_id,
               other_limit_id,
               limits.name AS other_limit_name,
               n
             FROM (
               SELECT
                 user_mappings.limit_id,
                 user_mappings.class_id,
                 limits.id AS other_limit_id
               FROM (
                 SELECT limit_id, class_id
                 FROM mappings
                 JOIN limits ON limits.id = limit_id
                 JOIN classes ON classes.id = class_id
                 WHERE USER = %s0
                 AND limit_id != (SELECT total_limit_id FROM users WHERE id = %s0)
               ) AS user_mappings
               JOIN mappings ON user_mappings.class_id = mappings.class_id
               JOIN limits ON mappings.limit_id = limits.id
               WHERE limits.user = %s0
             ) AS user_mappings_extended
             JOIN (
               SELECT class_id, COUNT(*) AS n
               FROM mappings
               JOIN limits ON mappings.limit_id = limits.id
               WHERE USER = %s0
               AND limit_id != (SELECT total_limit_id FROM users WHERE id = %s0)
               GROUP BY class_id
             ) AS limit_count
             ON user_mappings_extended.class_id = limit_count.class_id
             JOIN limits ON other_limit_id = limits.id
             WHERE limits.id != (SELECT total_limit_id FROM users WHERE id = %s0)
             HAVING limit_id != other_limit_id OR n = 1
           ) AS user_mappings_non_redundant
           GROUP BY limit_id, class_id, n
           UNION
           SELECT limit_id, class_id, "", ""
           FROM mappings
           JOIN limits ON mappings.limit_id = limits.id
           WHERE limits.id = (SELECT total_limit_id FROM users WHERE id = %s0)
           AND class_id NOT IN (
             SELECT class_id FROM mappings
             JOIN limits ON mappings.limit_id = limits.id
             WHERE user = %s0
             AND limits.id != (SELECT total_limit_id FROM users WHERE id = %s0)
           )
         ) AS result
         JOIN classes ON class_id = classes.id
         JOIN limits ON limit_id = limits.id
         LEFT JOIN (
           SELECT
             limit_id,
             GROUP_CONCAT(config_text ORDER BY config_text SEPARATOR ", ") AS cfg
           FROM (
             SELECT limit_id, CONCAT(k, "=", v) AS config_text
             FROM limit_config
           ) AS limit_config_text
           GROUP BY limit_id
         ) AS limit_config_texts
         ON limit_config_texts.limit_id = limits.id
         ORDER BY lim, class',
        $user);
    $table = [];
    foreach ($rows as $row) {
      $table[] = [
          $row['lim'] ?? '',
          $row['class'] ?? '',
          $row['CASE WHEN n = 1 THEN "" ELSE other_limits END'], // can't alias CASE
          $row['cfg'] ?? ''];
    }
    return $table;
  }

  /**
   * Returns a table listing all classes, their classification rules and samples from all users.
   *
   * TODO: Add time limit.
   */
  public static function getClassesToClassificationTable(): array {
    DB::query('SET @prev_title = ""');
    $rows = DB::query('
        SELECT
          classes.id AS id,
          name,
          re,
          priority,
          COUNT(DISTINCT title) AS n,
          LEFT(GROUP_CONCAT(title ORDER BY title SEPARATOR "\n"), 1025) AS samples
        FROM classes
        LEFT JOIN classification ON classes.id = classification.class_id
        LEFT JOIN (
          SELECT DISTINCT title, class_id FROM activity WHERE title != ""
        ) samples
        ON samples.class_id = classification.class_id
        WHERE classes.id != %i0
        GROUP BY id, name, re, priority
        ORDER BY name, priority DESC',
        DEFAULT_CLASS_ID);
    $table = [];
    foreach ($rows as $r) {
      $samples = strlen($r['samples']) <= 1024
          ? $r['samples']
          : (substr($r['samples'], 0, 1021) . '...');
      $table[] = [$r['name'], $r['re'], intval($r['priority']), intval($r['n']), $samples];
    }
    return $table;
  }

  /** Returns an array of class names keyed by class ID. */
  public static function getAllClasses(): array {
    $rows = DB::query('SELECT id, name FROM classes ORDER BY name');
    $classes = [];
    foreach ($rows as $row) {
      $classes[$row['id']] = $row['name'];
    }
    return $classes;
  }

  /**
   * Returns an array keyed by classification ID containing arrays with the class name (key 'name'),
   * regular expression (key 're') and priority (key 'priority'). The default classification is not
   * returned.
   */
  public static function getAllClassifications(): array {
    $rows = DB::query('
        SELECT classification.id, name, re, priority
          FROM classification
          JOIN classes on classification.class_id = classes.id
          WHERE classes.id != %i
          ORDER BY name',
        DEFAULT_CLASS_ID);
    $classifications = [];
    foreach ($rows as $row) {
      $classifications[$row['id']] = [
          'name' => $row['name'],
          're' => $row['re'],
          'priority' => intval($row['priority'])];
    }
    return $classifications;
  }

  /** Reclassify all activity for all users, starting at the specified time. */
  public static function reclassify($fromTime): void {
    DB::query('SET @prev_title = ""');
    DB::query(self::reclassifyQuery(false), $fromTime->getTimestamp());
  }

  /** Reclassify all activity for all users to prepare removal of the specified class. */
  public static function reclassifyForRemoval($classToRemove): void {
    DB::query('SET @prev_title = ""');
    DB::query(self::reclassifyQuery(true), $classToRemove);
  }

  private static function reclassifyQuery($forRemoval): string {
    // Activity to reclassify.
    $conditionActivity = $forRemoval ? 'activity.class_id = %i0' : 'to_ts > %i0';
    // Classes available for reclassification.
    $conditionClassification = $forRemoval ? 'classification.class_id != %i0' : 'true';
    return "
        REPLACE INTO activity (user, seq, from_ts, to_ts, class_id, title)
          SELECT user, seq, from_ts, to_ts, reclassification.class_id, activity.title
          FROM (
            SELECT
              title,
              class_id,
              IF (@prev_title = title, 0, 1) AS first,
              @prev_title := title
              FROM (
                SELECT title, classification.class_id, priority
                FROM (
                  SELECT DISTINCT title FROM activity
                  WHERE title != ''
                  AND $conditionActivity
                ) distinct_titles
                JOIN classification ON title REGEXP re
                WHERE $conditionClassification
                ORDER BY title, priority DESC
              ) reclassification_all_prios
              HAVING first = 1
            ) reclassification
          JOIN activity ON reclassification.title = activity.title
          WHERE $conditionActivity";
  }

  /**
   * Returns the top $num titles since $fromTime that were classified as default. Order is by time
   * spent ($orderBySum = true) or else by recency.
   */
  public static function queryTopUnclassified($user, $fromTime, $orderBySum, $num): array {
    $rows = self::queryTimeSpentByTitleInternal(
        $user, $fromTime->getTimestamp(), MYSQL_SIGNED_BIGINT_MAX, $orderBySum, $num);
    $table = [];
    foreach ($rows as $r) {
      $table[] = [intval($r['sum_s']), $r['title'], date("Y-m-d H:i:s", $r['ts_last_seen'])];
    }
    return $table;
  }

  // ---------- WRITE ACTIVITY QUERIES ----------

  /**
   * Records the specified window titles (array). Return value is that of classify().
   *
   * We assume only one client. The client never performs concurrent requests ("Critical, On" in AHK
   * code), so we can assume requests are serialized. However, a request may arrive within the same
   * epoch second as the previous request.
   *
   * Title classification can change between calls; the new classification will replace the old one
   * back until the start of the title's current interval. Previous intervals are unaffected.
   *
   * If no windows are open, $titles should be an empty array. In this case the timestamp is
   * recorded for the computation of the previous interval.
   */
  public static function insertActivity($user, $lastError, $titles): array {
    $ts = self::$now->getTimestamp();
    if ($lastError) {
      DB::update('users', ['last_error' => $lastError], 'id = %s', $user);
    }
    $config = self::getClientConfig($user);
    // Grace period is always 30s. It accounts for the client being slow to send its request. A
    // higher value means that we tend towards interpreting an observation as a continuation of the
    // current session rather than a gap and a new session. We argue that 30s means at most the user
    // logged out and right back in without even rebooting, which can be viewed as a continuation.
    // Note that the sample interval can be much larger or smaller. In the latter case we simply
    // update measurements more frequently.
    // ==========>>> KEEP THE DEFAULT IN SYNC WITH THE CLIENT CODE!!! <<<==========
    $maxInterval = getOrDefault($config, 'sample_interval_seconds', 15) + 30;

    // We use a pseudo-title of '' to indicate that nothing was running. In the unlikely event a
    // window actually has an empty title, change that to avoid a clash.
    if (!$titles) {
      $titles = [''];
      $classifications = self::$NO_TITLES_PSEUDO_CLASSIFICATION; // info on limits is not used
    } else {
      $classifications = self::classify($user, $titles); // no harm classifying the original ''
      foreach ($titles as $i => $title) {
        if (!$title) {
          $titles[$i] = '(no title)';
        }
      }
    }

    // Map title to current classification. Note that everything is case insensitive:
    // - Regular expression matching
    // - The DB returns e.g. 'Foo' for "title IN ('foo')"
    // Therefore we normalize keys in this map and add the original title to the value.
    $classificationsMap = [];
    $newTitles = [];
    foreach ($classifications as $i => $classification) {
      $title = $titles[$i];
      $titleLowerCase = strtolower($title);
      $classificationsMap[$titleLowerCase] =
          ['class_id' => $classification['class_id'], 'title' => $title];
      // We will remove continued titles from this so the newly started titles remain.
      $newTitles[$titleLowerCase] = $title;
    }

    // Find next sequence number. The sequence number imposes order even when multiple requests are
    // received within the same epoch second.
    $seq = 0;
    $rows = DB::query('SELECT MAX(seq) AS seq FROM activity WHERE user = %s0', $user);
    if ($rows) {
      $seq = intval($rows[0]['seq']) + 1;
    }
    $previousSeq = $seq - 1;

    // Update continued titles, i.e. recent titles included in $titles. We need the from_ts for
    // each, as it is part of the PK. Titles that we don't find remain in $newTitles and are
    // inserted later.
    $rows = DB::query('
        SELECT title, from_ts
        FROM activity
        WHERE user = %s
        AND seq = %i
        AND to_ts >= %i
        AND title IN %ls',
        $user,
        $previousSeq,
        $ts - $maxInterval,
        $titles);
    if ($rows) {
      $updates = [];
      foreach ($rows as $i => $row) {
        $title = $row['title'];
        $titleLowerCase = strtolower($title);
        $updates[] = [
            'user' => $user,
            'title' => $title,
            'from_ts' => $row['from_ts'],
            'to_ts' => $ts,
            'seq' => $seq,
            // Classification may have changed:
            'class_id' => $classificationsMap[$titleLowerCase]['class_id']
            ];
        unset($newTitles[$titleLowerCase]);
      }
      DB::replace('activity', $updates);
    }

    // Update concluded titles. Since we have already updated 'seq' for all continued titles above,
    // anything stilil at $previousSeq must be concluded (i.e. 'NOT IN $titles' is not needed).
    DB::query('
        UPDATE activity
        SET to_ts = %i
        WHERE user = %s
        AND seq = %i
        AND to_ts >= %i',
        $ts,
        $user,
        $previousSeq,
        $ts - $maxInterval);

    // Insert new titles.
    if ($newTitles) {
      $updates = [];
      foreach ($newTitles as $titleLowerCase => $title) {
        $updates[] = [
            'user' => $user,
            'title' => $title,
            'from_ts' => $ts,
            'to_ts' => $ts,
            'seq' => $seq,
            'class_id' => $classificationsMap[$titleLowerCase]['class_id']
            ];
      }
      DB::insert('activity', $updates);
    }

    return $classifications === self::$NO_TITLES_PSEUDO_CLASSIFICATION ? [] : $classifications;
  }

  // ---------- CONFIG QUERIES ----------

  /** Updates the specified user config value. */
  public static function setUserConfig($user, $key, $value): void {
    DB::replace('user_config', ['user' => $user, 'k' => $key, 'v' => $value]);
  }

  /** Updates the specified global config value. */
  public static function setGlobalConfig($key, $value): void {
    DB::replace('global_config', ['k' => $key, 'v' => $value]);
  }

  /** Deletes the specified user config value. */
  public static function clearUserConfig($user, $key): void {
    DB::delete('user_config', 'user = %s AND k = %s', $user, $key);
  }

  /** Deletes the specified global config value. */
  public static function clearGlobalConfig($key): void {
    DB::delete('global_config', 'k = %s', $key);
  }

  /** Returns user config. */
  public static function getUserConfig($user): array {
    return self::parseKvRows(DB::query('SELECT k, v FROM user_config WHERE user = %s', $user));
  }

  /** Returns the global config. */
  public static function getGlobalConfig(): array {
    return self::parseKvRows(DB::query('SELECT k, v FROM global_config'));
  }

  /**
   * Returns the config for the client of the specified user. This is the global config merged with
   * the user specific config.
   */
  public static function getClientConfig($user): array {
    return self::parseKvRows(DB::query('
        SELECT k, v FROM global_config
        WHERE k NOT IN (SELECT k FROM user_config WHERE user = %s0)
        UNION
        SELECT k, v FROM user_config WHERE user = %s0',
        $user));
  }

  private static function parseKvRows($rows): array {
    $config = [];
    foreach ($rows as $row) {
      $config[$row['k']] = $row['v'];
    }
    return $config;
  }

  /** Returns all users ordered by user ID. */
  public static function getUsers(): array {
    $rows = DB::query('SELECT id FROM users ORDER BY id');
    $users = [];
    foreach ($rows as $row) {
      $users[] = $row['id'];
    }
    return $users;
  }

  /** Returns an unacknowledged error, if any. */
  public static function getUnackedError($user): string {
    $rows = DB::query('
        SELECT last_error FROM users
        WHERE id = %s
        AND SUBSTR(last_error, 1, 15) != acked_error', $user);
    return $rows ? $rows[0]['last_error'] : '';
  }

  /** Acknowledges the specified error. Only the first 15 characters are relevant. */
  public static function ackError($user, $error): void {
    DB::update('users', ['acked_error' => $error], 'id = %s', $user);
  }

  // ---------- TIME SPENT/LEFT QUERIES ----------

  /**
   * Returns the time in seconds spent between $fromTime and $toTime, as a 2D array keyed by limit
   * ID and then date. $toTime may be null to omit the upper limit.
   */
  public static function queryTimeSpentByLimitAndDate($user, $fromTime, $toTime = null): array {
    $fromTimestamp = $fromTime->getTimestamp();
    $toTimestamp = $toTime ? $toTime->getTimestamp() : MYSQL_SIGNED_BIGINT_MAX;
    $rows = DB::query('
        SELECT
          limit_id,
          GREATEST(%i1, from_ts) AS from_ts,
          LEAST(%i2, to_ts) AS to_ts
        FROM activity
        LEFT JOIN (
          SELECT class_id, limit_id
          FROM mappings
          JOIN limits ON mappings.limit_id = limits.id
          WHERE user = %s0
        ) user_mappings
        ON activity.class_id = user_mappings.class_id
        WHERE user = %s0
        AND title != ""
        AND '.self::$ACTIVITY_OVERLAP_CONDITION.'
        ORDER BY from_ts, to_ts',
        $user, $fromTimestamp, $toTimestamp);

    if (!$rows) {
      return [];
    }

    // Compute time per limit per day. First collect all relevant timestamps.
    // $timestamps contains each relevant timestamp as a key mapped to an array of events. This
    // array contains the following keys/values:
    // 'starting' => array of all limit IDs that start at this timestamp
    // 'ending' => array of all limit IDs that end at this timestamp
    // 'day' => string denoting the previous day, i.e. an exclusive end marker for that day
    $timestamps = [];
    // Used as a set to collect all observed limits
    $limitIds = [];
    $minTs = $rows[0]['from_ts'];
    $maxTs = $minTs;
    foreach ($rows as $row) {
      $fromTs = $row['from_ts'];
      $toTs = $row['to_ts'];
      $limitId = $row['limit_id'];
      // We should always at least have the total limit. But that works via a trigger that maps
      // new classes to the total limit. Theoretically these mappings could be removed by hand. In
      // that unlikely event, bail out because the code doesn't expect null as a limit ID.
      if ($limitId == null) {
        self::throwException('Missing total limit in row: '.dumpArrayToString($row));
      }
      getOrCreate(getOrCreate($timestamps, $fromTs, []), 'starting', [])[] = $limitId;
      getOrCreate(getOrCreate($timestamps, $toTs, []), 'ending', [])[] = $limitId;
      $maxTs = max($maxTs, $toTs);
      $limitIds[$limitId] = 1;
    }

    // Insert timestamps for each new day, with the string representation of the previous day. At
    // this timestamp we need to store the accumulated times for that previous day. Need to use
    // no-arg ctor to pick up TZ.
    $dateTime = (new DateTime())->setTimestamp($minTs)->setTime(0, 0);
    do {
      $dateString = getDateString($dateTime);
      $dateTime->add(new DateInterval('P1D'));
      $ts = $dateTime->getTimestamp();
      getOrCreate($timestamps, $ts, [])['day'] = $dateString;
    } while ($ts < $maxTs);

    // Accumulate time per limit per day.
    ksort($timestamps);
    $timeByLimitAndDate = [];
    $limitCount = []; // number of active intervals, keyed by limit ID
    $limitStart = []; // start time of earliest active interval, keyed by limit ID
    $limitTime = []; // accumulated time on current day, keyed by limit ID
    foreach ($timestamps as $ts => $events) {
      foreach (getOrDefault($events, 'starting', []) as $limitId) {
        $n = &getOrCreate($limitCount, $limitId, 0);
        if ($n == 0) {
          $limitStart[$limitId] = $ts;
        }
        $n++;
      }

      foreach (getOrDefault($events, 'ending', []) as $limitId) {
        $n = &$limitCount[$limitId];
        if (!$n) {
          self::throwException('Invalid: Limit interval ending before it started');
        }
        $n--;
        if ($n == 0) {
          $t = &getOrCreate($limitTime, $limitId, 0);
          $t += $ts - $limitStart[$limitId];
        }
      }

      if (isset($events['day'])) {
        $dateString = $events['day'];
        foreach (array_keys($limitIds) as $limitId) {
          if (getOrDefault($limitCount, $limitId)) {
            // Limit is active: Accumulate time until end of day, then reset start time.
            $t = &getOrCreate($limitTime, $limitId, 0);
            $t += $ts - $limitStart[$limitId];
            $limitStart[$limitId] = $ts;
          } // else: Count may be zero, or the key does not exist yet because the limit was not
            // observed yet.

          // The limit is used in the considered time interval, but may not have been observed yet.
          if (isset($limitTime[$limitId])) {
            // Record accumulated time for this limit on this day.
            getOrCreate($timeByLimitAndDate, $limitId, [])[$dateString] = $limitTime[$limitId];
            $limitTime[$limitId] = 0;
          }
        }
      }
    }

    ksort($timeByLimitAndDate);
    return $timeByLimitAndDate;
  }

  public static function queryTimeSpentByTitleInternal(
      $user, $fromTimestamp, $toTimestamp, $orderBySum, $topUnclassified = 0): array {
    $orderBy = $orderBySum
        ? 'ORDER BY sum_s DESC, title'
        : 'ORDER BY ts_last_seen DESC, title';
    $limit = $topUnclassified ? "LIMIT $topUnclassified" : '';
    $filter = $topUnclassified ? 'AND class_id = '.DEFAULT_CLASS_ID : '';
    return DB::query("
        SELECT
          title,
          name,
          SUM(LEAST(%i2, to_ts) - GREATEST(%i1, from_ts)) AS sum_s,
          MAX(LEAST(%i2, to_ts)) AS ts_last_seen
        FROM activity
        JOIN classes ON class_id = id
        WHERE user = %s0
        AND title != ''
        $filter
        AND ".self::$ACTIVITY_OVERLAP_CONDITION."
        GROUP BY title, name
        $orderBy
        $limit",
        $user, $fromTimestamp, $toTimestamp);
  }

  /**
   * Returns the time spent by window title and limit name, starting at $fromTime and ending 1d
   * (i.e. usually 24h) later. $date should therefore usually have a time of 0:00. Records are
   * ordered by the amount of time ($orderBySum = true) or else by recency.
   *
   * TODO: Semantics, parameter names.
   */
  public static function queryTimeSpentByTitle($user, $fromTime, $orderBySum = true): array {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    $rows = self::queryTimeSpentByTitleInternal(
        $user, $fromTime->getTimestamp(), $toTime->getTimestamp(), $orderBySum);
    $timeByTitle = [];
    foreach ($rows as $row) {
      // TODO: This should use the client's local time format.
      $timeByTitle[] = [
          date("Y-m-d H:i:s", $row['ts_last_seen']),
          intval($row['sum_s']),
          $row['name'],
          $row['title']];
    }
    return $timeByTitle;
  }

  /**
   * Returns an array keyed by limit ID that holds TimeLeft instances. All limits for the user are
   * present. The result is sorted by key (for consistency, as the ID is not meaningful).
   */
  public static function queryTimeLeftTodayAllLimits($user): array {
    $configs = self::getAllLimitConfigs($user);
    $overridesByLimit = self::queryOverridesByLimit($user);
    $timeSpentByLimitAndDate =
        self::queryTimeSpentByLimitAndDate($user, getWeekStart(self::$now), null);

    $timeLeftByLimit = [];
    foreach (array_keys($configs) as $limitId) {
      $config = getOrDefault($configs, $limitId, []);
      $timeSpentByDate = getOrDefault($timeSpentByLimitAndDate, $limitId, []);
      $overrides = getOrDefault($overridesByLimit, $limitId, []);
      $timeLeftByLimit[$limitId] =
          self::computeTimeLeftToday($config, $overrides, $timeSpentByDate, $limitId);
    }
    ksort($timeLeftByLimit, SORT_NUMERIC);
    return $timeLeftByLimit;
  }

  /**
   * Returns a TimeLeft instance for one limit. That limit's config, overrides and time spent this
   * week are specified.
   *
   * This considers the unlock requirement, weekly minutes, daily minutes, time slots and overrides
   * for daily minutes and/or time slots.
   *
   * Available time depending on presence/absence of minutes and slots:
   * - No minutes, no slots: zero
   * - Minutes, no slots: the minutes
   * - No minutes, but slots: the slots
   * - Minutes and slots: minimum from both
   */
  private static function computeTimeLeftToday($config, $overrides, $timeSpentByDate): TimeLeft {
    $nowString = getDateString(self::$now);
    $dow = strtolower(self::$now->format('D'));

    // Limit is locked if unlock is required and it's not unlocked.
    $locked =
        getOrDefault($config, 'locked', false) && !getOrDefault($overrides, 'unlocked', false);
    // Extract slots string.
    $slotsSpec = getOrDefault($config, 'times');
    $slotsSpec = getOrDefault($config, "times_$dow", $slotsSpec);
    $slotsSpec = getOrDefault($overrides, 'slots', $slotsSpec);
    // Compute the regular minutes for today: default, day-of-week or overridden. Zero if none set,
    // or in case of NULL (which can happen for overrides).
    $minutesLimitToday = getOrDefault($config, 'minutes_day');
    $minutesLimitToday = getOrDefault($config, "minutes_$dow", $minutesLimitToday);
    $minutesLimitToday = getOrDefault($overrides, 'minutes', $minutesLimitToday);
    // Compute minutes limit in seconds, considering presence/absence of config.
    if ($minutesLimitToday === null) {
      if ($slotsSpec === null) {
        // When a limit is totally not configured, available time is zero.
        $secondsLimitToday = 0;
      } else {
        // When no daily minutes are set, but slots are used, then set the minutes to "inf". They
        // are later limited by the optional weekly minutes and always by the time left in the day.
        $secondsLimitToday = PHP_INT_MAX;
      }
    } else {
      // Minutes are set.
      $secondsLimitToday = $minutesLimitToday * 60;
    }
    // A weekly limit can further shorten the minute contingent, but not extend it.
    if (isset($config['minutes_week'])) {
      $secondsLeftInWeek = $config['minutes_week'] * 60 - array_sum($timeSpentByDate);
      $secondsLimitToday = min($secondsLimitToday, $secondsLeftInWeek);
    }
    // Compute time left in the minutes contingent. Can't exceed time left in the day.
    $tomorrow = (clone self::$now)->setTime(0, 0)->add(new DateInterval('P1D'));
    $secondsLeftInDay = $tomorrow->getTimestamp() - self::$now->getTimestamp();
    $totalSeconds =
        min($secondsLimitToday - getOrDefault($timeSpentByDate, $nowString, 0), $secondsLeftInDay);
    $timeLeft = new TimeLeft($locked, $totalSeconds);

    // Apply slots, if set.
    if ($slotsSpec !== null) {
      self::applySlots($slotsSpec, $timeLeft);
    }

    return $timeLeft;
  }

  /**
   * Takes the current time and slots string from config/overrides and applies the resulting
   * additional limitation to the specified TimeLeft instance. Also sets the computed current and
   * next slot.
   *
   * Public for testing.
   */
  public static function applySlots($slotsSpec, $timeLeft): void {
    $slots = self::slotsSpecToEpochSlotsOrError($slotsSpec);
    if (is_string($slots)) {
      self::throwException($slots);
    }

    $ts = self::$now->getTimestamp();
    $slots[] = []; // avoids next slot extraction special case
    $currentSeconds = 0;
    $totalSeconds = 0;
    $currentSlot = [];
    $nextSlot = [];
    for ($i = 0; $i < count($slots) - 1; $i++) {
      $slot = $slots[$i];
      if ($slot[0] <= $ts && $ts < $slot[1]) {
        $totalSeconds = $currentSeconds = $slot[1] - $ts;
        $currentSlot = $slot;
        $nextSlot = $slots[$i + 1];
        $i++;
        break;
      }
      if ($ts < $slot[0]) { // next slot is in the future
        $nextSlot = $slot;
        break;
      }
    }
    // Sum up remaining time in slots.
    for (; $i < count($slots) - 1; $i++) {
      $totalSeconds += $slots[$i][1] - $slots[$i][0];
    }

    $timeLeft->reflectSlots($currentSeconds, $totalSeconds, $currentSlot, $nextSlot);
  }

  /**
   * Returns a sorted array of non-overlapping slots in the form
   * [[from_ts_0, to_ts_0], [from_ts_1, to_ts_1]]. On error returns an error message (string).
   */
  static function slotsSpecToEpochSlotsOrError($slotsSpec) /* mixed - only in PHP 8 */ {
    $slotsStrings = explode(',', $slotsSpec);
    $slots = [];
    foreach ($slotsStrings as $slotString) {
      $slotString = trim($slotString);
      if (!$slotString) {
        continue;
      }
      $m = [];
      $valid = false;
      if (preg_match_all(self::$TIME_OF_DAY_PATTERN, $slotString, $m) && count($m[0]) == 2) {
        $fromHour = self::adjust12hFormat(intval($m[1][0]), strtolower($m[3][0]));
        $fromMinute = $m[2][0] ? intval($m[2][0]) : 0;
        $toHour = self::adjust12hFormat(intval($m[1][1]), strtolower($m[3][1]));
        $toMinute = $m[2][1] ? intval($m[2][1]) : 0;

        // Special case: The 24h format has hours 0, 12 and 24:00, whereas the 12h format only has
        // 12am (i.e. 0) and 12pm (i.e. 12). If 12am is specified as the end time, interpret it as
        // 24:00 (which DateTime handles correctly).
        if ($toHour == 0 && $toMinute == 0) {
          $toHour = 24;
        }

        $valid = ($fromHour != 24 || $fromMinute == 0) && ($toHour != 24 || $toMinute == 0);
      }
      if ($valid) {
        $d = clone self::$now;
        $fromTimestamp = $d->setTime($fromHour, $fromMinute)->getTimestamp();
        $toTimestamp = $d->setTime($toHour, $toMinute)->getTimestamp();
        // If $toHour was 24 then $d is now at the next day, so it's wasted!
        $valid = $fromTimestamp <= $toTimestamp;
      }
      if (!$valid) {
        return "Invalid time slot: '$slotString'";
      }
      $slots[] = [$fromTimestamp, $toTimestamp];
    }
    // Sort by from timestamp.
    usort($slots, function ($a, $b) { return $a[0] - $b[0]; });
    for ($i = 1; $i < count($slots); $i++) {
      if ($slots[$i][0] < $slots[$i - 1][1]) {
        return "Time slots overlap: '$slotsSpec'";
      }
    }
    return $slots;
  }

  private static function adjust12hFormat($h, $amOrPm): int {
    if ($amOrPm == 'a') {
      return $h == 12 ? 0 : $h;
    } else if ($amOrPm == 'p') {
      return $h < 12 ? $h + 12 : $h;
    }
    return $h;
  }

  /**
   * Returns a list of strings describing all available classes by name and seconds left today.
   * This considers the most restrictive limit and assumes that no time is spent on any other class
   * (which might count towards a shared limit and thus reduce time for other classes). Classes with
   * zero time are omitted.
   */
  public static function queryClassesAvailableTodayTable(
      $user, $timeLeftTodayAllLimits = null): array {
    $timeLeftTodayAllLimits = $timeLeftTodayAllLimits ?? self::queryTimeLeftTodayAllLimits($user);
    $rows = DB::query(
        'SELECT classes.name, class_id, limit_id FROM limits
         JOIN mappings on limits.id = mappings.limit_id
         JOIN classes ON mappings.class_id = classes.id
         WHERE user = %s
         ORDER BY class_id, limit_id',
        $user);
    $classes = [];
    foreach ($rows as $row) {
      $classId = $row['class_id'];
      if (!array_key_exists($classId, $classes)) {
        $classes[$classId] = [$row['name'], PHP_INT_MAX];
      }
      $newLimit = $timeLeftTodayAllLimits[$row['limit_id']]->currentSeconds;
      $classes[$classId][1] = min($classes[$classId][1], $newLimit);
    }
    // Remove classes for which no time is left.
    $classes = array_filter($classes, function($c) { return $c[1] > 0; });
    // Sort by time left, then by name.
    usort($classes, function($a, $b) { return $b[1] - $a[1] ?: strcasecmp($a[0], $b[0]); });

    // List classes, adding time left to last class of a sequence that have this much time left.
    $classesList = [];
    for ($i = 0; $i < count($classes); $i++) {
      $s = $classes[$i][0];
      if ($i == count($classes) - 1 || $classes[$i + 1][1] != $classes[$i][1]) {
        $s .= ' (' . secondsToHHMMSS($classes[$i][1]) . ')';
      }
      $classesList[] = $s;
    }
    return $classesList;
  }

  // ---------- OVERRIDE QUERIES ----------

  /**
   * Overrides the limit minutes limit for $date, which is a String in the format 'YYYY-MM-DD'.
   *
   * Returns queryOverlappingLimits().
   */
  public static function setOverrideMinutes($user, $date, $limitId, $minutes): array {
    DB::insertUpdate('overrides', [
        'user' => $user,
        'date' => $date,
        'limit_id' => $limitId,
        'minutes' => $minutes],
        'minutes=%i', $minutes);
    return self::queryOverlappingLimits($limitId);
  }

  /**
   * Unlocks the specified limit for $date, which is a String in the format 'YYYY-MM-DD'.
   *
   * Returns queryOverlappingLimits().
   */
  public static function setOverrideUnlock($user, $date, $limitId): array {
    DB::insertUpdate('overrides', [
        'user' => $user,
        'date' => $date,
        'limit_id' => $limitId,
        'unlocked' => 1],
        'unlocked=%i', 1);
    return self::queryOverlappingLimits($limitId, $date);
  }

  /** Overrides the time slots otherwise applicable for this date. */
  public static function setOverrideSlots($user, $date, $limitId, $slots): array {
    self::checkSlotsString($slots);
    DB::insertUpdate('overrides', [
        'user' => $user,
        'date' => $date,
        'limit_id' => $limitId,
        'slots' => $slots],
        'slots=%s', $slots);
    return self::queryOverlappingLimits($limitId, $date);
  }

  /**
   * Clears all overrides for the specified limit for $date, which is a String in the format
   * 'YYYY-MM-DD'.
   */
  public static function clearOverrides($user, $date, $limitId): void {
    DB::delete('overrides', 'user=%s AND date=%s AND limit_id=%i', $user, $date, $limitId);
  }

  /**
   * Returns a list of other limits (by name) that overlap with this limit. Only limits of the
   * same user are considered.
   *
   * If $dateForUnlock (as a string in 'YYYY-MM-DD' format) is specified, the query is restricted to
   * limits that are locked on that day, taking overrides into consideration.
   */
  public static function queryOverlappingLimits($limitId, $dateForUnlock = null): array {
    return array_map(
        function($a) { return $a['name']; },
        DB::query('
            SELECT name, id FROM (
              SELECT DISTINCT limit_id FROM (
                SELECT class_id FROM limits
                JOIN mappings ON id = limit_id
                WHERE id = %i0
              ) AS affected_classes
              JOIN mappings ON affected_classes.class_id = mappings.class_id
              WHERE limit_id NOT IN (
                %i0,
                (SELECT total_limit_id FROM users WHERE id =
                  (SELECT user FROM limits WHERE id = %i0))
              )
            ) AS overlapping_limits
            JOIN limits ON id = limit_id
            ' . ($dateForUnlock
            ? 'JOIN limit_config ON id = limit_config.limit_id' : '') . '
            WHERE user = (SELECT user FROM limits WHERE id = %i0)
            ' . ($dateForUnlock
            ? 'AND k = "locked" AND v
               AND id NOT IN (
                 SELECT limit_id FROM overrides
                 WHERE user = (SELECT user FROM limits WHERE id = %i0)
                 AND date = %s1
                 AND unlocked)' : '') . '
            ORDER BY name', $limitId, $dateForUnlock));

  }

  /** Returns this week's overrides for the specified user. */
  // TODO: Allow setting the date range.
  public static function queryRecentOverrides($user): array {
    $fromDate = getWeekStart(self::$now);
    return DB::query('
        SELECT
          date,
          name,
          CASE WHEN minutes IS NOT NULL THEN minutes ELSE "default" END,
          CASE WHEN slots IS NOT NULL THEN slots ELSE "default" END,
          CASE WHEN unlocked = 1 THEN "unlocked" ELSE "default" END
        FROM overrides
        JOIN limits ON limit_id = id
        WHERE overrides.user = %s0
        AND date >= %s1
        ORDER BY date DESC, name',
        $user, $fromDate->format('Y-m-d'));
  }

  /** Returns all overrides as a 2D array keyed first by limit ID, then by override. */
  private static function queryOverridesByLimit($user): array {
    $rows = DB::query('
        SELECT limit_id, unlocked , minutes, slots
        FROM overrides
        WHERE user = %s
        AND date = %s',
        $user, getDateString(self::$now));
    $overridesByLimit = [];
    // PK is (user, date, limit_id), so there is at most one row per limit_id.
    foreach ($rows as $row) {
      $overridesByLimit[$row['limit_id']] = array_filter([
          'minutes' => $row['minutes'],
          'unlocked' => $row['unlocked'],
          'slots' => $row['slots']],
          function ($v) { return $v !== null; }); // remove NULL for easier use with getOrDefault()
    }
    return $overridesByLimit;
  }

  // ---------- DEBUG/SPECIAL/DUBIOUS/OBNOXIOUS QUERIES ----------

  /**
   * Returns the sequence of window titles for the specified user and date. This will typically be
   * a long array and is intended for debugging.
   */
  public static function queryTitleSequence($user, $fromTime): array {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    $rows = DB::query('
      SELECT from_ts, to_ts, name, title
      FROM activity
      JOIN classes ON class_id = id
      WHERE user = %s0
      AND '.self::$ACTIVITY_OVERLAP_CONDITION.'
      ORDER BY to_ts DESC, from_ts, title',
      $user, $fromTime->getTimestamp(), $toTime->getTimestamp());
    $windowTitles = [];
    foreach ($rows as $row) {
      // TODO: This should use the client's local time format.
      $windowTitles[] = [
          date("Y-m-d H:i:s", $row['from_ts']),
          date("Y-m-d H:i:s", $row['to_ts']),
          $row['name'],
          $row['title']];
    }
    return $windowTitles;
  }

  private static function throwException($message): void {
    Logger::Instance()->critical($message);
    throw new Exception($message);
  }

  private static $NO_TITLES_PSEUDO_CLASSIFICATION = [['class_id' => DEFAULT_CLASS_ID]];

  /**
   * The activity starts in the interval, or ends in the interval, or fully encloses it. Note that
   * the end timestamp is always exclusive.
   */
  private static $ACTIVITY_OVERLAP_CONDITION = '
      (
        (%i1 <= from_ts AND from_ts < %i2)
        OR
        (%i1 < to_ts AND to_ts <= %i2)
        OR
        (from_ts < %i1 AND %i2 <= to_ts)
      )';

  private static $TIME_OF_DAY_PATTERN =
      '/(?:^|-) *((?:[01]?[0-9])|(?:2[0-4]))(?::([0-5]?[0-9]))?(?: *(a|p)[.m]*)? *(?=-|$)/i';
}
