<?php

require_once '../common/common.php';

error_reporting(0);

// TODO: Make sure warnings and errors are surfaced appropriately. Catch exceptions.

$content = file_get_contents('php://input');
$response = RX::handleRequest($content, KFC::create());
echo $response;
Logger::Instance()->debug('RESPONSE: ' . str_replace("\n", '\n', $response));
