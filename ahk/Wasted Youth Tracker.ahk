; Get config for contacting server.
EnvGet, USERPROFILE, USERPROFILE ; e.g. c:\users\johndoe
INI_FILE := USERPROFILE "\wasted.ini"
global URL, HTTP_USER, HTTP_PASS, USER, LOGFILE, LAST_ERROR, APP_NAME
APP_NAME := "Wasted Youth Tracker 0.0.0-7"
IniRead, URL, %INI_FILE%, server, url
IniRead, HTTP_USER, %INI_FILE%, server, username
IniRead, HTTP_PASS, %INI_FILE%, server, password
IniRead, USER, %INI_FILE%, account, user
LOGFILE := A_Temp "\wasted.log"
LAST_ERROR := GetLastLogLine() ; possibly empty

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

RequestConfig()

; TODO: Is this a loophole?
DetectHiddenWindows, Off

; The tray icon allows the user to exit the script, so hide it.
; Killing it with the task manager or removing it from autostart requires
; significantly more skill; for now this is good enough.
if (!CFG[DISABLE_ENFORCEMENT])
  Menu, Tray, NoIcon

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

; Close the window with the specified ahk_id. "title" can be set to the originally doomed title, or
; be empty. In the former case the window is only doomed if its title matches. This is for browsers,
; which change the title when the tab is closed (or deselected, which unfortunately we can't tell
; apart), but retain the ahk_id.
TerminateWindow(id, title) {
  if (title != "") {
    WinGetTitle, currentTitle, ahk_id %id%
  } else {
    currentTitle := "" ; skip the check below
  }
  if (currentTitle == title) {
    if (CFG[DISABLE_ENFORCEMENT]) {
      Critical, On ; prevent pseudo-multithreaded creation of conflicting GUI
      ShowDebugGui("enforcement disabled: close/kill '" title "' (" id ")")
      Critical, Off
    } else {
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
    Critical, On ; prevent pseudo-multithreaded creation of conflicting GUI
    ShowDebugGui("enforcement disabled: kill process " pid)
    Critical, Off
  } else {
    Process, Close, %pid%
  }
  DOOMED_PROCESSES.Delete(pid)
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
DoTheThing(showStatusGui) {
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
    OpenRequest("POST", request, "rx/").send(USER "`n" LAST_ERROR windowList)
    CheckStatus200(request)
    LAST_REQUEST_DURATION_MS := A_TickCount - requestStart
    LAST_SUCCESSFUL_REQUEST := EpochSeconds()
    LAST_ERROR := "" ; successfully reported error to server
  } catch exception {
    HandleOffline(exception, windows)
    return ; Better luck next time!
  }

  ; Parse response. Format is described in RX.php.
  responseLines := StrSplit(request.responseText, "`n")
  ; Collect titles by limit ID so we can show them if showStatusGui is set.
  limitsToTitles := {}

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
        s := StrSplit(line, ";", "", 7)
        limits[s[1]] := {"id": s[1]}
        limits[s[1]]["locked"] := s[2]
        limits[s[1]]["remaining"] := s[3]
        limits[s[1]]["total"] := s[4] ; ? rename this and the above
        limits[s[1]]["currentSlot"] := s[5]
        limits[s[1]]["nextSlot"] := s[6]
        limits[s[1]]["name"] := s[7]
      case 2:
        title := indexToTitle[index++]
        limitIds := StrSplit(line, ",")
        for ignored, id in limitIds {
          if (!limitsToTitles[id]) {
            limitsToTitles[id] := []
          }
          limitsToTitles[id].Push(title)
        }
    }
  }
  if (section != 2) {
    LogError("Invalid response received from server:`n" request.responseText, true)
    return ; Better luck next time!
  }

  ; Business logic for both "time low" and "time up" warnings: If there is a new limit/title to warn
  ; about, show the GUI with all warnings (but don't show "time low" for limits for which no titles
  ; are active). If there are no warnings at all, destroy the GUI.

  ; Find limits whose time is low or up. Entries: [limit, title]
  timeLow := []
  timeUp := []
  newlyWarned := false
  newlyDoomed := false
  for id, limit in limits {
    if (limit["remaining"] <= 0) {
      ; This shows a title that maps to N limits N times in the list. This is useful so the kid
      ; knows which limits would need to be extended.
      for ignored, title in limitsToTitles[id] {
        timeUp.Push([limit, title])
        newlyDoomed |= DoomWindow(windows[title], title)
      }
    } else if (limit["remaining"] <= 300) { ; TODO: 300 -> config option
      for ignored, title in limitsToTitles[id] {
        timeLow.Push([limit, title])
        if (!WARNED_LIMITS[id]) {
          ; We have warned about at least one current title. Don't warn about future titles.
          WARNED_LIMITS[id] := 1
          newlyWarned := true
        }
      }
    } else {
      WARNED_LIMITS.Delete(id) ; limit was increased, would need to warn again next time it's low
    }
  }

  ; "Time low" is shown in top left corner, "time up" in top center.
  ShowOrDestroyTimeLowGui(newlyWarned, timeLow)
  ShowOrDestroyTimeUpGui(newlyDoomed, timeUp)

  if (showStatusGui)
    ShowStatusGui(limits, limitsToTitles)
}

ToGuiRow(limit, title = "") {
  locked := limit["locked"] ? "L" : ""
  name := limit["name"]
  remaining := FormatSeconds(limit["remaining"])
  currentSlot := limit["currentSlot"]
  nextSlot := limit["nextSlot"]
  total := FormatSeconds(limit["total"])
  return [locked, name, title, remaining, currentSlot, nextSlot, total]
}

AddGuiListView(displayRows, totalRows) {
  Gui, Add, ListView, r%displayRows% w760 Count%totalRows%, Lock|Limit|Title|Now Left|Current Slot|Next Slot|Total Left
}

AddGuiRow(row) {
  LV_Add("", row[1], row[2], row[3], row[4], row[5], row[6], row[7])
}

SetGuiColumnWidths() {
  LV_ModifyCol(1, 20)
  LV_ModifyCol(2, 100)
  LV_ModifyCol(3, 300)
  LV_ModifyCol(4, 60)
  LV_ModifyCol(5, 100)
  LV_ModifyCol(6, 100)
  LV_ModifyCol(7, 60)
}

ShowStatusGui(limits, limitsToTitles) {
  limitsSorted := {}
  for id, limit in limits {
    limitsSorted[limit["name"]] := limit
  }
  topRows := []
  bottomRows := []
  for name, limit in limitsSorted {
    for ignored, title in limitsToTitles[limit["id"]] {
      topRows.Push([limit, title])
    }
    ; Put limits that don't affect current titles at the bottom.
    if (!limitsToTitles[limit["id"]].Length()) {
      bottomRows.Push([limit, ""])
    }
  }
  
  listLimitAndTitle := topRows
  listLimitAndTitle.Push(bottomRows*) ; pass as variadic args

  BuildTimeGui("Status", "Status", "", listLimitAndTitle)
  Gui, Show
}

ShowOrDestroyTimeLowGui(newlyWarned, timeLow) {
  if (!timeLow.Length()) {
    Gui, TimeLow:Destroy ; No active titles with low limits.
    return
  }
  if (!newlyWarned)
    return ; Don't reshow the GUI when nothing was added.
  BuildTimeGui("TimeLow", "Time Low", "Time is low for:", timeLow)
  Gui, Show, X42 Y42 NoActivate
  SoundTimeLow()
}

ShowOrDestroyTimeUpGui(newlyDoomed, timeUp) {
  if (!timeUp.Length()) {
    Gui, TimeUp:Destroy ; All windows were closed/terminated.
    return
  }
  if (!newlyDoomed)
    return ; Don't reshow the GUI when nothing was added.
  BuildTimeGui("TimeUp", "Time Up", "Time is up for:", timeUp)
  Gui, Show, xCenter Y42 NoActivate
  SoundTimeUp()
}

BuildTimeGui(guiName, guiTitle, guiMessage, listLimitAndTitle) {
  totalRows := listLimitAndTitle.Length()
  displayRows := Min(20, totalRows)
  Gui, %guiName%:New, AlwaysOnTop, %APP_NAME% - %guiTitle%
  if (guiMessage)
    Gui, Add, Text,, %guiMessage%
  AddGuiListView(displayRows, totalRows)
  for ignored, limitAndTitle in listLimitAndTitle {
    AddGuiRow(ToGuiRow(limitAndTitle[1], limitAndTitle[2]))
  }
  SetGuiColumnWidths()
  Gui, Add, Button, w80 x310 g%guiName%GuiOK, &OK
}

SoundTimeUp() {
  Loop, 3 {
    SoundBeep, 1000, 500
  }
}

SoundError() {
  SoundBeep, 2000, 1000
}

SoundTimeLow() {
  SoundBeep, 523, 500
}

CreateRequest() {
  return ComObjCreate("MSXML2.XMLHTTP.6.0")
}

OpenRequest(method, request, path) {
  request.open(method, URL "/" path, false, HTTP_USER, HTTP_PASS)
  return request
}

CheckStatus200(request) {
  if (request.status != 200)
    throw Exception("HTTP " request.status ": " request.statusText)
}

; Logs the exception and, after a while, dooms all windows and shows a variant of the TimeUp GUI.
HandleOffline(exception, windows) {
  ; Server may be temporarily unavailable, so initially we only log silently.
  LogError(ExceptionToString(exception), false)
  offlineSeconds := EpochSeconds() - LAST_SUCCESSFUL_REQUEST
  if (offlineSeconds > CFG[OFFLINE_GRACE_PERIOD_SECONDS]) {
    anyNewlyDoomed := false
    for title, window in windows
      anyNewlyDoomed |= DoomWindow(window, "")
    if (anyNewlyDoomed) {
      ; Build a variant of the TimeUp GUI.
      Gui, TimeUp:New, AlwaysOnTop, %APP_NAME% - Server Unreachable
      Gui, Add, Text,, Server is unreachable`, please close all programs!
      Gui, Add, Button, w80 x210, &OK
      Gui, Show, w500 xCenter Y42 NoActivate
      SoundTimeUp()
    }
  }
}

; Dooms the specified window/process. Returns true if at least one title was newly doomed.
DoomWindow(window, title) {
  newlyDoomed := false
  ; Handle regular windows.
  for ignored, id in window["ids"] {
    if (!DOOMED_WINDOWS[id]) {
      DOOMED_WINDOWS[id] := 1
      newlyDoomed := true
      terminateWindow := Func("TerminateWindow").Bind(id, title)
      SetTimer % terminateWindow, % CFG[GRACE_PERIOD_SECONDS] * (-1000)
    }
  }
  ; Handle process without window.
  if (window["pid"]) {
    pid := window["pid"]
    if (!DOOMED_PROCESSES[pid]) {
      DOOMED_PROCESSES[pid] := 1
      newlyDoomed := true
      terminateProcess := Func("TerminateProcess").Bind(pid)
      ; The kill should happen after the same amount of time as for a window.
      SetTimer % terminateProcess, % (CFG[GRACE_PERIOD_SECONDS] + CFG[KILL_AFTER_SECONDS]) * (-1000)
    }
  }
  return newlyDoomed
}

RequestConfig() {
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
    LogError(ExceptionToString(exception), true)
    return
  }

  errors := ""
  Loop % responseLines.Length() / 2 {
    k := responseLines[A_Index * 2 - 1]
    v := responseLines[A_Index * 2]
    if (InStr(k, "watch_process") == 1) {
      i := RegExMatch(v, "=[^=]+$")
      if (i > 0) {
        name := trim(SubStr(v, 1, i - 1))
        title := trim(SubStr(v, i + 1))
        CFG[WATCH_PROCESSES][name] := title
      } else {
        errors .= "`nIgnoring invalid value for option " k ": '" v "'"
      }
    } else if (InStr(k, "ignore_process") == 1) {
      CFG[IGNORE_PROCESSES][v] := 1
    } else {
      CFG[k] := v
    }
  }
  if (StrLen(errors) > 0)
    LogError(SubStr(errors, 2), true)
}

; The main loop that does the thing.
Loop {
  Critical, On
  DoTheThing(false)
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
  Gui, Status:New, , %APP_NAME% - Status - Requesting...
  Gui, Add, Text
  Gui, Add, Text, w350 +Center, Requesting status, please wait...
  Gui, Add, Text
  Gui, Show
  DoTheThing(true)
  Critical, Off
}

LogErrorHandler(exception) {
  LogError(ExceptionToString(exception), true)
  ; No ExitApp: Leave the script running because it may help figure out the error.
  return 1
}

ExceptionToString(exception) {
  msg := exception.Message
  if (exception.Extra)
    msg .= " (" exception.Extra ")"
  return exception.Line " " msg
}

; Logs the message to a file and optionally to the UI.
LogError(message, showGui) {
  FormatTime, t, Time, yyyyMMdd HHmmss
  message := t " " USER " "  message
  LAST_ERROR := RegExReplace(message, "[`r`n]+", " // ")
  FileGetSize, filesize, % LOGFILE, K
  if (filesize > 1024)
    FileDelete % LOGFILE
  FileAppend % LAST_ERROR "`n", % LOGFILE
  if (showGui)
    ShowErrorGui("Please get your parents to look at this error:`n" message "`nFull details logged to " LOGFILE)
}

GetLastLogLine() {
  lastLine := ""
  Loop, Read, %LOGFILE%
  {
    lastLine := A_LoopReadLine
  }
  return lastLine
}

ShowErrorGui(text) {
  static errorButtonOK := 0
  Gui, Error:New, AlwaysOnTop, %APP_NAME% - Error
  Gui, Add, Edit, w700 ReadOnly, %text%
  Gui, Add, Button, w80 x310 gErrorGuiOK VerrorButtonOK, &OK
  GuiControl, Focus, errorButtonOK ; to avoid text selection
  Gui, Show
  ; TODO: Should we use (possibly disabled) system sounds instead? Or bundle mp3-s?
  SoundError()
}

ShowDebugGui(text) {
  static debugButtonOK := 0
  Gui, Debug:New,, %APP_NAME% - Debug
  Gui, Add, Edit, w700 ReadOnly, %text%
  Gui, Add, Button, w80 x310 gDebugGuiOK VdebugButtonOK, &OK
  GuiControl, Focus, debugButtonOK ; to avoid text selection
  Gui, Show
}

DebugShowStatus() {
  text := ""
  for title, window in GetAllWindows() {
    ids := ""
    for ignored, id in window["ids"] {
      ids .= (ids ? "/" : "") id
    }
    text .= "title=" title " ids=" ids " name=" window["name"] "`n"
  }
  text .= "----- warned limits:`n"
  for limit, ignored in WARNED_LIMITS
    text .= "warned: " limit "`n"
  text .= "----- doomed windows`n"
  for id, ignored in DOOMED_WINDOWS
    text .= "doomed: " id "`n"
  text .= "----- doomed processes`n"
  for pid, ignored in DOOMED_PROCESSES
    text .= "doomed: " pid "`n"
  text .= "----- watched processes`n"
  for name, title in CFG[WATCH_PROCESSES]
    text .= "process: " name " -> " title "`n"
  text .= "----- ignored processes`n"
  for name, ignored in CFG[IGNORE_PROCESSES]
    text .= "process: " name "`n"
  t := FormatSeconds(EpochSeconds() - LAST_SUCCESSFUL_REQUEST)
  text .= "Last successful request: " t " ago, duration " LAST_REQUEST_DURATION_MS "ms`n"
  text .= "Last error: " LAST_ERROR

  ShowDebugGui(text)
}

; --- GUI ---

StatusGuiOK:
StatusGuiEscape:
Gui, Status:Destroy
return

TimeLowGuiOK:
TimeLowGuiEscape:
Gui, TimeLow:Destroy
return

TimeUpGuiOK:
TimeUpGuiEscape:
Gui, TimeUp:Destroy
return

ErrorGuiOK:
ErrorGuiEscape:
Gui, Error:Destroy
return

DebugGuiOK:
DebugGuiEscape:
Gui, Debug:Destroy
return

; --- User hotkeys ---

^F12::
ShowStatus()
return

; --- Debug hotkeys ---

^+F12::
DebugShowStatus()
return
