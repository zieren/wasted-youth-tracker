<?php

require_once '../common/common.php';
require_once '../common/html_util.php';

$user = get('user');
if (!$user) {
  http_response_code(400);
  return;
}

$response = Config::handleRequest(KFC::create(), $user);
echo $response;
Logger::Instance()->debug(
    'Config response for user "' . $user . '": ' . str_replace("\n", '\n', $response));
