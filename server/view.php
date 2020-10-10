<html>
<body>
<?php
require_once 'common.php';

$db = new Database(false/* create missing tables */);
echo "<h2>Total time today</h2>";
echo $db->getMinutesSpentToday("admin")." minutes"; // TODO: Input the name.
echo "<h2>Window titles</h2>";
$db->echoWindowTitles();
?>
</body>
</html>
