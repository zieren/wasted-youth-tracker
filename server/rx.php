<?php
require_once 'common.php';

error_reporting(0);
// TODO: Make sure warnings and errors are surfaced appropriately.

function handleRequest() {
  $logger = Logger::Instance();
  $content = file_get_contents('php://input');
  $data = json_decode($content, true);
  // TODO: limit size of $content before output
  if (!$data) {
    $logger->critical('json decoding failed: "'.$content.'"');
    return array('status' => 'invalid json: '.$content);
  }
  $logger->debug('Received data with keys: '.implode(array_keys($data), ', '));

  $db = new Database();  // TODO: Handle failure.
  $config = $db->getConfig();
  if (isset($data['title'])) {
    $db->insertWindowTitle(urldecode($data['title']));
  }
  $response = "ok\n".$config['sample_interval_seconds']."\n";
  return $response;
}

$response = handleRequest();
echo $response;
Logger::Instance()->debug('RESPONSE: '.str_replace("\n", '\n', $response));
