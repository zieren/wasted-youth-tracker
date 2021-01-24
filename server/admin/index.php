<html>
<head>
  <title>KFC Admin</title>
  <link rel="stylesheet" href="../common/kfc.css">
</head>
<body>
<script type="text/javascript">
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

$db = new Database(true /* create missing tables */);

// TODO: This should sanitize the user input.
if (isset($_POST['setUserConfig'])) {
  $user = trim($_POST['configUser']);
  $key = trim($_POST['configKey']);
  $db->setUserConfig($user, $key, $_POST['configValue']);
} else if (isset($_POST['clearUserConfig'])) {
  $user = trim($_POST['configUser']);
  $key = trim($_POST['configKey']);
  $db->clearUserConfig($user, $key);
} else if (isset($_POST['setGlobalConfig'])) {
  $key = trim($_POST['configKey']);
  $db->setGlobalConfig($key, $_POST['configValue']);
} else if (isset($_POST['clearGlobalConfig'])) {
  $key = trim($_POST['configKey']);
  $db->clearGlobalConfig($key);
} else if (isset($_POST['setMinutes'])) {
  $user = $_POST['user'];
  $dateString = $_POST['dateOverride'];
  $minutes = get($_POST['overrideMinutes'], 0);
  $db->setOverrideMinutes($user, $dateString, $minutes);
} else if (isset($_POST['unlock'])) {
  $user = $_POST['user'];
  $dateString = $_POST['dateOverride'];
  $db->setOverrideUnlock($user, $dateString, 1);
} else if (isset($_POST['clearOverride'])) {
  $user = $_POST['user'];
  $dateString = $_POST['dateOverride'];
  $db->clearOverride($user, $dateString);
} else if (isset($_POST['prune'])) {
  $dateString = $_POST['datePrune'];
  $dateTime = DateTime::createFromFormat("Y-m-d", $dateString);
  $db->pruneTables($dateTime);
  echo '<b>Tables pruned before ' . getDateString($dateTime) . '</b></hr>';
} else if (isset($_POST['clearAll'])) {
  $db->dropAllTablesExceptConfig();
  echo '<b>Tables dropped</b></hr>';
}

echo '
<h1>'.KFC_SERVER_HEADING.'</h1>
<p>(c) 2021 J&ouml;rg Zieren - <a href="http://zieren.de">zieren.de</a> - GNU GPL v3.
Components:
<a href="http://codefury.net/projects/klogger/">KLogger</a> by Kenny Katzgrau, MIT license.</p>
<p><a href="../view/">View activity</a></p>

<h2>Configuration</h2>

<h3>Users</h3>';
$users = $db->getUsers();
echo implode(",", $users);

$now = new DateTime();
echo '
<h3>Overrides</h3>
  <form method="post">
    <label for="idUser">User:</label>
    <select id="idUser" name="user">';
foreach ($users as $u) {
  echo '<option value="' . $u . '">' . $u . '</option>';
}
echo '
    </select>
  <input type="date" name="dateOverride" value="' . getDateString($now) . '">
  <label for="idOverrideMinutes">Minutes: </label>
  <input id="idOverrideMinutes" name="overrideMinutes" type="number" value="" min=0>
  <input type="submit" value="Set Minutes" name="setMinutes">
  <input type="submit" value="Unlock" name="unlock">
  <input type="submit" value="Clear" name="clearOverride">
</form>';

foreach ($users as $u) {
  echo '<h4>' . $u . '</h4>';
  echoTable($db->queryRecentOverrides($u));
}

echo '<h3>User config</h3>';
echoTable($db->getAllUsersConfig());

echo '<h3>Global config</h3>';
echoTable($db->getGlobalConfig());

echo '<h3>Update</h3>
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
<p>
  PRUNE data and logs before
  <form method="post">
    <input type="date" name="datePrune" value="' . getDateString($now) . '">
    <input class="kfcDestructive" type="submit" value="PRUNE" name="prune" disabled />
  </form>
</p>
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
