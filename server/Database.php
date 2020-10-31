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
            . 'enabled BOOL, '
            . 'PRIMARY KEY (user, date))');
  }

  public function dropTablesExceptConfig() {
    $this->query('DROP TABLE IF EXISTS activity');
    $this->log->notice('tables dropped');
  }

  /** Delete all records prior to $timestamp. */
  public function pruneTables($timestamp) {
    $this->query('DELETE FROM activity WHERE start_ts < ' . $timestamp);
  }

  /** Stores the specified window title. */
  public function insertWindowTitle($user, $windowTitle) {
    $q = 'REPLACE INTO activity (ts, user, title) VALUES ('
            . time()
            . ',"' . $this->esc($user) . '"'
            . ',"' . $this->esc($windowTitle) . '"'
            . ')';
    $this->query($q);
  }

  /**
   * Compute the time spent on the current calendar day, in minutes.
   *
   * Caveat: This will yield incorrect results when changing the sampling interval while or after
   * data has been stored.
   */
  public function queryMinutesSpent($user, $date) {
    $fromTime = clone $date;
    $toTime = (clone $date)->add(new DateInterval('P1D'));
    $config = $this->getUserConfig($user);
    $q = 'SET @prev_ts := 0;'
            . ' SELECT SUM(s) '
            . ' FROM ('
            . '   SELECT'
            . '     if (@prev_ts = 0, 0, ts - @prev_ts) as s,'
            . '     @prev_ts := ts'
            . '   FROM activity'
            . '   WHERE'
            . '     user = "' . $this->esc($user) . '"'
            . '     AND ts >= ' . $fromTime->getTimestamp()
            . '     AND ts < ' . $toTime->getTimestamp()
            . ' ) t1'
            . ' WHERE s <= ' . ($config['sample_interval_seconds'] + 10); // TODO 10 magic
    $this->multiQuery($q);
    $this->mysqli->next_result();
    $result = $this->mysqli->use_result();
    if ($row = $result->fetch_row()) {
      return $row[0] / 60.0;
    }
    return 0;
  }

  /* Returns the time spent by window title, ordered by the amount of time. */
  public function queryTimeSpentByTitle($user, $date) {
    $fromTime = clone $date;
    $toTime = (clone $date)->add(new DateInterval('P1D'));
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
            . ' WHERE s <= ' . ($config['sample_interval_seconds'] + 10) // TODO 10 magic
            . ' GROUP BY title'
            . ' ORDER BY total DESC';
    $this->multiQuery($q);
    $this->mysqli->next_result();
    $result = $this->mysqli->use_result();
    $timeByTitle = array();
    while ($row = $result->fetch_row()) {
      // TODO: This should use the client's local time format.
      $timeByTitle[] = array(date("Y-m-d H:i:s", $row[0]), $row[1],
          htmlentities($row[2], ENT_COMPAT | ENT_HTML401, "Windows-1252"));
    }
    $result->close();
    return $timeByTitle;
  }

  /**
   * Returns the sequence of window titles for the specified user and date. This will typically be
   * a long array and is intended for debugging.
   */
  public function queryAllTitles($user, $date) {
    $fromTime = clone $date;
    $toTime = (clone $date)->add(new DateInterval('P1D'));
    $q = 'SELECT ts, title FROM activity'
            . ' WHERE user = "' . $this->esc($user) . '"'
            . ' AND ts >= ' . $fromTime->getTimestamp()
            . ' AND ts < ' . $toTime->getTimestamp()
            . ' ORDER BY ts DESC';
    $result = $this->query($q);
    $windowTitles = array();
    while ($row = $result->fetch_row()) {
      // TODO: This should use the client's local time format.
      $windowTitles[] = array(date("Y-m-d H:i:s", $row[0]), $row[1]);
    }
    return $windowTitles;
  }

  // TODO: Reject invalid values like '"'.

  /** Updates the specified user config value. */
  public function setUserConfig($user, $key, $value) {
    $q = 'REPLACE INTO user_config (user, k, v) VALUES ("'
            . $user . '", "'
            . $key . '", "'
            . $value . '")';
    $this->query($q);
  }

  /** Updates the specified globalconfig value. */
  public function setGlobalConfig($key, $value) {
    $q = 'REPLACE INTO global_config (k, v) VALUES ("'
            . $key . '", "'
            . $value . '")';
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

  // TODO: Consider caching frequently used configs.

  /** Returns user config. */
  public function getUserConfig($user) {
    $result = $this->query('SELECT k, v FROM user_config WHERE user="'
            . $user . '" ORDER BY k ASC');
    $config = array();
    while ($row = $result->fetch_assoc()) {
      $config[$row['k']] = $row['v'];
    }
    return $config;
  }

  /** Returns global config. */
  public function getGlobalConfig() {
    $result = $this->query('SELECT k, v FROM global_config ORDER BY k ASC');
    $config = array();
    while ($row = $result->fetch_assoc()) {
      $config[$row['k']] = $row['v'];
    }
    return $config;
  }

  // TODO: Add weekly limit (and option whether that includes overrides).
  public function queryMinutesLeft($user) {
    // Explicit overrides have highest priority.
    $now = new DateTime();
    $result = $this->query('SELECT minutes FROM overrides'
            . ' WHERE user="' . $user . '"'
            . ' AND date="' . $now->format('Y-m-d') . '"');
    if ($row = $result->fetch_row()) {
      return $row[0];
    }
    // Next: Weekday specific default.
    $config = $this->getUserConfig($user);
    $key = DAILY_LIMIT_MINUTES_PREFIX . strtolower($now->format('D'));
    if (isset($config[$key])) {
      return $config[$key];
    }
    // Next: Global default.
    return get($config[DAILY_LIMIT_MINUTES_PREFIX . 'default'], 0);
  }

  /** Populate config table from defaults in file, keeping existing values. */
  /*
    public function populateUserConfig($user) {
    $cfg = file(CONFIG_DEFAULT_FILENAME, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $q = '';
    foreach ($cfg as $line) {
    list($key, $value) = explode('=', $line);
    if ($q) {
    $q .= ',';
    }
    $q .= '("' . $user . '","' . $key . '","' . $value . '")';
    }
    $q = 'INSERT IGNORE INTO config (user, k, v) VALUES ' . $q;
    $this->query($q, 'notice');
    }
   */

  public function getUsers() {
    $result = $this->query('SELECT DISTINCT user FROM user_config ORDER BY user ASC');
    $users = array();
    while ($row = $result->fetch_row()) {
      $users[] = $row[0];
    }
    return $users;
  }

  public function queryConfigAllUsers() {
    $result = $this->query('SELECT user, k, v FROM user_config ORDER BY user, k');
    return $result->fetch_all();
  }

  public function queryConfigGlobal() {
    $result = $this->query('SELECT k, v FROM global_config ORDER BY k');
    return $result->fetch_all();
  }

  /** $date is a String in the format 'YYYY-MM-DD'. */
  public function setOverrideMinutes($user, $date, $minutes) {
    $this->query('INSERT INTO overrides SET'
            .' user="'.$this->esc($user).'", date="'.$date.'", minutes='.$minutes
            .' ON DUPLICATE KEY UPDATE minutes='.$minutes);
  }

  /** $date is a String in the format 'YYYY-MM-DD'. */
  public function setOverrideUnlock($user, $date) {
    $this->query('INSERT INTO overrides SET'
            . ' user="'.$this->esc($user).'", date="'.$date.'", enabled=1'
            . ' ON DUPLICATE KEY UPDATE enabled=1');
  }

  /** $date is a String in the format 'YYYY-MM-DD'. */
  public function clearOverride($user, $date) {
    $this->query('DELETE FROM overrides '
            . ' WHERE user="'.$this->esc($user).'" AND date="'.$date.'"');
  }

  // TODO: Allow setting the date range. For now this covers last week until 20 entries in the
  // future.
  public function queryOverrides($user) {
    $fromDate = new DateTime();
    // TODO: This assumes the week starts on Monday.
    $dayOfWeek = ($fromDate->format('w') + 6) % 7;
    $fromDate->sub(new DateInterval('P' . ($dayOfWeek + 7) . 'D'));
    $toDate = clone $fromDate;
    $toDate->add(new DateInterval('P4W'));
    $result = $this->query('SELECT user, date, minutes, enabled FROM overrides'
            . ' WHERE user="' . $user . '"'
            . ' AND date >= "' . $fromDate->format('Y-m-d') . '"'
            . ' ORDER BY date ASC');
    return $result->fetch_all();
  }

  /**
   * Runs the specified query, throwing an Exception on failure. Logs the query unconditionally with
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
   * TODO
   */
  private function multiQuery($multiQuery, $logLevel = 'debug') {
    $this->log->log($logLevel, 'Multi query: ' . $multiQuery);
    if (!$this->mysqli->multi_query($multiQuery)) {
      $this->throwMySqlErrorException($multiQuery);
    }
  }

  private function throwMySqlErrorException($query) {
    $message = 'MySQL error ' . $this->mysqli->errno . ': ' . $this->mysqli->error . ' -- Query: ' . $query;
    $this->throwException($message);
  }

  private function throwException($message) {
    $this->log->critical($message);
    throw new Exception($message);
  }

  private function esc($s) {
    return $this->mysqli->real_escape_string($s);
  }

}
