<?php

require_once '../common/common.php';

error_reporting(0);

// TODO: Make sure warnings and errors are surfaced appropriately. Catch exceptions.

/**
 * Incoming data format by line:
 *
 * 1. username
 * 2. window title #0
 * 3. window title #1
 * ...
 *
 * At least the first line must be sent. Window title #0 is the one that has focus. If none has
 * focus, this line is empty. This line may never be absent, even when there are no windows.
 *
 * Response format by line:
 *
 * 1. "ok"
 * 2. dummy budget ID TODO
 */
function handleRequest() {
  $logger = Logger::Instance();
  $content = file_get_contents('php://input');
  $lines = $array = preg_split("/\r\n|\n|\r/", $content);
  $logger->debug('Received data: ' . implode($lines, '\n'));
  if (count($lines) < 2) {
    http_response_code(400);
    $message = "Invalid request content: expected at least 2 lines, got " . count($lines);
    $logger->error($message . '. Content: ' . str_replace("\n", '\n', $content));
    return "error\n" . $message;
  }

  $user = $lines[0];
  $titles = array_slice($lines, 1); // could be empty

  $kfc = KFC::create();

  $classifications = $kfc->insertWindowTitles($user, $titles);


  foreach ($classifications as $classification) {

  }

  // Special case: Nothing is running.
  // TODO: Whether this is OK or not should probably be a config option.
  if (!$classifications) {
  }


  return "ok\n42\n" ;

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

$response = handleRequest();
echo $response;
Logger::Instance()->debug('RESPONSE: ' . str_replace("\n", '\n', $response));
