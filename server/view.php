<html>
<head>
<style>
table, th, td {
  border: 1px solid black;
}
</style>
</head>
<body>
<?php
// TODO: Extract CSS.
require_once 'common.php';
require_once 'html_util.php';

$db = new Database(false/* create missing tables */);
$dateString = get($_GET['date'], date('Y-m-d'));
list($year, $month, $day) = explode('-', $dateString);
$users = $db->getUsers();
$user = get($_GET['user'], get($users[0], ''));

echo '<form action="view.php" method="get">'
    . '<label for="idUsers">User:</label> '
    . '<select id="idUsers" name="user" onChange="if (this.value != 0) { this.form.submit(); }">';
foreach ($users as $u) {
  $selected = $user == $u ? 'selected="selected"' : '';
  echo '<option value="' . $u . '" ' . $selected . '>' . $u . '</option>';
}
echo '</select>
      <p>Date: <input type="date" value="' . $dateString
        . '" name="date" onInput="this.form.submit()"/></p>
      </form>';

// TODO: Add "Today" button


echo "<h3>Minutes left today</h3>";
echo $db->queryMinutesLeftToday($user);

echo "<h2>Minutes spent on selected date</h2>";
$fromTime = new DateTime($dateString);
$toTime = (clone $fromTime)->add(new DateInterval('P1D'));
$minutesSpentByDate = $db->queryMinutesSpentByDate($user, $fromTime, $toTime);
echo get($minutesSpentByDate[$dateString], 0);

echo "<h2>Minutes per window title</h2>";
echoTable($db->queryTimeSpentByTitle($user, $fromTime));

echo "<h2>Window title sequence (for debugging)</h2>";
echoTable($db->queryTitleSequence($user, $fromTime));

?>
</body>
</html>
