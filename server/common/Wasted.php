<?php

require_once 'common.php';
require_once 'db.class.php';

define('DAILY_LIMIT_MINUTES_PREFIX', 'daily_limit_minutes_');

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

function logDbQuery($params) {
  Logger::Instance()->debug(
      'DB query ['.$params['runtime'].'ms]: '.str_replace("\r\n", '', $params['query']));
}

function logDbQueryError($params) {
  Logger::Instance()->error('DB query: '.str_replace("\r\n", '', $params['query']));
  Logger::Instance()->error('DB error: '.$params['error']);
}

class Wasted {

  /** Creates a new instance for use in production, using parameters from config.php. */
  public static function create($createMissingTables = false): Wasted {
    return new Wasted(
        DB_NAME, DB_USER, DB_PASS, function() { return time(); }, $createMissingTables);
  }

  /** Creates a new instance for use in tests. $timeFunction is used in place of system time(). */
  public static function createForTest(
      $dbName, $dbUser, $dbPass, $timeFunction): Wasted {
    return new Wasted($dbName, $dbUser, $dbPass, $timeFunction, true);
  }

  public function clearAllForTest(): void {
    foreach (DB::query('SHOW TRIGGERS') as $row) {
      DB::query('DROP TRIGGER IF EXISTS ' . $row['Trigger']);
    }
    DB::query('SET FOREIGN_KEY_CHECKS = 0');
    $rows = DB::query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = %s', DB::$dbName);
    foreach ($rows as $row) {
      DB::query('DELETE FROM `' . $row['table_name'] . '`');
    }
    DB::query('SET FOREIGN_KEY_CHECKS = 1');
    $this->insertDefaultRows();
  }

  /**
   * Connects to the database, or exits on error. $timeFunction is used in place of system time().
   */
  private function __construct($dbName, $dbUser, $dbPass, $timeFunction, $createMissingTables) {
    DB::$dbName = $dbName;
    DB::$user = $dbUser;
    DB::$password = $dbPass;
    DB::$encoding = 'latin1'; // aka iso-8859-1
    DB::query('SET NAMES ' . CHARSET_AND_COLLATION); // for 'SET @foo = "bar"'
    $this->timeFunction = $timeFunction;
    if ($createMissingTables) {
      $this->createMissingTables();
    }
    // Configure global logger.
    // TODO: Report a proper error if we crash here because we weren't initialized
    // (e.g. new install and never visited the admin page).
    $config = $this->getGlobalConfig();
    if (array_key_exists('log_level', $config)) {
      Logger::Instance()->setLogLevelThreshold(strtolower($config['log_level']));
    }  // else: defaults to debug
  }

  // ---------- TABLE MANAGEMENT ----------

  // TODO: This also inserts a few default rows. Reflect that in the name.
  public function createMissingTables() {
    DB::query('SET default_storage_engine=INNODB');
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
        . 'PRIMARY KEY (user, seq, from_ts, title), '
        . 'FOREIGN KEY (class_id) REFERENCES classes(id) '
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
        . 'PRIMARY KEY (id) '
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
        . 'PRIMARY KEY (user, k) '
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
        . 'minutes INT, '
        . 'unlocked BOOL, '
        . 'PRIMARY KEY (user, date, limit_id) '
        . ') '
        . CREATE_TABLE_SUFFIX);
    $this->insertDefaultRows();
  }

  private function insertDefaultRows() {
    DB::insertIgnore('classes', ['id' => DEFAULT_CLASS_ID, 'name' => DEFAULT_CLASS_NAME]);
    DB::insertIgnore('classification', [
        'id' => DEFAULT_CLASSIFICATION_ID,
        'class_id' => DEFAULT_CLASS_ID,
        'priority' => MYSQL_SIGNED_INT_MIN,
        're' => '()']); // RE can't be ''
  }

  /** Delete all activity of all users, and all server log files, prior to DateTime $date. */
  public function pruneTables($date) {
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

  public function addLimit($user, $limitName) {
    DB::insert('limits', ['user' => $user, 'name' => $limitName]);
    return intval(DB::insertId());
  }

  public function removeLimit($limitId) {
    DB::delete('limits', 'id = %i', $limitId);
  }

  public function renameLimit($limitId, $newName) {
    DB::update('limits', ['name' => $newName], 'id = %s', $limitId);
  }

  public function addClass($className) {
    DB::insert('classes', ['name' => $className]);
    return DB::insertId();
  }

  public function removeClass($classId) {
    if ($classId == DEFAULT_CLASS_ID) {
      $this->throwException('Cannot delete default class "' . DEFAULT_CLASS_NAME . '"');
    }
    // Delete classifications first to avoid new titles (and their classification) racing for
    // insertion against the class deletion.
    DB::delete('classification', 'class_id = %i', $classId);

    $this->reclassifyForRemoval($classId);
    DB::delete('classes', 'id = %i', $classId);
  }

  public function renameClass($classId, $newName) {
    // There should be no harm in renaming the default class.
    DB::update('classes', ['name' => $newName], 'id = %s', $classId);
  }

  public function addClassification($classId, $priority, $regEx) {
    DB::insert('classification', [
        'class_id' => $classId,
        'priority' => $priority,
        're' => $regEx,
        ]);
    return DB::insertId();
  }

  public function removeClassification($classificationId) {
    if ($classificationId == DEFAULT_CLASSIFICATION_ID) {
      $this->throwException('Cannot delete default classification');
    }
    DB::delete('classification', 'id = %i', $classificationId);
  }

  public function changeClassification($classificationId, $newRegEx, $newPriority) {
    if ($classificationId == DEFAULT_CLASSIFICATION_ID) {
      $this->throwException('Cannot change default classification');
    }
    DB::update(
        'classification',
        ['re' => $newRegEx, 'priority' => $newPriority],
        'id = %s', $classificationId);
  }

  public function addMapping($classId, $limitId) {
    DB::insert('mappings', ['class_id' => $classId, 'limit_id' => $limitId]);
    return DB::insertId();
  }

  public function removeMapping($classId, $limitId) {
    DB::delete('mappings', 'class_id=%i AND limit_id=%i', $classId, $limitId);
  }

  private function getTotalLimitTriggerName($user) {
    return 'total_limit_' . hash('crc32', $user);
  }

  /**
   * Maps all existing classes to the specified limit and installs a trigger that adds each newly
   * added class to this limit.
   */
  public function setTotalLimit($user, $limitId) {
    $triggerName = $this->getTotalLimitTriggerName($user);
    DB::query(
        'INSERT IGNORE INTO mappings (limit_id, class_id)
          SELECT %i AS limit_id, classes.id AS class_id
          FROM classes',
        $limitId);
    DB::query('DROP TRIGGER IF EXISTS ' . $triggerName);
    DB::query(
        'CREATE TRIGGER ' . $triggerName . ' AFTER INSERT ON classes
          FOR EACH ROW
          INSERT IGNORE INTO mappings
          SET limit_id = %i, class_id = NEW.id',
        $limitId);
  }

  /** Removes the total limit (if any) for the user. */
  public function unsetTotalLimit($user) {
    $triggerName = $this->getTotalLimitTriggerName($user);
    DB::query('DROP TRIGGER IF EXISTS ' . $triggerName);
  }

  /**
   * Returns an array the size of $titles that contains, at the corresponding position, an array
   * with keys 'class_id' and 'limits'. The latter is again an array and contains the list of
   * limit IDs to which the class_id maps, where 0 indicates "limit to zero".
   */
  public function classify($user, $titles) {
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
        $this->throwException('Failed to classify "' . $title . '"');
      }

      $classification = [];
      $classification['class_id'] = intval($rows[0]['id']);
      $classification['limits'] = [];
      foreach ($rows as $row) {
        // Limit ID may be null.
        if ($limitId = $row['limit_id']) {
          $classification['limits'][] = intval($limitId);
        } else {
          $classification['limits'][] = 0;
        }
      }
      $classifications[] = $classification;
    }
    return $classifications;
  }

  // TODO: Decide how to treat disabled (non-enabled!?) limits. Then check callers.

  /** Sets the specified limit config. */
  public function setLimitConfig($limitId, $key, $value) {
    DB::insertUpdate('limit_config', ['limit_id' => $limitId, 'k' => $key, 'v' => $value]);
  }

  /** Clears the specified limit config. */
  public function clearLimitConfig($limitId, $key) {
    DB::delete('limit_config', 'limit_id = %s AND k = %s', $limitId, $key);
  }

  /**
   * Returns configs of all limits for the specified user. Returns a 2D array
   * $configs[$limitId][$key] = $value. The array is sorted by limit ID.
   */
  public function getAllLimitConfigs($user) {
    $rows = DB::query(
        'SELECT id, name, k, v
          FROM limit_config
          RIGHT JOIN limits ON limit_config.limit_id = limits.id
          WHERE user = %s
          ORDER BY id, k',
        $user);
    $configs = [];
    foreach ($rows as $row) {
      $limitId = $row['id'];
      if (!array_key_exists($limitId, $configs)) {
        $configs[$limitId] = [];
      }
      if ($row['k']) { // May be absent due to RIGHT JOIN.
        $configs[$limitId][$row['k']] = $row['v'];
      }
      $configs[$limitId]['name'] = $row['name'];
    }
    ksort($configs);
    return $configs;
  }

  /**
   * Returns a table listing all limits and their classes. For each class the last column lists
   * all other limits affecting this class ("" if there are none).
   */
  public function getLimitsToClassesTable($user) {
    $rows = DB::query(
        'SELECT
           limits.name as lim,
           classes.name AS class,
           CASE WHEN n = 1 THEN "" ELSE other_limits END
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
               GROUP BY class_id
             ) AS limit_count
             ON user_mappings_extended.class_id = limit_count.class_id
             JOIN limits ON other_limit_id = limits.id
             HAVING limit_id != other_limit_id OR n = 1
           ) AS user_mappings_non_redundant
           GROUP BY limit_id, class_id, n
         ) AS result
         JOIN classes ON class_id = classes.id
         JOIN limits ON limit_id = limits.id
         ORDER BY lim, class',
        $user);
    $table = [];
    foreach ($rows as $row) {
      $table[] = [
          $row['lim'] ?? '',
          $row['class'] ?? '',
          $row['CASE WHEN n = 1 THEN "" ELSE other_limits END']]; // can't alias CASE
    }
    return $table;
  }

  /**
   * Returns a table listing all classes, their classification rules and samples from all users.
   *
   * TODO: Add time limit.
   */
  public function getClassesToClassificationTable() {
    DB::query('SET @prev_title = ""');
    $rows = DB::query(
        'SELECT
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
  public function getAllClasses() {
    $rows = DB::query('SELECT id, name FROM classes ORDER BY name');
    $table = [];
    foreach ($rows as $row) {
      $table[$row['id']] = $row['name'];
    }
    return $table;
  }

  /**
   * Returns an array keyed by classification ID containing arrays with the class name (key 'name'),
   * regular expression (key 're') and priority (key 'priority'). The default classification is not
   * returned.
   */
  public function getAllClassifications() {
    $rows = DB::query(
        'SELECT classification.id, name, re, priority
          FROM classification
          JOIN classes on classification.class_id = classes.id
          WHERE classes.id != %i
          ORDER BY name',
        DEFAULT_CLASS_ID);
    $table = [];
    foreach ($rows as $row) {
      $table[$row['id']] = [
          'name' => $row['name'],
          're' => $row['re'],
          'priority' => intval($row['priority'])];
    }
    return $table;
  }

  /** Reclassify all activity for all users, starting at the specified time. */
  public function reclassify($fromTime) {
    DB::query('SET @prev_title = ""');
    DB::query($this->reclassifyQuery(false), $fromTime->getTimestamp());
  }

  /** Reclassify all activity for all users to prepare removal of the specified class. */
  public function reclassifyForRemoval($classToRemove) {
    DB::query('SET @prev_title = ""');
    DB::query($this->reclassifyQuery(true), $classToRemove);
  }

  private function reclassifyQuery($forRemoval) {
    // Activity to reclassify.
    $conditionActivity = $forRemoval ? 'activity.class_id = %i0' : 'to_ts > %i0';
    // Classes available for reclassification.
    $conditionClassification = $forRemoval ? 'classification.class_id != %i0' : 'true';
    return '
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
                  WHERE title != ""
                  AND '.$conditionActivity.'
                ) distinct_titles
                JOIN classification ON title REGEXP re
                WHERE '.$conditionClassification.'
                ORDER BY title, priority DESC
              ) reclassification_all_prios
              HAVING first = 1
            ) reclassification
          JOIN activity ON reclassification.title = activity.title
          WHERE '.$conditionActivity;
  }

  /**
   * Returns the top $num titles since $fromTime that were classified as default. Order is by time
   * spent ($orderBySum = true) or else by recency.
   */
  public function queryTopUnclassified($user, $fromTime, $orderBySum, $num) {
    $rows = $this->queryTimeSpentByTitleInternal(
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
   * If no windows are open, $titles should be an empty array. In this case the timestamp is
   * recorded for the computation of the previous interval.
   */
  public function insertWindowTitles($user, $titles) {
    $ts = $this->time();

    // The below code expects that $titles does not contain duplicates. Rather than filter
    // duplicates in PHP, use the database's case sensitivity and language setting (ground truth).
    if (count($titles) > 1) {
      $s = [];
      foreach ($titles as $i => $title) {
        $s[] = "SELECT %s_$i AS t";
      }
      $rows = DB::query(implode(' UNION ', $s), $titles);
      $t = [];
      foreach ($rows as $row) {
        $t[] = $row['t'];
      }
      $titles = $t;
    }

    // Find next sequence number. The sequence number imposes order even when multiple requests are
    // received within the same epoch second.
    $seq = 0;
    $rows = DB::query('SELECT MAX(seq) AS seq FROM activity WHERE user = %s0', $user);
    if ($rows) {
      $seq = intval($rows[0]['seq']) + 1;
    }
    $previousSeq = $seq - 1;

    // Query latest report, if within continuation period.
    $rows = DB::query('
        SELECT from_ts, title, class_id
        FROM activity
        WHERE user = %s
        AND seq = %i
        AND to_ts >= %i',
        $user, $previousSeq, $ts - 25); // TODO: magic 25=15+10
    // Find potentially continued titles and their from_ts.
    $activeTitlesToFromTs = [];
    foreach ($rows as $row) {
      // Including the class ID in the key means we'll start a new interval if classification
      // changed.
      $activeTitlesToFromTs[$row['class_id'].':'.$row['title']] = $row['from_ts'];
    }

    // We use a pseudo-title of '' to indicate that nothing was running. In the unlikely event a
    // window actually has an empty title, change that to avoid a clash.
    if (!$titles) {
      $titles = [''];
      $classifications = [['class_id' => DEFAULT_CLASS_ID]]; // info on limits is not used
      $classificationsToReturn = [];
    } else {
      $classifications = $this->classify($user, $titles); // no harm classifying the original ''
      $classificationsToReturn = &$classifications;
      foreach ($titles as $i => $title) {
        if (!$title) {
          $titles[$i] = '(no title)';
        }
      }
    }

    // ö: Can we change some of the loop code to direct MySQL code?

    $newActivities = [];
    foreach ($titles as $i => $title) {
      $k = $classifications[$i]['class_id'].':'.$title;
      if (array_key_exists($k, $activeTitlesToFromTs)) {
        // Previous activity continues: Update seq and to_ts.
        // ö Faster when assembling updates as a string separated by ";"?
        DB::query('
            UPDATE activity
            SET seq = %i, to_ts = %i
            WHERE user = %s
            AND seq = %i
            AND from_ts = %i
            AND title = %s',
            $seq, $ts,
            $user,
            $previousSeq,
            $activeTitlesToFromTs[$k],
            $title);
        unset($activeTitlesToFromTs[$k]);
      } else {
        // New activity starts.
        $newActivities[] = [
          'user' => $user,
          'seq' => $seq,
          'from_ts' => $ts,
          'to_ts' => $ts,
          'title' => $title,
          'class_id' => $classifications[$i]['class_id']];
      }
    }
    // Update titles from last observation that are now gone: Set to_ts to now() but keep seq, so
    // they are effectively concluded. If they were observed again next time, new intervals would be
    // started.
    foreach ($activeTitlesToFromTs as $key => $fromTs) {
      $title = explode(':', $key, 2)[1]; // strip leading class ID
      DB::query('
          UPDATE activity
          SET to_ts = %i
          WHERE user = %s
          AND seq = %i
          AND from_ts = %i
          AND title = %s',
          $ts,
          $user,
          $previousSeq,
          $fromTs,
          $title);
    }
    if ($newActivities) {
      DB::insert('activity', $newActivities);
    }
    return $classificationsToReturn;
  }

  // ---------- CONFIG QUERIES ----------
  // TODO: Reject invalid values like '"'.

  /** Updates the specified user config value. */
  public function setUserConfig($user, $key, $value) {
    DB::replace('user_config', ['user' => $user, 'k' => $key, 'v' => $value]);
  }

  /** Updates the specified global config value. */
  public function setGlobalConfig($key, $value) {
    DB::replace('global_config', ['k' => $key, 'v' => $value]);
  }

  /** Deletes the specified user config value. */
  public function clearUserConfig($user, $key) {
    DB::delete('user_config', 'user = %s AND k = %s', $user, $key);
  }

  /** Deletes the specified global config value. */
  public function clearGlobalConfig($key) {
    DB::delete('global_config', 'k = %s', $key);
  }

  // TODO: Consider caching the config(s).

  /** Returns user config. */
  public function getUserConfig($user) {
    return Wasted::parseKvRows(DB::query('SELECT k, v FROM user_config WHERE user = %s', $user));
  }

  /** Returns the global config. */
  public function getGlobalConfig() {
    return Wasted::parseKvRows(DB::query('SELECT k, v FROM global_config'));
  }

  /**
   * Returns the config for the client of the specified user. This is the global config merged with
   * the user specific config.
   */
  public function getClientConfig($user) {
    return Wasted::parseKvRows(DB::query(
        'SELECT k, v FROM ('
        . '  SELECT k, v FROM global_config'
        . '  WHERE k NOT IN (SELECT k FROM user_config WHERE user = %s0)'
        . '  UNION ALL'
        . '  SELECT k, v FROM user_config WHERE user = %s0'
        . ') AS t1 ORDER BY k',
        $user));
  }

  private static function parseKvRows($rows) {
    $config = [];
    foreach ($rows as $row) {
      $config[$row['k']] = $row['v'];
    }
    return $config;
  }

  /** Returns all users, i.e. all distinct user keys for which at least one limit is present. */
  public function getUsers() {
    $rows = DB::query('SELECT DISTINCT user FROM limits ORDER BY user');
    $users = [];
    foreach ($rows as $row) {
      $users[] = $row['user'];
    }
    return $users;
  }

  // ---------- TIME SPENT/LEFT QUERIES ----------

  /**
   * Returns the time in seconds spent between $fromTime and $toTime, as a 2D array keyed by limit
   * ID (including NULL for "limit to zero", if applicable) and date. $toTime may be null to omit
   * the upper limit.
   */
  public function queryTimeSpentByLimitAndDate($user, $fromTime, $toTime = null) {
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
        AND (
          (%i1 <= from_ts AND from_ts < %i2)
          OR
          (%i1 <= to_ts AND to_ts < %i2)
          OR
          (from_ts < %i1 AND %i2 <= to_ts)
        )
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
      getOrCreate(getOrCreate($timestamps, $fromTs, []), 'starting', [])[] = $limitId;
      getOrCreate(getOrCreate($timestamps, $toTs, []), 'ending', [])[] = $limitId;
      $maxTs = max($maxTs, $toTs);
      $limitIds[$limitId] = 1;
    }

    // Insert timestamps for each new day, with the string representation of the previous day. At
    // this timestamp we need to store the accumulated times for that previous day.
    $dateTime = (new DateTime())->setTimestamp($minTs);
    $dateTime->setTime(0, 0);
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
          $this->throwException('Invalid: Limit interval ending before it started');
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
          if (getOrDefault($limitCount, $limitId, 0)) {
            // Limit is active: Accumulate time until end of day, then reset start time.
            $t = &getOrCreate($limitTime, $limitId, 0);
            $t += $ts - $limitStart[$limitId];
            $limitStart[$limitId] = $ts;
          }
          // Record accumulated time for this limit on this day.
          getOrCreate($timeByLimitAndDate, $limitId, [])[$dateString] = $limitTime[$limitId];
          $limitTime[$limitId] = 0;
        }
      }
    }

    ksort($timeByLimitAndDate);
    return $timeByLimitAndDate;
  }

  private function queryTimeSpentByTitleInternal(
      $user, $fromTimestamp, $toTimestamp, $orderBySum, $topUnclassified = 0) {
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
        AND (
          (%i1 <= from_ts AND from_ts < %i2)
          OR
          (%i1 <= to_ts AND to_ts < %i2)
          OR
          (from_ts < %i1 AND %i2 <= to_ts)
        )
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
  public function queryTimeSpentByTitle($user, $fromTime, $orderBySum = true) {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    $rows = $this->queryTimeSpentByTitleInternal(
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
   * Returns the time (in seconds) left today, in an array keyed by limit ID. In order of
   * decreasing priority, this considers the unlock requirement, an override limit, the limit
   * configured for the day of the week, and the default daily limit. For the last two, a possible
   * weekly limit is additionally applied.
   *
   * The special ID null indicating "limit to zero" is present iff the value is < 0, meaning that
   * time was spent outside of any defined limit.
   *
   * The result is sorted by key.
   */
  public function queryTimeLeftTodayAllLimits($user) {
    $configs = $this->getAllLimitConfigs($user);
    $now = $this->newDateTime();

    $overridesByLimit = $this->queryOverridesByLimit($user, $now);

    $timeSpentByLimitAndDate =
        $this->queryTimeSpentByLimitAndDate($user, getWeekStart($now), null);

    // $minutesSpentByLimitAndDate may contain a limit ID of NULL to indicate "limit to zero", which
    // $configs never contains.
    $limitIds = array_keys($configs);
    if (array_key_exists(null, $timeSpentByLimitAndDate)) {
      $limitIds[] = null;
    }
    $timeLeftByLimit = array();
    foreach ($limitIds as $limitId) {
      $config = getOrDefault($configs, $limitId, array());
      $timeSpentByDate = getOrDefault($timeSpentByLimitAndDate, $limitId, array());
      $overrides = getOrDefault($overridesByLimit, $limitId, array());
      $timeLeftByLimit[$limitId] = $this->computeTimeLeftToday(
          $config, $now, $overrides, $timeSpentByDate, $limitId);
    }
    ksort($timeLeftByLimit, SORT_NUMERIC);
    return $timeLeftByLimit;
  }

  private function computeTimeLeftToday($config, $now, $overrides, $timeSpentByDate) {
    $nowString = getDateString($now);
    $timeSpentToday = getOrDefault($timeSpentByDate, $nowString, 0);

    // Explicit overrides have highest priority.
    $requireUnlock = getOrDefault($config, 'require_unlock', false);
    if ($overrides) {
      if ($requireUnlock && $overrides['unlocked'] != 1) {
        return 0;
      }
      if ($overrides['minutes'] != null) {
        return $overrides['minutes'] * 60 - $timeSpentToday;
      }
    } else if ($requireUnlock) {
      return 0;
    }

    $minutesLimitToday = getOrDefault($config, DAILY_LIMIT_MINUTES_PREFIX . 'default', 0);

    // Weekday-specific limit overrides default limit.
    $key = DAILY_LIMIT_MINUTES_PREFIX . strtolower($now->format('D'));
    $minutesLimitToday = getOrDefault($config, $key, $minutesLimitToday);

    $timeLeftToday = $minutesLimitToday * 60 - $timeSpentToday;

    // A weekly limit can shorten the daily limit, but not extend it.
    if (isset($config['weekly_limit_minutes'])) {
      $timeLeftInWeek = $config['weekly_limit_minutes'] * 60 - array_sum($timeSpentByDate);
      $timeLeftToday = min($timeLeftToday, $timeLeftInWeek);
    }

    return $timeLeftToday;
  }

  /**
   * Returns a list of strings describing all available classes
   * TODO ö
   *  class names and seconds left for that class. This considers the most
   * restrictive limit and assumes that no time is spent on any other class (which might count
   * towards a shared limit and thus reduce time for other classes). Classes with zero time are
   * omitted.
   */
  public function queryClassesAvailableTodayTable($user, $timeLeftTodayAllLimits = null) {
    $timeLeftTodayAllLimits =
        $timeLeftTodayAllLimits ?? $this->queryTimeLeftTodayAllLimits($user);
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
      $newLimit = $timeLeftTodayAllLimits[$row['limit_id']];
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
  public function setOverrideMinutes($user, $date, $limitId, $minutes) {
    DB::insertUpdate('overrides', [
        'user' => $user,
        'date' => $date,
        'limit_id' => $limitId,
        'minutes' => $minutes],
        'minutes=%i', $minutes);
    return $this->queryOverlappingLimits($limitId);
  }

  /**
   * Unlocks the specified limit for $date, which is a String in the format 'YYYY-MM-DD'.
   *
   * Returns queryOverlappingLimits().
   */
  public function setOverrideUnlock($user, $date, $limitId) {
    DB::insertUpdate('overrides', [
        'user' => $user,
        'date' => $date,
        'limit_id' => $limitId,
        'unlocked' => 1],
        'unlocked=%i', 1);
    return $this->queryOverlappingLimits($limitId, $date);
  }

  /**
   * Clears all overrides (minutes and unlock) for the specified limit for $date, which is a
   * String in the format 'YYYY-MM-DD'.
   */
  public function clearOverrides($user, $date, $limitId) {
    DB::delete('overrides', 'user=%s AND date=%s AND limit_id=%i', $user, $date, $limitId);
  }

  /**
   * Returns a list of other limits (by name) that overlap with this limit. Only limits of the
   * same user are considered.
   *
   * If $dateForUnlock (as a string in 'YYYY-MM-DD' format) is specified, the query is restricted to
   * limits that are locked on that day, taking overrides into consideration.
   */
  public function queryOverlappingLimits($limitId, $dateForUnlock = null) {
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
              WHERE limit_id != %i0
            ) AS overlapping_limits
            JOIN limits ON id = limit_id
            ' . ($dateForUnlock
            ? 'JOIN limit_config ON id = limit_config.limit_id' : '') . '
            WHERE user = (SELECT user FROM limits WHERE id = %i0)
            ' . ($dateForUnlock
            ? 'AND k = "require_unlock" AND v = "1"
               AND id NOT IN (
                 SELECT limit_id FROM overrides
                 WHERE user = (SELECT user FROM limits WHERE id = %i0)
                 AND date = %s1
                 AND unlocked = "1")' : '') . '
            ORDER BY name', $limitId, $dateForUnlock));

  }

  /** Returns recent overrides for the specified user. */
  // TODO: Allow setting the date range.
  public function queryRecentOverrides($user) {
    $fromDate = $this->newDateTime();
    $fromDate->sub(new DateInterval('P0D'));
    return DB::query(
        'SELECT date, name,'
        . ' CASE WHEN minutes IS NOT NULL THEN minutes ELSE "default" END,'
        . ' CASE WHEN unlocked = 1 THEN "unlocked" ELSE "default" END'
        . ' FROM overrides'
        . ' JOIN limits ON limit_id = id'
        . ' WHERE overrides.user = %s0'
        . ' AND date >= %s1'
        . ' ORDER BY date DESC, name',
        $user, $fromDate->format('Y-m-d'));
  }

  /** Returns all overrides as a 2D array keyed first by limit ID, then by override. */
  private function queryOverridesByLimit($user, $now) {
    $rows = DB::query('SELECT limit_id, minutes, unlocked FROM overrides'
        . ' WHERE user = %s'
        . ' AND date = %s',
        $user, getDateString($now));
    $overridesByLimit = array();
    // PK is (user, date, limit_id), so there is at most one row per limit_id.
    foreach ($rows as $row) {
      $overridesByLimit[$row['limit_id']] = array('minutes' => $row['minutes'], 'unlocked' => $row['unlocked']);
    }
    return $overridesByLimit;
  }

  // ---------- DEBUG/SPECIAL/DUBIOUS/OBNOXIOUS QUERIES ----------

  /**
   * Returns the sequence of window titles for the specified user and date. This will typically be
   * a long array and is intended for debugging.
   */
  public function queryTitleSequence($user, $fromTime) {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    $rows = DB::query(
        'SELECT ts, name, title FROM activity JOIN classes ON class_id = id
          WHERE user = %s
          AND ts >= %i
          AND ts < %i
          ORDER BY ts DESC',
        $user, $fromTime->getTimestamp(), $toTime->getTimestamp());
    foreach ($rows as $row) {
      // TODO: This should use the client's local time format.
      $windowTitles[] = array(
          date("Y-m-d H:i:s", $row['ts']),
          $row['name'],
          $row['title']);
    }
    return $windowTitles;
  }

  private function throwException($message) {
    Logger::Instance()->critical($message);
    throw new Exception($message);
  }

  /**
   * Returns epoch time in seconds. Allows manual dependency injection in test.
   *
   * TODO: It is probably cleaner to pass this in instead.
   */
  private function time() {
    return ($this->timeFunction)();
  }

  /**
   * Helper method to return a new DateTime object representing $this->time() in the server's
   * timezone.
   *
   * TODO: Timestamp for "now" should be set on construction, to ensure it remains constant during
   * the entire request. But that requires changing the code in the test that advances time.
   */
  private function newDateTime() {
    $d = new DateTime();
    $d->setTimestamp($this->time());
    return $d;
  }

}
