; Hard coded parameters that probably don't need to be configurable.
; Time the user has to manually close a window for which time has expired.
; After this time the script attempts to close the window.
GRACE_PERIOD_MILLIS := -30000 ; negative for SetTimer semantics
; Time the script allows for closing the window. After this time the process
; is killed.
KILL_AFTER_SECONDS := 30
; Contact the server every x seconds.
SAMPLE_INTERVAL_SECONDS := 15

EnvGet, TMP, TMP ; current user's temp directory
EnvGet, USERPROFILE, USERPROFILE ; e.g. c:\users\johndoe

INI_FILE := USERPROFILE "\kfc.ini"
IniRead, URL, %INI_FILE%, account, url
IniRead, USER, %INI_FILE%, account, user
IniRead, DEBUG_NO_ENFORCE, %INI_FILE%, debug, disableEnforcement, 0

FILE_REQUEST := TMP . "\kfc.request"
FILE_RESPONSE := TMP . "\kfc.response"
FileEncoding, UTF-8-RAW

ShowMessage(msg) {
  Gui, Destroy
  Gui, +AlwaysOnTop
  Gui, Add, Text,, %msg%
  Gui, Add, Button, default w80, OK
  Gui, Show, NoActivate, KFC
}

Beep(t) {
  Loop, %t% {
    SoundBeep
  }
}

; Track windows for which a "please close" message was already shown.
; There's no set, so use an associative array.
; This should be safe since AutoHotkey simulates concurrency using a
; single thread: https://www.autohotkey.com/docs/misc/Threads.htm
doomedWindows := {}

class Terminator {
  terminate(title) {
    global KILL_AFTER_SECONDS
    global doomedWindows
    WinGet, id, ID, %title%
    if (id) {
      WinGet, pid, PID, ahk_id %id%
      WinClose, ahk_id %id%, , %KILL_AFTER_SECONDS%
      id2 := WinExist("ahk_id" . id)
      if (id2) {
        if (DEBUG_NO_ENFORCE) {
          ShowMessage("enforcement disabled: kill process " pid)
        } else {
          Process, Close, %pid%
        }
      }
      doomedWindows.Delete(title)
    }
  }
}

Loop {
  WinGetTitle, windowTitle, A
  if (!windowTitle) {
    windowTitle := "<none>"
  }
  file := FileOpen(FILE_REQUEST, "w")
  file.Write(USER "|" windowTitle)
  file.Close()
  RunWait, curl --header "Content-Type: text/plain; charset=utf-8" --request POST --data "@%FILE_REQUEST%" %URL%/rx/ -o "%FILE_RESPONSE%", , Hide
  FileRead, response, %FILE_RESPONSE%
  responseLines:= StrSplit(response, "`n")
  status := responseLines[1]
  if (status = "ok") {
    ; do nothing
  } else if (status = "close") {
    if (!doomedWindows.HasKey(windowTitle)) {
      Beep(2)
      ShowMessage("Time is up for this app, please close it now:\n" windowTitle)
      terminateWindow := ObjBindMethod(Terminator, "terminate", windowTitle)
      doomedWindows[windowTitle] := 1
      SetTimer, %terminateWindow%, %GRACE_PERIOD_MILLIS%
    }
  } else if (status = "logout") {
    if (DEBUG_NO_ENFORCE) {
      ShowMessage("enforcement disabled: logout")
    } else {
      Shutdown, 0 ; 0 means logout
    }
  } else if (status = "message") {
    ShowMessage(responseLines[2])
  } else { ; an error message - TODO: Handle "error" status explicitly
    ShowMessage(response)
    Beep(5)
  }
  waitMillis := SAMPLE_INTERVAL_SECONDS * 1000
  Sleep, waitMillis
}

ButtonOK:
Gui, Destroy
Return
