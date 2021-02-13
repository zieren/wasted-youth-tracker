<?php

require_once 'base.php';

final class Logger {

  private static $instance;

  public static function Instance(): Katzgrau\KLogger\Logger {
    if (!self::$instance) {
      self::$instance = new Katzgrau\KLogger\Logger('../logs');
    }
    return self::$instance;
  }

  public static function initializeForTest($filename): void {
    if (self::$instance) {
      throw new AssertionError('already initialized');
    }
    self::$instance = new Katzgrau\KLogger\Logger('.', $filename);
  }

  private function __construct() {

  }

}
