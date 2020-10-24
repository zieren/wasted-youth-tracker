<html>
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
require_once 'common.php';

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
}

echo '
<h1>'.KFC_SERVER_HEADING.'</h1>
<p>(c) 2020 J&ouml;rg Zieren - <a href="http://zieren.de">zieren.de</a> - GNU GPL v3.
Components:
<a href="http://codefury.net/projects/klogger/">KLogger</a> by Kenny Katzgrau, MIT license.
</p>

<h2>Configuration</h2>

<p>Users: ';
echo implode(",", $db->getUsers()).'</p>';

$db->echoUserConfig();
$db->echoGlobalConfig();

echo 
'<form method="post" enctype="multipart/form-data">
  <input type="text" name="configUser" value="" placeholder="user">
  <input type="text" name="configKey" value="" placeholder="key">
  <input type="text" name="configValue" value="" placeholder="value">
  <input type="submit" name="setUserConfig" value="Set User Config">
  <input type="submit" name="clearConfig" value="Clear User Config">
  <input type="submit" name="setGlobalConfig" value="Set Global Config">
  <input type="submit" name="clearGlobalConfig" value="Clear Global Config">
  </form>';

echo
'<hr />
<h2>Manage Database/Logs (TO BE IMPLEMENTED)</h2>
<form action="prune.php" method="get">
  <p>
    PRUNE data and logs older than <input type="text" name="days" value=""> days.
    <input class="kfcDestructive" type="submit" value="PRUNE" disabled />
  </p>
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
