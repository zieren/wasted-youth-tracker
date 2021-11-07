<?php

/** Describes the various aspects of time left today, all with respect to one specific limit. */
class TimeLeft {
  /** Whether the limit is locked. */
  public $locked;
  /**
   * Seconds left before the minutes condingent for the day runs out or the current slot ends, i.e.
   * time until the user has to close running programs. Zero if the limit is locked.
   */
  public $currentSeconds;
  /** Total number of seconds left today, ignoring slots. */
  public $totalSeconds;
  /** An array with [from_epoch, to_epoch] indicating the current time slot, or else []. */
  public $currentSlot;
  /** An array with [from_epoch, to_epoch] indicating the next time slot, or else []. */
  public $nextSlot;

  /**
   * Construct based on the minute contingent: If not locked, both current and total seconds are
   * initialized to the same value. Otherwise current seconds are set to zero.
   */
  public function __construct($locked, $totalSeconds) {
    $this->locked = $locked;
    // It is clearer for the user, and easier for the client to process, when we set this to zero
    // for locked limits.
    $this->currentSeconds = $locked ? 0 : $totalSeconds;
    $this->totalSeconds = $totalSeconds;
    $this->currentSlot = [];
    $this->nextSlot = [];
  }

  /** Reflect the restrictions computed from time slots. */
  public function reflectSlots($currentSeconds, $totalSeconds, $currentSlot, $nextSlot): TimeLeft {
    $this->currentSeconds = min($this->currentSeconds, $currentSeconds);
    $this->totalSeconds = min($this->totalSeconds, $totalSeconds);
    $this->currentSlot = $currentSlot;
    $this->nextSlot = $nextSlot;
    return $this;
  }

  public function toClientResponse() {
    $date = new DateTime();
    $response = [$this->locked ? 1 : 0, $this->currentSeconds, $this->totalSeconds];
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

  public static function toCurrentSeconds($timeLeft) {
    return $timeLeft->currentSeconds;
  }
}
