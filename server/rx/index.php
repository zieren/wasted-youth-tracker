<?php

require_once '../common/common.php';

error_reporting(0);

// TODO: Make sure warnings and errors are surfaced appropriately.

function handleRequest() {
  $logger = Logger::Instance();
  $content = file_get_contents('php://input');
  $data = explode("|", $content, 2);
  $logger->debug('Received data: ' . implode($data, '|'));
  if (count($data) != 2) {
    $logger->error('Invalid request content: ' . $content);
    return "error\nInvalid request content";
  }

  $user = $data[0];
  $title = $data[1];

  $db = new Database();

  // Check time left for this specific budget.
  list($budgetId, $budgetName) = $db->insertWindowTitle($user, $title);
  $minutesLeftToday = $db->queryMinutesLeftToday($user, $budgetId);

  if ($minutesLeftToday <= 0) {
    return "close\n" . $budgetName;
  }
  // TODO: Make trigger time configurable. Code below relies on it being <= 60.
  if ($minutesLeftToday <= 5) {
    $mmssLeftToday = gmdate("i:s", $minutesLeftToday * 60);
    return "warn\n" . $budgetId . "\n" . $mmssLeftToday . " left today for '" . $budgetName . "'";
  }
  return "ok\n" . $budgetId;
}

$response = handleRequest();
echo $response;
Logger::Instance()->debug('RESPONSE: ' . str_replace("\n", '\n', $response));
