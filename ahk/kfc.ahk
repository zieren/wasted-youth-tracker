RX_URL := "http://zieren.de/kfc/rx.php"
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
  filename := TMP . "\kfc.txt"
  Run, cmd /c echo {"title":"%title%"} >"%filename%", , Hide
  Run, curl --header "Content-Type: application/json" --request POST --data "@%filename%" %RX_URL%, , Hide
  Sleep, 1000
}
