<?php

require_once '../common/common.php';
require_once '../common/html_util.php';

$user = getString('user');
if (!$user) {
  http_response_code(400);
  return;
}

Wasted::initialize();
$response = Config::handleRequest($user);
echo $response;
Logger::Instance()->debug(
    "Config response for user '$user': " . str_replace("\n", '\n', $response));
