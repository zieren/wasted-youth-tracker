<html>
<head>
  <title>KFC View</title>
  <link rel="stylesheet" href="../common/kfc.css">
</head>
<body>
<?php
require_once '../common/common.php';
require_once '../common/html_util.php';

echo '<h2>Links</h2>
  <a href="../admin/">Admin page</a>
  <h2>View</h2>';

$db = new Database(false/* create missing tables */);
$dateString = get($_GET['date'], date('Y-m-d'));
list($year, $month, $day) = explode('-', $dateString);
// TODO: Catch invalid date.
// TODO: Date needs to be pretty for the input (2-digit month/day etc.)
$users = $db->getUsers();
$user = get($_GET['user'], get($users[0], ''));
$configs = $db->getAllBudgetConfigs($user);

echo '<form action="index.php" method="get">'
    . '<label for="idUsers">User:</label> '
    . '<select id="idUsers" name="user" onChange="if (this.value != 0) { this.form.submit(); }">';
foreach ($users as $u) {
  $selected = $user == $u ? 'selected="selected"' : '';
  echo '<option value="' . $u . '" ' . $selected . '>' . $u . '</option>';
}
echo '</select>
      <p>Date: <input id="idDate" type="date" value="' . $dateString
        . '" name="date" onInput="this.form.submit()"/>
      <button onClick="setToday()">Today</button>
      </p>
      </form>
<script type="text/javascript">
function setToday() {
  var dateInput = document.querySelector("#idDate");
  dateInput.value = "' . date('Y-m-d') . '";
}
</script>';

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
