<?php

/** Handle an incomding request. */
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
   * Response format by line: TODO update this
   *
   * 1. "ok"
   * 2. dummy budget ID TODO
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
   $configs = $kfc->getAllBudgetConfigs($user);
   $budgetIdToName = getBudgetIdToNameMap($configs);
   $response = [];
   foreach ($classifications as $i => $classification) {
     foreach ($classification['budgets'] as $budget) {
       $response[] = $i . ':' . $budget['remaining'] . ':' . $budgetIdToName[$budget['id']];
     }
   }

   // Special case: Nothing is running.
   // TODO: Whether this is OK or not should probably be a config option.
   if (!$classifications) {
   }

   return implode("\n", $response);

   /*
   $minutesLeftToday = $kfc->queryMinutesLeftToday($user, $classId);

   if ($minutesLeftToday <= 0) {
     return "close\n" . $budgetName;
   }
   // TODO: Make trigger time configurable. Code below relies on it being <= 60.
   if ($minutesLeftToday <= 5) {
     $mmssLeftToday = gmdate("i:s", $minutesLeftToday * 60);
     return "warn\n" . $budgetId . "\n" . $mmssLeftToday . " left today for '" . $budgetName . "'";
   }
   return "ok\n" . $budgetId;*/
 }
}