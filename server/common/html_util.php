<?php

function echoTable($header, $data, $html = 'class="titled"') {
  array_unshift($data, $header);
  echo "<table $html>";
  foreach ($data as $row) {
    $row = array_map('html', $row);
    echo '<tr><td>' . implode('</td><td>', $row) . "</td></tr>\n";
  }
  echo "</table>\n";
}

function echoTableAssociative($data) {
  echo "<table>\n";
  foreach ($data as $k => $v) {
    echo "<tr><td>" . html($k) . "</td><td>" . html($v) . "</td></tr>\n";
  }
  echo "</table>\n";
}

function html($text) {
  return nl2br(htmlentities($text, ENT_COMPAT | ENT_HTML401, 'ISO-8859-1'));
}

function action($name) {
  return array_key_exists($name, $_POST);
}

function postString($key) {
  $s = filter_input(INPUT_POST, $key);
  return is_null($s) ? null : trim($s);
}

function postInt($key, $default = 0) {
  $i = filter_input(INPUT_POST, $key, FILTER_SANITIZE_NUMBER_INT);
  return is_null($i) ? $default : $i;
}

function getString($key) {
  $s = filter_input(INPUT_GET, $key);
  return is_null($s) ? null : trim($s);
}

function userSelector($users, $selectedUser) {
  $select = '
      <label for="idUsers">User:</label>
      <select id="idUsers" name="user" onChange="submitWithUiState(this);">';
  foreach ($users as $u) {
    $selected = $selectedUser == $u ? 'selected="selected"' : '';
    // TODO: Quote user.
    $select .= '<option value="' . $u . '" ' . $selected . '>' . $u . '</option>';
  }
  $select .= "</select>\n";
  return $select;
}

function limitSelector($limitConfigs, $selectedLimitId, $skipTotal = false) {
  $select = '<label for="idLimit">Limit: </label><select id="idLimit" name="limitId">';
  foreach ($limitConfigs as $limitId => $config) {
    if ($skipTotal && $config['is_total']) {
      continue;
    }
    $selected = $selectedLimitId == $limitId ? 'selected="selected"' : '';
    $select .=
        '<option value="' . $limitId . '" ' . $selected . '>' . html($config['name']) . '</option>';
  }
  $select .= "</select>\n";
  return $select;
}

function classSelector($classes, $includeDefault = false) {
  $select = '<label for="idClass">Class: </label><select id="idClass" name="classId">';
  foreach ($classes as $classId => $className) {
    if ($includeDefault || $classId != DEFAULT_CLASS_ID) {
      $select .= '<option value="' . $classId . '">' . html($className) . '</option>';
    }
  }
  $select .= "</select>\n";
  return $select;
}

function _arrayUtf8Encode(&$v, &$k) {
  $v = mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
}

function classificationSelectorJs($classificationsLatin1) {
  $classificationsUtf8 = $classificationsLatin1;
  array_walk_recursive($classificationsUtf8, '_arrayUtf8Encode');
  return '<script>
            var classifications = ' . json_encode($classificationsUtf8) . ';
            function populateClassificationFields() {
              var selectClassification = document.querySelector("#idClassification");
              var inputTextRegEx = document.querySelector("#idClassificationRegEx");
              var inputNumberPrio = document.querySelector("#idClassificationPriority");
              var selectedIndex = selectClassification.selectedIndex;
              if (selectedIndex >= 0) {
                var id = selectClassification.options[selectedIndex].value;
                inputTextRegEx.value = classifications[id]["re"];
                inputNumberPrio.value = classifications[id]["priority"];
              }
            }
            window.addEventListener("load", populateClassificationFields);
          </script>';
}

function classificationSelector($classifications) {
  $select =
      '<label for="idClassification">Classification: </label>
      <select onchange="populateClassificationFields()"
          id="idClassification" name="classificationId">';
  foreach ($classifications as $id => $c) {
    $select .= '<option value="' . $id . '">' . html($c['name'] . ': ' . $c['re']) . '</option>';
  }
  $select .= "</select>\n";
  return $select;
}
