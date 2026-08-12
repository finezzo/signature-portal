# SignaturePortal — Outlook-Add-in-Cache zurücksetzen (Windows, klassisches Outlook)
#
# Beendet Outlook (nach Rückfrage), leert den Add-in-Cache (Wef-Ordner) und
# startet Outlook neu. Danach lädt Outlook Manifest und Add-in-Dateien frisch
# vom Server.
#
# Verwendung: Rechtsklick -> "Mit PowerShell ausführen"
# (oder: powershell -ExecutionPolicy Bypass -File .\Outlook-AddinCache-Reset.ps1)
#
# Hinweis "neues Outlook" (olk.exe): dort gibt es keinen lokalen Wef-Cache in
# dieser Form — meist genügt ein Neustart der App. Dieses Skript zielt auf das
# klassische Outlook (outlook.exe).

$ErrorActionPreference = 'SilentlyContinue'
$wef = Join-Path $env:LOCALAPPDATA 'Microsoft\Office\16.0\Wef'

$outlook = Get-Process -Name OUTLOOK
if ($outlook) {
    Write-Host '==> Outlook wird beendet (bitte offene Entwuerfe vorher sichern) ...'
    # Erst hoeflich schliessen (entspricht Datei -> Beenden) ...
    $outlook | ForEach-Object { $_.CloseMainWindow() | Out-Null }
    Start-Sleep -Seconds 8
    # ... und nur falls noetig hart nachhelfen.
    if (Get-Process -Name OUTLOOK) {
        Stop-Process -Name OUTLOOK -Force
        Start-Sleep -Seconds 3
    }
}

if (Get-Process -Name OUTLOOK) {
    Write-Host 'FEHLER: Outlook laeuft noch. Bitte manuell beenden und Skript erneut starten.'
    Read-Host 'Enter zum Schliessen'
    exit 1
}

Write-Host '==> Leere Add-in-Cache (Wef) ...'
if (Test-Path $wef) {
    Remove-Item -Path (Join-Path $wef '*') -Recurse -Force
}

Write-Host '==> Starte Outlook neu ...'
Start-Process outlook.exe

Write-Host ''
Write-Host 'Fertig. Outlook laedt das Signatur-Add-in jetzt frisch vom Server.'
Write-Host 'Bitte ~1 Minute warten, dann eine neue E-Mail als Test erstellen.'
Read-Host 'Enter zum Schliessen'
