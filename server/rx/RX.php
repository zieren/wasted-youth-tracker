<?php

/**
 * Handle an incoming request. The expectation is that this is not called concurrently for the same
 * user.
 */
class RX {

  /**
   * Incoming data format by line:
   *
   * 1. username
   * 2. last error, format "YYYYMMDD HHMMSS ..." (or else empty line)
   * 3. window title #0
   * 4. window title #1
   * ...
   *
   * No trailing newline must be added. At least the first two lines must be sent. This would
   * indicate that no windows are open.
   *
   * Example request:
   *
   * john_doe
   *
   * Minecraft
   * Calculator
   *
   * The response contains two parts. The first part lists all limits configured for the user, the
   * remaining time (starting right now) in seconds, the remaining time today (possibly in an
   * upcoming slot), the current time slot (if any), the next time slot (if any), and the limit's
   * name:
   *
   * limitId ";" timeLeftNow ";" timeLeftToday ";" currentSlot ";" nextSlot ";" limitName
   *
   * The second part, separated by a blank line, lists the limits to which each window title maps,
   * in the order they appear in the request. If multiple limits match a title, they are separated
   * by commas:
   *
   * limitId ( "," limitId )*
   *
   * Example response for the above request:
   *
   * 10;42;42;16:00-18:00;;Games
   * 11;23;53;16:00-18:00;18:30-19:00;Minecraft
   * 12;666;666;08:00-13:00;15:00-16:00;School
   *
   * 10,11
   * 12
   */
  public static function handleRequest($content): string {
    $lines = preg_split("/\r\n|\n|\r/", $content);
    $linesForLog = implode('\n', $lines);
    Logger::Instance()->debug("Received data: '$linesForLog'");
    if (count($lines) < 2 || !$lines[0]) {
      http_response_code(400);
      Logger::Instance()->error("Invalid request: '$linesForLog'");
      return '';
    }
    $user = $lines[0];
    $lastError = $lines[1];
    $titles = array_slice($lines, 2); // could be empty, but we still need to insert a timestamp
    // AutoHotkey sends strings in utf8, but we use latin1 because MySQL's RegExp library doesn't
    // support utf8. https://github.com/zieren/kids-freedom-control/issues/33
    $titles = array_map('utf8_decode', $titles);

    $classifications = Wasted::insertActivity($user, $lastError, $titles);

    // Build response.

    // Part 1: Limits and TimeLeft.
    $response = [];
    $configs = Wasted::getAllLimitConfigs($user);
    $limitIdToName = getLimitIdToNameMap($configs);
    $timeLeftByLimit = Wasted::queryTimeLeftTodayAllLimits($user);
    foreach ($timeLeftByLimit as $limitId => $timeLeft) {
      $response[] = $limitId.';'.$timeLeft->toClientResponse().';'.$limitIdToName[$limitId];
    }
    $response[] = '';

    // Part 2: Window titles to limits.
    foreach ($classifications as &$classification) {
      $response[] = implode(',', $classification['limits']);
    }

    return implode("\n", $response);
  }
}