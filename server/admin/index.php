<html>
<head>
  <title>KFC Admin</title>
  <meta charset="iso-8859-1"/>
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

echo dateSelectorJs();

if (action('setUserConfig')) {
  $user = post('configUser');
  $kfc->setUserConfig($user, post('configKey'), post('configValue'));
} else if (action('clearUserConfig')) {
  $user = post('configUser');
  $kfc->clearUserConfig($user, post('configKey'));
} else if (action('setGlobalConfig')) {
  $kfc->setGlobalConfig(post('configKey'), post('configValue'));
} else if (action('clearGlobalConfig')) {
  $kfc->clearGlobalConfig(post('configKey'));
} else if (action('addBudget')) {
  $user = post('user');
  // TODO: Handle budget exists.
  $kfc->addBudget($user, post('budgetName'));
} else if (action('addClass')) {
  // TODO: Handle class exists.
  $kfc->addClass(post('className'));
} else if (action('removeBudget')) {
  $budgetId = postInt('budgetId');
  $kfc->removeBudget($budgetId);
} else if (action('setBudgetConfig')) {
  $budgetId = postInt('budgetId');
  $kfc->setBudgetConfig($budgetId, post('budgetConfigKey'), post('budgetConfigValue'));
} else if (action('clearBudgetConfig')) {
  $budgetId = postInt('budgetId');
  $kfc->clearBudgetConfig($budgetId, post('budgetConfigKey'));
} else if (action('setMinutes')) {
  $user = post('user');
  $dateString = post('date');
  $budgetId = postInt('budgetId');
  $kfc->setOverrideMinutes($user, $dateString, $budgetId, postInt('overrideMinutes', 0));
} else if (action('unlock')) {
  $user = post('user');
  $dateString = post('date');
  $budgetId = postInt('budgetId');
  $kfc->setOverrideUnlock($user, $dateString, $budgetId);
} else if (action('clearOverride')) {
  $user = post('user');
  $dateString = post('date');
  $budgetId = postInt('budgetId');
  $kfc->clearOverride($user, $dateString, $budgetId);
} else if (action('addMapping')) {
  $user = post('user');
  $budgetId = postInt('budgetId');
  $classId = post('classId');
  $kfc->addMapping($classId, $budgetId);
} else if (action('removeMapping')) {
  $user = post('user');
  $budgetId = postInt('budgetId');
  $classId = post('classId');
  $kfc->removeMapping($classId, $budgetId);

// TODO: Implement.
} else if (action('prune')) {
  $dateString = post('datePrune');
  $dateTime = DateTime::createFromFormat("Y-m-d", $dateString);
  $kfc->pruneTables($dateTime);
  echo '<b>Tables pruned before ' . getDateString($dateTime) . '</b></hr>';
} else if (action('clearAll')) {
  $kfc->dropAllTablesExceptConfig();
  echo '<b>Tables dropped</b></hr>';
}

$users = $kfc->getUsers();

if (!isset($user)) {
  $user = get('user') ?? post('user') ?? getOrDefault($users, 0, '');
}
if (!isset($dateString)) {
  $dateString = get('date') ?? date('Y-m-d');
}
if (!isset($budgetId)) {
  $budgetId = 0; // never exists, MySQL index is 1-based
}

$budgetConfigs = $kfc->getAllBudgetConfigs($user);
$budgetNames = budgetIdsToNames(array_keys($budgetConfigs), $budgetConfigs);
$classes = $kfc->getAllClasses();
$configs = $kfc->getAllBudgetConfigs($user);

echo '<h1>'.KFC_SERVER_HEADING.'</h1>
<p>(c) 2021 J&ouml;rg Zieren - <a href="http://zieren.de">zieren.de</a> - GNU GPL v3.
Components:
<a href="https://www.autohotkey.com/">AutoHotkey</a> by The AutoHotkey Foundation;
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

echo '<h3>Time left today</h3>';
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
foreach ($timeSpentByBudgetAndDate as $id=>$timeSpentByDate) {
  $timeSpentByBudget[$id] = getOrDefault($timeSpentByDate, $dateString, 0);
}
// TODO: Classes can map to budgets that are not configured (so not in $configs), or map to no
// budget at all.
echoTable(array(
    budgetIdsToNames(array_keys($timeSpentByBudget), $configs),
    array_map("secondsToHHMMSS", array_values($timeSpentByBudget))));
// --- END duplicate code

echo '<h3>Budgets</h3>';

// TODO: Handle invalid budget ID below. Currently a silent error.
// TODO: Show classes mapped to budget.

foreach ($budgetConfigs as $id => $config) {
  echo '<h4>' . html($budgetNames[$id]) . "</h4>\n";
  $config['id'] = $id;
  echoTableAssociative($config);
}

echo '
<h4>Configuration</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . budgetSelector($budgetNames, $budgetId) .
  '<input type="text" name="budgetConfigKey" value="" placeholder="key">
  <input type="text" name="budgetConfigValue" value="" placeholder="value">
  <input type="submit" value="Set config" name="setBudgetConfig">
  <input type="submit" value="Clear config" name="clearBudgetConfig">
  <input type="submit" value="!! Remove budget and its config !!" name="removeBudget">
</form>

<h4>New budget</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">
  <label for="idBudgetName">Budget name: </label>
  <input id="idBudgetName" name="budgetName" type="text" value="">
  <input type="submit" value="Add budget" name="addBudget">
</form>

<h4>New class</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">
  <label for="idClassName">Class name: </label>
  <input id="idClassName" name="className" type="text" value="">
  <input type="submit" value="Add class" name="addClass">
</form>
';

/* There is currently no user config. But there probably will be soon.
echo '<h3>User config</h3>';
echoTable($kfc->getAllUsersConfig());
*/

echo '<h4>Budgets to classes</h4>';
echoTable($kfc->getBudgetsToClassesTable($user));

echo '<h4>Map class to budget</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . classSelector($classes, 0) . '==> ' . budgetSelector($budgetNames, 0) . '
  <input type="submit" value="Add" name="addMapping">
  <input type="submit" value="Remove" name="removeMapping">
</form>
';

echo '<h4>Classes to classification</h4>';
echoTable($kfc->getClassesToClassificationTable());

echo '<h3>Global config</h3>';
echoTable($kfc->getGlobalConfig());

echo '<h2>Update config</h2>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="configUser" value="' . $user . '">
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
