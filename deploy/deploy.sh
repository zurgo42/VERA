#!/bin/bash
#
# deploy.sh - Synchronisiert den Code aus neu/ per rsync/SSH auf den
# dedizierten Versammlungs-Server (isoliertes internes WLAN, kein Git-
# Zugriff dort noetig - Uebertragung erfolgt von diesem Rechner aus).
#
# VORAUSSETZUNG: rsync + ssh auf DIESEM Rechner (unter Windows z. B. via
# WSL - "wsl --install", danach "sudo apt install rsync" einmalig), sowie
# SSH-Zugang zum Zielserver (am besten per Schluessel statt Passwort, siehe
# "ssh-copy-id"). Ohne WSL/rsync alternativ ein Synchronisations-Skript in
# WinSCP (winscp.com /script=deploy.txt) mit demselben Prinzip - dieselben
# Ausschluesse (unten) dort als "-filemask" nachbilden.
#
# NUTZUNG:
#   Variablen unten EINMALIG anpassen, dann:
#   ./deploy.sh            - echte Uebertragung
#   ./deploy.sh --probe     - nur anzeigen, was uebertragen wuerde (nichts aendern)
#
# WICHTIG: Noch nicht gegen den echten Zielserver getestet (der steht laut
# Planung noch nicht fest) - vor dem ersten echten Einsatz unbedingt erst
# mit --probe gegenpruefen, ob die Liste der zu uebertragenden/zu
# loeschenden Dateien plausibel aussieht.

set -euo pipefail

# ---- Anpassen ----
ZIEL_HOST="user@zielserver"          # SSH-Benutzer@Server des dedizierten Versammlungs-Servers
ZIEL_PFAD="/pfad/zum/webroot"        # Kuenftiges Wurzelverzeichnis der Domain dort
# ------------------

SKRIPT_VERZEICHNIS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
QUELL_PFAD="$SKRIPT_VERZEICHNIS/../"

RSYNC_OPTIONEN=(-avz --delete
    --exclude 'config.php'      # serverspezifisch, nicht im Git-Repo - NIE ueberschreiben
    --exclude '.git'
    --exclude 'storage/'        # erzeugte Dateien (z.B. Stimmkarten-PDFs) - keine Code-Daten
    --exclude 'uploads/'        # von Mitgliedern hochgeladene Dateien - keine Code-Daten
    --exclude 'wartung.aktiv'   # Wartungsmodus-Status bleibt unabhaengig vom Deployment erhalten
)

if [[ "${1:-}" == "--probe" ]]; then
    RSYNC_OPTIONEN+=(--dry-run)
    echo "=== PROBELAUF - es wird NICHTS uebertragen oder geloescht ==="
fi

echo "Synchronisiere $QUELL_PFAD nach $ZIEL_HOST:$ZIEL_PFAD ..."
rsync "${RSYNC_OPTIONEN[@]}" "$QUELL_PFAD" "$ZIEL_HOST:$ZIEL_PFAD"

if [[ "${1:-}" != "--probe" ]]; then
    echo ""
    echo "Fertig. WICHTIG - jetzt noch von Hand:"
    echo "1. Schema-Abgleich aufrufen: https://<ziel-domain>/tools/db_schema_sync.php"
    echo "   (legt neue Tabellen/Spalten an, falls DB_SELBSTHEILUNG_AKTIV=false gesetzt ist)"
    echo "2. Danach tools/db_schema_sync.php wieder loeschen oder schuetzen."
fi
