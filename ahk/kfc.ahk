; TODO: Ideas:
; - Watch windows and upload on change.
; - Try ComObjGet if window title isn't good enough.

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

Loop {
  WinGetTitle, title, A
  title := UrlEncode(title)
  fileRequest := TMP . "\kfc.request"
  fileResponse := TMP . "\kfc.response"
  Run, cmd /c echo {"title":"%title%"`,"user":"%USER%"} >"%fileRequest%", , Hide
  Run, curl --header "Content-Type: application/json" --request POST --data "@%fileRequest%" %RX_URL% -o "%fileResponse%", , Hide
  FileRead, responseLines, %fileResponse%
  response := StrSplit(responseLines, "`n")
  status := response[1]
  waitSeconds := response[2]
  if (status != "ok")
  {
    MsgBox, Warning: %status%
  }
  waitMillis := 1000 * waitSeconds
  ;MsgBox, Sleeping %waitMillis%
  Sleep, waitMillis
}
