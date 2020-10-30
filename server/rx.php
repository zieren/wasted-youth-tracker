<?php
require_once 'common.php';

error_reporting(0);
// TODO: Make sure warnings and errors are surfaced appropriately.

function handleRequest() {
  $logger = Logger::Instance();
  $content = file_get_contents('php://input');
  
  // ------------- TODO: What if $content is false? Does this sometimes fail?
  
  $data = json_decode($content, true);
  if (!$data) {
    $logger->critical('json decoding failed: "'.$content.'"');
    return 'invalid json';
  }
  $logger->debug('Received data with keys: '.implode(array_keys($data), ', '));

  $db = new Database();  // TODO: Handle failure.
  if (isset($data['title']) and isset($data['user'])) {
    $db->insertWindowTitle($data['user'], urldecode($data['title']));
  } // TODO: else: Do something. We now assume the fields are set anyway.
  $minutesSpentToday = $db->getMinutesSpent($data['user'], new DateTime());
  $config = $db->getUserConfig($data['user']);
  // TODO: Override default by weekday and then by date.
  $minutesLeftToday = $config['daily_time_default_minutes'] - $minutesSpentToday;
  // TODO: Make trigger time configurable.
  $response = "";
  if ($minutesLeftToday <= 0) {
    $response .= "logout\n";
  } elseif ($minutesLeftToday <= 5) {
    // TODO: magic 5 should be configurable
    // TODO: The client shouldn't pop up a message repeatedly. Maybe just once again?
    $response .= $minutesLeftToday." minutes left today\n";
  } else {
    $response = "ok\n";
  }
  $response .= $config['sample_interval_seconds']."\n";
  return $response;
}

$response = handleRequest();
echo $response;
Logger::Instance()->debug('RESPONSE: '.str_replace("\n", '\n', $response));
