<?php

require_once '../common/common.php';
require_once 'RX.php';

// TODO: Make sure warnings and errors are surfaced appropriately. Catch exceptions.

$content = file_get_contents('php://input');
Wasted::initialize();
$response = RX::handleRequest($content);
echo $response;
Logger::Instance()->debug('RESPONSE: ' . str_replace("\n", '\n', $response));
