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
    $logger->critical('JSON decoding failed: "'.$content.'"');
    return 'Invalid JSON';
  }
  $logger->debug('Received data with keys: '.implode(array_keys($data), ', '));

  $user = get($data['user']);
  $title = get($data['title']);
  if ($user == null || $title == null) {
    $logger->critical('Missing user and/or title in JSON');
    return 'Missing user and/or title in JSON';
  }

  $db = new Database();  // TODO: Handle failure.
  
  $db->insertWindowTitle($user, urldecode($title));
  
  $minutesSpentToday = $db->getMinutesSpent($user, new DateTime());
  $minutesLeftToday = $db->getMinutesLeft($user) - $minutesSpentToday;
  
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
  $config = $db->getUserConfig($user);
  $response .= $config['sample_interval_seconds']."\n";
  return $response;
}

$response = handleRequest();
echo $response;
Logger::Instance()->debug('RESPONSE: '.str_replace("\n", '\n', $response));
