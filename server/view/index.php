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

$db = Database::create(false /* create missing tables */);
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

echo "<h3>Time left today</h3>";
$timeLeftByBudget = $db->queryTimeLeftTodayAllBudgets($user);
echoTable(array(
    budgetIdsToNames(array_keys($timeLeftByBudget), $configs),
    array_values($timeLeftByBudget)));

echo "<h3>Time spent on selected date</h3>";
$fromTime = new DateTime($dateString);
$toTime = (clone $fromTime)->add(new DateInterval('P1D'));
$timeSpentByBudgetAndDate = $db->queryTimeSpentByBudgetAndDate($user, $fromTime, $toTime);
$timeSpentByBudget = [];
foreach ($timeSpentByBudgetAndDate as $budgetId=>$timeSpentByDate) {
  $timeSpentByBudget[$budgetId] = get($timeSpentByDate[$dateString], 0);
}
// TODO: Classes can map to budgets that are not configured (so not in $configs), or map to no
// budget at all.
echoTable(array(
    budgetIdsToNames(array_keys($timeSpentByBudget), $configs),
    array_values($timeSpentByBudget)));

echo "<h3>Time per window title</h3>";
// TODO: echoTable($db->queryTimeSpentByTitle($user, $fromTime));

if (get($_GET['debug'])) {
  echo "<h2>Window title sequence</h2>";
  echoTable($db->queryTitleSequence($user, $fromTime));
}

?>
</body>
</html>
