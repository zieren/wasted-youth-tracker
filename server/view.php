<html>
<head>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
    . '<select id="idUsers" name="user" onchange="if (this.value != 0) { this.form.submit(); }">';
foreach ($users as $u) {
  $selected = $user == $u ? 'selected="selected"' : '';
  echo '<option value="' . $u . '" ' . $selected . '>' . $u . '</option>';
}
echo '</select>'
    . '<input name="date" type="hidden" value="' . $dateString . '">'
    . '</form>';

// TODO: Use default date picker (?).
echo '<p>Date: <input id="idDateSelector" placeholder="date" /></p>
<script type="text/javascript">
var fp = flatpickr("#idDateSelector", {
  defaultDate: new Date(' . $year. ',' . ($month - 1) . ',' . $day . '),
  allowInput: true,
  locale: {
    firstDayOfWeek: 1
  },
  onClose: function(selectedDates, dateStr, instance) {
    window.location.href = window.location.origin + window.location.pathname
      + "?user='. $user . '&date=" + dateStr;
  }
});
</script>';

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
