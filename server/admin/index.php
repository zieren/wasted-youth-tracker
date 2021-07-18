<html>
<head>
  <title>Wasted Youth Tracker - Admin</title>
  <meta charset="iso-8859-1"/>
  <link rel="stylesheet" href="../common/kfc.css">
</head>
<body onload="setup()">
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
function toggleCollapsed(tr) {
  if (tr.classList.contains("expanded")) {
    tr.classList.remove("expanded");
  } else {
    tr.classList.add("expanded");
  }
};
function setup() {
  var trs = document.querySelectorAll("table.collapsible tr");
  trs.forEach(function(tr, index) {
    tr.addEventListener("click", function() { toggleCollapsed(tr); });
  });
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
$now = new DateTime();

if (action('setUserConfig')) {
  $user = postSanitized('configUser');
  $kfc->setUserConfig($user, postSanitized('configKey'), postSanitized('configValue'));
} else if (action('clearUserConfig')) {
  $user = postSanitized('configUser');
  $kfc->clearUserConfig($user, postSanitized('configKey'));
} else if (action('setGlobalConfig')) {
  $kfc->setGlobalConfig(postSanitized('configKey'), postSanitized('configValue'));
} else if (action('clearGlobalConfig')) {
  $kfc->clearGlobalConfig(postSanitized('configKey'));
} else if (action('addBudget')) {
  $user = postSanitized('user');
  $kfc->addBudget($user, postSanitized('budgetName'));
} else if (action('renameBudget')) {
  $budgetId = postInt('budgetId');
  $kfc->renameBudget($budgetId, postSanitized('budgetName'));
} else if (action('addClass')) {
  $kfc->addClass(postSanitized('className'));
} else if (action('removeBudget')) {
  $budgetId = postInt('budgetId');
  $kfc->removeBudget($budgetId);
} else if (action('setBudgetConfig')) {
  $budgetId = postInt('budgetId');
  $kfc->setBudgetConfig($budgetId, postSanitized('budgetConfigKey'), postSanitized('budgetConfigValue'));
} else if (action('clearBudgetConfig')) {
  $budgetId = postInt('budgetId');
  $kfc->clearBudgetConfig($budgetId, postSanitized('budgetConfigKey'));
} else if (action('setMinutes')) {
  $user = postSanitized('user');
  $dateString = postSanitized('date');
  $budgetId = postInt('budgetId');
  $kfc->setOverrideMinutes($user, $dateString, $budgetId, postInt('overrideMinutes', 0));
} else if (action('unlock')) {
  $user = postSanitized('user');
  $dateString = postSanitized('date');
  $budgetId = postInt('budgetId');
  $kfc->setOverrideUnlock($user, $dateString, $budgetId);
} else if (action('clearOverride')) {
  $user = postSanitized('user');
  $dateString = postSanitized('date');
  $budgetId = postInt('budgetId');
  $kfc->clearOverride($user, $dateString, $budgetId);
} else if (action('addMapping')) {
  $user = postSanitized('user');
  $budgetId = postInt('budgetId');
  $classId = postInt('classId');
  $kfc->addMapping($classId, $budgetId);
} else if (action('removeMapping')) {
  $user = postSanitized('user');
  $budgetId = postInt('budgetId');
  $classId = postInt('classId');
  $kfc->removeMapping($classId, $budgetId);
} else if (action('setTotalBudget')) {
  $user = postSanitized('user');
  $budgetId = postInt('budgetId');
  $kfc->setTotalBudget($user, $budgetId);
} else if (action('unsetTotalBudget')) {
  $user = postSanitized('user');
  $kfc->unsetTotalBudget($user);
} else if (action('removeClassification')) {
  $classificationId = postInt('classificationId');
  $kfc->removeClassification($classificationId);
} else if (action('addClassification')) {
  $classId = postInt('classId');
  $kfc->addClassification(
      $classId, postInt('classificationPriority'), postRaw('classificationRegEx'));
} else if (action('changeClassification')) {
  $classificationId = postInt('classificationId');
  $kfc->changeClassification($classificationId, postRaw('classificationRegEx'));
} else if (action('removeClass')) {
  $classId = postInt('classId');
  $kfc->removeClass($classId);
} else if (action('renameClass')) {
  $classId = postInt('classId');
  $kfc->renameClass($classId, postSanitized('className'));
} else if (action('doReclassify')) {
  $days = postInt('reclassificationDays');
  $fromTime = (clone $now)->sub(new DateInterval('P' . $days . 'D'));
  $kfc->reclassify($fromTime);

// TODO: Implement.
} else if (action('prune')) {
  $dateString = postSanitized('datePrune');
  $dateTime = DateTime::createFromFormat("Y-m-d", $dateString);
  $kfc->pruneTables($dateTime);
  echo '<b>Tables pruned before ' . getDateString($dateTime) . '</b></hr>';
}

$users = $kfc->getUsers();

if (!isset($user)) {
  $user = get('user') ?? postSanitized('user') ?? getOrDefault($users, 0, '');
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
$classifications = $kfc->getAllClassifications();

echo dateSelectorJs();
echo classificationSelectorJs($classifications);

echo '<h1>'.KFC_SERVER_HEADING.'</h1>
<p>&copy; 2021 J&ouml;rg Zieren - <a href="http://zieren.de">zieren.de</a> - GNU GPL v3.
Credits:
<a href="https://www.autohotkey.com/">AutoHotkey</a> by The AutoHotkey Foundation;
<a href="https://meekro.com/">MeekroDB</a> by Sergey Tsalkov, LGPL;
<a href="http://codefury.net/projects/klogger/">KLogger</a> by Kenny Katzgrau, MIT license

<p>
<form action="index.php" method="get" style="display: inline; margin-right: 1em;">'
. userSelector($users, $user) .
'</form>
<span style="display: inline; margin-right: 1em;">
  <a href="../view/index.php?user=' . $user . '">View activity</a>
</span>
<form style="display: inline; margin-right: 1em;">
  <input type="checkbox" name="confirm" id="idKfcEnableDestructive"
      onclick="enableDestructiveButtons(false)"/>
  <span onclick="enableDestructiveButtons(true)">Enable destructive actions
  (e.g. delete class/budget, prune activity)</span>
</form>
</p>

<h3>Overrides</h3>
  <form method="post" action="index.php">
    <input type="hidden" name="user" value="' . $user . '">'
    . dateSelector($dateString, false)
    . budgetSelector($budgetNames, $budgetId) .
    '<label for="idOverrideMinutes">Minutes: </label>
    <input id="idOverrideMinutes" name="overrideMinutes" type="number" value="" min=0>
    <input type="submit" value="Set Minutes" name="setMinutes">
    <input type="submit" value="Unlock" name="unlock">
    <input type="submit" value="Clear overrides" name="clearOverride">
  </form>';

echo '<h4>Current overrides</h4>';
echoTable(
    ['Date', 'Budget', 'Minutes', 'Lock'],
    $kfc->queryRecentOverrides($user));

// --- BEGIN duplicate code. TODO: Extract.
$fromTime = new DateTime($dateString);
$toTime = (clone $fromTime)->add(new DateInterval('P1D'));
$timeSpentByBudgetAndDate = $kfc->queryTimeSpentByBudgetAndDate($user, $fromTime, $toTime);
$timeSpentByBudget = [];
foreach ($timeSpentByBudgetAndDate as $id=>$timeSpentByDate) {
  $timeSpentByBudget[$id] = getOrDefault($timeSpentByDate, $dateString, 0);
}
// TODO: Classes can map to budgets that are not configured (so not in $configs), or map to no
// budget at all.
echo '<span class="inlineBlockWithMargin"><h3>Time spent on selected date</h3>';
echoTable(
    budgetIdsToNames(array_keys($timeSpentByBudget), $configs),
    [array_map("secondsToHHMMSS", array_values($timeSpentByBudget))]);
echo '</span>';
// --- END duplicate code

$timeLeftByBudget = $kfc->queryTimeLeftTodayAllBudgets($user);
echo '<span class="inlineBlock"><h3>Time left today</h3>';
echoTable(
    budgetIdsToNames(array_keys($timeLeftByBudget), $configs),
    [array_map("secondsToHHMMSS", array_values($timeLeftByBudget))]);
echo '</span>';

// TODO: This IGNORED the selected date. Add its own date selector?

$fromTime = (clone $now)->sub(new DateInterval('P7D'));
$topUnclassified = $kfc->queryTopUnclassified($user, $fromTime, false, 10);
foreach ($topUnclassified as &$i) {
  $i[0] = secondsToHHMMSS($i[0]);
}
echo '<br><span class="inlineBlockWithMargin"><h4>Top 10 unclassified last seven days, by recency</h4>';
echoTable(['Time', 'Title', 'Last Used'], $topUnclassified, 'titled inlineTableWithMargin limitTdWidth');
echo '</span>';

$fromTime = (clone $now)->sub(new DateInterval('P7D'));
$topUnclassified = $kfc->queryTopUnclassified($user, $fromTime, true, 10);
foreach ($topUnclassified as &$i) {
  $i[0] = secondsToHHMMSS($i[0]);
}
echo '<span class="inlineBlock"><h4>Top 10 unclassified last seven days, by total time</h4>';
echoTable(['Time', 'Title', 'Last Used'], $topUnclassified, 'titled inlineTable limitTdWidth');
echo '</span>';

echo '<h4>Classification (for all users)</h4>';
echoTable(
    ['Class', 'Classification', 'Prio', 'Matches', 'Samples (click to expand)'],
    $kfc->getClassesToClassificationTable(),
    'titled collapsible limitTdWidth');

echo '<hr><h4>Classes and Budgets</h4>';
echoTable(['Class', 'Budget'], $kfc->getBudgetsToClassesTable($user));

echo '<h4>Map class to budget</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . classSelector($classes, true) . '==> ' . budgetSelector($budgetNames, $budgetId) . '
  <input type="submit" value="Add" name="addMapping">
  <input type="submit" value="Remove" name="removeMapping">
</form>

<h4>Classification</h4>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '"> '
  . classSelector($classes, false) .
  '<input type="submit" value="Remove (incl. classification!)" name="removeClass"
    class="kfcDestructive" disabled>
  <label for="idClassName">Name: </label>
  <input id="idClassName" name="className" type="text" value="">
  <input type="submit" value="Rename" name="renameClass">
  <input type="submit" value="Add" name="addClass">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . classSelector($classes, false) . '
  <input type="text" name="classificationRegEx" value="" placeholder="Regular Expression">
  <input type="number" name="classificationPriority" value="0">
  <input type="submit" value="Add classification" name="addClassification">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . classificationSelector($classifications) . '
  <input type="submit" value="Remove" name="removeClassification" class="kfcDestructive" disabled>
  <input type="text" id="idClassificationRegEx" name="classificationRegEx" value=""
      style="width: 40em">
  <input type="submit" value="Change" name="changeClassification">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">
  <label for="idReclassificationDays">Previous days: </label>
  <input id="idReclassificationDays" type="number" name="reclassificationDays" value="7">
  <input type="submit" value="Reclassify" name="doReclassify">
</form>
';

echo '<h3>Budgets</h3>';

foreach ($budgetConfigs as $id => $config) {
  echo '<h4>' . html($budgetNames[$id]) . "</h4>\n";
  unset($config['name']);
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
  <input type="submit" value="Set total budget" name="setTotalBudget">
  <input type="submit" value="Unset total budget" name="unsetTotalBudget">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '"> '
  . budgetSelector($budgetNames, $budgetId) .
  '<input type="submit" value="Remove (incl. config!)" name="removeBudget"
    class="kfcDestructive" disabled>
  <label for="idBudgetName">Name: </label>
  <input id="idBudgetName" name="budgetName" type="text" value="">
  <input type="submit" value="Rename" name="renameBudget">
  <input type="submit" value="Add" name="addBudget">
</form>
';

echo '<h3>User config</h3>';
echoTableAssociative($kfc->getUserConfig($user));

echo '<h3>Global config</h3>';
echoTableAssociative($kfc->getGlobalConfig());

echo '<h3>Update config</h3>
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
';
?>
</body>
</html>
