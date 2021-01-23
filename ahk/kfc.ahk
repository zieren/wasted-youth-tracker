IniRead, URL, kfc.ini, account, url
IniRead, USER, kfc.ini, account, user
EnvGet, TMP, TMP ; current user's temp directory

GRACE_PERIOD_MILLIS := -30000 ; negative for SetTimer semantics
KILL_AFTER_SECONDS := 30

; This is really used to escape strings in JSON, not actually for URLs.
UrlEncode(s) {
	Loop, Parse, s
  {
		if A_LoopField is alnum
		{
			encoded .= A_LoopField
			continue
		}
    encoded .= "%" . Format("{:02X}", Asc(A_LoopField))
	}
	return encoded
}

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
closingWindows := {}

class Terminator {
	terminate(title) {
    global KILL_AFTER_SECONDS
    global closingWindows
    WinGet, id, ID, %title%
    if (id) {
      WinGet, pid, PID, ahk_id %id%
      WinClose, ahk_id %id%, , %KILL_AFTER_SECONDS%
      id2 := WinExist("ahk_id" . id)
      if (id2) {
        Process, Close, %pid%
      }
      closingWindows.Delete(title)
    }
	}
}

Loop {
  WinGetTitle, windowTitle, A
  if (!windowTitle) {
    windowTitle := "<none>"
  }
  windowTitleUrlEncoded := UrlEncode(windowTitle)
  fileRequest := TMP . "\kfc.request"
  fileResponse := TMP . "\kfc.response"
  RunWait, cmd /c echo {"title":"%windowTitleUrlEncoded%"`,"user":"%USER%"} >"%fileRequest%", , Hide
  RunWait, curl --header "Content-Type: application/json" --request POST --data "@%fileRequest%" %URL%/rx/ -o "%fileResponse%", , Hide
  FileRead, responseLines, %fileResponse%
  response := StrSplit(responseLines, "`n")
  status := response[1]
  ; Avoid excessive QPS.
  waitSeconds := response[2] >= 15 ? response[2] : 15
  if (status = "ok") {
    ; do nothing
  } else if (status = "close") {
    if (!closingWindows.HasKey(windowTitle)) {
      Beep(2)
      ShowMessage("Time is up, please close now: " windowTitle)
      terminateWindow := ObjBindMethod(Terminator, "terminate", windowTitle)
      closingWindows[windowTitle] := 1
      SetTimer, %terminateWindow%, %GRACE_PERIOD_MILLIS%
    }
  } else if (status = "logout") {
    Shutdown, 0 ; 0 means logout
  ; } else if (status = "hibernate") {
    ; --------------- > Shutdown, 0 ; 0 means logout
  } else { ; an error message
    ShowMessage(status)
    Beep(5)
  }
  waitMillis := 1000 * waitSeconds
  Sleep, waitMillis
}

ButtonOK:
Gui, Destroy
Return
