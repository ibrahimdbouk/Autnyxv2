' Autnyx auto-push — hidden launcher
' Runs autopush.bat with NO visible window (window style 0), so the
' every-minute scheduled task never flashes a CMD window.
Option Explicit
Dim fso, shell, here
Set fso   = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")
here = fso.GetParentFolderName(WScript.ScriptFullName)
shell.CurrentDirectory = here
' 0 = hidden window, False = do not wait for it to finish
shell.Run "cmd /c """ & here & "\autopush.bat""", 0, False
