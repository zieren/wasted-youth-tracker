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
require_once 'common.php';

$db = new Database(false/* create missing tables */);
$user = $_GET["user"];
echo "<h2>Total time spent today</h2>";
echo $db->getMinutesSpentToday($user)." minutes";
echo "<h2>Window titles</h2>";
$db->echoTimeSpentByTitleToday($user);
$db->echoWindowTitles($user);
?>
</body>
</html>
