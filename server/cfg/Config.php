<?php

// TODO: Make sure warnings and errors are surfaced appropriately. Catch exceptions.

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
  public static function handleRequest(KFC $kfc, string $user): string {
    $config = $kfc->getUserConfig($user);
    $response = '';
    foreach ($config as $k => $v) {
      if ($response) {
        $response .= "\n";
      }
      $response .= $k . "\n" . $v;
    }
    return $response;
  }
}