RX_URL := "http://zieren.de/kfc/rx.php"
USER := "admin"
EnvGet, TMP, TMP ; current user's temp directory

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

Loop {
  WinGetTitle, title, A
  if (!title) {
    title = "<none>"
  }
  title := UrlEncode(title)
  fileRequest := TMP . "\kfc.request"
  fileResponse := TMP . "\kfc.response"
  RunWait, cmd /c echo {"title":"%title%"`,"user":"%USER%"} >"%fileRequest%", , Hide
  RunWait, curl --header "Content-Type: application/json" --request POST --data "@%fileRequest%" %RX_URL% -o "%fileResponse%", , Hide
  FileRead, responseLines, %fileResponse%
  response := StrSplit(responseLines, "`n")
  status := response[1]
  ; Avoid excessive QPS.
  waitSeconds := response[2] >= 15 ? response[2] : 15
  if (status = "ok") {
    ; do nothing
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
