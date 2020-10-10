<html>
<body>
<?php
require_once 'common.php';

$db = new Database(false/* create missing tables */);
echo "<h2>Total time spent today</h2>";
echo $db->getMinutesSpentToday($_GET["user"])." minutes";
echo "<h2>Window titles</h2>";
$db->echoWindowTitles($_GET["user"]);
?>
</body>
</html>
