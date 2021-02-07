<?php

require_once '../common/common.php';

error_reporting(0);

// TODO: Make sure warnings and errors are surfaced appropriately. Catch exceptions.

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
  $titles = array_slice($lines, 1);

  $db = new Database();

  $classifications = $db->insertWindowTitles($user, $titles);
  $leftAllBudgets = $db->queryMinutesLeftTodayAllBudgets($user);

  ob_start();
  var_dump($classifications);
  var_dump($leftAllBudgets);
  return "ok\n" . ob_get_clean();

  /*
  $minutesLeftToday = $db->queryMinutesLeftToday($user, $classId);

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
