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
require_once 'common.php';

$db = new Database(false/* create missing tables */);
$user = get($_GET["user"], "default"); // TODO proper user selector
$date = get($_GET["date"], date("Y-m-d"));
list($year, $month, $day) = explode("-", $date);
echo 'Date: <input id="idDateSelector" size="16" placeholder="date" />
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

echo "<h2>Minutes today</h2>";
$dateTime = new DateTime($year . "-" . $month . "-" . $day);
echo $db->getMinutesSpent($user, $dateTime);

echo "<h2>Minutes per window title</h2>";
$timeByTitle = $db->queryTimeSpentByTitle($user, $dateTime);
echo "<table>\n";
foreach($timeByTitle as $t) {
  echo "<tr><td>" . implode("</td><td>", $t) . "</td></tr>\n";
}
echo "</table>\n";

echo "<h2>Window title sequence (for debugging)</h2>";
$windowTitles = $db->queryAllTitles($user, $dateTime);
echo "<table>\n";
foreach($windowTitles as $w) {
  echo "<tr><td>" . implode("</td><td>", $w) . "</td></tr>\n";
}
echo "</table>\n";

?>
</body>
</html>
