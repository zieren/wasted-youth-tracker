; Get config for contacting server.
EnvGet, USERPROFILE, USERPROFILE ; e.g. c:\users\johndoe
INI_FILE := USERPROFILE "\wasted.ini"
global URL, HTTP_USER, HTTP_PASS, USER
IniRead, URL, %INI_FILE%, server, url
IniRead, HTTP_USER, %INI_FILE%, server, username
IniRead, HTTP_PASS, %INI_FILE%, server, password
IniRead, USER, %INI_FILE%, account, user

; Shared variables should be safe since AutoHotkey simulates concurrency
; using a single thread: https://www.autohotkey.com/docs/misc/Threads.htm

; Track last successful request to server. If this exceeds the configured
; threshold, we assume the network is down and will doom everything because
; we can no longer enforce limits.
global LAST_SUCCESSFUL_REQUEST := 0 ; epoch seconds
; Track windows for which a "please close" message was already shown.
; There's no set type, so use an associative array.
global DOOMED_WINDOWS := {}
; Same for processes without windows.
global DOOMED_PROCESSES := {}
; Remember budgets for which a "time is almost up" message has been shown.
global WARNED_BUDGETS := {}

; Populate config with defaults.
global CFG := {}
; Contact the server every x seconds.
global SAMPLE_INTERVAL_SECONDS := "sample_interval_seconds"
CFG[SAMPLE_INTERVAL_SECONDS] := 15
; Time the user has to manually close a window for which time has expired.
; After this time the script attempts to close the window.
global GRACE_PERIOD_SECONDS := "grace_period_seconds"
CFG[GRACE_PERIOD_SECONDS] := 30
; Time the script allows for closing the window. After this time the process is killed.
global KILL_AFTER_SECONDS := "kill_after_seconds"
CFG[KILL_AFTER_SECONDS] := 30
; Special processes that should never be closed.
global IGNORE_PROCESSES := "ignore_processes"
CFG[IGNORE_PROCESSES] := {}
CFG[IGNORE_PROCESSES]["explorer.exe"] := 1 ; also runs the task bar and the start menu
CFG[IGNORE_PROCESSES]["AutoHotkey.exe"] := 1 ; this script itself (and other AHK scripts)
CFG[IGNORE_PROCESSES]["LogiOverlay.exe"] := 1 ; Logitech mouse/trackball driver
; Processes that don't (always) have windows but should be included, e.g. audio players.
global WATCH_PROCESSES := "watch_processes"
CFG[WATCH_PROCESSES] := {}
; Offline time after which we warn the user.
global OFFLINE_WARN_SECONDS := "offline_warn_seconds"
CFG[OFFLINE_WARN_SECONDS] := 60
; Offline time after which we close everything.
global OFFLINE_CLOSE_SECONDS := "offline_close_seconds"
CFG[OFFLINE_CLOSE_SECONDS] := 2 * 60
; For debugging: Don't actually close windows.
global DISABLE_ENFORCEMENT := "disable_enforcement"
CFG[DISABLE_ENFORCEMENT] := 0

ReadConfig()

; TODO: Is this a loophole?
DetectHiddenWindows, Off

; The tray icon allows the user to exit the script, so hide it.
; Killing it with the task manager or removing it from autostart requires 
; significantly more skill; for now this is good enough.
if (!CFG[DISABLE_ENFORCEMENT]) {
  Menu, Tray, NoIcon
}

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
  Gui, Show, , Wasted Youth Tracker
}

; Returns the specified seconds formatted as "hh:mm:ss".
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
  return sign Format("{:i}:{:02i}:{:02i}", hours, minutes, seconds)
}

; Close the window with the specified ahk_id.
TerminateWindow(id) {
  if (CFG[DISABLE_ENFORCEMENT]) {
    ShowMessage("enforcement disabled: close window " id)
  } else {
    if (WinExist("ahk_id" id)) {
      WinGet, pid, PID, ahk_id %id%
      ; WinKill does not seem to work: E.g. EditPlus with an unsaved file will just prompt to save,
      ; but not actually close. So maybe it sends a close request, and when the program acks that
      ; request it considers that success.
      WinClose % "ahk_id" id, , % CFG[KILL_AFTER_SECONDS]
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
        Process, Close, %pid%
      }
    }
  }
  DOOMED_WINDOWS.Delete(id)
}

TerminateProcess(pid) {
  if (CFG[DISABLE_ENFORCEMENT]) {
    ShowMessage("enforcement disabled: kill process " pid)
  } else {
    Process, Close, %pid%
  }
  DOOMED_PROCESSES.Delete(pid)
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
;
; Elements are arrays with these keys:
; "ids": ahk_id-s of all windows with that title
; "active": 0 or 1 to indicate whether the window is active (has focus)
; "name": The name of the process owning the window, for debugging
;
; For processes without windows, or whose windows cannot be detected
; (see https://github.com/zieren/kids-freedom-control/issues/18), process
; names can be configured on the server together with a "synthetic" title.
; If these processes are detected, the synthetic title is injected into the
; result. In this case the keys are:
; "ids": always empty, i.e. []
; "active": always false (to avoid multiple active titles)
; "name": The process name (which was already known and now detected)
; "pid": The PID, for termination handling
; If an entry with the synthetic title already exists, the "pid" field is
; added to it and the other fields are preserved.
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
  if (rootTitle && !CFG[IGNORE_PROCESSES][processName]) {
      ; Store process name for debugging: This is needed when we close a window that should have
      ; been ignored.
      if (!windows[rootTitle]) {
        windows[rootTitle] := {"ids": [rootID], "active": id == activeID, "name": processName}
      } else {
        windows[rootTitle]["ids"].Push(rootID)
        windows[rootTitle]["active"] := windows[rootTitle]["active"] || id == activeID
      }
    }
  }
  ; Inject synthetic titles for configured processes.
  for name, title in CFG[WATCH_PROCESSES] {
    Process, Exist, %name%
    if (ErrorLevel) {
      if (!windows[title]) {
        windows[title] := {"ids": [], "pid": ErrorLevel, "active": false, "name": name}
      } else {
        ; It is possible that this title already exists, e.g. if the user has deliberately
        ; chosen such a title. Just add the PID for termination handling.
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
  request := CreateRequest()
  try {
    OpenRequest("POST", request, "rx/").send(USER "`n" focusIndex windowList)
    LAST_SUCCESSFUL_REQUEST := EpochSeconds()
  } catch {
    return HandleOffline(windows)
  }

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

CreateRequest() {
  return ComObjCreate("MSXML2.XMLHTTP.6.0")
}

OpenRequest(method, request, path) {
  request.open(method, URL "/" path, false, HTTP_USER, HTTP_PASS)
  return request
}

HandleOffline(windows) {
  offlineWarnSecondsLeft := OFFLINE_WARN_SECONDS - (EpochSeconds() - LAST_SUCCESSFUL_REQUEST)
  offlineCloseSecondsLeft := OFFLINE_CLOSE_SECONDS - (EpochSeconds() - LAST_SUCCESSFUL_REQUEST)
  if (offlineCloseSecondsLeft < 0) {
    messages := []
    for title, window in windows {
      DoomWindow(window, messages, "No uplink to the mothership. Please close '" title "'")
    }
    return messages
  }
  if (offlineWarnSecondsLeft < 0) {
    return ["No uplink to the mothership. Will close everything in " FormatSeconds(offlineCloseSecondsLeft)]
  }
}

ProcessTitleResponse(line, windows, title, budgets, titlesByBudget, messages) {
  budgetIds := StrSplit(line, ",")
  for ignored, id in budgetIds {
    if (!titlesByBudget[id]) {
      titlesByBudget[id] := []
    }
    titlesByBudget[id].Push(title)
  }
  lowestBudgetId := FindLowestBudget(budgetIds, budgets)
  secondsLeft := budgets[lowestBudgetId]["remaining"]
  budget := budgets[lowestBudgetId]["name"]
  if (secondsLeft <= 0) {
    closeMessage := "Time is up for budget '" budget "', please close '" title "'"
    DoomWindow(windows[title], messages, closeMessage)
  } else if (secondsLeft <= 300) {
    ; TODO: Make this configurable. Maybe pull config from server on start?
    if (!WARNED_BUDGETS[lowestBudgetId]) {
      WARNED_BUDGETS[lowestBudgetId] := 1
      timeLeftString := FormatSeconds(secondsLeft)
      messages.Push("Budget '" budget "' for '" title "' has " timeLeftString " left.")
    }
  } else if (WARNED_BUDGETS[lowestBudgetId]) { ; budget time was increased, need to warn again
    WARNED_BUDGETS.Delete(lowestBudgetId)
  }
}

; Dooms the specified window/process, adding the appropriate message to "messages".
DoomWindow(window, messages, closeMessage) {
  pushCloseMessage := false
  ; Handle regular windows.
  for ignored, id in window["ids"] {
    if (!DOOMED_WINDOWS[id]) {
      DOOMED_WINDOWS[id] := 1
      terminateWindow := Func("TerminateWindow").Bind(id)
      SetTimer % terminateWindow, % CFG[GRACE_PERIOD_SECONDS] * (-1000)
      pushCloseMessage = true
    }
  }
  ; Handle process without window.
  if (window["pid"]) {
    pid := window["pid"]
    if (!DOOMED_PROCESSES[pid]) {
      DOOMED_PROCESSES[pid] := 1
      terminateProcess := Func("TerminateProcess").Bind(pid)
      ; The kill should happen after the same amount of time as for a window.
      SetTimer % terminateProcess, % (CFG[GRACE_PERIOD_SECONDS] + CFG[KILL_AFTER_SECONDS]) * (-1000)
      pushCloseMessage = true
    }
  }
  if (pushCloseMessage)
    messages.Push(closeMessage)
}

AddStatusReport(budgets, titlesByBudget, messages) {
  if (messages.Length()) {
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

ReadConfig() {
  responseLines := ["Not the Mama!"]
  path := "cfg/?user=" USER
  try {
    request := CreateRequest()
    OpenRequest("GET", request, path).send() 
    responseLines := StrSplit(request.responseText, "`n")
  } catch {
    ; handled below
  }
  ; This also handles request failure.
  if (responseLines[1] != "-*- cfg -*-" || Mod(responseLines.Length(), 2) != 1) {
    ShowMessage("Failed to read config from " URL "/" path)
    return
  }
  msgs := []
  Loop % (responseLines.Length() - 1) / 2 {
    k := responseLines[A_Index * 2]
    v := responseLines[A_Index * 2 + 1]
    if (InStr(k, "watch_process") = 1) { 
      i := RegExMatch(v, "=[^=]+$")
      if (i > 0) {
        name := trim(SubStr(v, 1, i - 1))
        title := trim(SubStr(v, i + 1))
        CFG[WATCH_PROCESSES][name] := title
      } else {
        msgs.Push("Ignoring invalid value for option " k ": " v)
      }
    } else if (InStr(k, "ignore_process") = 1) {
      CFG[IGNORE_PROCESSES][v] := 1
    } else {
      CFG[k] := v
    }
  }
  ShowMessages(msgs, false)
}

; The main loop that does the thing.
Loop {
  Critical, On
  ShowMessages(DoTheThing(false))
  Critical, Off
  Sleep % CFG[SAMPLE_INTERVAL_SECONDS] * 1000
}

EpochSeconds() {
  ts := A_NowUTC
  EnvSub, ts, 19700101000000, Seconds
  return ts
}

ShowStatus() {
  Critical, On
  ShowMessages(DoTheThing(true), false)
  Critical, Off
}

DebugShowStatus() {
  msgs := []
  for title, window in GetAllWindows()
  {
    ids := ""
    for id, ignored in window["ids"] {
      ids .= (ids ? "/" : "") id
    }
    msgs.Push("title=" title " ids=" ids " active=" window["active"] " name=" window["name"])
  }
  msgs.Push("----- warned budgets:")
  for budget, ignored in WARNED_BUDGETS {
    msgs.Push("warned: " budget)
  }
  msgs.Push("----- doomed windows")
  for id, ignored in DOOMED_WINDOWS {
    msgs.Push("doomed: " id)
  }
  msgs.Push("----- doomed processes")
  for pid, ignored in DOOMED_PROCESSES {
    msgs.Push("doomed: " pid)
  }
  msgs.Push("----- watched processes")
  for name, title in CFG[WATCH_PROCESSES] {
    msgs.Push("process: " name " -> " title)
  }
  msgs.Push("----- ignored processes")
  for name, ignored in CFG[IGNORE_PROCESSES] {
    msgs.Push("process: " name)
  }
  msgs.Push("", "Last successful request: " LAST_SUCCESSFUL_REQUEST)

  ShowMessages(msgs, false)
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
