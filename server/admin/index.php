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
    $unmet[] = 'PHP version '.PHP_MIN_VERSION.' is required, but this is '.PHP_VERSION.'.';
  }
  if (!function_exists('mysqli_connect')) {
    $unmet[] = 'The mysqli extension is missing.';
  }
  if (!$unmet) {
    return;
  }
  echo '<p><b>The following requirements are not met:</b></p>'
  . '<ul><li>' . implode('</li><li>', $unmet) . '</li></ul><hr />';
  throw new Exception(implode($unmet));
}
checkRequirements();

Wasted::initialize(true);

$considerUnlocking = false;
$furtherLimits = false;
$tab = 'idTabOverrides';

if (action('setUserConfig')) {
  $user = postString('configUser');
  Wasted::setUserConfig($user, postString('configKey'), postString('configValue'));
} else if (action('clearUserConfig')) {
  $user = postString('configUser');
  Wasted::clearUserConfig($user, postString('configKey'));
} else if (action('setGlobalConfig')) {
  Wasted::setGlobalConfig(postString('configKey'), postString('configValue'));
} else if (action('clearGlobalConfig')) {
  Wasted::clearGlobalConfig(postString('configKey'));
} else if (action('addLimit')) {
  $user = postString('user');
  Wasted::addLimit($user, postString('limitName'));
} else if (action('renameLimit')) {
  $limitId = postInt('limitId');
  Wasted::renameLimit($limitId, postString('limitName'));
} else if (action('addClass')) {
  Wasted::addClass(postString('className'));
} else if (action('removeLimit')) {
  $limitId = postInt('limitId');
  Wasted::removeLimit($limitId);
} else if (action('setLimitConfig')) {
  $limitId = postInt('limitId');
  Wasted::setLimitConfig($limitId, postString('limitConfigKey'), postString('limitConfigValue'));
} else if (action('clearLimitConfig')) {
  $limitId = postInt('limitId');
  Wasted::clearLimitConfig($limitId, postString('limitConfigKey'));
} else if (action('setMinutes')) {
  $user = postString('user');
  $dateString = postString('date');
  $limitId = postInt('limitId');
  $furtherLimits =
      Wasted::setOverrideMinutes($user, $dateString, $limitId, postInt('overrideMinutes', 0));
} else if (action('setTimes')) {
  $user = postString('user');
  $dateString = postString('date');
  $limitId = postInt('limitId');
  $furtherLimits =
      Wasted::setOverrideSlots($user, $dateString, $limitId, postString('overrideTimes', ''));
} else if (action('unlock')) {
  $user = postString('user');
  $dateString = postString('date');
  $limitId = postInt('limitId');
  $considerUnlocking = Wasted::setOverrideUnlock($user, $dateString, $limitId);
} else if (action('clearOverrides')) {
  $user = postString('user');
  $dateString = postString('date');
  $limitId = postInt('limitId');
  Wasted::clearOverrides($user, $dateString, $limitId);
} else if (action('addMapping')) {
  $user = postString('user');
  $limitId = postInt('limitId');
  $classId = postInt('classId');
  Wasted::addMapping($classId, $limitId);
} else if (action('removeMapping')) {
  $user = postString('user');
  $limitId = postInt('limitId');
  $classId = postInt('classId');
  Wasted::removeMapping($classId, $limitId);
} else if (action('removeClassification')) {
  $classificationId = postInt('classificationId');
  Wasted::removeClassification($classificationId);
} else if (action('addClassification')) {
  $classId = postInt('classId');
  Wasted::addClassification(
      $classId, postInt('classificationPriority'), postRaw('classificationRegEx'));
} else if (action('changeClassification')) {
  $tab = 'idTabClassification';
  $classificationId = postInt('classificationId');
  Wasted::changeClassification(
      $classificationId, postRaw('classificationRegEx'), postRaw('classificationPriority'));
} else if (action('removeClass')) {
  $classId = postInt('classId');
  Wasted::removeClass($classId);
} else if (action('renameClass')) {
  $classId = postInt('classId');
  Wasted::renameClass($classId, postString('className'));
} else if (action('doReclassify')) {
  $days = postInt('reclassificationDays');
  $fromTime = (clone Wasted::$now)->sub(new DateInterval('P' . $days . 'D'));
  Wasted::reclassify($fromTime);
} else if (action('addUser')) {
  $newUser = postString('userId');
  Wasted::addUser($newUser);
} else if (action('removeUser')) {
  $newUser = postString('userId');
  Wasted::removeUser($newUser);
} else if (action('ackError')) {
  $user = postString('user');
  $ackedError = postString('ackedError');
  Wasted::ackError($user, $ackedError);
} else if (action('prune')) {
  // Need to postfix 00:00:00 to not get current time of day.
  $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', postString('datePrune') . ' 00:00:00');
  Wasted::pruneTables($dateTime);
  echo '<b class="notice">Deleted data before ' . getDateString($dateTime) . '</b></hr>';
}

$users = Wasted::getUsers();

if (!isset($user)) {
  $user = get('user') ?? postString('user') ?? getOrDefault($users, 0, '');
}
if (!isset($dateString)) {
  $dateString = get('date') ?? date('Y-m-d');
}
if (!isset($limitId)) {
  $limitId = 0; // never exists, MySQL index is 1-based
}
$unackedError = $user ? Wasted::getUnackedError($user) : '';

$limitConfigs = Wasted::getAllLimitConfigs($user);
$classes = Wasted::getAllClasses();
$configs = Wasted::getAllLimitConfigs($user);
$classifications = Wasted::getAllClassifications();

echo dateSelectorJs();
echo classificationSelectorJs($classifications);

// ----- Header and global settings -----
// TODO: Move credits to their own tab, or to the bottom.

echo '<h1>'.WASTED_SERVER_HEADING.'</h1>';
if ($unackedError) {
  $ackedError = substr($unackedError, 0, 15);
  // TODO: Probably just htmlentities() instead of html() below.
  echo '
    <p class="warning" style="display: inline; margin-right: 1em;">
    Last client error: '.html($unackedError).'</p>
    <form method="post" action="index.php"  style="display: inline;">
      <input type="hidden" name="ackedError" value="'.html($ackedError).'">
      <input type="hidden" name="user" value="'.$user.'">
      <input type="submit" value="Acknowledge" name="ackError">
    </form>';
}
if ($considerUnlocking) {
  $limitIdToName = getLimitIdToNameMap($configs);
  echo '
    <p class="notice">Further locked limits affecting classes in "'
      .$limitIdToName[$limitId].'": <b>'.html(implode($considerUnlocking, ', ')).'</b>';
} else if ($furtherLimits) {
  $limitIdToName = getLimitIdToNameMap($configs);
  echo '
    <p class="notice">Further limits affecting classes in "'
      .$limitIdToName[$limitId].'": <b>'.html(implode($furtherLimits, ', ')).'</b>';
}
echo '
  <p>
  <form action="index.php" method="get" style="display: inline; margin-right: 1em;">'
    .userSelector($users, $user).'
  </form>
  <span style="display: inline; margin-right: 1em;">
  <a href="../view/index.php?user=' . $user . '">View activity</a>
  </span>
  <form style="display: inline; margin-right: 1em;">
    <input type="checkbox" name="confirm" id="idWastedEnableDestructive"
      onclick="enableDestructiveButtons(false)"/>
    <span onclick="enableDestructiveButtons(true)">Enable destructive actions
      (e.g. delete class/limit, prune activity)</span>
  </form>
  </p>';

// ----- Tab setup -----

function inputRadioTab($id) {
  global $tab;
  echo
      '<input type="radio" id="'.$id.'" name="tabs" '.($id == $tab ? 'checked="checked"' : '').'/>';
}

echo '
<div class="tabbed">';
inputRadioTab('idTabOverrides');
inputRadioTab('idTabLimits');
inputRadioTab('idTabClassification');
echo '
   <nav>
      <label for="idTabOverrides">Overrides</label>
      <label for="idTabLimits">Limits</label>
      <label for="idTabClassification">Classification</label>
   </nav>

   <figure>';

// ----- TAB: Overrides -----

echo '
<div class="tabOverrides">
  <h3>Overrides</h3>
  <form method="post" action="index.php">
    <input type="hidden" name="user" value="' . $user . '">'
    . dateSelector($dateString, false)
    . limitSelector($limitConfigs, $limitId) .
    '<label for="idOverrideMinutes">Minutes: </label>
    <input id="idOverrideMinutes" name="overrideMinutes" type="number" value="" min=0>
    <input type="submit" value="Set minutes" name="setMinutes">
    <label for="idOverrideTimes">Times: </label>
    <input id="idOverrideTimes" name="overrideTimes" type="text" value="">
    <input type="submit" value="Set times" name="setTimes">
    <input type="submit" value="Unlock" name="unlock">
    <input type="submit" value="Clear overrides" name="clearOverrides">
  </form>
  <h4>This week\'s overrides</h4>';

echoTable(
    ['Date', 'Limit', 'Minutes', 'Times', 'Lock'],
    Wasted::queryRecentOverrides($user));

$timeLeftByLimit = Wasted::queryTimeLeftTodayAllLimits($user);

echo '
  <h4>Available classes today</h4>
  <p>'.implode(', ', Wasted::queryClassesAvailableTodayTable($user, $timeLeftByLimit)).'</p>';

// --- BEGIN duplicate code. TODO: Extract.
$fromTime = new DateTime($dateString);
$toTime = (clone $fromTime)->add(new DateInterval('P1D'));
$timeSpentByLimitAndDate = Wasted::queryTimeSpentByLimitAndDate($user, $fromTime, $toTime);
$timeSpentByLimit = [];
foreach ($timeSpentByLimitAndDate as $id=>$timeSpentByDate) {
  $timeSpentByLimit[$id] = getOrDefault($timeSpentByDate, $dateString, 0);
}
echo '<h3>Time left today</h3>';
echoTable(
    limitIdsToNames(array_keys($timeLeftByLimit), $configs),
    [array_map('secondsToHHMMSS', array_map('TimeLeft::toCurrentSeconds', $timeLeftByLimit))]);

echo '<h3>Time spent on selected date</h3>';
echo count($timeSpentByLimit) > 0
    ? echoTable(
        limitIdsToNames(array_keys($timeSpentByLimit), $configs),
        [array_map("secondsToHHMMSS", array_values($timeSpentByLimit))])
    : 'no time spent';

// --- END duplicate code

// TODO: This IGNORED the selected date. Add its own date selector?

echo '</div>'; // tab

// ----- TAB: Limits -----

echo '<div class="tabLimits">
<h4>Limits and classes</h4>';
echoTable(['Limit', 'Class', 'Further limits', 'Config'], Wasted::getLimitsToClassesTable($user));

echo '
<h4>Map class to limit</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . classSelector($classes, true) . '==> ' . limitSelector($limitConfigs, $limitId, true) . '
  <input type="submit" value="Add" name="addMapping">
  <input type="submit" value="Remove" name="removeMapping">
</form>';

echo '<h3>Limits</h3>';

foreach ($limitConfigs as $id => $config) {
  echo '<h4>' . html($config['name']) . "</h4>\n";
  unset($config['name']);
  unset($config['is_total']);
  echoTableAssociative($config);
}
echo '
<h4>Configuration</h4>
<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">'
  . limitSelector($limitConfigs, $limitId) .
  '<input type="text" name="limitConfigKey" value="" placeholder="key">
  <input type="text" name="limitConfigValue" value="" placeholder="value">
  <input type="submit" value="Set config" name="setLimitConfig">
  <input type="submit" value="Clear config" name="clearLimitConfig">
</form>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '"> '
  . limitSelector($limitConfigs, $limitId, true) .
  '<input type="submit" value="Remove (incl. config!)" name="removeLimit"
    class="wastedDestructive" disabled>
  <label for="idLimitName">Name: </label>
  <input id="idLimitName" name="limitName" type="text" value="">
  <input type="submit" value="Rename" name="renameLimit">
  <input type="submit" value="Add" name="addLimit">
</form>
';

echo '<h3>User config</h3>';
echoTableAssociative(Wasted::getUserConfig($user));

echo '<h3>Global config</h3>';
echoTableAssociative(Wasted::getGlobalConfig());

echo '<h3>Update config</h3>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="configUser" value="' . $user . '">
  <input type="text" name="configKey" placeholder="key">
  <input type="text" name="configValue" placeholder="value">
  <input type="submit" name="setUserConfig" value="Set User Config">
  <input type="submit" name="clearUserConfig" value="Clear User Config">
  <input type="submit" name="setGlobalConfig" value="Set Global Config">
  <input type="submit" name="clearGlobalConfig" value="Clear Global Config">
</form>

<hr />';

echo '<h3>Users</h3>
<form method="post" enctype="multipart/form-data">
  <input type="text" name="userId" required="required" placeholder="id">
  <input type="submit" name="addUser" value="Add">
  <input type="submit" name="removeUser" value="Remove" class="wastedDestructive" disabled>
</form>

<hr />';

$pruneFromDate = (clone Wasted::$now)->sub(new DateInterval('P4W'));

echo '<h2>Manage Database</h2>
<form method="post">
  Delete activity (of all users!) and server logs older than
  <input type="date" name="datePrune" value="' . $pruneFromDate->format('Y-m-d') . '">
  <input class="wastedDestructive" type="submit" value="DELETE" name="prune" disabled />
</form>';

echo '</div>'; // tab

// ----- TAB: Classification -----
echo '<div class="tabClassification">';

echo '<h4>Classification</h4>

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
</form>';

$fromTime = (clone Wasted::$now)->sub(new DateInterval('P7D'));
$topUnclassified = Wasted::queryTopUnclassified($user, $fromTime, false, 10);
foreach ($topUnclassified as &$i) {
  $i[0] = secondsToHHMMSS($i[0]);
}
echo '<br><span class="inlineBlockWithMargin"><h4>Top 10 unclassified last seven days, by recency</h4>';
echoTable(['Time', 'Title', 'Last Used'], $topUnclassified, 'titled inlineTableWithMargin limitTdWidth');
echo '</span>';

$topUnclassified = Wasted::queryTopUnclassified($user, $fromTime, true, 10);
foreach ($topUnclassified as &$i) {
  $i[0] = secondsToHHMMSS($i[0]);
}
echo '<span class="inlineBlock"><h4>Top 10 unclassified last seven days, by total time</h4>';
echoTable(['Time', 'Title', 'Last Used'], $topUnclassified, 'titled inlineTable limitTdWidth');
echo '</span>';

echo '
<h4>Classification (for all users)</h4>';
echoTable(
    ['Class', 'Classification', 'Prio', 'Matches', 'Samples (click to expand)'],
    Wasted::getClassesToClassificationTable(),
    'titled collapsible limitTdWidth');
echo '</div>'; // tab

// ----- Tabs: closing tags -----

echo '</figure></div>'; // from div setup

// ----- Footer -----

echo '
<p>&copy; 2021 J&ouml;rg Zieren (<a href="https://zieren.de">zieren.de</a>), GNU GPL v3.
Components:
<a href="https://www.autohotkey.com/">AutoHotkey</a> by The AutoHotkey Foundation, GNU GPL v2;
<a href="https://meekro.com/">MeekroDB</a> by Sergey Tsalkov, GNU LGPL v3;
<a href="https://github.com/katzgrau/KLogger">KLogger</a> by Kenny Katzgrau, MIT license.
</body>
</html>';