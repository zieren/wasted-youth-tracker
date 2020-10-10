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
      $this->throwMySqlErrorException('select_db('.DB_NAME.')');
    }
    if ($createMissingTables) {
      $this->createMissingTables();
    }
    // Configure global logger.
    $config = $this->getConfig();
    if (array_key_exists('s:log_level', $config)) {
      $this->log->setLogLevelThreshold(strtolower($config['s:log_level']));
    }  // else: defaults to debug
  }
  
  public function createMissingTables() {
    $this->query('SET default_storage_engine=INNODB');
    $this->query( // TODO: 256?
      'CREATE TABLE IF NOT EXISTS window_titles (ts BIGINT PRIMARY KEY, title VARCHAR(256))');
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
    $this->query('DELETE FROM window_titles WHERE start_ts < '.$timestamp);
  }
  
  public function insertWindowTitle($windowTitle) {
    $windowTitle = $this->mysqli->real_escape_string($windowTitle);
    $q = 'REPLACE INTO window_titles (ts, title) VALUES ('.time().',"'.$windowTitle.'")';
    $this->query($q);
  }
  
  // TODO: Remove.
  public function echoWindowTitles() {
    echo '<p><table border="1">';
    $q = 'SELECT ts, title FROM window_titles ORDER BY ts DESC';
    $result = $this->query($q);
    while ($row = $result->fetch_row()) {
      echo '<tr><td>'.$row[0].'</td><td>'.$row[1].'</td></tr>';
    }
    echo '</table></p>';
  }
  
  /** Updates the specified config value. */
  public function setConfig($key, $value) {
    $q = 'REPLACE INTO config (k, v) VALUES ("'.$key.'", "'.$value.'")';
    $this->query($q);
    unset($this->config);
  }
  
  /** Deletes the specified config value. */
  public function clearConfig($key) {
    $q = 'DELETE FROM config WHERE k="'.$key.'"';
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
      $q .= '("'.$key.'","'.$value.'")';
    }
    $q = 'INSERT IGNORE INTO config (k, v) VALUES '.$q;
    $this->query($q, 'notice');
    unset($this->config);
  }
  
  public function echoConfig() {
    echo '<p><table border="1">';
    foreach ($this->getConfig() as $k => $v) {
      echo '<tr><td>'.$k.'</td><td>'.$v.'</td></tr>';
    }
    echo '</table></p>';
  }
  
  /**
   * Runs the specified query, throwing an Exception on failure. Logs the query unconditionally with
   * the specified level (specify null to disable logging).
   */
  private function query($query, $logLevel = 'debug') {
    $this->log->log($logLevel, 'Query: '.$query);
    if ($result = $this->mysqli->query($query)) {
      return $result;
    }
    $this->throwMySqlErrorException($query);
  }
  
  private function throwMySqlErrorException($query) {
    $message = 'MySQL error '.$this->mysqli->errno.': '.$this->mysqli->error.' -- Query: '.$query;
    $this->throwException($message);
  }
  
  private function throwException($message) {
    $this->log->critical($message);
    throw new Exception($message);
  }
}
