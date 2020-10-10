<?php
require_once 'common.php';

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
  $response = array();
  try {
    $db->beginTransaction();

    if (isset($data['title'])) {
      $db->insertWindowTitle(urldecode($data['title']));
    }

    $db->commit();
    $response['status'] = 'ok';
  } catch (Exception $e) {
    $db->rollback();
    $logger->critical('Exception in rx.php: '.$e);
    $response['status'] = 'failure: '.$e;
  }
  return $response;
}

$jsonResponse = json_encode(handleRequest());
echo $jsonResponse;
Logger::Instance()->debug('RESPONSE: '.$jsonResponse);
