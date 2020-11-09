<?php

function echoTable($data) {
  echo "<table>\n";
  foreach($data as $d) {
    echo "<tr><td>" . implode("</td><td>", $d) . "</td></tr>\n";
  }
  echo "</table>\n";
}
