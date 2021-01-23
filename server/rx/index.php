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
    return "error\nInvalid request content\n";
  }

  $user = $data[0];
  $title = $data[1];

  $db = new Database();
  $db->insertWindowTitle($user, $title);
  $minutesLeftToday = $db->queryMinutesLeftToday($user);

  // TODO: Make trigger time configurable.
  if ($minutesLeftToday <= 0) {
    return "logout\n";
  } elseif ($minutesLeftToday <= 5) {
    // TODO: The client shouldn't pop up a message repeatedly. Maybe handle that on the client
    // with two buttons for "snooze" and "dismiss"?
    return "message\n" . minutesLeftToday . " minutes left today\n";
  }
  return "ok\n";
}

$response = handleRequest();
echo $response;
Logger::Instance()->debug('RESPONSE: ' . str_replace("\n", '\n', $response));
