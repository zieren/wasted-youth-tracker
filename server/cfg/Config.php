<?php

/** Provides the user config to the client. */
class Config {
  /**
   * Returns the user config (a key-value map) as a string. The format is:
   *
   * key1
   * value1
   * key2
   * value2
   * ...
   */
  public static function handleRequest(Wasted $wasted, string $user): string {
    $config = $wasted->getClientConfig($user);
    $response = [];
    foreach ($config as $k => $v) {
      $response[] = $k;
      $response[] = $v;
    }
    return implode("\n", $response);
  }
}