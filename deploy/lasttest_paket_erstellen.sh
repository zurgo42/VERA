#!/bin/bash
#
# lasttest_paket_erstellen.sh - Stellt NUR die Dateien zusammen, die
# abstimmen.php zum Laufen braucht (vollstaendige require_once-Kette,
# keine einzige Admin- oder Mitgliederseite dabei), damit der Lasttest
# gegen eine Kopie in einem NICHT per SSO geschuetzten Verzeichnis laufen
# kann, ohne die SSO-Pflicht auf dem echten Produktivpfad aufzuheben.
#
# HINTERGRUND: abstimmen.php selbst braucht kein SSO (Berechtigung laeuft
# nur ueber das Stimmkarten-Token) - die SSO-Weiterleitung, die der
# Lasttest gegen den echten Pfad ausgeloest hat, kommt von der
# Infrastruktur-Ebene davor, die fuer ANDERE Teile der Anwendung (Mitglieds-
# nummer-Uebermittlung) noetig ist und nicht aufgehoben werden darf.
#
# NUTZUNG:
#   ./lasttest_paket_erstellen.sh
#   -> erzeugt lasttest-paket/ mit allen noetigen Dateien AUSSER config.php
#      (siehe Warnung unten - die muss von Hand dazu).
#
# DANACH:
#   1. eigene config.php (die echte, produktive - liegt bereits auf dem
#      Server bzw. lokal unter neu/config.php) VON HAND in lasttest-paket/
#      hineinkopieren.
#   2. Inhalt von lasttest-paket/ in ein NEUES Verzeichnis hochladen, das
#      NICHT vom SSO erfasst wird (mit der Serveradministration klären,
#      welcher Pfad/welche Subdomain dafuer infrage kommt - z.B. eine
#      eigene Subdomain, die nicht unter der SSO-Regel liegt).
#   3. Idealerweise zusaetzlich mit Basic-Auth oder IP-Beschraenkung
#      schuetzen (Verzeichnisschutz beim Hoster/.htaccess), solange der
#      Test laeuft - auch wenn dort nur die Test-Runde mit Platzhaltertext
#      liegt, nicht echte Mitglieder- oder Abstimmungsdaten.
#   4. Lasttest gegen diese neue URL laufen lassen (lasttest.py --url
#      https://.../abstimmen.php).
#   5. Nach dem Test das GESAMTE Verzeichnis auf dem Server wieder
#      LOESCHEN (nicht nur die Testrunde in der Datenbank).
#
# WICHTIG: Diese Kopie greift auf dieselbe Produktiv-Datenbank zu wie die
# echte Installation (gleiche config.php) - das ist gewollt, damit der
# Lasttest die echte Datenbank-Last misst. Sie testet NICHT die SSO-Stufe
# selbst (deren Kapazitaet bei vielen gleichzeitigen Anmeldungen ist eine
# eigene, hier nicht geprueft Frage).

set -euo pipefail

SKRIPT_VERZEICHNIS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NEU_VERZEICHNIS="$SKRIPT_VERZEICHNIS/.."
ZIEL="$SKRIPT_VERZEICHNIS/lasttest-paket"

# Vollstaendige require_once-Kette von abstimmen.php (gepruefte Abhaengigkeiten,
# keine Admin-/Mitgliederseiten) - config.php bewusst NICHT hier, siehe oben.
DATEIEN=(
    abstimmen.php
    functions_neu.php
    db_functions.php
    mitgliederquelle_functions.php
    protokoll_functions.php
    abstimmung_functions.php
    wahl_functions.php
    beamer_functions.php
    antraege_functions.php
    jahr_functions.php
    tagesordnung_functions.php
    tagesordnung_standard.php
    session_config.php
)

rm -rf "$ZIEL"
mkdir -p "$ZIEL"

for datei in "${DATEIEN[@]}"; do
    cp "$NEU_VERZEICHNIS/$datei" "$ZIEL/"
done

echo "Fertig: $ZIEL enthaelt ${#DATEIEN[@]} Dateien."
echo ""
echo "FEHLT NOCH VON HAND: config.php (nicht im Repo, enthaelt echte Zugangsdaten)"
echo "-> die bestehende, produktive config.php zusaetzlich nach $ZIEL/ kopieren."
echo ""
echo "Danach lasttest-paket/ komplett in das NEUE, nicht SSO-geschuetzte"
echo "Verzeichnis auf dem Server hochladen."
