<?php

define('LOG_DIR', '../logs');

spl_autoload_register(function ($class) {
  include str_replace('\\', '/', $class) . '.php';
});
