<?php

define('DAILY_LIMIT_MINUTES_PREFIX', 'daily_limit_minutes_');

class Database {

  /** Connects to the database, or exits on error. */
  public function __construct($createMissingTables = false) {
    $this->log = Logger::Instance();
    $this->mysqli = new mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME);
    if ($this->mysqli->connect_errno) {
      $this->throwMySqlErrorException('__construct()');
    }
    if (!$this->mysqli->select_db(DB_NAME)) {
      $this->throwMySqlErrorException('select_db(' . DB_NAME . ')');
    }
    if ($createMissingTables) {
      $this->createMissingTables();
    }
    // Configure global logger.
    $config = $this->getGlobalConfig();
    if (array_key_exists('log_level', $config)) {
      $this->log->setLogLevelThreshold(strtolower($config['log_level']));
    }  // else: defaults to debug
  }

  // ---------- TABLE MANAGEMENT ----------

  public function createMissingTables() {
    $this->query('SET default_storage_engine=INNODB');
    $this->query(
            'CREATE TABLE IF NOT EXISTS activity ('
            . 'user VARCHAR(32) NOT NULL, '
            . 'ts BIGINT NOT NULL, '
            . 'title VARCHAR(256) NOT NULL, '
            . 'PRIMARY KEY (user, ts))');
    $this->query(
            'CREATE TABLE IF NOT EXISTS user_config ('
            . 'user VARCHAR(32) NOT NULL, '
            . 'k VARCHAR(200) NOT NULL, '
            . 'v TEXT NOT NULL, '
            . 'PRIMARY KEY (user, k))');
    $this->query(
            'CREATE TABLE IF NOT EXISTS global_config ('
            . 'k VARCHAR(200) NOT NULL, '
            . 'v TEXT NOT NULL, '
            . 'PRIMARY KEY (k))');
    $this->query(
            'CREATE TABLE IF NOT EXISTS overrides ('
            . 'user VARCHAR(32) NOT NULL, '
            . 'date DATE NOT NULL, '
            . 'minutes INT, '
            . 'unlocked BOOL, '
            . 'PRIMARY KEY (user, date))');
  }

  public function dropAllTablesExceptConfig() {
    $this->query('DROP TABLE IF EXISTS activity');
    $this->query('DROP TABLE IF EXISTS overrides');
    $this->log->notice('tables dropped');
  }

  /** Delete all records prior to DateTime $date. */
  public function pruneTables($date) {
    $this->query('DELETE FROM activity WHERE ts < ' . $date->getTimestamp());
    $this->query('DELETE FROM overrides WHERE date < "' . getDateString($date) . '"');
    $this->log->notice('tables pruned up to ' . $date->format(DateTimeInterface::ATOM));
  }

  // ---------- WRITE ACTIVITY QUERIES ----------

  /** Stores the specified window title. */
  public function insertWindowTitle($user, $windowTitle) {
    $q = 'REPLACE INTO activity (ts, user, title) VALUES ('
            . time()
            . ',"' . $this->esc($user) . '"'
            . ',"' . $this->esc($windowTitle) . '")';
    $this->query($q);
  }

  // ---------- CONFIG QUERIES ----------
  // TODO: Reject invalid values like '"'.

  /** Updates the specified user config value. */
  public function setUserConfig($user, $key, $value) {
    $q = 'REPLACE INTO user_config (user, k, v) VALUES ("'
            . $user . '", "' . $key . '", "' . $value . '")';
    $this->query($q);
  }

  /** Updates the specified global config value. */
  public function setGlobalConfig($key, $value) {
    $q = 'REPLACE INTO global_config (k, v) VALUES ("' . $key . '", "' . $value . '")';
    $this->query($q);
  }

  /** Deletes the specified user config value. */
  public function clearUserConfig($user, $key) {
    $q = 'DELETE FROM user_config WHERE user="' . $user . '" AND k="' . $key . '"';
    $this->query($q);
  }

  /** Deletes the specified global config value. */
  public function clearGlobalConfig($key) {
    $q = 'DELETE FROM global_config WHERE k="' . $key . '"';
    $this->query($q);
  }

  // TODO: Consider caching the config(s).

  /** Returns user config. */
  public function getUserConfig($user) {
    $result = $this->query('SELECT k, v FROM user_config WHERE user="'
            . $user . '" ORDER BY k');
    $config = array();
    while ($row = $result->fetch_assoc()) {
      $config[$row['k']] = $row['v'];
    }
    return $config;
  }

  /** Returns the config for all users. */
  public function getAllUsersConfig() {
    $result = $this->query('SELECT user, k, v FROM user_config ORDER BY user, k');
    return $result->fetch_all();
  }

  /** Returns the global config. */
  public function getGlobalConfig() {
    $result = $this->query('SELECT k, v FROM global_config ORDER BY k');
    return $result->fetch_all();
  }

  /** Returns all users, i.e. all distinct user keys present in the config. */
  public function getUsers() {
    $result = $this->query('SELECT DISTINCT user FROM user_config ORDER BY user ASC');
    $users = array();
    while ($row = $result->fetch_row()) {
      $users[] = $row[0];
    }
    return $users;
  }

  // ---------- TIME SPENT/LEFT QUERIES ----------

  /**
   * Returns the time spent between $fromTime and $toTime, as a sparse array keyed by date. $toTime
   * may be null to omit the upper limit.
   */
  public function queryMinutesSpentByDate($user, $fromTime, $toTime) {
    $config = $this->getUserConfig($user);
    $q = 'SET @prev_ts := 0;'
            . ' SELECT date, SUM(s) / 60 '
            . ' FROM ('
            . '   SELECT'
            . '     if (@prev_ts = 0, 0, ts - @prev_ts) as s,'
            . '     @prev_ts := ts,'
            . '     DATE_FORMAT(FROM_UNIXTIME(ts), "%Y-%m-%d") as date'
            . '   FROM activity'
            . '   WHERE'
            . '     user = "' . $this->esc($user) . '"'
            . '     AND ts >= ' . $fromTime->getTimestamp()
            . ($toTime ?
              '     AND ts < ' . $toTime->getTimestamp()
            : '')
            . ' ) t1'
            . ' WHERE s <= ' . ($config['sample_interval_seconds'] + 10) // TODO: 10 magic
            . ' GROUP BY date';
    $this->multiQuery($q);
    $result = $this->multiQueryGetNextResult();
    $minutesByDay = array();
    while ($row = $result->fetch_row()) {
      $minutesByDay[$row[0]] = $row[1];
    }
    $result->close();
    return $minutesByDay;
  }

  /**
   * Returns the time spent by window title, ordered by the amount of time, starting at $fromTime
   * and ending 1d (i.e. usually 24h) later. $date should therefore usually have a time of 0:00.
   */
  public function queryTimeSpentByTitle($user, $fromTime) {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    $config = $this->getUserConfig($user);
    // TODO: Remove "<init>" placeholder.
    $q = 'SET @prev_ts := 0, @prev_title := "<init>";'
            // TODO: Should the client handle SEC_TO_TIME?
            . ' SELECT ts, SEC_TO_TIME(SUM(s)) as total, title '
            . ' FROM ('
            . '   SELECT'
            . '     ts,'
            . '     if (@prev_ts = 0, 0, ts - @prev_ts) as s,'
            . '     @prev_title as title,'
            . '     @prev_ts := ts,'
            . '     @prev_title := title'
            . '   FROM activity'
            . '   WHERE'
            . '     user = "' . $this->esc($user) . '"'
            . '     AND ts >= ' . $fromTime->getTimestamp()
            . '     AND ts < ' . $toTime->getTimestamp()
            . '   ORDER BY ts ASC'
            . ' ) t1'
            . ' WHERE s <= ' . ($config['sample_interval_seconds'] + 10) // TODO: 10 magic
            . ' AND s > 0'
            . ' GROUP BY title'
            . ' ORDER BY total DESC';
    $this->multiQuery($q);
    $result = $this->multiQueryGetNextResult();
    $timeByTitle = array();
    while ($row = $result->fetch_assoc()) {
      // TODO: This should use the client's local time format.
      $timeByTitle[] = array(
          date("Y-m-d H:i:s", $row['ts']),
          $row['total'],
          htmlentities($row['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8'));
    }
    $result->close();
    return $timeByTitle;
  }

  /**
   * Returns the minutes left today. In order of decreasing priority, this considers the unlock
   * requirement, an override limit, the limit configured for the day of the week, and the default
   * daily limit. For the last two, a possible weekly limit is additionally applied.
   */
  public function queryMinutesLeftToday($user) {
    $config = $this->getUserConfig($user);
    $requireUnlock = get($config['require_unlock'], false);
    $now = new DateTime();
    $nowString = getDateString($now);
    $weekStart = getWeekStart($now);
    $minutesSpentByDate = $this->queryMinutesSpentByDate($user, $weekStart, null);
    $minutesSpentToday = get($minutesSpentByDate[$nowString], 0);

    // Explicit overrides have highest priority.
    $result = $this->query('SELECT minutes, unlocked FROM overrides'
            . ' WHERE user="' . $user . '"'
            . ' AND date="' . $nowString . '"');
    // We may have "minutes" and/or "unlocked", so check both.
    if ($row = $result->fetch_assoc()) {
      if ($requireUnlock && $row['unlocked'] != 1) {
        return 0;
      }
      if (isset($row['minutes'])) {
        return $row['minutes'] - $minutesSpentToday;
      }
    } else if ($requireUnlock) {
      return 0;
    }

    $minutesLimitToday = get($config[DAILY_LIMIT_MINUTES_PREFIX . 'default'], 0);
    // Weekday-specific limit overrides default limit.
    $key = DAILY_LIMIT_MINUTES_PREFIX . strtolower($now->format('D'));
    if (isset($config[$key])) {
      $minutesLimitToday = $config[$key];
    }

    $minutesLeftToday = $minutesLimitToday - $minutesSpentToday;

    // A weekly limit can shorten the daily limit, but not extend it.
    if (isset($config['weekly_limit_minutes'])) {
      $minutesLeftInWeek = $config['weekly_limit_minutes'] - array_sum($minutesSpentByDate);
      $minutesLeftToday = min($minutesLeftToday, $minutesLeftInWeek);
    }

    return $minutesLeftToday;
  }

  // ---------- OVERRIDE QUERIES ----------

  /** Overrides the minutes limit for $date, which is a String in the format 'YYYY-MM-DD'. */
  public function setOverrideMinutes($user, $date, $minutes) {
    $this->query('INSERT INTO overrides SET'
            . ' user="' . $this->esc($user) . '", date="' . $date . '", minutes=' . $minutes
            . ' ON DUPLICATE KEY UPDATE minutes=' . $minutes);
  }

  /** Unlocks the specified $date, which is a String in the format 'YYYY-MM-DD'. */
  public function setOverrideUnlock($user, $date) {
    $this->query('INSERT INTO overrides SET'
            . ' user="' . $this->esc($user) . '", date="' . $date . '", unlocked=1'
            . ' ON DUPLICATE KEY UPDATE unlocked=1');
  }

  /**
   * Clears all overrides (minutes and unlock) for $date, which is a String in the format
   * 'YYYY-MM-DD'.
   */
  public function clearOverride($user, $date) {
    $this->query('DELETE FROM overrides'
            . ' WHERE user="' . $this->esc($user) . '" AND date="' . $date . '"');
  }

  /** Returns all overrides for the specified user, starting the week before the current week. */
  // TODO: Allow setting the date range.
  public function queryOverrides($user) {
    $fromDate = getWeekStart(new DateTime());
    $fromDate->sub(new DateInterval('P1W'));
    $result = $this->query('SELECT user, date,'
            . ' CASE WHEN minutes IS NOT NULL THEN minutes ELSE "default" END,'
            . ' CASE WHEN unlocked = 1 THEN "unlocked" ELSE "default" END'
            . ' FROM overrides'
            . ' WHERE user="' . $user . '"'
            . ' AND date >= "' . $fromDate->format('Y-m-d') . '"'
            . ' ORDER BY date ASC');
    return $result->fetch_all();
  }

  // ---------- DEBUG/SPECIAL/DUBIOUS/OBNOXIOUS QUERIES ----------

  /**
   * Returns the sequence of window titles for the specified user and date. This will typically be
   * a long array and is intended for debugging.
   */
  public function queryTitleSequence($user, $fromTime) {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    $q = 'SELECT ts, title FROM activity'
            . ' WHERE user = "' . $this->esc($user) . '"'
            . ' AND ts >= ' . $fromTime->getTimestamp()
            . ' AND ts < ' . $toTime->getTimestamp()
            . ' ORDER BY ts DESC';
    $result = $this->query($q);
    $windowTitles = array();
    while ($row = $result->fetch_assoc()) {
      // TODO: This should use the client's local time format.
      // TODO: Titles are in UTF-8 format
      $windowTitles[] = array(date("Y-m-d H:i:s", $row['ts']), $row['title']);
    }
    return $windowTitles;
  }

  // ---------- MYSQL LOW LEVEL HELPERS ----------

  /**
   * Runs the specified query, throwing an exception on failure. Logs the query unconditionally with
   * the specified level (specify null to disable logging).
   */
  private function query($query, $logLevel = 'debug') {
    $this->log->log($logLevel, 'Query: ' . $query);
    if ($result = $this->mysqli->query($query)) {
      return $result;
    }
    $this->throwMySqlErrorException($query);
  }

  /**
   * Like query() above, but using mysqli::multi_query. Returns the first query result, throwing an
   * exception on failure. Subsequent results are available via multiQueryGetNextResult() below.
   */
  private function multiQuery($multiQuery, $logLevel = 'debug') {
    $this->log->log($logLevel, 'Multi query: ' . $multiQuery);
    $this->multiQuery = $multiQuery;
    if (!$this->mysqli->multi_query($multiQuery)) {
      $this->throwMySqlErrorException($multiQuery);
    }
    return $this->mysqli->use_result();
  }

  /**
   * Returns the second, third etc. result from calling multiQuery() above, throwing an exception on
   * failure.
   */
  private function multiQueryGetNextResult() {
    if (!$this->mysqli->next_result()) {
      $this->throwMySqlErrorException($this->multiQuery);
    }
    return $this->mysqli->use_result();
  }

  private function throwMySqlErrorException($query) {
    $message = 'MySQL error ' . $this->mysqli->errno . ': ' . $this->mysqli->error . ' -- Query: ' . $query;
    $this->throwException($message);
  }

  private function throwException($message) {
    $this->log->critical($message);
    throw new Exception($message);
  }

  /** Escapes the specified String for MySQL use. */
  private function esc($s) {
    // This needs the instance because it considers the character set of the connection.
    return $this->mysqli->real_escape_string($s);
  }

}
