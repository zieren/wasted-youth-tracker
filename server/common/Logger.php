<?php

require_once 'base.php';

final class Logger {

  private static $instance;
  private static $logDir;

  public static function Instance(): Katzgrau\KLogger\Logger {
    if (!self::$instance) {
      self::$logDir = dirname(__DIR__, 1).'/logs';
      self::$instance = new Katzgrau\KLogger\Logger(self::$logDir);
    }
    return self::$instance;
  }

  /** Creates a log file in the current (i.e. the test's) directory, with the specified name. */
  public static function initializeForTest($filename): void {
    if (self::$instance) {
      throw new AssertionError('already initialized');
    }
    self::$logDir = '.';
    self::$instance = new Katzgrau\KLogger\Logger(self::$logDir, $filename);
  }

  public static function getLogDir(): string {
    return self::$logDir;
  }

  private function __construct() {}

}
