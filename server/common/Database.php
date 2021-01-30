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

  // TODO: Consider FOREIGN KEY.

  public function createMissingTables() {
    $this->query('SET default_storage_engine=INNODB');
    $this->query(
            'CREATE TABLE IF NOT EXISTS activity ('
            . 'user VARCHAR(32) NOT NULL, '
            . 'ts BIGINT NOT NULL, '
            . 'budget_id INT NOT NULL, '
            . 'title VARCHAR(256) NOT NULL, '
            . 'PRIMARY KEY (user, ts))');  // ON DELETE CASCADE?
    $this->query(
            'CREATE TABLE IF NOT EXISTS budget ('
            . 'id INT NOT NULL AUTO_INCREMENT, '
            . 'name VARCHAR(100) NOT NULL, '
            . 'priority INT NOT NULL, '
            . 'PRIMARY KEY (id))');
    // TODO: Insert synthetic budget DEFAULT_BUDGET_NAME with id=0 and minimum priority into "budget"
    // (matching expressions are not required). Will be needed when showing budget names.
    $this->query(
            'CREATE TABLE IF NOT EXISTS budget_definition ('
            . 'budget_id INT NOT NULL, '
            . 'budget_re VARCHAR(1024) NOT NULL, '
            . 'FOREIGN KEY (budget_id) REFERENCES budget(id) ON DELETE RESTRICT)');
    $this->query(
            'CREATE TABLE IF NOT EXISTS budget_config ('
            . 'budget_id INT NOT NULL, '
            . 'user VARCHAR(32) NOT NULL, '
            . 'k VARCHAR(100) NOT NULL, '
            . 'v VARCHAR(200) NOT NULL, '
            . 'PRIMARY KEY (budget_id, user, k), '
            . 'FOREIGN KEY (budget_id) REFERENCES budget(id) ON DELETE RESTRICT)');
    $this->query(
            'CREATE TABLE IF NOT EXISTS user_config ('
            . 'user VARCHAR(32) NOT NULL, '
            . 'k VARCHAR(100) NOT NULL, '
            . 'v VARCHAR(200) NOT NULL, '
            . 'PRIMARY KEY (user, k))');
    $this->query(
            'CREATE TABLE IF NOT EXISTS global_config ('
            . 'k VARCHAR(100) NOT NULL, '
            . 'v VARCHAR(200) NOT NULL, '
            . 'PRIMARY KEY (k))');
    $this->query(
            'CREATE TABLE IF NOT EXISTS overrides ('
            . 'user VARCHAR(32) NOT NULL, '
            . 'date DATE NOT NULL, '
            . 'budget_id INT NOT NULL, '
            . 'minutes INT, '
            . 'unlocked BOOL, '
            . 'PRIMARY KEY (user, date, budget_id))');
  }

  public function dropAllTablesExceptConfig() {
    $this->throwException("dropAllTablesExceptConfig() not implemented");
    // TODO: Update for new tables.
    $this->query('DROP TABLE IF EXISTS activity');
    $this->query('DROP TABLE IF EXISTS overrides');
    $this->log->notice('tables dropped');
  }

  /** Delete all records prior to DateTime $date. */
  public function pruneTables($date) {
    $this->throwException("pruneTables() not implemented");
    // TODO: Update for new tables.
    $this->query('DELETE FROM activity WHERE ts < ' . $date->getTimestamp());
    $this->query('DELETE FROM overrides WHERE date < "' . getDateString($date) . '"');
    $this->log->notice('tables pruned up to ' . $date->format(DateTimeInterface::ATOM));
  }

  // ---------- BUDGET QUERIES ----------

  // TODO

  /**
   * Returns an array containing ID and name of the matching budget with the highest priority.
   * If no defined budget matches, returns the default budget id (zero) and name.
   */
  private function getBudget($user, $windowTitle) {
    $q = 'SELECT budget.id, budget.name'
      . ' FROM budget_config'
      . ' JOIN budget_definition ON budget_config.budget_id = budget_definition.budget_id'
      . ' JOIN budget ON budget_config.budget_id = budget.id'
      . ' WHERE user = "' . $this->esc($user) . '" AND k = "enabled" AND v = "1"'
      . ' AND "' . $this->esc($windowTitle) . '" REGEXP budget_re'
      . ' ORDER BY priority DESC'
      . ' LIMIT 1';
    $result = $this->query($q);
    if ($row = $result->fetch_assoc()) {
      return array($row['id'], $row['name']);
    }
    return array("0", DEFAULT_BUDGET_NAME); // synthetic catch-all budget
  }

  // TODO: Decide how to treat disabled (non-enabled!?) budgets. Then check callers.

  /** Returns budget config. */
  public function getBudgetConfig($user, $budgetId) {
    $result = $this->query('SELECT k, v FROM budget_config'
            . ' WHERE user="' . $user . '" AND budget_id="' . $budgetId . '"'
            . ' ORDER BY k');
    $config = array();
    while ($row = $result->fetch_assoc()) {
      $config[$row['k']] = $row['v'];
    }
    return $config;
  }

  /**
   * Returns all budget configs that have at least one key for the specified user. The virtual key
   * 'name' is populated with the budget's name.
   */
  public function getAllBudgetConfigs($user) {
    // TODO: Consider caching this.
    $result = $this->query(
            'SELECT id, name, k, v'
            . ' FROM budget_config'
            . ' JOIN budget ON budget_id = id'
            . ' WHERE user="' . $user . '"'
            . ' ORDER BY id, k');
    $configs = array();
    while ($row = $result->fetch_assoc()) {
      $budgetId = $row['id'];
      if (!array_key_exists($budgetId, $configs)) {
        $configs[$budgetId] = array();
      }
      $configs[$budgetId][$row['k']] = $row['v'];
      $configs[$budgetId]['name'] = $row['name'];
    }
    return $configs;
  }

  // ---------- WRITE ACTIVITY QUERIES ----------

  /** Stores the specified window title. Returns the matched budget ID and name. */
  public function insertWindowTitle($user, $windowTitle) {
    list($budgetId, $budgetName) = $this->getBudget($user, $windowTitle);
    $q = 'REPLACE INTO activity (ts, user, title, budget_id) VALUES ('
            . time()
            . ',"' . $this->esc($user) . '"'
            . ',"' . $this->esc($windowTitle) . '"'
            . ',"' . $budgetId . '")';
    $this->query($q);
    return array($budgetId, $budgetName);
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
   * Returns the time spent between $fromTime and $toTime, as an array keyed by date and budget ID.
   * $toTime may be null to omit the upper limit.
   */
  public function queryMinutesSpentByBudgetAndDate($user, $fromTime, $toTime) {
    $q = 'SET @prev_ts := 0;'
            . ' SELECT date, budget_id, SUM(s) / 60 AS minutes'
            . ' FROM ('
            . '   SELECT'
            . '     IF (@prev_ts = 0, 0, ts - @prev_ts) AS s,'
            . '     @prev_ts := ts,'
            . '     DATE_FORMAT(FROM_UNIXTIME(ts), "%Y-%m-%d") AS date,'
            . '     budget_id'
            . '   FROM activity'
            . '   WHERE'
            . '     user = "' . $this->esc($user) . '"'
            . '     AND ts >= ' . $fromTime->getTimestamp()
            . ($toTime ?
              '     AND ts < ' . $toTime->getTimestamp()
            : '')
            . ' ) t1'
            . ' WHERE s <= 25' // TODO: 15 (sample interval) + 10 (latency compensation) magic
            . ' GROUP BY date, budget_id'
            . ' ORDER BY minutes DESC';
    $this->multiQuery($q);
    $result = $this->multiQueryGetNextResult();
    $minutesByBudgetAndDate = array();
    while ($row = $result->fetch_assoc()) {
      $date = $row['date'];
      $budgetId = $row['budget_id'];
      $minutes = $row['minutes'];
      if (!array_key_exists($budgetId, $minutesByBudgetAndDate)) {
        $minutesByBudgetAndDate[$budgetId] = array();
      }
      $minutesByBudgetAndDate[$budgetId][$date] = $minutes;
    }
    $result->close();
    ksort($minutesByBudgetAndDate, SORT_NUMERIC);
    return $minutesByBudgetAndDate;
  }

  /**
   * Returns the time spent by window title and budget ID, ordered by the amount of time, starting
   * at $fromTime and ending 1d (i.e. usually 24h) later. $date should therefore usually have a time
   * of 0:00.
   */
  public function queryTimeSpentByTitle($user, $fromTime) {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    // TODO: Remove "<init>" placeholder.
    $q = 'SET @prev_ts := 0, @prev_id := 0, @prev_title := "<init>";'
            // TODO: Should the client handle SEC_TO_TIME?
            . ' SELECT ts, SEC_TO_TIME(SUM(s)) as total, id, title '
            . ' FROM ('
            . '   SELECT'
            . '     ts,'
            . '     if (@prev_ts = 0, 0, ts - @prev_ts) as s,'
            . '     @prev_id as id,'
            . '     @prev_title as title,'
            . '     @prev_ts := ts,'
            . '     @prev_id := budget_id,'
            . '     @prev_title := title'
            . '   FROM activity'
            . '   WHERE'
            . '     user = "' . $this->esc($user) . '"'
            . '     AND ts >= ' . $fromTime->getTimestamp()
            . '     AND ts < ' . $toTime->getTimestamp()
            . '   ORDER BY ts ASC'
            . ' ) t1'
            . ' WHERE s <= 25' // TODO: 15 (sample interval) + 10 (latency compensation) magic
            . ' AND s > 0'
            . ' GROUP BY title, id'
            . ' ORDER BY total DESC';
    $this->multiQuery($q);
    $result = $this->multiQueryGetNextResult();
    $timeByTitle = array();
    while ($row = $result->fetch_assoc()) {
      // TODO: This should use the client's local time format.
      $timeByTitle[] = array(
          date("Y-m-d H:i:s", $row['ts']),
          $row['total'],
          $row['id'],
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
  public function queryMinutesLeftToday($user, $budgetId) {
    $config = $this->getBudgetConfig($user, $budgetId);
    $now = new DateTime();

    $overrides = $this->queryOverrides($user, $budgetId, $now);

    $minutesSpentByBudgetAndDate =
        $this->queryMinutesSpentByBudgetAndDate($user, getWeekStart($now), null);
    $minutesSpentByDate = get($minutesSpentByBudgetAndDate[$budgetId], array());

    return $this->computeMinutesLeftToday(
        $config, $now, $overrides, $minutesSpentByDate, $budgetId);
  }

  /**
   * Like queryMinutesLeftToday() above, but returns results for all budgets in an array keyed by
   * budget ID.
   */
  public function queryMinutesLeftTodayAllBudgets($user) {
    $configs = $this->getAllBudgetConfigs($user);
    $now = new DateTime();

    $overridesByBudget = $this->queryOverridesByBudget($user, $now);

    $minutesSpentByBudgetAndDate =
        $this->queryMinutesSpentByBudgetAndDate($user, getWeekStart($now), null);

    $minutesLeftByBudget = array();
    foreach ($configs as $budgetId => $config) {
      $minutesSpentByDate = $minutesSpentByBudgetAndDate[$budgetId];
      $overrides = get($overridesByBudget[$budgetId], array());
      $minutesLeftByBudget[$budgetId] = $this->computeMinutesLeftToday(
          $config, $now, $overrides, $minutesSpentByDate, $budgetId);
    }
    ksort($minutesLeftByBudget, SORT_NUMERIC);
    return $minutesLeftByBudget;
  }

  // TODO: Placement
  private function computeMinutesLeftToday($config, $now, $overrides, $minutesSpentByDate) {
    $nowString = getDateString($now);
    $minutesSpentToday = get($minutesSpentByDate[$nowString], 0);

    // Explicit overrides have highest priority.
    $requireUnlock = get($config['require_unlock'], false);
    if ($overrides) {
      if ($requireUnlock && $overrides['unlocked'] != 1) {
        return 0;
      }
      if ($overrides['minutes'] != null) {
        return $overrides['minutes'] - $minutesSpentToday;
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
  public function queryRecentOverrides($user) {
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

  /** Returns overrides in an associative array, or an empty array if none are defined. */
  private function queryOverrides($user, $budgetId, $dateTime) {
    $result = $this->query('SELECT budget_id, minutes, unlocked FROM overrides'
            . ' WHERE user="' . $user . '"'
            . ' AND date="' . getDateString($dateTime) . '"'
            . ' AND budget_id="' . $budgetId . '"');
    if ($row = $result->fetch_assoc()) {
      return array('minutes'=>$row['minutes'], 'unlocked'=>$row['unlocked']);
    }
    return array();
  }

  /** Returns all overrides as a 2D array keyed first by budget ID, then by override. */
  private function queryOverridesByBudget($user, $now) {
    $result = $this->query('SELECT budget_id, minutes, unlocked FROM overrides'
            . ' WHERE user="' . $user . '"'
            . ' AND date="' . getDateString($now) . '"');
    $overridesByBudget = array();
    // PK is (user, date, budget_id), so there is at most one row per budget_id.
    while ($row = $result->fetch_assoc()) {
      $overridesByBudget[$row['budget_id']] =
          array('minutes'=>$row['minutes'], 'unlocked'=>$row['unlocked']);
    }
    return $overridesByBudget;
  }

  // ---------- DEBUG/SPECIAL/DUBIOUS/OBNOXIOUS QUERIES ----------

  /**
   * Returns the sequence of window titles for the specified user and date. This will typically be
   * a long array and is intended for debugging.
   */
  public function queryTitleSequence($user, $fromTime) {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    $q = 'SELECT ts, budget_id, title FROM activity'
            . ' WHERE user = "' . $this->esc($user) . '"'
            . ' AND ts >= ' . $fromTime->getTimestamp()
            . ' AND ts < ' . $toTime->getTimestamp()
            . ' ORDER BY ts DESC';
    $result = $this->query($q);
    $windowTitles = array();
    while ($row = $result->fetch_assoc()) {
      // TODO: This should use the client's local time format.
      // TODO: Titles are in UTF-8 format
      $windowTitles[] = array(
          date("Y-m-d H:i:s", $row['ts']),
          $row['budget_id'],
          htmlentities($row['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8'));
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
