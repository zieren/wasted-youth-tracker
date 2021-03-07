<?php

require_once '../common/common.php';

// error_reporting(0);

header("Cache-Control: max-age=0");

$user = getOrDefault($_GET, 'user');
if (!$user) {
  exit("Request is missing 'user' parameter");
}

$db = KFC::create();

$response = "";
$minutesLeftByBudget = $db->queryMinutesLeftTodayAllBudgets($user);
$budgetConfigs = $db->getAllBudgetConfigs($user);
foreach ($minutesLeftByBudget as $budgetId => $minutesLeft) {
  $minutesLeft = max(0, $minutesLeft);
  $response .=
      gmdate("H:i:s", $minutesLeft * 60) . " - " . $budgetConfigs[$budgetId]['name'] . "\n";
}
echo $response;
