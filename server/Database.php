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
    $config = $this->getConfig();
    if (array_key_exists('log_level', $config)) {
      $this->log->setLogLevelThreshold(strtolower($config['log_level']));
    }  // else: defaults to debug
  }

  public function createMissingTables() {
    // TODO: Tables should have user name.
    $this->query('SET default_storage_engine=INNODB');
    $this->query(// TODO: 256?
            'CREATE TABLE IF NOT EXISTS window_titles ('
            . 'user VARCHAR(32), '
            . 'ts BIGINT, '
            . 'title VARCHAR(256), '
            . 'PRIMARY KEY (user, ts)'
            . ')');
    $this->query(
            'CREATE TABLE IF NOT EXISTS config (k VARCHAR(200) PRIMARY KEY, v TEXT NOT NULL)');
  }

  public function dropTablesExceptConfig() {
    $this->query('DROP TABLE IF EXISTS window_titles');
    $this->log->notice('tables dropped');
    unset($this->config);
  }

  /** Delete all records prior to $timestamp. */
  public function pruneTables($timestamp) {
    $this->query('DELETE FROM window_titles WHERE start_ts < ' . $timestamp);
  }

  /** Stores the specified window title. */
  public function insertWindowTitle($user, $windowTitle) {
    $q = 'REPLACE INTO window_titles (ts, user, title) VALUES ('
            . time()
            . ',"' . $this->mysqli->real_escape_string($user) . '"'  // TODO: This is verbose
            . ',"' . $this->mysqli->real_escape_string($windowTitle) . '"'
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
    $result = $this->query('SELECT COUNT(*) FROM window_titles'
            . ' WHERE user = "' . $this->mysqli->real_escape_string($user) . '"'
            . ' AND ts >= ' . $fromTime->getTimestamp()
            . ' AND ts < ' . $toTime->getTimestamp());
    $interval = intval($this->getConfig()['sample_interval_seconds']);
    while ($row = $result->fetch_row()) {
      $seconds = $interval * $row[0];
      return $seconds / 60;
    }
    return 0;
  }

  // TODO: For testing...
  public function echoWindowTitles() {
    echo '<p><table border="1">';
    $q = 'SELECT user, ts, title FROM window_titles ORDER BY user ASC, ts DESC';
    $result = $this->query($q);
    while ($row = $result->fetch_row()) {
      // TODO: Format time.
      echo '<tr><td>' . $row[0]
      . '</td><td>' . date("Y-m-d H:i:s", $row[1])
      . '</td><td>' . $row[2] . '</td></tr>';
    }
    echo '</table></p>';
  }

  /** Updates the specified config value. */
  public function setConfig($key, $value) {
    $q = 'REPLACE INTO config (k, v) VALUES ("' . $key . '", "' . $value . '")';
    $this->query($q);
    unset($this->config);
  }

  /** Deletes the specified config value. */
  public function clearConfig($key) {
    $q = 'DELETE FROM config WHERE k="' . $key . '"';
    $this->query($q);
    unset($this->config);
  }

  /** Returns application config. Lazily initialized. */
  public function getConfig() {
    if (!isset($this->config)) {
      $this->config = array();
      $result = $this->query('SELECT k, v FROM config ORDER BY k ASC');
      while ($row = $result->fetch_assoc()) {
        $this->config[$row['k']] = $row['v'];
      }
    }
    return $this->config;
  }

  /** Populate config table from defaults in file, keeping existing values. */
  public function populateConfig() {
    $cfg = file(CONFIG_DEFAULT_FILENAME, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $q = '';
    foreach ($cfg as $line) {
      list($key, $value) = explode('=', $line);
      if ($q) {
        $q .= ',';
      }
      $q .= '("' . $key . '","' . $value . '")';
    }
    $q = 'INSERT IGNORE INTO config (k, v) VALUES ' . $q;
    $this->query($q, 'notice');
    unset($this->config);
  }

  public function echoConfig() {
    echo '<p><table border="1">';
    foreach ($this->getConfig() as $k => $v) {
      echo '<tr><td>' . $k . '</td><td>' . $v . '</td></tr>';
    }
    echo '</table></p>';
  }

  /**
   * Runs the specified query, throwing an Exception on failure. Logs the query unconditionally with
   * the specified level (specify null to disable logging).
   */
  private function query($query, $logLevel = 'debug') {
    $this->log->log($logLevel, 'Query: ' . $query);
    if (($result = $this->mysqli->query($query))) {
      return $result;
    }
    $this->throwMySqlErrorException($query);
  }

  private function throwMySqlErrorException($query) {
    $message = 'MySQL error ' . $this->mysqli->errno . ': ' . $this->mysqli->error . ' -- Query: ' . $query;
    $this->throwException($message);
  }

  private function throwException($message) {
    $this->log->critical($message);
    throw new Exception($message);
  }

}
