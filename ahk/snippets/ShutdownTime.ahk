; Returns the time of the last shutdown in epoch seconds, read from the registry.
; TODO (#52): Send this timestamp to the server to check whether the latest activity log is at most
; a few minutes older. Otherwise we seem to have crashed (or were terminated?!) and need to alert
; the authorities.
ShutdownTime() {
  ; This value is of type REG_BINARY and contains 8 bytes as 2-digit hex strings.
  RegRead, hexValue, HKEY_LOCAL_MACHINE\SYSTEM\CurrentControlSet\Control\Windows, ShutdownTime
  Loop % VarSetCapacity(fileTime, 8, 0)
    NumPut("0x" SubStr(hexValue, 2 * A_Index - 1, 2), fileTime, A_Index - 1, "Char")
  ; FILETIME is 100-nanosecond intervals since 1601-01-01. Adding that date in epoch seconds, which 
  ; is -11644473600, yields a regular timestamp in epoch seconds.
  return NumGet(fileTime, 0, "Int64" ) // 10000000 - 11644473600
}
