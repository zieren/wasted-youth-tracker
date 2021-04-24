; Hard coded parameters that probably don't need to be configurable.

; Time the user has to manually close a window for which time has expired.
; After this time the script attempts to close the window.
global GRACE_PERIOD_MILLIS := 30 * -1000 ; negative for SetTimer semantics

; Time the script allows for closing the window. After this time the process
; is killed.
global KILL_AFTER_SECONDS := 30

; Contact the server every x seconds.
global SAMPLE_INTERVAL_SECONDS := 15

; Special processes that should never be closed.
global IGNORE_PROCESSES := {}
IGNORE_PROCESSES["explorer.exe"] := 1 ; also runs the task bar and the start menu
IGNORE_PROCESSES["LogiOverlay.exe"] := 1 ; Logitech mouse/trackball driver
IGNORE_PROCESSES["AutoHotkey.exe"] := 1 ; KFC itself (and other AHK scripts)

EnvGet, USERPROFILE, USERPROFILE ; e.g. c:\users\johndoe

INI_FILE := USERPROFILE "\kfc.ini"
global URL, HTTP_USER, HTTP_PASS, USER, DEBUG_NO_ENFORCE, WATCH_PROCESSES
IniRead, URL, %INI_FILE%, server, url
IniRead, HTTP_USER, %INI_FILE%, server, username
IniRead, HTTP_PASS, %INI_FILE%, server, password
IniRead, USER, %INI_FILE%, account, user
IniRead, DEBUG_NO_ENFORCE, %INI_FILE%, debug, disableEnforcement, 0
WATCH_PROCESSES := {}
Loop, 99 {
  IniRead, p, %INI_FILE%, processes, process_%A_Index%, %A_Space%
  if (p) {
    i := RegExMatch(p, "=[^=]+$")
    if (i > 0) {
      name := trim(SubStr(p, 1, i - 1))
      WATCH_PROCESSES[name] := trim(SubStr(p, i + 1))
    } else {
      MsgBox, Ignoring invalid INI value in %INI_FILE%: `nprocess_%A_Index%=%p%
    }
  }
}

; TODO: Is this a loophole?
DetectHiddenWindows, Off

; Shared variables should be safe since AutoHotkey simulates concurrency
; using a single thread: https://www.autohotkey.com/docs/misc/Threads.htm

; Track windows for which a "please close" message was already shown.
; There's no set type, so use an associative array.
global doomedWindows := {}

; Same for processes without windows.
global doomedProcesses := {}

; Remember budgets for which a "time is almost up" message has been shown.
global warnedBudgets := {}

; Remember last request timestamp (Unix epoch seconds).
global lastRequest := 0

Beep(t) {
  Loop, %t% {
    SoundBeep
  }
}

ShowMessage(msg) {
  Gui, Destroy
  Gui, +AlwaysOnTop
  Gui, Add, Text,, %msg%
  Gui, Add, Button, default w80, OK ; this implicitly sets a handler of ButtonOK
  Gui, Show, , KFC
}

FormatSeconds(seconds) {
  sign := ""
  if (seconds < 0) {
    sign := "-"
    seconds := -seconds
  }
  hours := Floor(seconds / (60 * 60))
  seconds -= hours * 60 * 60
  minutes := Floor(seconds / 60)
  seconds -= minutes * 60
  return sign Format("{}:{:02}:{:02}", hours, minutes, seconds)
}

; Close the window with the specified ahk_id.
; WinKill does not seem to work: E.g. EditPlus with an unsaved file will just prompt to save,
; but not actually close. So maybe it sends a close request, and when the program acks that
; request it considers that success.
TerminateWindow(id) {
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
        ShowMessage("enforcement disabled: kill process " pid " (window " id ")")
      } else {
        Process, Close, %pid%
      }
    }
  }
  doomedWindows.Delete(id)
}

TerminateProcess(pid) {
  if (DEBUG_NO_ENFORCE) {
    ShowMessage("enforcement disabled: kill process " pid)
  } else {
    Process, Close, %pid%
  }
  doomedProcesses.Delete(pid)
}

FindLowestBudget(budgetIds, budgets) {
  lowestBudgetId := -1
  for ignored, budgetId in budgetIds {
    if (lowestBudgetId == -1
        || budgets[budgetId]["remaining"] < budgets[lowestBudgetId]["remaining"]) {
      lowestBudgetId := budgetId
    }
  }
  return lowestBudgetId
}

ProcessTitleResponse(line, windows, title, budgets, titlesByBudget, messages) {
  budgetIds := StrSplit(line, ",")
  for ignored, id in budgetIds {
    if (!titlesByBudget.HasKey(id)) {
      titlesByBudget[id] := []
    }
    titlesByBudget[id].Push(title)
  }
  lowestBudgetId := FindLowestBudget(budgetIds, budgets)
  secondsLeft := budgets[lowestBudgetId]["remaining"]
  budget := budgets[lowestBudgetId]["name"]
  if (secondsLeft <= 0) {
    for ignored, id in windows[title]["ids"] {
      if (!doomedWindows.HasKey(id)) {
        doomedWindows[id] := 1
        terminateWindow := Func("TerminateWindow").Bind(id)
        SetTimer, %terminateWindow%, %GRACE_PERIOD_MILLIS%
        messages.Push("Time is up for budget '" budget "', please close window '" title "'")
      }
    }
    if (windows[title].HasKey("pid")) {
      pid := windows[title]["pid"]
      if (!doomedProcesses.HasKey(pid)) {
        doomedProcesses[pid] := 1
        terminateProcess := Func("TerminateProcess").Bind(pid)
        ; The kill should happen after the same amount of time as for a window.
        millis := GRACE_PERIOD_MILLIS + KILL_AFTER_SECONDS
        SetTimer, %terminateProcess%, %millis%
        messages.Push("Time is up for budget '" budget "', please close process '" title "'")
      }
    }
  } else if (secondsLeft <= 300) {
    ; TODO: Make this configurable. Maybe pull config from server on start?
    if (!warnedBudgets[lowestBudgetId]) {
      warnedBudgets[lowestBudgetId] := 1
      timeLeftString := FormatSeconds(secondsLeft)
      messages.Push("Budget '" budget "' for '" title "' has " timeLeftString " left.")
    }
  } else if (warnedBudgets[lowestBudgetId]) { ; budget time was increased, need to warn again
    warnedBudgets.Delete(lowestBudgetId)
  }
}

ShowMessages(messages, enableBeep = true) {
  if (messages.Length()) {
    text := ""
    for ignored, message in messages {
      text .= "`n" message
    }
    ShowMessage(SubStr(text, 2))
    if (enableBeep) {
      Beep(3)
    }
  }
}

; Returns an associative array describing all windows, keyed by title.
; For processes without windows, or whose windows cannot be detected
; (see https://github.com/zieren/kids-freedom-control/issues/18), process
; names can be configured in the .ini file together with a "synthetic" title.
; If these processes are detected, the synthetic title is injected into the
; result, with an window ID of 0 and an activity status of false.
; Elements are arrays with these keys:
; "ids": ahk_id-s of all windows with that title
; "active": 0 or 1 to indicate whether the window is active (has focus)
; "name": The process name, for debugging.
GetAllWindows() {
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
        windows[rootTitle] := {"ids": [rootID], "active": id == activeID, "name": processName}
      } else {
        windows[rootTitle]["ids"].Push(rootID)
        windows[rootTitle]["active"] := windows[rootTitle]["active"] || id == activeID
      }
    }
  }
  ; Inject synthetic titles for configured processes.
  for name, title in WATCH_PROCESSES {
    Process, Exist, %name%
    if (ErrorLevel) {
      ; It is possible that this title already exists, e.g. if the user has deliberately
      ; chosen such a title. Processes have no window IDs to close and are always considered
      ; to be non-active (to avoid mulitple active titles).
      if (!windows.HasKey(title)) {
        windows[title] := {"ids": [], "pid": ErrorLevel, "active": false, "name": name}
      } else {
        ; Just add the PID for termination handling.
        windows[title]["pid"] := Errorlevel
      }
    }
  }
  return windows
}

; This function does the thing. "Critical" should be set while this is running, since the code
; uses global state. Interruptions may currently only occur from the status hotkey, which calls
; this function asynchronously to the main loop.
DoTheThing(reportStatus) {
  windows := GetAllWindows()

  ; Build request payload.
  windowList := ""
  indexToTitle := []
  focusIndex := -1
  for title, window in windows {
    windowList .= "`n" title
    if (window["active"]) {
      focusIndex := indexToTitle.Length()
    }
    indexToTitle.Push(title)
  }

  ; Perform request.
  ; https://docs.microsoft.com/en-us/previous-versions/windows/desktop/ms759148(v=vs.85)
  request := ComObjCreate("MSXML2.XMLHTTP.6.0")
  request.open("POST", URL "/rx/", false, HTTP_USER, HTTP_PASS)
  request.send(USER "`n" focusIndex windowList)

  ; Parse response. Format is described in RX.php.
  responseLines := StrSplit(request.responseText, "`n")
  ; Collect messages to show after processing the response.
  messages := []
  ; Collect titles by budget ID so we can show them if reportStatus is set.
  titlesByBudget := {}

  if (responseLines[1] == "error") {
    messages := responseLines
    messages[1] := "The server reported an error:"
  } else {
    ; See RX.php for response sections.
    section := 1
    index := 1
    budgets := {}
    for ignored, line in responseLines {
      if (line == "") {
        section++
        continue
      }
      switch (section) {
        case 1:
          s := StrSplit(line, ":", "", 3)
          budgets[s[1]] := {"remaining": s[2], "name": s[3]}
        case 2:
          ProcessTitleResponse(line, windows, indexToTitle[index++], budgets, titlesByBudget, messages)
        default:
          messages := responseLines
          messages.InsertAt(1, "Invalid response received from server:")
      }
    }
    ; TODO: Add option to logout/shutdown:
    ; Shutdown, 0 ; 0 means logout
  }

  if (reportStatus) {
    AddStatusReport(budgets, titlesByBudget, messages)
  }

  return messages
}

AddStatusReport(budgets, titlesByBudget, messages) {
  if (messages.length()) {
    messages.Push("")
  }
  messages.Push("---------- * STATUS * ----------", "")
  budgetsSorted := {}
  for id, budget in budgets {
    budgetsSorted[budget["name"]] := {"remaining": budget["remaining"], "id": id}
  }
  for name, budget in budgetsSorted {
    messages.Push("")
    messages.Push(FormatSeconds(budget["remaining"]) " " name)
    for ignored, title in titlesByBudget[budget["id"]] {
      messages.Push("  --> " title)
    }
  }
}

; The main loop that does the thing.
Loop {
  Critical, On
  ShowMessages(DoTheThing(false))
  Critical, Off
  Sleep, SAMPLE_INTERVAL_SECONDS * 1000
}

ShowStatus() {
  Critical, On
  ShowMessages(DoTheThing(true), false)
  Critical, Off
}

DebugShowStatus() {
  msg := ""
  for title, window in GetAllWindows()
  {
    ids := ""
    for id, ignored in window["ids"] {
      ids .= (ids ? "/" : "") id
    }
    msg .= "title=" title " ids=" ids " active=" window["active"] " name=" window["name"] "`n"
  }
  msg .= "----- warned budgets:`n"
  for budget, ignored in warnedBudgets {
    msg .= "warned: " budget "`n"
  }
  msg .= "----- doomed windows`n"
  for id, ignored in doomedWindows {
    msg .= "doomed: " id "`n"
  }
  msg .= "----- doomed processes`n"
  for pid, ignored in doomedProcesses {
    msg .= "doomed: " pid "`n"
  }
  msg .= "----- watched processes`n"
  for name, title in WATCH_PROCESSES {
    msg .= "process: " name " -> " title "`n"
  }

  ShowMessage(msg)
}

; --- GUI ---

ButtonOK:
Gui, Destroy
return

; --- User hotkeys ---

^F12::
ShowStatus()
return

; --- Debug hotkeys ---

^+F12::
DebugShowStatus()
return
