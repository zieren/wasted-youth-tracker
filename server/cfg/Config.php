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
    $config = $kfc->getClientConfig($user);
    // Prefix response with a marker to simplify error (e.g. 404) detection on the client.
    $response = '-*- cfg -*-';
    foreach ($config as $k => $v) {
      $response .= "\n" . $k . "\n" . $v;
    }
    return $response;
  }
}