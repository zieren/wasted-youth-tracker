<html>
<head>
  <title>KFC View</title>
  <meta charset="utf-8"/>
  <link rel="stylesheet" href="../common/kfc.css">
</head>
<body>
<?php
require_once '../common/common.php';
require_once '../common/html_util.php';

echo dateSelectorJs();

$db = new Database(false/* create missing tables */);
$dateString = get($_GET['date'], date('Y-m-d'));
list($year, $month, $day) = explode('-', $dateString);
// TODO: Catch invalid date.
// TODO: Date needs to be pretty for the input (2-digit month/day etc.)
$users = $db->getUsers();
$user = get($_GET['user'], get($users[0], ''));
$configs = $db->getAllBudgetConfigs($user);

echo '<h2>Links</h2>
  <a href="../admin/index.php?user=' . $user . '">Admin page</a>
  <h2>View</h2>';

echo
    '<form action="index.php" method="get">'
    . userSelector($users, $user)
    . dateSelector($dateString, true)
    . '</form>';

echo "<h3>Minutes left today</h3>";
$minutesLeftByBudget = $db->queryMinutesLeftTodayAllBudgets($user);
echoTable(array(
    budgetIdsToNames(array_keys($minutesLeftByBudget), $configs),
    array_values($minutesLeftByBudget)));

echo "<h3>Minutes spent on selected date</h3>";
$fromTime = new DateTime($dateString);
$toTime = (clone $fromTime)->add(new DateInterval('P1D'));
$minutesSpentByBudgetAndDate = $db->queryMinutesSpentByBudgetAndDate($user, $fromTime, $toTime);
$minutesSpentByBudget = array();
foreach ($minutesSpentByBudgetAndDate as $budgetId=>$minutesSpentByDate) {
  $minutesSpentByBudget[$budgetId] = get($minutesSpentByDate[$dateString], 0);
}
// TODO: Classes can map to budgets that are not configured (so not in $configs), or map to no
// budget at all.
echoTable(array(
    budgetIdsToNames(array_keys($minutesSpentByBudget), $configs),
    array_values($minutesSpentByBudget)));

echo "<h3>Minutes per window title</h3>";
echoTable($db->queryTimeSpentByTitle($user, $fromTime));

if (get($_GET['debug'])) {
  echo "<h2>Window title sequence</h2>";
  echoTable($db->queryTitleSequence($user, $fromTime));
}

?>
</body>
</html>
