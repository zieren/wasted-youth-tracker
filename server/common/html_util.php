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
  return htmlentities($text, ENT_COMPAT | ENT_HTML401, 'UTF-8');
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
      <select id="idBudget" name="budget">';
  foreach ($budgetNames as $budgetId => $budgetName) {
    $selected = $selectedBudgetId == $budgetId ? 'selected="selected"' : '';
    $select .=
        '<option value="' . $budgetId . '" ' . $selected . '>' . html($budgetName) . '</option>';
  }
  $select .= "</select>\n";
  return $select;
}
