<?php

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
            . 'user VARCHAR(32), '
            . 'ts BIGINT, '
            . 'title VARCHAR(256), '
            . 'PRIMARY KEY (user, ts))');
    $this->query(
            'CREATE TABLE IF NOT EXISTS user_config ('
            . 'user VARCHAR(32), '
            . 'k VARCHAR(200), '
            . 'v TEXT NOT NULL, '
            . 'PRIMARY KEY (user, k))');
    $this->query(
            'CREATE TABLE IF NOT EXISTS global_config ('
            . 'k VARCHAR(200), '
            . 'v TEXT NOT NULL, '
            . 'PRIMARY KEY (k))');
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
  public function getMinutesSpentToday($user) {
    $fromTime = (new DateTimeImmutable())->setTime(0, 0);
    $toTime = $fromTime->add(new DateInterval('P1D'));
    $result = $this->query('SELECT COUNT(*) FROM activity'
            . ' WHERE user = "' . $this->esc($user) . '"'
            . ' AND ts >= ' . $fromTime->getTimestamp()
            . ' AND ts < ' . $toTime->getTimestamp());
    if ($row = $result->fetch_row()) {
      $interval = intval($this->getConfig()['sample_interval_seconds']);
      $seconds = $interval * $row[0];
      return $seconds / 60;
    }
    return 0;
  }

  public function echoTimeSpentByTitleToday($user) {
    $fromTime = (new DateTimeImmutable())->setTime(0, 0);
    $toTime = $fromTime->add(new DateInterval('P1D'));
    $config = $this->getUserConfig($user);
    $q = 'SET @prev_ts := 0, @prev_title := "<init>";'
            . ' SELECT ts, SEC_TO_TIME(SUM(s)), t '
            . ' FROM ('
            . '   SELECT'
            . '     ts,'
            . '     if (@prev_ts = 0, 0, ts - @prev_ts) as s,'
            . '     @prev_title as t,'
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
            . ' GROUP BY t'
            . ' ORDER BY ts DESC';
    $this->multiQuery($q);
    $this->mysqli->next_result();
    $result = $this->mysqli->use_result();
    echo '<table>';
    while ($row = $result->fetch_row()) {
      echo '<tr><td>'
      . date("Y-m-d H:i:s", $row[0])
      . '</td><td>' . $row[1]
      . '</td><td>' . htmlentities($row[2], ENT_COMPAT | ENT_HTML401, "Windows-1252")
      . '</td></tr>' . "\n";
    }
    echo '</table>';
    $result->close();
  }

  // TODO: For testing...
  public function echoWindowTitles($user) {
    $q = 'SELECT ts, title FROM activity'
            . ' WHERE user = "' . $this->esc($user)
            . '" ORDER BY ts DESC LIMIT 100';
    $result = $this->query($q);
    echo '<p><table border="1">';
    while ($row = $result->fetch_row()) {
      echo
      '</td><td>' . date("Y-m-d H:i:s", $row[0])
      . '</td><td>' . $row[1] . '</td></tr>';
    }
    echo '</table></p>';
  }

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
    $users = array();
    $result = $this->query('SELECT DISTINCT user FROM user_config');
    while ($row = $result->fetch_row()) {
      $users[] = $row[0];
    }
    return $users;
  }

  public function echoUserConfig() {
    $result = $this->query('SELECT user, k, v FROM user_config ORDER BY user, k');
    echo '<p><table border="1">';
    while ($row = $result->fetch_assoc()) {
      echo '<tr><td>' . $row['user'] . '</td><td>'
      . $row['k'] . '</td><td>'
      . $row['v'] . '</td></tr>';
    }
    echo '</table></p>';
  }

  public function echoGlobalConfig() {
    $result = $this->query('SELECT k, v FROM global_config ORDER BY k');
    echo '<p><table border="1">';
    while ($row = $result->fetch_assoc()) {
      echo '<tr><td>'
      . $row['k'] . '</td><td>'
      . $row['v'] . '</td></tr>';
    }
    echo '</table></p>';
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
