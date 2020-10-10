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
// TODO: Get rid of "initialized" now that we have other keys.
if (!array_key_exists('initialized', $db->getConfig())) {  // first run
  echo '<h2>Initializing...</h2><p><i>This seems to be the first run. Setting default config...';
  $db->populateConfig();
  echo ' done.</i></p>';
}

if (isset($_POST['clearAll'])) {
  $db->dropTablesExceptConfig();
  $db->createMissingTables();
} else if (isset($_POST['configDefaults'])) {
  $db->populateConfig();
} else if (isset($_POST['setConfig']) || isset($_POST['clearConfig'])) {
  // TODO: This should sanitize the user input.
  $key = trim($_POST['configKey']);
  if (isset($_POST['setConfig'])) {
    $db->setConfig($key, $_POST['configValue']);
  } else {
    $db->clearConfig($key);
  }
}

echo '
<h1>'.KFC_SERVER_HEADING.'</h1>
<p>(c) 2020 J&ouml;rg Zieren - <a href="http://zieren.de">zieren.de</a> - GNU GPL v3.
Components:
<a href="http://codefury.net/projects/klogger/">KLogger</a> by Kenny Katzgrau, MIT license.
</p>
<p><a href="../view/">View anemometer</a></p>

<h2>Configuration</h2>';
$db->echoConfig();

echo 
'<form method="post" enctype="multipart/form-data">
  <input type="text" name="configKey" value="" placeholder="key">
  <input type="text" name="configValue" value="" placeholder="value">
  <input type="submit" name="setConfig" value="Set">
  <input type="submit" name="clearConfig" value="Clear">
  <p><input type="submit" name="configDefaults" value="set defaults for missing keys" /></p>
  </form>';

echo
'<hr />
<h2>Manage Database/Logs</h2>
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
