<?php

/**
 * Describes the time left today as well as the current and next slot, if applicable. This always
 * pertains to one specific limit.
 */
class TimeLeft {
  /** Whether the limit is locked. */
  public $locked;
  /** An int containing the remaining seconds for today. */
  public $seconds;
  /** An array with [from_epoch, to_epoch] indicating the current time slot. */
  public $currentSlot;
  /** An array with [from_epoch, to_epoch] indicating the next time slot. */
  public $nextSlot;

  public function __construct($locked, $seconds, $currentSlot, $nextSlot) {
    $this->locked = $locked;
    $this->seconds = $seconds;
    $this->currentSlot = $currentSlot;
    $this->nextSlot = $nextSlot;
  }

  public function toClientResponse() {
    $date = new DateTime();
    $response = [$this->locked ? 1 : 0, $this->seconds];
    foreach ([$this->currentSlot, $this->nextSlot] as $slot) {
      if ($slot) {
        $response[] =
            $date->setTimestamp($slot[0])->format('H:i') . '-' .
            $date->setTimestamp($slot[1])->format('H:i');
      } else {
        $response[] = '';
      }
    }
    return implode(';', $response);
  }

  public static function toSeconds($timeLeft) {
    return $timeLeft->seconds;
  }
}
