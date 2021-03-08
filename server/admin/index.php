<html>
<head>
  <title>KFC Admin</title>
  <meta charset="utf-8"/>
  <link rel="stylesheet" href="../common/kfc.css">
</head>
<body>
<script>
function enableDestructiveButtons(toggleCheckbox) {
  var checkbox = document.getElementById('idKfcEnableDestructive');
  if (toggleCheckbox) {
    checkbox.checked = !checkbox.checked;
  }
  var buttons = document.getElementsByClassName('kfcDestructive');
  for (var i = 0; i < buttons.length; i++) {
    buttons[i].disabled = !checkbox.checked;
  }
}
</script>
<?php
require_once '../common/common.php';
require_once '../common/html_util.php';

echo dateSelectorJs();

function checkRequirements() {
  $unmet = array();
  if (version_compare(PHP_VERSION, PHP_MIN_VERSION) < 0) {
    $unmet[] = 'PHP version ' . PHP_MIN_VERSION . ' is required, but this is ' . PHP_VERSION . '.';
  }
  if (!function_exists('mysqli_connect')) {
    $unmet[] = 'The mysqli extension is missing.';
  }
  if (!$unmet) {
    return;
  }
  echo '<p><b>Please follow these steps to complete the installation:</b></p>'
  . '<ul><li>' . implode('</li><li>', $unmet) . '</li></ul><hr />';
  throw new Exception(implode($unmet));
}

checkRequirements();

$kfc = KFC::create(true /* create missing tables */);

// TODO: This should sanitize the user input.
if (isset($_POST['setUserConfig'])) {
  $user = trim($_POST['configUser']);
  $key = trim($_POST['configKey']);
  $kfc->setUserConfig($user, $key, $_POST['configValue']);
} else if (isset($_POST['clearUserConfig'])) {
  $user = trim($_POST['configUser']);
  $key = trim($_POST['configKey']);
  $kfc->clearUserConfig($user, $key);
} else if (isset($_POST['setGlobalConfig'])) {
  $key = trim($_POST['configKey']);
  $kfc->setGlobalConfig($key, $_POST['configValue']);
} else if (isset($_POST['clearGlobalConfig'])) {
  $key = trim($_POST['configKey']);
  $kfc->clearGlobalConfig($key);
} else if (isset($_POST['addBudget'])) {
  $budgetName = trim($_POST['budgetName']);
  $kfc->addBudget($budgetName);
  echo "Budget added: " . $budgetName;
} else if (isset($_POST['removeBudget'])) {
  $budgetName = trim($_POST['budgetName']);
  $kfc->removeBudget($budgetName);
} else if (isset($_POST['setMinutes'])) {
  $user = $_POST['user'];
  $dateString = $_POST['date'];
  $budgetId = $_POST['budget'];
  $minutes = getOrDefault($_POST, 'overrideMinutes', 0);
  $kfc->setOverrideMinutes($user, $dateString, $budgetId, $minutes);
} else if (isset($_POST['unlock'])) {
  $user = $_POST['user'];
  $dateString = $_POST['date'];
  $budgetId = $_POST['budget'];
  $kfc->setOverrideUnlock($user, $dateString, $budgetId);
} else if (isset($_POST['clearOverride'])) {
  $user = $_POST['user'];
  $dateString = $_POST['date'];
  $budgetId = $_POST['budget'];
  $kfc->clearOverride($user, $dateString, $budgetId);
} else if (isset($_POST['prune'])) {
  $dateString = $_POST['datePrune'];
  $dateTime = DateTime::createFromFormat("Y-m-d", $dateString);
  $kfc->pruneTables($dateTime);
  echo '<b>Tables pruned before ' . getDateString($dateTime) . '</b></hr>';
} else if (isset($_POST['clearAll'])) {
  $kfc->dropAllTablesExceptConfig();
  echo '<b>Tables dropped</b></hr>';
}

$users = $kfc->getUsers();
if (!isset($user)) {
  $user = getOrDefault($_GET, 'user', getOrDefault($users, 0, ''));
}
if (!isset($dateString)) {
  $dateString = getOrDefault($_GET, 'date', date('Y-m-d'));
}
if (!isset($budgetId)) {
  $budgetId = 0;
}

$budgetConfigs = $kfc->getAllBudgetConfigs($user);
$budgetNames = budgetIdsToNames(array_keys($budgetConfigs), $budgetConfigs);
$configs = $kfc->getAllBudgetConfigs($user);

echo '<h1>'.KFC_SERVER_HEADING.'</h1>
<p>(c) 2021 J&ouml;rg Zieren - <a href="http://zieren.de">zieren.de</a> - GNU GPL v3.
Components:
<a href="https://meekro.com/">MeekroDB</a> by Sergey Tsalkov, LGPL;
<a href="http://codefury.net/projects/klogger/">KLogger</a> by Kenny Katzgrau, MIT license
';

echo '<p><a href="../view/index.php?user=' . $user . '">View activity</a></p>';

echo '<h2>Configuration</h2>
    <form action="index.php" method="get">'
    . userSelector($users, $user)
    . '</form>';

echo '
<h3>Overrides</h3>
  <form method="post" action="index.php">
    <input type="hidden" name="user" value="' . $user . '">'
    . dateSelector($dateString, false)
    . budgetSelector($budgetNames, $budgetId) .
    '<label for="idOverrideMinutes">Minutes: </label>
    <input id="idOverrideMinutes" name="overrideMinutes" type="number" value="" min=0>
    <input type="submit" value="Set Minutes" name="setMinutes">
    <input type="submit" value="Unlock" name="unlock">
    <input type="submit" value="Clear" name="clearOverride">
  </form>';

echo '<h4>Current overrides</h4>';
echoTable($kfc->queryRecentOverrides($user));

echo "<h3>Time left today</h3>";
$timeLeftByBudget = $kfc->queryTimeLeftTodayAllBudgets($user);
echoTable(array(
    budgetIdsToNames(array_keys($timeLeftByBudget), $configs),
    array_map("secondsToHHMMSS", array_values($timeLeftByBudget))));

// --- BEGIN duplicate code. TODO: Extract.
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
echoTable(array(
    budgetIdsToNames(array_keys($timeSpentByBudget), $configs),
    array_map("secondsToHHMMSS", array_values($timeSpentByBudget))));
// --- END duplicate code

echo '<h3>Budgets</h3>';

foreach ($budgetConfigs as $budgetId => $config) {
  echo '<h4>' . html($budgetNames[$budgetId]) . "</h4>\n";
  echoTableAssociative($config);
}

echo '
<h4>Add/remove budget</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">
  <label for="idBudgetName">Budget name: </label>
  <input id="idBudgetName" name="budgetName" type="text" value="">
  <input type="submit" value="Add budget" name="addBudget">
  <input type="submit" value="Remove budget and its config" name="removeBudget">
</form>';

/* There is currently no user config. But there probably will be soon.
echo '<h3>User config</h3>';
echoTable($kfc->getAllUsersConfig());
*/

echo '<h3>Global config</h3>';
echoTable($kfc->getGlobalConfig());

echo '<h2>Update config</h2>
<form method="post" enctype="multipart/form-data">
  <input type="text" name="configUser" value="" placeholder="user">
  <input type="text" name="configKey" value="" placeholder="key">
  <input type="text" name="configValue" value="" placeholder="value">
  <input type="submit" name="setUserConfig" value="Set User Config">
  <input type="submit" name="clearUserConfig" value="Clear User Config">
  <input type="submit" name="setGlobalConfig" value="Set Global Config">
  <input type="submit" name="clearGlobalConfig" value="Clear Global Config">
</form>

<hr />

<h2>Manage Database</h2>
PRUNE data and logs before
<form method="post">
  <input type="date" name="datePrune" value="' . date('Y-m-d') . '">
  <input class="kfcDestructive" type="submit" value="PRUNE" name="prune" disabled />
</form>
<form method="post">
  <p>
    CLEAR ALL DATA except config
    <input class="kfcDestructive" type="submit" name="clearAll" value="CLEAR" disabled />
  </p>
</form>
<form>
  <input type="checkbox" name="confirm" id="idKfcEnableDestructive"
      onclick="enableDestructiveButtons(false)"/>
  <span onclick="enableDestructiveButtons(true)">Yes, I really really want to!</span>
</form>
';
?>
</body>
</html>
