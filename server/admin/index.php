<html>
<head>
  <title>Wasted Youth Tracker - Admin</title>
  <meta charset="iso-8859-1"/>
  <link rel="stylesheet" href="../common/wasted.css">
</head>
<body onload="setup()">
<script>
function enableDestructiveButtons(toggleCheckbox) {
  var checkbox = document.getElementById('idWastedEnableDestructive');
  if (toggleCheckbox) {
    checkbox.checked = !checkbox.checked;
  }
  var buttons = document.getElementsByClassName('wastedDestructive');
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

$wasted = Wasted::create(true /* create missing tables */);
$now = new DateTime();

if (action('setUserConfig')) {
  $user = postSanitized('configUser');
  $wasted->setUserConfig($user, postSanitized('configKey'), postSanitized('configValue'));
} else if (action('clearUserConfig')) {
  $user = postSanitized('configUser');
  $wasted->clearUserConfig($user, postSanitized('configKey'));
} else if (action('setGlobalConfig')) {
  $wasted->setGlobalConfig(postSanitized('configKey'), postSanitized('configValue'));
} else if (action('clearGlobalConfig')) {
  $wasted->clearGlobalConfig(postSanitized('configKey'));
} else if (action('addLimit')) {
  $user = postSanitized('user');
  $wasted->addLimit($user, postSanitized('limitName'));
} else if (action('renameLimit')) {
  $limitId = postInt('limitId');
  $wasted->renameLimit($limitId, postSanitized('limitName'));
} else if (action('addClass')) {
  $wasted->addClass(postSanitized('className'));
} else if (action('removeLimit')) {
  $limitId = postInt('limitId');
  $wasted->removeLimit($limitId);
} else if (action('setLimitConfig')) {
  $limitId = postInt('limitId');
  $wasted->setLimitConfig(
      $limitId, postSanitized('limitConfigKey'), postSanitized('limitConfigValue'));
} else if (action('clearLimitConfig')) {
  $limitId = postInt('limitId');
  $wasted->clearLimitConfig($limitId, postSanitized('limitConfigKey'));
} else if (action('setMinutes')) {
  $user = postSanitized('user');
  $dateString = postSanitized('date');
  $limitId = postInt('limitId');
  $furtherLimits =
      $wasted->setOverrideMinutes($user, $dateString, $limitId, postInt('overrideMinutes', 0));
} else if (action('unlock')) {
  $user = postSanitized('user');
  $dateString = postSanitized('date');
  $limitId = postInt('limitId');
  $considerUnlocking = $wasted->setOverrideUnlock($user, $dateString, $limitId);
} else if (action('clearOverrides')) {
  $user = postSanitized('user');
  $dateString = postSanitized('date');
  $limitId = postInt('limitId');
  $wasted->clearOverrides($user, $dateString, $limitId);
} else if (action('addMapping')) {
  $user = postSanitized('user');
  $limitId = postInt('limitId');
  $classId = postInt('classId');
  $wasted->addMapping($classId, $limitId);
} else if (action('removeMapping')) {
  $user = postSanitized('user');
  $limitId = postInt('limitId');
  $classId = postInt('classId');
  $wasted->removeMapping($classId, $limitId);
} else if (action('setTotalLimit')) {
  $user = postSanitized('user');
  $limitId = postInt('limitId');
  $wasted->setTotalLimit($user, $limitId);
} else if (action('unsetTotalLimit')) {
  $user = postSanitized('user');
  $wasted->unsetTotalLimit($user);
} else if (action('removeClassification')) {
  $classificationId = postInt('classificationId');
  $wasted->removeClassification($classificationId);
} else if (action('addClassification')) {
  $classId = postInt('classId');
  $wasted->addClassification(
      $classId, postInt('classificationPriority'), postRaw('classificationRegEx'));
} else if (action('changeClassification')) {
  $classificationId = postInt('classificationId');
  $wasted->changeClassification(
      $classificationId, postRaw('classificationRegEx'), postRaw('classificationPriority'));
} else if (action('removeClass')) {
  $classId = postInt('classId');
  $wasted->removeClass($classId);
} else if (action('renameClass')) {
  $classId = postInt('classId');
  $wasted->renameClass($classId, postSanitized('className'));
} else if (action('doReclassify')) {
  $days = postInt('reclassificationDays');
  $fromTime = (clone $now)->sub(new DateInterval('P' . $days . 'D'));
  $wasted->reclassify($fromTime);

// TODO: Implement.
} else if (action('prune')) {
  $dateString = postSanitized('datePrune');
  $dateTime = DateTime::createFromFormat("Y-m-d", $dateString);
  $wasted->pruneTables($dateTime);
  echo '<b>Tables pruned before ' . getDateString($dateTime) . '</b></hr>';
}

$users = $wasted->getUsers();

if (!isset($user)) {
  $user = get('user') ?? postSanitized('user') ?? getOrDefault($users, 0, '');
}
if (!isset($dateString)) {
  $dateString = get('date') ?? date('Y-m-d');
}
if (!isset($limitId)) {
  $limitId = 0; // never exists, MySQL index is 1-based
}

$limitConfigs = $wasted->getAllLimitConfigs($user);
$limitNames = limitIdsToNames(array_keys($limitConfigs), $limitConfigs);
$classes = $wasted->getAllClasses();
$configs = $wasted->getAllLimitConfigs($user);
$classifications = $wasted->getAllClassifications();

echo dateSelectorJs();
echo classificationSelectorJs($classifications);

echo '<h1>'.WASTED_SERVER_HEADING.'</h1>
<p>&copy; 2021 J&ouml;rg Zieren - <a href="http://zieren.de">zieren.de</a> - GNU GPL v3.
Credits:
<a href="https://www.autohotkey.com/">AutoHotkey</a> by The AutoHotkey Foundation;
<a href="https://meekro.com/">MeekroDB</a> by Sergey Tsalkov, LGPL;
<a href="http://codefury.net/projects/klogger/">KLogger</a> by Kenny Katzgrau, MIT license';
if ($considerUnlocking) {
  $limitIdToName = getLimitIdToNameMap($configs);
  echo '<p class="notice">Further locked limits affecting classes in "'
      . $limitIdToName[$limitId] . '": <b>' . html(implode($considerUnlocking, ', ')) . '</b>';
} else if ($furtherLimits) {
  $limitIdToName = getLimitIdToNameMap($configs);
  echo '<p class="notice">Further limits affecting classes in "'
      . $limitIdToName[$limitId] . '": <b>' . html(implode($furtherLimits, ', ')) . '</b>';
}
echo '<p>
<form action="index.php" method="get" style="display: inline; margin-right: 1em;">'
. userSelector($users, $user) .
'</form>
<span style="display: inline; margin-right: 1em;">
  <a href="../view/index.php?user=' . $user . '">View activity</a>
</span>
<form style="display: inline; margin-right: 1em;">
  <input type="checkbox" name="confirm" id="idWastedEnableDestructive"
      onclick="enableDestructiveButtons(false)"/>
  <span onclick="enableDestructiveButtons(true)">Enable destructive actions
  (e.g. delete class/limit, prune activity)</span>
</form>
</p>

<h3>Overrides</h3>
  <form method="post" action="index.php">
    <input type="hidden" name="user" value="' . $user . '">'
    . dateSelector($dateString, false)
    . limitSelector($limitNames, $limitId) .
    '<label for="idOverrideMinutes">Minutes: </label>
    <input id="idOverrideMinutes" name="overrideMinutes" type="number" value="" min=0>
    <input type="submit" value="Set minutes" name="setMinutes">
    <input type="submit" value="Unlock" name="unlock">
    <input type="submit" value="Clear overrides" name="clearOverrides">
  </form>';

echo '<h4>Current overrides</h4>';
echoTable(
    ['Date', 'Limit', 'Minutes', 'Lock'],
    $wasted->queryRecentOverrides($user));

$timeLeftByLimit = $wasted->queryTimeLeftTodayAllLimits($user);

echo '<h4>Available classes today</h4>
<p>' . implode(', ', $wasted->queryClassesAvailableTodayTable($user, $timeLeftByLimit)) . '</p>';

// --- BEGIN duplicate code. TODO: Extract.
$fromTime = new DateTime($dateString);
$toTime = (clone $fromTime)->add(new DateInterval('P1D'));
$timeSpentByLimitAndDate = $wasted->queryTimeSpentByLimitAndDate($user, $fromTime, $toTime);
$timeSpentByLimit = [];
foreach ($timeSpentByLimitAndDate as $id=>$timeSpentByDate) {
  $timeSpentByLimit[$id] = getOrDefault($timeSpentByDate, $dateString, 0);
}
// TODO: Classes can map to limits that are not configured (so not in $configs), or map to no
// limit at all.
echo '<span class="inlineBlockWithMargin"><h3>Time spent on selected date</h3>';
echoTable(
    limitIdsToNames(array_keys($timeSpentByLimit), $configs),
    [array_map("secondsToHHMMSS", array_values($timeSpentByLimit))]);
echo '</span>';
// --- END duplicate code

echo '<span class="inlineBlock"><h3>Time left today</h3>';
echoTable(
    limitIdsToNames(array_keys($timeLeftByLimit), $configs),
    [array_map("secondsToHHMMSS", array_values($timeLeftByLimit))]);
echo '</span>';

// TODO: This IGNORED the selected date. Add its own date selector?

$fromTime = (clone $now)->sub(new DateInterval('P7D'));
$topUnclassified = $wasted->queryTopUnclassified($user, $fromTime, false, 10);
foreach ($topUnclassified as &$i) {
  $i[0] = secondsToHHMMSS($i[0]);
}
echo '<br><span class="inlineBlockWithMargin"><h4>Top 10 unclassified last seven days, by recency</h4>';
echoTable(['Time', 'Title', 'Last Used'], $topUnclassified, 'titled inlineTableWithMargin limitTdWidth');
echo '</span>';

$fromTime = (clone $now)->sub(new DateInterval('P7D'));
$topUnclassified = $wasted->queryTopUnclassified($user, $fromTime, true, 10);
foreach ($topUnclassified as &$i) {
  $i[0] = secondsToHHMMSS($i[0]);
}
echo '<span class="inlineBlock"><h4>Top 10 unclassified last seven days, by total time</h4>';
echoTable(['Time', 'Title', 'Last Used'], $topUnclassified, 'titled inlineTable limitTdWidth');
echo '</span>';

echo '<h4>Classification (for all users)</h4>';
echoTable(
    ['Class', 'Classification', 'Prio', 'Matches', 'Samples (click to expand)'],
    $wasted->getClassesToClassificationTable(),
    'titled collapsible limitTdWidth');

echo '<hr><h4>Limits and classes</h4>';
echoTable(['Limit', 'Class', 'Further limits'], $wasted->getLimitsToClassesTable($user));

echo '<h4>Map class to limit</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . classSelector($classes, true) . '==> ' . limitSelector($limitNames, $limitId) . '
  <input type="submit" value="Add" name="addMapping">
  <input type="submit" value="Remove" name="removeMapping">
</form>

<h4>Classification</h4>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '"> '
  . classSelector($classes, false) .
  '<input type="submit" value="Remove (incl. classification!)" name="removeClass"
    class="wastedDestructive" disabled>
  <label for="idClassName">Name: </label>
  <input id="idClassName" name="className" type="text" value="">
  <input type="submit" value="Rename" name="renameClass">
  <input type="submit" value="Add" name="addClass">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . classSelector($classes, false) . '
  <input type="text" name="classificationRegEx" value="" placeholder="Regular Expression">
  Prio: <input type="number" name="classificationPriority" value="0">
  <input type="submit" value="Add classification" name="addClassification">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . classificationSelector($classifications) . '
  <input type="submit" value="Remove" name="removeClassification" class="wastedDestructive"
      disabled>
  <input type="text" id="idClassificationRegEx" name="classificationRegEx" value="">
  Prio: <input type="number" name="classificationPriority" id="idClassificationPriority" value="0">
  <input type="submit" value="Change" name="changeClassification">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">
  <label for="idReclassificationDays">Previous days: </label>
  <input id="idReclassificationDays" type="number" name="reclassificationDays" value="7">
  <input type="submit" value="Reclassify" name="doReclassify">
</form>
';

echo '<h3>Limits</h3>';

foreach ($limitConfigs as $id => $config) {
  echo '<h4>' . html($limitNames[$id]) . "</h4>\n";
  unset($config['name']);
  echoTableAssociative($config);
}
echo '
<h4>Configuration</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . limitSelector($limitNames, $limitId) .
  '<input type="text" name="limitConfigKey" value="" placeholder="key">
  <input type="text" name="limitConfigValue" value="" placeholder="value">
  <input type="submit" value="Set config" name="setLimitConfig">
  <input type="submit" value="Clear config" name="clearLimitConfig">
  <input type="submit" value="Set total limit" name="setTotalLimit">
  <input type="submit" value="Unset total limit" name="unsetTotalLimit">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '"> '
  . limitSelector($limitNames, $limitId) .
  '<input type="submit" value="Remove (incl. config!)" name="removeLimit"
    class="wastedDestructive" disabled>
  <label for="idLimitName">Name: </label>
  <input id="idLimitName" name="limitName" type="text" value="">
  <input type="submit" value="Rename" name="renameLimit">
  <input type="submit" value="Add" name="addLimit">
</form>
';

echo '<h3>User config</h3>';
echoTableAssociative($wasted->getUserConfig($user));

echo '<h3>Global config</h3>';
echoTableAssociative($wasted->getGlobalConfig());

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
  <input class="wastedDestructive" type="submit" value="PRUNE" name="prune" disabled />
</form>
';
?>
</body>
</html>
