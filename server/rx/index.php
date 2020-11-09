<?php

require_once '../common/common.php';

error_reporting(0);

// TODO: Make sure warnings and errors are surfaced appropriately.

function handleRequest() {
  $logger = Logger::Instance();
  $content = file_get_contents('php://input');
  $data = json_decode($content, true);
  if (!$data) {
    $logger->critical('JSON decoding failed: "' . substr($content, 999) . '"');
    return 'Invalid JSON';
  }
  $logger->debug('Received data with keys: ' . implode(array_keys($data), ', '));

  $user = get($data['user']);
  $title = get($data['title']);
  if ($user == null || $title == null) {
    $logger->critical('Missing user and/or title in JSON');
    return 'Missing user and/or title in JSON';
  }

  $db = new Database();
  $db->insertWindowTitle($user, urldecode($title));
  $minutesLeftToday = $db->queryMinutesLeftToday($user);

  // TODO: Make trigger time configurable.
  if ($minutesLeftToday <= 0) {
    $response = "logout\n";
  } elseif ($minutesLeftToday <= 5) {
    // TODO: The client shouldn't pop up a message repeatedly. Maybe handle that on the client
    // with two buttons for "snooze" and "dismiss"?
    $response = $minutesLeftToday . " minutes left today\n";
  } else {
    $response = "ok\n";
  }
  $config = $db->getUserConfig($user);
  $response .= $config['sample_interval_seconds'] . "\n";
  return $response;
}

$response = handleRequest();
echo $response;
Logger::Instance()->debug('RESPONSE: ' . str_replace("\n", '\n', $response));
