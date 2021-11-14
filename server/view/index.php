<html>
<head>
  <title>Wasted Youth Tracker - View</title>
  <meta charset="iso-8859-1"/>
  <link rel="stylesheet" href="../common/wasted.css">
</head>
<body>
<?php
require_once '../common/common.php';
require_once '../common/html_util.php';

echo dateSelectorJs();

$wasted = Wasted::initialize();
$dateString = getOrDefault($_GET, 'date', date('Y-m-d'));
list($year, $month, $day) = explode('-', $dateString);
// TODO: Catch invalid date.
// TODO: Date needs to be pretty for the input (2-digit month/day etc.)
$users = Wasted::getUsers();
$user = getOrDefault($_GET, 'user', getOrDefault($users, 0, ''));
$configs = Wasted::getAllLimitConfigs($user);

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
$timeSpentByLimitAndDate = Wasted::queryTimeSpentByLimitAndDate($user, $fromTime, $toTime);
$timeSpentByLimit = [];
foreach ($timeSpentByLimitAndDate as $limitId=>$timeSpentByDate) {
  $timeSpentByLimit[$limitId] = getOrDefault($timeSpentByDate, $dateString, 0);
}
echoTable(
    limitIdsToNames(array_keys($timeSpentByLimit), $configs),
    [array_map("secondsToHHMMSS", array_values($timeSpentByLimit))]);

echo "<h3>Time left today</h3>";
$timeLeftByLimit = Wasted::queryTimeLeftTodayAllLimits($user);
echoTable(
    limitIdsToNames(array_keys($timeLeftByLimit), $configs),
    [array_map('secondsToHHMMSS', array_map('TimeLeft::toCurrentSeconds', $timeLeftByLimit))]);

echo "<h3>Most Recently Used<h3>";
$timeSpentPerTitle = Wasted::queryTimeSpentByTitle($user, $fromTime, false);
for ($i = 0; $i < count($timeSpentPerTitle); $i++) {
  $timeSpentPerTitle[$i][1] = secondsToHHMMSS($timeSpentPerTitle[$i][1]);
}
echoTable(['Last Used', 'Time', 'Class', 'Title'], $timeSpentPerTitle);

echo "<h3>Most Time Spent<h3>";
$timeSpentPerTitle = Wasted::queryTimeSpentByTitle($user, $fromTime);
for ($i = 0; $i < count($timeSpentPerTitle); $i++) {
  $timeSpentPerTitle[$i][1] = secondsToHHMMSS($timeSpentPerTitle[$i][1]);
}
echoTable(['Last Used', 'Time', 'Class', 'Title'], $timeSpentPerTitle);

if (get('debug')) {
  echo '<h2>Window title sequence</h2>';
  echoTable(['From', 'To', 'Class', 'Title'], Wasted::queryTitleSequence($user, $fromTime));
}

?>
</body>
</html>
