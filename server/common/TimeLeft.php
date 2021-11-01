<?php

/** Describes the time left today as well as the current and next slot, if applicable. */
class TimeLeft {
  /** An int containing the remaining seconds for today. */
  public $seconds;
  /** An array with [from_epoch, to_epoch] indicating the current time slot. */
  public $currentSlot;
  /** An array with [from_epoch, to_epoch] indicating the next time slot. */
  public $nextSlot;

  public function __construct($seconds, $currentSlot, $nextSlot) {
    $this->seconds = $seconds;
    $this->currentSlot = $currentSlot;
    $this->nextSlot = $nextSlot;
  }

  public static function toSeconds($timeLeft) {
    return $timeLeft->seconds;
  }
}
