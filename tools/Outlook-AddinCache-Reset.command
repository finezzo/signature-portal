#!/bin/bash
#
# SignaturePortal — Outlook-Add-in-Cache zurücksetzen (macOS)
#
# Beendet Outlook, leert die Add-in-Caches (Wef + WebKit) und startet Outlook
# neu. Danach lädt Outlook Manifest und Add-in-Dateien frisch vom Server.
#
# Verwendung: Doppelklick im Finder (öffnet ein Terminal-Fenster).
# Beim ersten Start ggf. Rechtsklick → Öffnen (Gatekeeper).

set -u

CONTAINER="$HOME/Library/Containers/com.microsoft.Outlook/Data"
WEF="$CONTAINER/Library/Application Support/Microsoft/Office/16.0/Wef"
WEBKIT="$CONTAINER/Library/Caches/WebKit"

echo "==> Beende Microsoft Outlook …"
osascript -e 'quit app "Microsoft Outlook"' >/dev/null 2>&1

# Auf sauberes Beenden warten (max. 20 s). Ein offener "Entwurf sichern?"-
# Dialog kann das Beenden blockieren — dann bitte den Dialog beantworten.
for _ in $(seq 1 20); do
    pgrep -x "Microsoft Outlook" >/dev/null || break
    sleep 1
done

if pgrep -x "Microsoft Outlook" >/dev/null; then
    echo "FEHLER: Outlook laeuft noch (offener Dialog?)."
    echo "Bitte Outlook manuell beenden (Cmd+Q) und dieses Skript erneut starten."
    read -r -p "Enter zum Schliessen …"
    exit 1
fi

echo "==> Leere Add-in-Caches …"
rm -rf "$WEF"/* "$WEBKIT" 2>/dev/null
# Hinweis: ~/…/Data/Documents/wef (manuell gesideloadete Manifeste) wird
# absichtlich NICHT geloescht, damit Test-Sideloads erhalten bleiben.

echo "==> Starte Outlook neu …"
open -a "Microsoft Outlook"

echo ""
echo "Fertig. Outlook laedt das Signatur-Add-in jetzt frisch vom Server."
echo "Bitte ~1 Minute warten, dann eine neue E-Mail als Test erstellen."
read -r -p "Enter zum Schliessen …"
