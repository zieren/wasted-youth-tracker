<html>
<head>
  <title>Wasted Youth Tracker - Admin</title>
  <meta charset="iso-8859-1"/>
  <link rel="stylesheet" href="../common/wasted.css">
</head>
<body onload="setup()">
<script>
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
function setToday(id) {
  var today = new Date();
  var dateReference =
      today.getFullYear() + '-'
      + String(today.getMonth() + 1).padStart(2, '0') + '-'
      + String(today.getDate()).padStart(2, '0');
  document.querySelector("#" + id).value = dateReference;
}
function setWeekStart() {
  var d = new Date(document.querySelector("#idDateReference").value);
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); // 0 = Sun
  var date =
      d.getFullYear() + '-'
      + String(d.getMonth() + 1).padStart(2, '0') + '-'
      + String(d.getDate()).padStart(2, '0');
  document.querySelector("#idDateSince").value = date;
}
function setSameDay() {
  document.querySelector("#idDateSince").value = document.querySelector("#idDateReference").value;
}
function switchUser(select) {
  if (select.value) {
    document.querySelector('#idSelectedTab').value =
        document.querySelector('input.tabRadio:checked').id;
    select.form.submit();
  }
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
$tab = get('selectedTab') ?? 'idTabControl';
// TODO: Set this in action handlers, or hidden directly in forms.

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
  $dateReference = postString('dateReference');
  $limitId = postInt('limitId');
  $furtherLimits =
      Wasted::setOverrideMinutes($user, $dateReference, $limitId, postInt('overrideMinutes', 0));
} else if (action('setTimes')) {
  $user = postString('user');
  $dateReference = postString('dateReference');
  $limitId = postInt('limitId');
  $furtherLimits =
      Wasted::setOverrideSlots($user, $dateReference, $limitId, postString('overrideTimes', ''));
} else if (action('unlock')) {
  $user = postString('user');
  $dateReference = postString('dateReference');
  $limitId = postInt('limitId');
  $considerUnlocking = Wasted::setOverrideUnlock($user, $dateReference, $limitId);
} else if (action('clearOverrides')) {
  $user = postString('user');
  $dateReference = postString('dateReference');
  $limitId = postInt('limitId');
  Wasted::clearOverrides($user, $dateReference, $limitId);
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

// Set UI parameters from what was posted, or else to defaults.
if (!isset($user)) {
  $user = get('user') ?? postString('user') ?? getOrDefault($users, 0, '');
}
if (!isset($dateReference)) {
  $dateReference = postString('dateReference') ?? date('Y-m-d');
}
$dateSince = postString('dateSince');
if (!$dateSince || $dateSince > $dateReference) {
  $dateSince = getDateString(getWeekStart(new DateTime($dateReference)));
}
if (!isset($limitId)) {
  $limitId = 0; // never exists, MySQL index is 1-based
}

$unackedError = $user ? Wasted::getUnackedError($user) : '';
$limitConfigs = Wasted::getAllLimitConfigs($user);
$classes = Wasted::getAllClasses();
$configs = Wasted::getAllLimitConfigs($user);
$classifications = Wasted::getAllClassifications();

echo classificationSelectorJs($classifications);

// ----- Header and global settings -----

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
    <form action="index.php" method="get">
      <input type="hidden" id="idSelectedTab" name="selectedTab" value="idTabControl">'
      .userSelector($users, $user).'
    </form>

  </p>';

// ----- Tab setup -----

function inputRadioTab($id) {
  global $tab;
  echo
      '<input class="tabRadio" type="radio" id="'.$id.'" name="tabs" '
      .($id == $tab ? 'checked="checked"' : '').'>';
}

echo '
<div class="tabbed">';
inputRadioTab('idTabControl');
inputRadioTab('idTabLimits');
inputRadioTab('idTabClassification');
inputRadioTab('idTabActivity');
inputRadioTab('idTabSystem');
echo '
   <nav>
      <label for="idTabControl">Control</label>
      <label for="idTabLimits">Limits</label>
      <label for="idTabClassification">Classification</label>
      <label for="idTabActivity">Activity</label>
      <label for="idTabSystem">System</label>
   </nav>

   <figure>';

// ----- TAB: Control -----

echo '
<div class="tabControl">
  <form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '">

  <label for="idDateReference">Date:</label>
  <input id="idDateReference" type="date" value="'.$dateReference.'" name="dateReference"
      oninput="this.form.submit()" />
  <button onClick="setToday(\'idDateReference\')" type="submit">Today</button>

  <p>'.limitSelector($limitConfigs, $limitId).'</p>
  <p>
    <input type="submit" value="Unlock" name="unlock">
    <input type="submit" value="Clear overrides" name="clearOverrides">
  </p>
  <p>
    <label for="idOverrideMinutes">Minutes: </label>
    <input id="idOverrideMinutes" name="overrideMinutes" type="number" value="" min=0>
    <input type="submit" value="Set minutes" name="setMinutes">
  </p>
  <p>
    <label for="idOverrideTimes">Times: </label>
    <input id="idOverrideTimes" name="overrideTimes" type="text" value="">
    <input type="submit" value="Set times" name="setTimes">
  </p>

  <p>
    <label for="idDateSince">View since:</label>
    <input id="idDateSince" type="date" value="'.$dateSince.'" name="dateSince"
        oninput="this.form.submit()" />
    <button onClick="setWeekStart(\'idDateSince\')" type="submit">Start of week</button>
    <button onClick="setSameDay(\'idDateSince\')" type="submit">Single day</button>
  </p>

<h3>Overrides</h3>';

$recentOverrides = Wasted::queryRecentOverrides($user, $dateSince, $dateReference);
if ($recentOverrides) {
  echoTable(['Date', 'Limit', 'Minutes', 'Times', 'Lock'], $recentOverrides);
} else {
  echo 'no overrides';
}

$timeLeftByLimit = Wasted::queryTimeLeftTodayAllLimits($user);

echo '
  <h4>Available classes today</h4>
  <p>'.implode(', ', Wasted::queryClassesAvailableTodayTable($user, $timeLeftByLimit)).'</p>';

$timeSpentByLimitAndDate =
    Wasted::queryTimeSpentByLimitAndDate(
        $user,
        new DateTime($dateSince),
        (new DateTime($dateReference))->add(new DateInterval('P1D')));
$timeSpentByLimitToday = [];
$timeSpentByLimitRange = [];
foreach ($timeSpentByLimitAndDate as $id => $timeSpentByDate) {
  $timeSpentByLimitToday[$id] = getOrDefault($timeSpentByDate, $dateReference, 0);
  $timeSpentByLimitRange[$id] = array_sum($timeSpentByDate);
}
echo '<h3>Time left today</h3>';
echoTable(
    limitIdsToNames(array_keys($timeLeftByLimit), $configs),
    [array_map('secondsToHHMMSS', array_map('TimeLeft::toCurrentSeconds', $timeLeftByLimit))]);

echo '<h3>Time spent on selected date</h3>';
echo array_sum($timeSpentByLimitToday) > 0
    ? echoTable(
        limitIdsToNames(array_keys($timeSpentByLimitToday), $configs),
        [array_map("secondsToHHMMSS", array_values($timeSpentByLimitToday))])
    : 'no time spent';

if ($dateSince != $dateReference) {
echo '<h3>Time spent in selected date range</h3>';
echo count($timeSpentByLimitRange) > 0
    ? echoTable(
        limitIdsToNames(array_keys($timeSpentByLimitRange), $configs),
        [array_map("secondsToHHMMSS", array_values($timeSpentByLimitRange))])
    : 'no time spent';
}

echo '</form>';

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
  '<input type="submit" value="Remove" name="removeLimit"
      onclick="return confirm(\'Remove selected limit and its configuration?\');">
  <label for="idLimitName">Name: </label>
  <input id="idLimitName" name="limitName" type="text" value="">
  <input type="submit" value="Rename" name="renameLimit">
  <input type="submit" value="Add" name="addLimit">
</form>
';

echo '</div>'; // tab

// ----- TAB: Classification -----
echo '<div class="tabClassification">';

echo '<h4>Classification</h4>

<form method="post" action="index.php">
  <input type="hidden" name="user" value="' . $user . '"> '
  . classSelector($classes, false) .
  '<input type="submit" value="Remove" name="removeClass"
      onclick="return confirm(\'Remove class and its classification rules?\');">
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
  <input type="submit" value="Remove" name="removeClassification"
      onclick="return confirm(\'Remove classification?\');">
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

// ----- TAB: Activity -----
echo '<div class="tabActivity">';

echo '<h3>Most Recently Used</h3>';
// TODO: Evaluate date selector.
$fromTime = (clone Wasted::$now)->setTime(0, 0);
$timeSpentPerTitle = Wasted::queryTimeSpentByTitle($user, $fromTime, false);
for ($i = 0; $i < count($timeSpentPerTitle); $i++) {
  $timeSpentPerTitle[$i][1] = secondsToHHMMSS($timeSpentPerTitle[$i][1]);
}
echoTable(['Last Used', 'Time', 'Class', 'Title'], $timeSpentPerTitle);

echo '<h3>Most Time Spent</h3>';
$timeSpentPerTitle = Wasted::queryTimeSpentByTitle($user, $fromTime);
for ($i = 0; $i < count($timeSpentPerTitle); $i++) {
  $timeSpentPerTitle[$i][1] = secondsToHHMMSS($timeSpentPerTitle[$i][1]);
}
echoTable(['Last Used', 'Time', 'Class', 'Title'], $timeSpentPerTitle);

if (get('debug')) {
  echo '<h2>Window title sequence</h2>';
  echoTable(['From', 'To', 'Class', 'Title'], Wasted::queryTitleSequence($user, $fromTime));
}

echo '</div>'; // tab

// ----- TAB: System -----
echo '<div class="tabSystem">';

echo '<h3>User config</h3>';
echoTableAssociative(Wasted::getUserConfig($user));

echo '<h3>Global config</h3>';
echoTableAssociative(Wasted::getGlobalConfig());

echo '<h3>Update config</h3>
<form method="post" enctype="multipart/form-data">
  <p>
    <input type="hidden" name="configUser" value="' . $user . '">
    <input type="text" name="configKey" placeholder="key">
    <input type="text" name="configValue" placeholder="value">
  </p>

  <p>
    <input type="submit" name="setUserConfig" value="Set User Config">
    <input type="submit" name="clearUserConfig" value="Clear User Config">
  </p>

  <p>
    <input type="submit" name="setGlobalConfig" value="Set Global Config">
    <input type="submit" name="clearGlobalConfig" value="Clear Global Config">
  </p>
</form>

<h3>Users</h3>
<form method="post" enctype="multipart/form-data">
  <input type="text" name="userId" required="required" placeholder="id">
  <input type="submit" name="addUser" value="Add">
  <input type="submit" name="removeUser" value="Remove"
      onclick="return confirm(\'Remove user and all their data?\');">
</form>';

$pruneFromDate = (clone Wasted::$now)->sub(new DateInterval('P4W'));

echo '<h3>Manage Database</h3>
<form method="post">
  Delete activity (of all users!) and server logs older than
  <input type="date" name="datePrune" value="' . $pruneFromDate->format('Y-m-d') . '">
  <input type="submit" value="DELETE" name="prune"
      onclick="return confirm(\'Delete activity and server logs?\');" />
</form>';

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