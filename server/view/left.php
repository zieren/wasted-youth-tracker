<?php

require_once '../common/common.php';

error_reporting(0);

header("Cache-Control: max-age=0");

$user = get($_GET['user']);
if (!$user) {
  exit("Request is missing 'user' parameter");
}

$db = new Database();

$response = "";
$minutesLeftByBudget = $db->queryMinutesLeftTodayAllBudgets($user);
foreach ($minutesLeftByBudget as $budgetId => $minutesLeft) {
  $minutesLeft = max(0, $minutesLeft);
  $response .= $budgetId . ": " . gmdate("H:i:s", $minutesLeft * 60) . "\n";
}
echo $response;
