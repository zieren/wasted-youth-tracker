<?php

/** Handle an incoming request. */
class RX {

  /**
   * Incoming data format by line:
   *
   * 1. username
   * 2. focused window index
   * 3. window title #0
   * 4. window title #1
   * ...
   *
   * At least the first line must be sent. This would indicate that no windows are open.
   *
   * Example request:
   *
   * john_doe
   * 0
   * Minecraft
   * Calculator
   *
   * The response contains two parts. The first part lists all budgets configured for the user:
   *
   * budgetId ":" timeLeftToday ":" budgetName
   *
   * The second part, separated by a blank line, lists the budgets to which each window title map,
   * in the order they appear in the request. If multiple budgets matcha title, they are separated
   * by commas:
   *
   * budgetId ( "," budgetId )*
   *
   * Example response:
   *
   * 10:42:Games
   * 11:23:Minecraft
   * 12:666:School
   *
   * 10,11
   * 12
   */
  public static function handleRequest($content, $kfc): string {
    $lines = $array = preg_split("/\r\n|\n|\r/", $content);
    Logger::Instance()->debug('Received data: ' . implode($lines, '\n'));
    $user = $lines[0];
    $focusIndex = getOrDefault($lines, 1, -1);
    if (!$user || !is_numeric($focusIndex)) {
      http_response_code(400);
      Logger::Instance()->error('Invalid request: "' . str_replace("\n", '\n', $content) . '"');
      return "error\nInvalid request";
    }
    $titles = array_slice($lines, 2); // could be empty, but we still need to insert a timestamp

    $classifications = $kfc->insertWindowTitles($user, $titles, $focusIndex);


    // Build response.

    // Part 1: Budgets and time left.
    $response = [];
    $configs = $kfc->getAllBudgetConfigs($user);
    $budgetIdToName = getBudgetIdToNameMap($configs);
    $timeLeftByBudget = $kfc->queryTimeLeftTodayAllBudgets($user);
    foreach ($timeLeftByBudget as $budgetId => $timeLeft) {
      $response[] =
          ($budgetId ? $budgetId : 0) . ':' . $timeLeft . ':' . $budgetIdToName[$budgetId];
    }
    $response[] = '';

    // Part 2: Window titles to budgets.
    foreach ($classifications as &$classification) {
      $response[] = implode(',', $classification['budgets']);
    }

    // Special case: Nothing is running.
    // TODO: Whether this is OK or not should probably be a config option.
    if (!$classifications) {
    }

    return implode("\n", $response);
  }
}