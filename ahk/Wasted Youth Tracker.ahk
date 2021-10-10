; Get config for contacting server.
EnvGet, USERPROFILE, USERPROFILE ; e.g. c:\users\johndoe
INI_FILE := USERPROFILE "\wasted.ini"
global URL, HTTP_USER, HTTP_PASS, USER
IniRead, URL, %INI_FILE%, server, url
IniRead, HTTP_USER, %INI_FILE%, server, username
IniRead, HTTP_PASS, %INI_FILE%, server, password
IniRead, USER, %INI_FILE%, account, user

OnError("LogErrorHandler") ; The handler logs some of the config read above.

; Shared variables should be safe since AutoHotkey simulates concurrency
; using a single thread: https://www.autohotkey.com/docs/misc/Threads.htm

; Track last successful request to server. If this exceeds the configured
; threshold, we assume the network is down and will doom everything because
; we can no longer enforce limits.
global LAST_SUCCESSFUL_REQUEST := EpochSeconds() ; allow offline grace period on startup
; Purely informational metric for server processing time. Shown in debug window.
global LAST_REQUEST_DURATION_MS := 0
; Track windows for which a "please close" message was already shown.
; There's no set type, so use an associative array.
global DOOMED_WINDOWS := {}
; Same for processes without windows.
global DOOMED_PROCESSES := {}
; Remember limits for which a "time is almost up" message has been shown.
global WARNED_LIMITS := {}

; Populate config with defaults.
global CFG := {}
; Contact the server every x seconds.
global SAMPLE_INTERVAL_SECONDS := "sample_interval_seconds"
CFG[SAMPLE_INTERVAL_SECONDS] := 15 ; KEEP THE DEFAULT IN SYNC WITH THE SERVER!!!
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
; Allowed offline grace period. After this, all programs are closed.
global OFFLINE_GRACE_PERIOD_SECONDS := "offline_grace_period_seconds"
CFG[OFFLINE_GRACE_PERIOD_SECONDS] := 60
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

; Close the window with the specified ahk_id. If the window title does not match what was originally
; doomed, this is a no-op. This is for browsers, which change the title when the tab is closed (or
; deselected, which unfortunately we can't tell apart), but retain the ahk_id.
TerminateWindow(id, title) {
  if (CFG[DISABLE_ENFORCEMENT]) {
    ShowMessage("enforcement disabled: close/kill '" title "' (" id ")")
  } else {
    WinGetTitle, currentTitle, ahk_id %id%
    if (currentTitle == title) {
      WinGet, pid, PID, ahk_id %id%
      ; WinKill does not seem to work: E.g. EditPlus with an unsaved file will just prompt to save,
      ; but not actually close. So maybe it sends a close request, and when the program acks that
      ; request it considers that success.
      WinClose % "ahk_id" id, , % CFG[KILL_AFTER_SECONDS]
      if (WinExist("ahk_id" id)) {
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

FindLowestLimit(limitIds, limits) {
  lowestLimitId := -1
  for ignored, limitId in limitIds {
    if (lowestLimitId == -1
        || limits[limitId]["remaining"] < limits[lowestLimitId]["remaining"]) {
      lowestLimitId := limitId
    }
  }
  return lowestLimitId
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
; "name": The name of the process owning the window, for debugging
;
; For processes without windows, or whose windows cannot be detected
; (see https://github.com/zieren/wasted-youth-tracker/issues/18), process
; names can be configured on the server together with a "synthetic" title.
; If these processes are detected, the synthetic title is injected into the
; result. In this case the keys are:
; "ids": always empty, i.e. []
; "name": The process name (which was already known and now detected)
; "pid": The PID, for termination handling
; If an entry with the synthetic title already exists, the "pid" field is
; added to it and the other fields are preserved.
GetAllWindows() {
  windows := {}
  WinGet, ids, List ; get all window IDs
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
        windows[rootTitle] := {"ids": [rootID], "name": processName}
      } else {
        windows[rootTitle]["ids"].Push(rootID)
      }
    }
  }
  ; Inject synthetic titles for configured processes.
  for name, title in CFG[WATCH_PROCESSES] {
    Process, Exist, %name%
    if (ErrorLevel) {
      if (!windows[title]) {
        windows[title] := {"ids": [], "pid": ErrorLevel, "name": name}
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
  for title, ignored in windows {
    windowList .= "`n" title
    indexToTitle.Push(title)
  }

  ; Perform request.
  ; https://docs.microsoft.com/en-us/previous-versions/windows/desktop/ms759148(v=vs.85)
  request := CreateRequest()
  try {
    requestStart := A_TickCount
    OpenRequest("POST", request, "rx/").send(USER windowList)
    CheckStatus200(request)
    LAST_REQUEST_DURATION_MS := A_TickCount - requestStart
    LAST_SUCCESSFUL_REQUEST := EpochSeconds()
  } catch exception {
    return HandleOffline(exception, windows)
  }

  ; Parse response. Format is described in RX.php.
  responseLines := StrSplit(request.responseText, "`n")
  ; Collect messages to show after processing the response.
  messages := []
  ; Collect titles by limit ID so we can show them if reportStatus is set.
  titlesByLimit := {}

  if (responseLines[1] == "error") {
    messages := responseLines
    messages[1] := "The server reported an error:"
  } else {
    ; See RX.php for response sections.
    section := 1
    index := 1
    limits := {}
    for ignored, line in responseLines {
      if (line == "") {
        section++
        continue
      }
      switch (section) {
        case 1:
          s := StrSplit(line, ":", "", 3)
          limits[s[1]] := {"remaining": s[2], "name": s[3]}
        case 2:
          ProcessTitleResponse(line, windows, indexToTitle[index++], limits, titlesByLimit, messages)
        default:
          messages := responseLines
          messages.InsertAt(1, "Invalid response received from server:")
      }
    }
    ; TODO: Add option to logout/shutdown:
    ; Shutdown, 0 ; 0 means logout
  }

  if (reportStatus) {
    AddStatusReport(limits, titlesByLimit, messages)
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

CheckStatus200(request) {
  if (request.status != 200) {
    throw Exception("HTTP " request.status ": " request.statusText)
  }
}

HandleOffline(exception, windows) {
  ; Server may be temporarily unavailable, so initially we only log silently.
  errorMessage := LogError(exception, false)
  offlineSeconds := EpochSeconds() - LAST_SUCCESSFUL_REQUEST
  messages := []
  if (offlineSeconds > CFG[OFFLINE_GRACE_PERIOD_SECONDS]) {
    for title, window in windows {
      ; Specifying the title here is slightly counterproductive because it allows to evade
      ; termination by e.g. switching between browser tabs. The offline grace period is likely
      ; larger than the regular sample interval, so this could be worthwhile. However, without a
      ; network connection the browser (which is the primary means of exploit) is not too useful.
      ; All in all, this case seems too obscure to special case it.
      DoomWindow(window, title, messages, "No uplink to the mothership. Please close '" title "'")
    }
    messages.Push("", "Error: " errorMessage)
  }
  return messages
}

ProcessTitleResponse(line, windows, title, limits, titlesByLimit, messages) {
  limitIds := StrSplit(line, ",")
  for ignored, id in limitIds {
    if (!titlesByLimit[id]) {
      titlesByLimit[id] := []
    }
    titlesByLimit[id].Push(title)
  }
  lowestLimitId := FindLowestLimit(limitIds, limits)
  secondsLeft := limits[lowestLimitId]["remaining"]
  limit := limits[lowestLimitId]["name"]
  if (secondsLeft <= 0) {
    closeMessage := "Time is up for '" limit "', please close '" title "'"
    DoomWindow(windows[title], title, messages, closeMessage)
  } else if (secondsLeft <= 300) {
    ; TODO: Make this configurable.
    if (!WARNED_LIMITS[lowestLimitId]) {
      WARNED_LIMITS[lowestLimitId] := 1
      timeLeftString := FormatSeconds(secondsLeft)
      messages.Push("Limit '" limit "' for '" title "' has " timeLeftString " left.")
    }
  } else if (WARNED_LIMITS[lowestLimitId]) { ; limit was increased, need to warn again
    WARNED_LIMITS.Delete(lowestLimitId)
  }
}

; Dooms the specified window/process, adding the appropriate message to "messages".
DoomWindow(window, title, messages, closeMessage) {
  pushCloseMessage := false
  ; Handle regular windows.
  for ignored, id in window["ids"] {
    if (!DOOMED_WINDOWS[id]) {
      DOOMED_WINDOWS[id] := 1
      terminateWindow := Func("TerminateWindow").Bind(id, title)
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

AddStatusReport(limits, titlesByLimit, messages) {
  if (messages.Length()) {
    messages.Push("")
  }
  messages.Push("---------- * STATUS * ----------", "")
  limitsSorted := {}
  for id, limit in limits {
    limitsSorted[limit["name"]] := {"remaining": limit["remaining"], "id": id}
  }
  for name, limit in limitsSorted {
    messages.Push("")
    messages.Push(FormatSeconds(limit["remaining"]) " " name)
    for ignored, title in titlesByLimit[limit["id"]] {
      messages.Push("  --> " title)
    }
  }
}

ReadConfig() {
  responseLines := ["Not the Mama!"] ; invalid
  path := "cfg/?user=" USER
  try {
    request := CreateRequest()
    OpenRequest("GET", request, path).send()
    CheckStatus200(request)
    responseLines := StrSplit(request.responseText, "`n")
    if (Mod(responseLines.Length(), 2) != 0) {
      throw Exception("Failed to read config from " URL "/" path " (failed to parse response)")
    }
  } catch exception {
    LogError(exception, true)
    return
  }

  msgs := []
  Loop % responseLines.Length() / 2 {
    k := responseLines[A_Index * 2 - 1]
    v := responseLines[A_Index * 2]
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

LogErrorHandler(exception) {
  LogError(exception, true)
  ; No ExitApp: Leave the script running because it may help figure out the error.
  return 1
}

; Logs the exception to a file and optionally to the UI. Returns a short error message.
LogError(exception, showMessage) {
  FormatTime, t, Time, yyyyMMdd HHmmss
  msg := exception.Message
  if (exception.Extra) {
    msg .= " (" exception.Extra ")"
  }
  filename := A_Temp "\wasted.log"
  FileGetSize, filesize, % filename, M
  if (filesize > 10) {
    FileDelete % filename
  }
  FileAppend % t " " USER " " exception.Line " " msg "`n", % filename
  if (showMessage) {
    ShowMessages(["Please get your parents to look at this error:", msg, "Full details logged to " filename])
  }
  ; TODO: Surface this on the server.
  return msg
}

DebugShowStatus() {
  msgs := []
  for title, window in GetAllWindows()
  {
    ids := ""
    for ignored, id in window["ids"] {
      ids .= (ids ? "/" : "") id
    }
    msgs.Push("title=" title " ids=" ids " name=" window["name"])
  }
  msgs.Push("----- warned limits:")
  for limit, ignored in WARNED_LIMITS {
    msgs.Push("warned: " limit)
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
  t := FormatSeconds(EpochSeconds() - LAST_SUCCESSFUL_REQUEST)
  msgs.Push("", "Last successful request: " t " ago")
  msgs.Push("Duration [ms]: " LAST_REQUEST_DURATION_MS)

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
