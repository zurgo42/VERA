<?php
/**
 * tab_backup.php - Admin-Funktion: jederzeit auf Knopfdruck ein
 * vollständiges SQL-Backup aller VERA/BEAM-Tabellen herunterladen (siehe
 * backup_functions.php/datenbank_backup.php).
 */
?>
<h3>Datensicherung</h3>
<p style="color:var(--text-secondary); font-size:13.5px;">
    Erzeugt und lädt sofort ein vollständiges SQL-Backup aller VERA/BEAM-Tabellen herunter
    (Mitglieder, Anträge, Protokolle, Abstimmungsergebnisse, Vereinsvorgaben, BEAM-Projekte - nicht
    die Tabellen anderer Anwendungen wie der Mitgliederverwaltung). Die Datei lässt sich bei Bedarf
    komplett wiedereinspielen (z. B. über phpMyAdmin → Importieren). Sinnvoll als zusätzliche,
    schnelle Absicherung während einer laufenden Versammlung - zusätzlich zur regelmäßigen
    Sicherung durch den Hoster/Admin, nicht als Ersatz dafür.
</p>
<p>
    <a href="datenbank_backup.php" class="btn">💾 Backup jetzt erzeugen und herunterladen</a>
</p>
