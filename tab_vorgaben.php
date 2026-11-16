<?php
/**
 * tab_vorgaben.php - Vereinsspezifische Vorgaben (Tabelle mvconfig).
 */
$config = get_config($pdo);
?>
<h3>Vereinsspezifische Vorgaben</h3>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="bereich" value="vorgaben">

    <h3 style="margin-top:0;">Verein</h3>
    <div class="form-group" style="margin-bottom:14px;">
        <label>Vereinsname</label>
        <input type="text" name="vereinsname" value="<?= h($config['vereinsname']) ?>" style="max-width:420px;" <?= defined('ORG_NAME') && ORG_NAME !== '' ? 'disabled' : '' ?>>
        <?php if (defined('ORG_NAME') && ORG_NAME !== ''): ?>
            <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">Fest durch die Konstante <code>ORG_NAME</code> in <code>config.php</code> vorgegeben - hier nicht änderbar.</p>
        <?php endif; ?>
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label>Bezeichnung der Versammlung</label>
        <input type="text" name="mv_bezeichnung" value="<?= h($config['mv_bezeichnung']) ?>" style="max-width:420px;">
        <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">
            Erscheint z. B. als „<?= h($config['mv_bezeichnung']) ?> 2027“ im Kopfbereich - manche Vereine nennen ihre Versammlung anders (z. B. „Hauptversammlung“).
        </p>
    </div>

    <h3>Anträge &amp; Unterstützung</h3>
    <div class="form-group" style="margin-bottom:14px;">
        <label>Maximale Zahl Hauptanträge je Mitglied</label>
        <input type="number" min="0" name="max_hauptantraege_pro_mitglied"
               value="<?= h($config['max_hauptantraege_pro_mitglied']) ?>" style="max-width:120px;">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Unterstützungs-Schwellenwert (Mindestzahl Unterstützer für die Tagesordnung)</label>
        <input type="number" min="0" name="unterstuetzung_schwellenwert"
               value="<?= h($config['unterstuetzung_schwellenwert']) ?>" style="max-width:120px;">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Frist dafür, in Wochen nach Antragsschluss</label>
        <input type="number" min="0" name="unterstuetzung_frist_wochen"
               value="<?= h($config['unterstuetzung_frist_wochen']) ?>" style="max-width:120px;">
        <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">
            Dieselbe Frist steuert auch, bis wann ein Mitglied Stimmungsbild-Ergebnisse erst nach der eigenen Abstimmung sieht (siehe unten).
        </p>
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Stimmungsbild: Mindestzahl an Teilnehmenden, ab der Ergebnisse (Wichtigkeit/Zustimmung) für normale Mitglieder überhaupt angezeigt werden</label>
        <input type="number" min="0" name="stimmungsbild_mindestteilnehmer"
               value="<?= h($config['stimmungsbild_mindestteilnehmer']) ?>" style="max-width:120px;">
        <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">
            Gilt unabhängig davon, ob das Mitglied selbst schon abgestimmt hat (siehe unten) - erst wenn beide Bedingungen erfüllt sind, werden Ergebnisse gezeigt. Die Redaktion sieht Ergebnisse immer sofort.
        </p>
    </div>

    <div class="form-group" style="margin-bottom:18px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="rueckzug_nur_bis_antragsschluss"
                   <?= $config['rueckzug_nur_bis_antragsschluss'] === '1' ? 'checked' : '' ?>>
            Antragsrückzug nur bis zum Antragsschluss erlauben (statt jederzeit bis zur eigenen Abstimmung)
        </label>
    </div>

    <h3 style="margin-top:24px;">Erscheinungsbild</h3>
    <div class="form-group" style="margin-bottom:14px;">
        <label>Logo-Datei (Pfad oder URL, erscheint im Kopfbereich)</label>
        <input type="text" name="logo_pfad" value="<?= h($config['logo_pfad']) ?>" placeholder="z. B. logo.png" style="max-width:420px;" <?= defined('ORG_LOGO') && ORG_LOGO !== '' ? 'disabled' : '' ?>>
        <?php if (defined('ORG_LOGO') && ORG_LOGO !== ''): ?>
            <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">Fest durch die Konstante <code>ORG_LOGO</code> in <code>config.php</code> vorgegeben - hier nicht änderbar.</p>
        <?php endif; ?>
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label>Favicon-Datei (Pfad oder URL, Browser-Tab-Symbol)</label>
        <input type="text" name="favicon_pfad" value="<?= h($config['favicon_pfad']) ?>" placeholder="z. B. favicon.ico" style="max-width:420px;">
    </div>

    <h3>Sichtbare Navigationspunkte</h3>
    <p style="font-size:12.5px; color:var(--text-secondary); margin-top:-6px;">Manche Vereine nutzen nicht alle dieser Bereiche - hier lassen sie sich einzeln ausblenden.</p>
    <div class="form-group" style="margin-bottom:10px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="diskussionsforum_aktiv" <?= $config['diskussionsforum_aktiv'] === '1' ? 'checked' : '' ?>>
            Button „Diskussionsforum“ in der Navigation anzeigen
        </label>
    </div>
    <div class="form-group" style="margin-bottom:10px;">
        <label>Link zum Diskussionsforum</label>
        <input type="url" name="diskussionsforum_link" value="<?= h($config['diskussionsforum_link']) ?>" placeholder="https://..." style="max-width:420px;">
    </div>
    <div class="form-group" style="margin-bottom:10px;">
        <label>Rückweg zum Einstieg (MISS oder Startseite des Vereins)</label>
        <input type="text" name="portal_url" value="<?= h($config['portal_url']) ?>" placeholder="/MISS/" style="max-width:260px;">
        <input type="text" name="portal_name" value="<?= h($config['portal_name']) ?>" placeholder="MISS" style="max-width:120px;">
        <small>Adresse und Name des Knopfs in der Navigation; ohne Adresse erscheint kein Knopf.</small>
    </div>
    <div class="form-group" style="margin-bottom:10px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="beschluesse_aktiv" <?= $config['beschluesse_aktiv'] === '1' ? 'checked' : '' ?>>
            „MV-Beschluss-Sammlung“ in der Navigation anzeigen
        </label>
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="wahlinfo_aktiv" <?= $config['wahlinfo_aktiv'] === '1' ? 'checked' : '' ?>>
            „Wahl-Information“ in der Navigation anzeigen
        </label>
    </div>

    <h3>Abstimmungsmaschine</h3>
    <div class="form-group" style="margin-bottom:14px;">
        <label>Basis-URL der Abstimmungsmaschine (ohne abschließenden Slash, ohne Dateinamen - für die QR-Codes auf den Stimmkarten)</label>
        <input type="url" name="abstimmung_basis_url" value="<?= h($config['abstimmung_basis_url']) ?>" placeholder="https://verein.de/mv" style="max-width:420px;">
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="enthaltung_zaehlt_mit" <?= $config['enthaltung_zaehlt_mit'] === '1' ? 'checked' : '' ?>>
            Enthaltungen zählen bei der Mehrheitsfeststellung mit
        </label>
        <small style="color:var(--text-secondary);">Je nach Satzung unterschiedlich geregelt. Unangekreuzt (Vorgabe): nur Ja/Nein bilden die Basis der Prozentzahlen, Enthaltungen werden nur als Anzahl mit ausgewiesen.</small>
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label>WLAN für dedizierten Versammlungs-Server (optional - nur bei eigenem, nicht-öffentlichem Netz ohne Internet)</label>
        <small style="color:var(--text-secondary); display:block; margin-bottom:6px;">Leer lassen für den normalen, über das Internet erreichbaren Betrieb. Ausgefüllt: Stimmkarte und Beamerbild zeigen zusätzlich einen WLAN-QR-Code, den aktuelle Smartphones beim Scannen direkt zum WLAN-Beitritt anbieten (kein Internet im Saal nötig). Nur WPA/WPA2 unterstützt.</small>
        <input type="text" name="wlan_ssid" value="<?= h($config['wlan_ssid']) ?>" placeholder="Netzwerkname (SSID)" style="max-width:300px;">
        <input type="text" name="wlan_passwort" value="<?= h($config['wlan_passwort']) ?>" placeholder="WLAN-Passwort" style="max-width:300px; margin-left:8px;">
    </div>

    <h3>Einlasskontrolle &amp; Anwesenheit (hybride/Online-Versammlung)</h3>
    <div class="form-group" style="margin-bottom:14px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="mitgliedschaftspruefung_aktiv" <?= $config['mitgliedschaftspruefung_aktiv'] === '1' ? 'checked' : '' ?>>
            Mitgliedschaft am Einlass per elektronischem Mitgliedsausweis (QR-Code) prüfen
        </label>
        <small style="color:var(--text-secondary);">Unangekreuzt (Vorgabe): keine Einlasskontrolle-Seite nötig - für Vereine, in denen sich die Mitglieder ohnehin persönlich kennen. Die Anwesenheit wird dann stattdessen automatisch simuliert, siehe Zeitfenster unten.</small>
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label>Versammlungsbeginn / -ende (nur für die automatische Anwesenheits-Simulation, falls keine Einlasskontrolle genutzt wird)</label>
        <small style="color:var(--text-secondary); display:block; margin-bottom:6px;">Nur VERA-Aufrufe innerhalb dieses Zeitfensters zählen als „wirklich anwesend“ - wer die Seite schon Tage vorher aufruft (z. B. zum Ausprobieren), wird dadurch nicht fälschlich als anwesend geführt. Leer = keine automatische Anwesenheitserfassung.</small>
        <input type="datetime-local" name="versammlung_beginn" value="<?= h($config['versammlung_beginn']) ?>" style="max-width:220px;">
        <input type="datetime-local" name="versammlung_ende" value="<?= h($config['versammlung_ende']) ?>" style="max-width:220px; margin-left:8px;">
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label>Foto-Quelle für den elektronischen Mitgliedsausweis (optional)</label>
        <small style="color:var(--text-secondary); display:block; margin-bottom:6px;">URL-Muster mit Platzhalter <code>{mnr}</code> für die Mitgliedsnummer, z. B. <code>https://miss.verein.de/foto.php?mnr={mnr}</code> - das Foto selbst verwaltet ausschließlich MISS, VERA lädt es nur direkt von dort. Leer = kein Foto auf dem Ausweis.</small>
        <input type="text" name="foto_quelle_url" value="<?= h($config['foto_quelle_url']) ?>" placeholder="https://miss.verein.de/foto.php?mnr={mnr}" style="max-width:420px;">
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="hybride_versammlung_aktiv" <?= $config['hybride_versammlung_aktiv'] === '1' ? 'checked' : '' ?>>
            Teilnahmeform-Auswahl (vor Ort/online) für Mitglieder anbieten
        </label>
        <small style="color:var(--text-secondary);">Nur für hybride oder reine Online-Versammlungen nötig. Angekreuzt: Mitglieder sehen auf der Startseite einen Button, um sich vor der Versammlung auf „vor Ort“ oder „online“ festzulegen - Grundlage dafür, dass niemand sowohl eine Stimmkarte vor Ort als auch online teilnehmen kann. Unangekreuzt (Vorgabe): kein solcher Button, für reine Präsenzversammlungen irrelevant.</small>
    </div>

    <h3>Abmelden</h3>
    <div class="form-group" style="margin-bottom:18px;">
        <label>SSO-Logout-Link (optional - wird der Klick auf „Abmelden“ dorthin geleitet; leer = nur lokale Sitzung beenden)</label>
        <input type="url" name="sso_logout_url" value="<?= h($config['sso_logout_url']) ?>" placeholder="https://..." style="max-width:420px;">
    </div>

    <h3>Mitgliederquelle &amp; Anmeldung</h3>
    <p style="font-size:12.5px; color:var(--text-secondary); margin-top:-6px;">
        Woher Vor-/Nachname und Mailadresse neu anzulegender Mitglieder kommen, und wie sich Mitglieder ohne vorgelagertes SSO anmelden können.
    </p>
    <div class="form-group" style="margin-bottom:14px;">
        <label>Mitgliederquelle</label>
        <select name="mitglieder_quelle" style="max-width:420px; padding:7px 10px;" <?= $config['demo_modus_aktiv'] === '1' ? 'disabled' : '' ?>>
            <option value="ldap" <?= $config['mitglieder_quelle'] === 'ldap' ? 'selected' : '' ?>>LDAP (Mensa-Bestandssystem)</option>
            <option value="tabelle" <?= $config['mitglieder_quelle'] === 'tabelle' ? 'selected' : '' ?>>Lokale Mitgliederstamm-Tabelle</option>
            <option value="svmembers" <?= $config['mitglieder_quelle'] === 'svmembers' ? 'selected' : '' ?>>Tabelle „svmembers“ (gemeinsame Datenbank mit Sitzungs-/Mitgliederverwaltung)</option>
        </select>
        <?php if ($config['demo_modus_aktiv'] === '1'): ?>
            <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">Bei aktivem Demo-Modus fest auf „svmembers“ gesetzt - hier nicht änderbar.</p>
        <?php endif; ?>
        <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">
            Bei „Lokale Mitgliederstamm-Tabelle“ erscheint unter Admin-Funktionen ein zusätzlicher Bereich zum Pflegen bzw. Importieren der Mitgliederdaten.
            Bei „svmembers“ wird ausschließlich gelesen - dort muss die Tabelle bereits vorhanden sein (z. B. durch die Sitzungsverwaltung „KONA“ oder die Mitgliederverwaltung „MISS“ in derselben Datenbank).
        </p>
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="login_modul_aktiv" <?= $config['login_modul_aktiv'] === '1' ? 'checked' : '' ?>>
            Eigenes Anmeldeformular (Magic-Link per Mail) zusätzlich zum SSO anbieten
        </label>
        <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">
            Für Vereine ohne eigenes SSO: Mitglieder ohne REMOTE_USER können sich dann über <code>login.php</code> per Mitgliedsnummer anmelden und bekommen einen 15 Minuten gültigen Anmeldelink per Mail.
        </p>
    </div>
    <div class="form-group" style="margin-bottom:18px;">
        <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="demo_modus_aktiv" <?= $config['demo_modus_aktiv'] === '1' ? 'checked' : '' ?> <?= defined('DEMO_MODE') ? 'disabled' : '' ?>>
            Demo-Modus: Direkt-Login-Auswahl auf <code>login.php</code> anbieten (ohne Mail-Versand)
        </label>
        <?php if (defined('DEMO_MODE')): ?>
            <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">Fest durch die Konstante <code>DEMO_MODE</code> in <code>config.php</code> vorgegeben - hier nicht änderbar.</p>
        <?php endif; ?>
        <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">
            ⚠️ Nur für Test-/Demo-Installationen! Zeigt auf <code>login.php</code> bis zu 12 zufällig ausgewählte Mitglieder der aktuellen Mitgliederquelle als anklickbare Karten - ein Klick meldet sofort an, ganz ohne Passwort, Mail-Bestätigung oder Anmeldelink. Niemals auf einer Installation mit echten Mitgliederdaten aktivieren. Wie beim normalen Login entsteht dabei nur für das tatsächlich angemeldete Mitglied ein Eintrag in <code>mvmitglieder_<?= h((string)get_aktuelles_jahr($pdo)) ?></code> - keine Massenübernahme.
        </p>
    </div>

    <button type="submit" class="btn">Speichern</button>
</form>

<?php if ($config['demo_modus_aktiv'] === '1'): ?>
    <h3 style="margin-top:28px;">Demo-Daten</h3>
    <p style="font-size:12.5px; color:var(--text-secondary); margin-top:-6px;">
        Erzeugt für das aktuelle MV-Jahr (<?= h((string)get_aktuelles_jahr($pdo)) ?>) einen kompletten, realistischen Demo-Satz: Tagesordnung, mehrere Hauptanträge mit Änderungsanträgen, Unterstützungen und Stimmungsbild-Werten - analog zu den Demo-Daten der Schwesterprojekte. <strong>Löscht dabei alle bereits vorhandenen Anträge und die Tagesordnung dieses Jahres unwiderruflich</strong> und ersetzt sie durch den Demo-Satz.
    </p>
    <form method="post" onsubmit="return confirm('Bestehende Anträge und Tagesordnung des Jahres ' + <?= (int)get_aktuelles_jahr($pdo) ?> + ' werden dabei gelöscht und durch Demo-Daten ersetzt. Fortfahren?');">
        <?= csrf_field() ?>
        <input type="hidden" name="bereich" value="demo_daten">
        <?php if (!system_admin_authentifiziert()): ?>
            <input type="password" name="admin_passwort" placeholder="Admin-Passwort" required style="padding:6px 8px; margin-right:8px;">
        <?php endif; ?>
        <button type="submit" class="btn btn-secondary">Demo-Daten einspielen (überschreibt vorhandene Anträge)</button>
    </form>
<?php endif; ?>
