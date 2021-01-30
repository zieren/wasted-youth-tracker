<?php

function echoTable($data) {
  echo "<table>\n";
  foreach($data as $row) {
    $row = array_map(
        function (&$td) { return htmlentities($td, ENT_COMPAT | ENT_HTML401, 'UTF-8'); },
        $row);
    echo "<tr><td>" . implode("</td><td>", $row) . "</td></tr>\n";
  }
  echo "</table>\n";
}
