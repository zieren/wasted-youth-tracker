; Hard coded parameters that probably don't need to be configurable.

; Time the user has to manually close a window for which time has expired.
; After this time the script attempts to close the window.
GRACE_PERIOD_MILLIS := 30 * -1000 ; negative for SetTimer semantics

; Time the script allows for closing the window. After this time the process
; is killed.
KILL_AFTER_SECONDS := 30

; Contact the server every x seconds.
SAMPLE_INTERVAL_SECONDS := 15

; Special processes that should never be closed.
IGNORE_PROCESSES := {}
IGNORE_PROCESSES["explorer.exe"] := 1 ; also runs the task bar and the start menu
IGNORE_PROCESSES["LogiOverlay.exe"] := 1 ; Logitech mouse/trackball driver
IGNORE_PROCESSES["AutoHotkey.exe"] := 1 ; KFC itself (and other scripts)

EnvGet, TMP, TMP ; current user's temp directory
EnvGet, USERPROFILE, USERPROFILE ; e.g. c:\users\johndoe

INI_FILE := USERPROFILE "\kfc.ini"
IniRead, URL, %INI_FILE%, server, url
IniRead, HTTP_USER, %INI_FILE%, server, username
IniRead, HTTP_PASS, %INI_FILE%, server, password
IniRead, USER, %INI_FILE%, account, user
IniRead, DEBUG_NO_ENFORCE, %INI_FILE%, debug, disableEnforcement, 0

; TODO: Is this a loophole?
DetectHiddenWindows, Off

ShowMessage(msg) {
  Gui, Destroy
  Gui, +AlwaysOnTop
  Gui, Add, Text,, %msg%
  Gui, Add, Button, default w80, OK ; this implicitly sets a handler of ButtonOK
  Gui, Show, , KFC
}

Beep(t) {
  Loop, %t% {
    SoundBeep
  }
}

; Shared variables should be safe since AutoHotkey simulates concurrency
; using a single thread: https://www.autohotkey.com/docs/misc/Threads.htm

; Track windows for which a "please close" message was already shown.
; There's no set type, so use an associative array.
doomedWindows := {}

; Remember budgets for which a "time is almost up" message has been shown.
warnedBudgets := {}

class Terminator {
  terminate(id) {
    global DEBUG_NO_ENFORCE
    global KILL_AFTER_SECONDS
    global doomedWindows
    if (WinExist("ahk_id" id)) {
      WinGet, pid, PID, ahk_id %id%
      WinClose, ahk_id %id%, , %KILL_AFTER_SECONDS%
      id2 := WinExist("ahk_id" id)
      if (id2) {
        ; The PID can be too high up in the hierarchy. E.g. calculator.exe has its own PID, but
        ; the below call returns that of "Application Frame Host", which is shared with other
        ; processes (try Solitaire). DllCall("GetWindowThreadProcessId"...) has the same problem.
        ; I'm not sure how common this problem is in practice. For now we accept that the blast
        ; radius in case of "force close" may be too large. The main problem is that, presumably,
        ; this may close system/driver processes that are missing in IGNORE_PROCESSES.
        ; TODO: Fix this. Maybe send window info incl. process name to server, so IGNORE_PROCESSES
        ; can be extended? But there really should be a way to get the proper PID.
        WinGet, pid, PID, ahk_id %id%
        if (DEBUG_NO_ENFORCE) {
          ShowMessage("enforcement disabled: kill process " pid)
        } else {
          Process, Close, %pid%
        }
      }
    }
    doomedWindows.Delete(id)
  }
}

; Returns an associative array describing all windows, keyed by title. 
; Elements are arrays with these keys:
; "ids": ahk_id-s of all windows with that title
; "active": 0 or 1 to indicate whether the window is active (has focus)
; "name": The process name, for debugging.
GetAllWindows() {
  global IGNORE_PROCESSES
  windows := {}
  WinGet, ids, List ; get all window IDs
  WinGet, activeID, ID, A ; get active window ID
  Loop %ids% {
    id := ids%A_Index%
	; Get the ancestor window because we may have dialogs titled "Open File" etc.
    ; https://docs.microsoft.com/en-us/windows/win32/api/winuser/nf-winuser-getancestor
    rootID := DllCall("GetAncestor", UInt, WinExist("ahk_id" id), UInt, 3)
    WinGetTitle, rootTitle, ahk_id %rootID%
	WinGet, processName, ProcessName, ahk_id %id%
	if (rootTitle && !IGNORE_PROCESSES[processName]) {
      ; Store process name for debugging: This is needed when we close a window that should have
      ; been ignored.
      if (!windows.HasKey(rootTitle)) {
        windows[rootTitle] := {"ids": {(rootID): 1}, "active": id == activeID, "name": processName}
      } else {
        windows[rootTitle]["ids"][(rootID)] := 1
        windows[rootTitle]["active"] := windows[rootTitle]["active"] || id == activeID
      }
    }
  }
  return windows
}

Loop {
  windows := GetAllWindows()
  
  ; Build request payload.
  windowList := ""
  indexToTitle := []
  focusIndex := -1
  for title, window in windows {
    windowList .= "`n" title
    if (window["active"]) {
      focusIndex := indexToTitle.MaxIndex()
    }
    indexToTitle.Push(title)
  }

  ; Perform request.
  ; https://docs.microsoft.com/en-us/previous-versions/windows/desktop/ms759148(v=vs.85)
  request := ComObjCreate("MSXML2.XMLHTTP.6.0")
  request.open("POST", URL "/rx/", false, HTTP_USER, HTTP_PASS)
  request.send(USER "`n" focusIndex windowList)
  
  ; Parse response. Format per line is:
  ; <title index> ":" <budget seconds remaining> ":" <budget name>
  responseLines := StrSplit(request.responseText, "`n")
  ; Collect messages to show (and beeps to beep) after processing the response.
  messages := []
  beeps := 0
  for ignored, line in responseLines {
    s := StrSplit(line, ":", "", 3)
    title := indexToTitle[s[1] + 1] ; on the API indexes are 0-based
    secondsLeft := s[2]
    budget := s[3]
    if (secondsLeft <= 0) {
      for id, ignored2 in windows[title]["ids"] {
        if (!doomedWindows[id]) {
          doomedWindows[id] := 1
          terminateWindow := ObjBindMethod(Terminator, "terminate", id)
          SetTimer, %terminateWindow%, %GRACE_PERIOD_MILLIS%
          messages.Push("Time is up for budget '" budget "', please close '" title "'")
          beeps := 5
        }
      }
    } else if (secondsLeft <= 300) { 
      ; TODO: Make this configurable. Maybe pull config from server on start?
      if (!warnedBudgets[budget]) {
        ; Using the budget name as key is awkward, but avoids budget IDs on the client.
        warnedBudgets[budget] := 1
        timeLeftString := Format("{:02}:{:02}", Floor(secondsLeft / 60), Mod(secondsLeft, 60))
        messages.Push("Budget '" budget "' for '" title "' has " timeLeftString " left.")
        beeps := 2
      }
    } else if (warnedBudgets[budget]) { ; budget time was increased, need to warn again
      warnedBudgets.Delete(budget)
    }
  }
  if (messages.MaxIndex()) {
    Beep(beeps)
    text := ""
    for ignored, message in messages {
      text .= "`n" message
    }
    ShowMessage(SubStr(text, 2))
  }
  ; TODO: Add option to logout/shutdown:
  ; Shutdown, 0 ; 0 means logout

  waitMillis := SAMPLE_INTERVAL_SECONDS * 1000
  Sleep, waitMillis
}

ShowTimeLeft() {
  global URL
  global USER
  leftUrl := URL "/view/left.php?user=" USER
  ; Extract HTTP basic authentication, if present.
  RegExMatch(leftUrl, "(https?://)(([^:]+):(.+)@)?([^@]+)", g)
  leftUrl := g1 g5
  httpUser := g3
  httpPass := g4
  try {
    request := ComObjCreate("MSXML2.XMLHTTP.6.0")
    request.open("GET", leftUrl, False, httpUser, httpPass)
    request.send()
    if (request.StatusText() = "OK") {
      ShowMessage("Time left:`n" request.ResponseText())
    } else {
      ShowMessage("Error: " request.StatusText())
    }
  } catch e {
    ShowMessage("Error: " e.message)
  }
}

DebugShowStatus() {
  global warnedBudgets
  global doomedWindows
  msg := ""
  for title, window in GetAllWindows()
  {
    ids := ""
    for id, ignored in window["ids"] {
      ids .= (ids ? "/" : "") id
    }
    msg .= "title=" title " ids=" ids " active=" window["active"] " name=" window["name"] "`n"
  }
  msg .= "-----`n"
  for budget, ignored in warnedBudgets {
    msg .= "warned: " budget "`n"
  }
  msg .= "-----`n"
  for id, ignored in doomedWindows {
    msg .= "doomed: " id "`n"
  }
  
  ShowMessage(msg)
}

ButtonOK:
Gui, Destroy
return

; --- User hotkeys ---

^F12::
ShowTimeLeft()
return

; --- Debug hotkeys ---

^+F12::
DebugShowStatus()
return
