<html>
<head>
  <title>KFC View</title>
  <meta charset="iso-8859-1"/>
  <link rel="stylesheet" href="../common/kfc.css">
</head>
<body>
<?php
require_once '../common/common.php';
require_once '../common/html_util.php';

echo dateSelectorJs();

$kfc = KFC::create(false /* create missing tables */);
$dateString = getOrDefault($_GET, 'date', date('Y-m-d'));
list($year, $month, $day) = explode('-', $dateString);
// TODO: Catch invalid date.
// TODO: Date needs to be pretty for the input (2-digit month/day etc.)
$users = $kfc->getUsers();
$user = getOrDefault($_GET, 'user', getOrDefault($users, 0, ''));
$configs = $kfc->getAllBudgetConfigs($user);

echo '<h2>Links</h2>
  <a href="../admin/index.php?user=' . $user . '">Admin page</a>
  <h2>View</h2>';

echo
    '<form action="index.php" method="get">'
    . userSelector($users, $user)
    . dateSelector($dateString, true)
    . '</form>';

echo "<h3>Time spent on selected date</h3>";
$fromTime = new DateTime($dateString);
$toTime = (clone $fromTime)->add(new DateInterval('P1D'));
$timeSpentByBudgetAndDate = $kfc->queryTimeSpentByBudgetAndDate($user, $fromTime, $toTime);
$timeSpentByBudget = [];
foreach ($timeSpentByBudgetAndDate as $budgetId=>$timeSpentByDate) {
  $timeSpentByBudget[$budgetId] = getOrDefault($timeSpentByDate, $dateString, 0);
}
// TODO: Classes can map to budgets that are not configured (so not in $configs), or map to no
// budget at all.
echoTable(
    budgetIdsToNames(array_keys($timeSpentByBudget), $configs),
    [array_map("secondsToHHMMSS", array_values($timeSpentByBudget))]);

echo "<h3>Time left today</h3>";
$timeLeftByBudget = $kfc->queryTimeLeftTodayAllBudgets($user);
echoTable(
    budgetIdsToNames(array_keys($timeLeftByBudget), $configs),
    [array_map("secondsToHHMMSS", array_values($timeLeftByBudget))]);

echo "<h3>Most Recently Used<h3>";
$timeSpentPerTitle = $kfc->queryTimeSpentByTitle($user, $fromTime, false);
for ($i = 0; $i < count($timeSpentPerTitle); $i++) {
  $timeSpentPerTitle[$i][1] = secondsToHHMMSS($timeSpentPerTitle[$i][1]);
}
echoTable(['Last Used', 'Time', 'Class', 'Title'], $timeSpentPerTitle);

echo "<h3>Most Time Spent<h3>";
$timeSpentPerTitle = $kfc->queryTimeSpentByTitle($user, $fromTime);
for ($i = 0; $i < count($timeSpentPerTitle); $i++) {
  $timeSpentPerTitle[$i][1] = secondsToHHMMSS($timeSpentPerTitle[$i][1]);
}
echoTable(['Last Used', 'Time', 'Class', 'Title'], $timeSpentPerTitle);

if (getOrDefault($_GET, 'debug')) {
  echo "<h2>Window title sequence</h2>";
  echoTable(['Date & Time', 'Class', 'Title'], $kfc->queryTitleSequence($user, $fromTime));
}

?>
</body>
</html>
