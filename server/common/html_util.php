<?php

function echoTable($data) {
  echo "<table>\n";
  foreach($data as $row) {
    $row = array_map("html", $row);
    echo "<tr><td>" . implode("</td><td>", $row) . "</td></tr>\n";
  }
  echo "</table>\n";
}

function echoTableAssociative($data) {
  echo "<table>\n";
  foreach($data as $k => $v) {
    echo "<tr><td>" . html($k) . "</td><td>" . html($v) . "</td></tr>\n";
  }
  echo "</table>\n";
}

function html($text) {
  return htmlentities($text, ENT_COMPAT | ENT_HTML401, 'ISO-8859-1');
}

function action($name) {
  return array_key_exists($name, $_POST);
}

function post($key) {
  $s = filter_input(INPUT_POST, $key, FILTER_SANITIZE_STRING);
  return is_null($s) ? null : trim($s);
}

function postInt($key, $default = 0) {
  $i = filter_input(INPUT_POST, $key, FILTER_SANITIZE_NUMBER_INT);
  return is_null($i) ? $default : $i;
}

function get($key) {
  $s = filter_input(INPUT_GET, $key, FILTER_SANITIZE_STRING);
  return is_null($s) ? null : trim($s);
}

function userSelector($users, $selectedUser) {
  $select =
      '<label for="idUsers">User:</label>
      <select id="idUsers" name="user" onChange="if (this.value != 0) { this.form.submit(); }">';
  foreach ($users as $u) {
    $selected = $selectedUser == $u ? 'selected="selected"' : '';
    $select .= '<option value="' . $u . '" ' . $selected . '>' . $u . '</option>';
  }
  $select .= "</select>\n";
  return $select;
}

function dateSelector($dateString, $submitOnInput) {
  $onInput = $submitOnInput ? ' onInput="this.form.submit()"' : '';
  $type = $submitOnInput ? 'submit' : 'button';
  return
      '<label for="idDate">Date:</label><input id="idDate" type="date" value="' . $dateString
      . '" name="date"' . $onInput . '/>
      <button onClick="setToday()" type="' . $type . '">Today</button>' . "\n";
}

function dateSelectorJs() {
  return '<script>
            function setToday() {
              var dateInput = document.querySelector("#idDate");
              dateInput.value = "' . date('Y-m-d') . '";
            }
          </script>';
}

function budgetSelector($budgetNames, $selectedBudgetId) {
  $select =
      '<label for="idBudget">Budget: </label>
      <select id="idBudget" name="budgetId">';
  foreach ($budgetNames as $budgetId => $budgetName) {
    $selected = $selectedBudgetId == $budgetId ? 'selected="selected"' : '';
    $select .=
        '<option value="' . $budgetId . '" ' . $selected . '>' . html($budgetName) . '</option>';
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

function classificationSelectorJs() {
  return '<script>
            function copyRegEx() {
              var selectClassification = document.querySelector("#idClassification");
              var inputTextRegEx = document.querySelector("#idClassificationRegEx");
              var selectedIndex = selectClassification.selectedIndex;
              if (selectedIndex >= 0) {
                inputTextRegEx.value = selectClassification.options[selectedIndex].text;
              }
            }
            window.addEventListener("load", copyRegEx);
          </script>';
}

function classificationSelector($classifications) {
  $select =
      '<label for="idClassification">Classification: </label>
      <select onchange="copyRegEx()" id="idClassification" name="classificationId"
         style="width: 20em">';
  foreach ($classifications as $id => $re) {
    $select .= '<option value="' . $id . '">' . html($re) . '</option>';
  }
  $select .= "</select>\n";
  return $select;
}
