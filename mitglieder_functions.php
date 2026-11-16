<?php
/**
 * mitglieder_functions.php - Registrierung neuer Mitglieder in
 * mvmitglieder_<jahr>: beim allerersten eigenen Zugriff eines Jahres,
 * oder wenn die Redaktion einen Antrag im Namen eines noch nicht
 * erfassten Mitglieds einstellt (siehe process_antraege.php: "fuer").
 *
 * Das Bestandssystem (mv.php/survivalkit.php) legt beim ersten Zugriff
 * einer/eines per SSO authentifizierten Person automatisch einen
 * mvmitglieder_<jahr>-Datensatz an, mit Vor-/Nachname/Mail aus einer
 * Direktabfrage des Mensa-LDAP (siehe fstandard.php: ldapident()) und
 * einem frischen Schluessel (siehe jahr_functions.php: erzeugekey_neu(),
 * identisch zur alten erzeugekey()). ldapident_neu() unten ist eine
 * eigenstaendige, schlanke Kopie derselben LDAP-Abfrage - fstandard.php
 * selbst wird bewusst NICHT eingebunden, da es beim Einbinden bereits
 * jede Menge Altsystem-Code auf oberster Ebene ausfuehrt.
 *
 * Fuer Vereine ohne eigenes LDAP ist die Quelle ueber die Vereinsvorgabe
 * mitglieder_quelle umschaltbar (siehe mitgliederquelle_functions.php) -
 * Default bleibt 'ldap', damit bestehende Installationen (Mensa) sich
 * nicht aendern.
 */

require_once __DIR__ . '/functions_neu.php'; // fuer get_aktuelle_mnr()/get_aktuelles_mitglied()
require_once __DIR__ . '/jahr_functions.php'; // fuer erzeugekey_neu()
require_once __DIR__ . '/protokoll_functions.php'; // fuer mv_log()/sql_insert()
require_once __DIR__ . '/mitgliederquelle_functions.php'; // fuer mitgliederquelle_abfragen() (Vereinsvorgabe mitglieder_quelle)

/**
 * Fragt Vorname/Name/Mail zu einer Mitgliedsnummer beim Mensa-LDAP ab -
 * exakt dieselben Parameter wie ldapident() im Altsystem. Liefert leere
 * Werte zurueck (statt eines Fehlers), wenn die LDAP-Erweiterung fehlt,
 * der Server nicht erreichbar ist oder die MNr dort nicht gefunden wird -
 * damit ein LDAP-Ausfall die Registrierung nicht hart zum Absturz bringt.
 */
function ldapident_neu(string $mnr): array {
    $leer = ['vorname' => '', 'name' => '', 'mail' => ''];
    if (!function_exists('ldap_connect') || $mnr === '') {
        return $leer;
    }

    $ldapconn = @ldap_connect('localhost');
    if (!$ldapconn) {
        return $leer;
    }
    @ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);

    $ldaptree = 'cn=aktive,ou=applications,dc=mensa,dc=de';
    $ldappass = defined('LDAP_BIND_PASS') ? LDAP_BIND_PASS : '';
    if (!@ldap_bind($ldapconn, $ldaptree, $ldappass)) {
        return $leer;
    }

    $dn = 'ou=members,dc=mensa,dc=de';
    $filter = '(uid=' . ldap_escape($mnr, '', LDAP_ESCAPE_FILTER) . ')';
    $attribute = ['givenName', 'sn', 'mail'];

    $sr = @ldap_search($ldapconn, $dn, $filter, $attribute);
    if (!$sr || !@ldap_count_entries($ldapconn, $sr)) {
        @ldap_close($ldapconn);
        return $leer;
    }

    $eintraege = ldap_get_entries($ldapconn, $sr);
    @ldap_close($ldapconn);

    return [
        'vorname' => $eintraege[0]['givenname'][0] ?? '',
        'name'    => $eintraege[0]['sn'][0] ?? '',
        'mail'    => $eintraege[0]['mail'][0] ?? '',
    ];
}

/**
 * Stellt die Tabelle mvmitglieder_<jahr> sicher (CREATE TABLE IF NOT
 * EXISTS) - bei Mensa (Bestandssystem) immer ein No-Op, da dort laengst
 * vorhanden; bei einer komplett neuen Installation ohne Bestandssystem
 * entsteht sie hier zum allerersten Mal, mit denselben Spalten wie im
 * Bestandssystem.
 */
function mvmitglieder_tabelle_sicherstellen(PDO $pdo, int $jahr): void {
    static $geprueft = [];
    if (isset($geprueft[$jahr]) || !selbstheilung_aktiv()) {
        return;
    }
    $tabelle = 'mvmitglieder_' . $jahr;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `$tabelle` (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            MNr VARCHAR(12), Vorname VARCHAR(100), Name VARCHAR(100), Mail VARCHAR(150),
            Red INT DEFAULT 0, Vorstand INT DEFAULT 0, Schluessel VARCHAR(32), Datum DATETIME,
            INDEX idx_mnr (MNr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Hybride/Online-Versammlung + Einlasskontrolle (siehe
    // Admin-Installationsanleitung.md, Abschnitt "Einlasskontrolle &
    // hybride Versammlung"):
    // - Teilnahmeform: 'vor_ort'|'online'|NULL (noch nicht gewaehlt) -
    //   verhindert, dass jemand online UND vor Ort eine Stimmkarte bekommt.
    // - StimmkarteAusgegeben: wurde (physisch am Einlass oder online per
    //   Echtzeit-Token) schon eine Stimmberechtigung ausgegeben? Nur ein
    //   Ja/Nein-Haken - welches Token das war, wird nie gespeichert (siehe
    //   Anonymitaets-Hinweis in der Doku).
    // - AnwesendSeit/AnwesendArt: Zeitpunkt und Herkunft der ersten
    //   erkannten Anwesenheit, fuer die Anwesenheitsliste im Protokoll.
    //   AnwesendArt: 'einlasskontrolle'|'simuliert'.
    $spalten = [
        'Teilnahmeform'         => "VARCHAR(10) NULL",
        'StimmkarteAusgegeben'  => 'TINYINT(1) DEFAULT 0',
        'AnwesendSeit'          => 'DATETIME NULL',
        'AnwesendArt'           => 'VARCHAR(20) NULL',
    ];
    foreach ($spalten as $spalte => $typ) {
        if (spalte_existiert($pdo, $tabelle, $spalte)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE `$tabelle` ADD COLUMN `$spalte` $typ");
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    $geprueft[$jahr] = true;
}

/**
 * Sucht ein Mitglied per Mitgliedsnummer in mvmitglieder_<jahr> und legt
 * es bei Bedarf frisch an (per LDAP-Abfrage, siehe oben). $ausloeser ist
 * die Person, die die Anlage veranlasst hat (fuer das Protokoll) - bei
 * der Selbstregistrierung das neue Mitglied selbst (null), bei einer
 * Anlage durch die Redaktion (Antrag im Namen eines Mitglieds, siehe
 * process_antraege.php) das jeweilige Redaktionsmitglied. Gibt
 * [$mitglied, $neu_angelegt] zurueck.
 */
function mitglied_finden_oder_anlegen(PDO $pdo, int $jahr, string $mnr, ?array $ausloeser = null): array {
    mvmitglieder_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` WHERE MNr = ? LIMIT 1");
    $stmt->execute([$mnr]);
    $mitglied = $stmt->fetch();
    if ($mitglied !== false) {
        return [$mitglied, false];
    }

    $daten = mitgliederquelle_abfragen($pdo, $mnr, get_config($pdo));
    $insert = $pdo->prepare(
        "INSERT INTO `$tabelle` (MNr, Vorname, Name, Mail, Red, Vorstand, Schluessel, Datum)
         VALUES (?, ?, ?, ?, 0, 0, ?, ?)"
    );
    $insert->execute([$mnr, $daten['vorname'], $daten['name'], $daten['mail'], erzeugekey_neu(), date('Y-m-d H:i:s')]);

    $protokoll_akteur = $ausloeser ?? ['MNr' => $mnr, 'Vorname' => $daten['vorname'], 'Name' => $daten['name']];
    $was = $ausloeser ? 'Neuanlage in Mitgliederliste (durch Redaktion, im Namen des Mitglieds)' : 'Neuanlage in Mitgliederliste (Erstzugriff)';
    mv_log($pdo, $jahr, $protokoll_akteur, $was, sql_insert($tabelle, [
        'MNr' => $mnr, 'Vorname' => $daten['vorname'], 'Name' => $daten['name'], 'Mail' => $daten['mail'],
    ]));

    $stmt->execute([$mnr]);
    return [$stmt->fetch(), true];
}

/**
 * Wie mitglied_finden_oder_anlegen(), fragt aber zusaetzlich erneut die
 * Mitgliederquelle ab und traegt Vorname/Name/Mail nach, falls ein bereits
 * vorhandener Datensatz dort (noch) leer ist - z. B. weil eine fruehere
 * Abfrage (LDAP-Ausfall o. Ae.) kein Ergebnis lieferte. Gedacht fuer das
 * nachtraegliche Korrigieren einer falsch eingegebenen Mitgliedsnummer
 * (siehe process_antraege.php: "ast_mnr_korrektur") - normale Neuanlagen
 * laufen weiterhin ueber mitglied_finden_oder_anlegen(), das reicht dort.
 */
function mitglied_laden_und_namen_nachtragen(PDO $pdo, int $jahr, string $mnr, ?array $ausloeser = null): array {
    [$mitglied, $neu_angelegt] = mitglied_finden_oder_anlegen($pdo, $jahr, $mnr, $ausloeser);
    if ($neu_angelegt || trim(($mitglied['Vorname'] ?? '') . ($mitglied['Name'] ?? '')) !== '') {
        return $mitglied;
    }
    $daten = mitgliederquelle_abfragen($pdo, $mnr, get_config($pdo));
    if (trim($daten['vorname'] . $daten['name']) === '') {
        return $mitglied; // weiterhin kein Treffer in der Mitgliederquelle
    }
    $tabelle = 'mvmitglieder_' . $jahr;
    $pdo->prepare("UPDATE `$tabelle` SET Vorname = ?, Name = ?, Mail = ? WHERE MNr = ?")
        ->execute([$daten['vorname'], $daten['name'], $daten['mail'], $mnr]);
    mv_log($pdo, $jahr, $ausloeser ?? $mitglied, 'Mitgliedsname nachträglich ermittelt', sql_update($tabelle, $daten, ['MNr' => $mnr]));
    return array_merge($mitglied, ['Vorname' => $daten['vorname'], 'Name' => $daten['name'], 'Mail' => $daten['mail']]);
}

/**
 * Loescht den mvmitglieder_<jahr>-Datensatz einer Mitgliedsnummer, aber NUR
 * wenn er tatsaechlich verwaist ist - gedacht fuer die Mitgliedsnummer-
 * Korrektur (process_antraege.php: "ast_mnr_korrektur"), damit eine
 * versehentlich falsch eingegebene Nummer nicht als leerer Karteileichen-
 * Eintrag in der Mitgliederliste stehen bleibt.
 *
 * Verwaist heisst: kein Name hinterlegt (also nie ueber die Mitgliederquelle
 * bestaetigt), keine Redaktions-/Vorstandsrolle, und nirgends sonst in
 * diesem Jahr referenziert (kein weiterer Antrag mit dieser MNr als
 * Antragsteller:in, keine Unterstuetzung/Mitantragstellerschaft, kein
 * Stimmungsbild-Eintrag). Ist auch nur EINE dieser Bedingungen verletzt,
 * wird NICHT geloescht - der Aufrufer muss davon ausgehen koennen, dass es
 * sich dann moeglicherweise um ein echtes, nur zufaellig getroffenes
 * Mitglied handelt. Gibt true zurueck, wenn tatsaechlich geloescht wurde.
 */
function mvmitglieder_eintrag_loeschen_falls_verwaist(PDO $pdo, int $jahr, string $mnr): bool {
    $tabelle = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` WHERE MNr = ?");
    $stmt->execute([$mnr]);
    $eintrag = $stmt->fetch();
    if (!$eintrag) {
        return false;
    }
    if (trim(($eintrag['Vorname'] ?? '') . ($eintrag['Name'] ?? '')) !== '') {
        return false;
    }
    if ((int)($eintrag['Red'] ?? 0) !== 0 || !empty($eintrag['Vorstand'])) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM `mvantraege_' . $jahr . '` WHERE Ast = ?');
    $stmt->execute([$mnr]);
    if ((int)$stmt->fetch()['n'] > 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM `mvunterst_' . $jahr . '` WHERE MNr = ?');
        $stmt->execute([$mnr]);
        if ((int)$stmt->fetch()['n'] > 0) {
            return false;
        }
    } catch (PDOException $e) {
        // Tabelle existiert (noch) nicht - dann auch keine Referenz moeglich
    }

    if (!empty($eintrag['Schluessel'])) {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM `mvvotum_' . $jahr . '` WHERE Schluessel = ?');
            $stmt->execute([$eintrag['Schluessel']]);
            if ((int)$stmt->fetch()['n'] > 0) {
                return false;
            }
        } catch (PDOException $e) {
            // Tabelle existiert (noch) nicht
        }
    }

    $pdo->prepare("DELETE FROM `$tabelle` WHERE MNr = ?")->execute([$mnr]);
    return true;
}

/**
 * Laedt den Mitgliedsdatensatz der/des aktuell Angemeldeten und legt ihn
 * bei Bedarf frisch an (erster Zugriff in diesem Jahr) - genau wie
 * mv.php: "es ist noch kein Datensatz angelegt". Gibt
 * [$mitglied, $neu_angelegt] zurueck; $mitglied ist null, wenn niemand
 * angemeldet ist (kein REMOTE_USER).
 */
function mitglied_laden_oder_registrieren(PDO $pdo, int $jahr): array {
    $mnr = get_aktuelle_mnr($pdo);
    if ($mnr === '') {
        return [null, false];
    }
    return mitglied_finden_oder_anlegen($pdo, $jahr, $mnr);
}

/**
 * Reiner Lese-Check, ob die/der aktuell Angemeldete im NEUESTEN Jahr
 * (get_aktuelles_jahr(), unabhaengig davon, ob es schon freigegeben ist)
 * Redaktionsrechte (Red=9) hat - fuer get_sichtbares_jahr() in index.php,
 * die VOR der eigentlichen Jahresauswahl/Mitgliedsermittlung entscheiden
 * muss, ob ein noch nicht freigegebenes neues Jahr trotzdem gezeigt werden
 * darf. Bewusst OHNE Seiteneffekt: anders als
 * mitglied_laden_oder_registrieren()/mitglied_finden_oder_anlegen() legt
 * dieser Check niemanden neu an und schreibt keinen Protokolleintrag - das
 * waere bei jedem Seitenaufruf unbeteiligter Gaeste/Mitglieder unerwuenscht,
 * noch bevor ueberhaupt feststeht, welches Jahr sie zu sehen bekommen.
 */
function ist_redaktion_im_neuesten_jahr(PDO $pdo): bool {
    $mnr = get_aktuelle_mnr($pdo);
    if ($mnr === '') {
        return false;
    }
    $jahr = get_aktuelles_jahr($pdo);
    mvmitglieder_tabelle_sicherstellen($pdo, $jahr);
    $stmt = $pdo->prepare('SELECT Red FROM `mvmitglieder_' . $jahr . '` WHERE MNr = ? LIMIT 1');
    $stmt->execute([$mnr]);
    $row = $stmt->fetch();
    return $row !== false && (int)$row['Red'] === 9;
}

/**
 * Ermittelt das Zielmitglied fuer eine Antrags-/Beteiligungshandlung:
 * normalerweise die/der Handelnde selbst, aber fuer Mitglieder des
 * Redaktionsteams wahlweise ein beliebiges anderes Mitglied ("im Namen
 * von", siehe Prozesskatalog) - wird bei Bedarf neu angelegt (siehe
 * mitglied_finden_oder_anlegen()). Nicht-Redaktionsmitglieder erhalten
 * immer sich selbst zurueck, unabhaengig vom uebergebenen $modus.
 *
 * @param string $modus 'eigen' oder 'fuer' (siehe Formularfeld ast_modus)
 * @param string $ziel_mnr Mitgliedsnummer bei $modus === 'fuer'
 * @return array{0: ?array, 1: ?string} [Zielmitglied, Fehlermeldung]
 */
function antrag_ziel_mitglied_ermitteln(PDO $pdo, int $jahr, array $mitglied, bool $ist_redaktion, string $modus, string $ziel_mnr): array {
    if (!$ist_redaktion || $modus !== 'fuer') {
        return [$mitglied, null];
    }
    $ziel_mnr = trim($ziel_mnr);
    if ($ziel_mnr === '') {
        return [null, 'Bitte die Mitgliedsnummer des Mitglieds angeben, in dessen Namen du handelst.'];
    }
    [$ziel, ] = mitglied_finden_oder_anlegen($pdo, $jahr, $ziel_mnr, $mitglied);
    return [$ziel, null];
}

/** Mailadressen aller aktuellen Redaktionsteam-Mitglieder (Red=9) eines Jahres, ohne leere Eintraege - fuer Benachrichtigungen. */
function get_redaktion_mailadressen(PDO $pdo, int $jahr): array {
    mvmitglieder_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare("SELECT Mail FROM `$tabelle` WHERE Red = 9 AND Mail IS NOT NULL AND Mail <> ''");
    $stmt->execute();
    return array_column($stmt->fetchAll(), 'Mail');
}

/**
 * Benachrichtigt das Redaktionsteam per Mail, dass die/der Antragsteller:in
 * eines Hauptantrags einen Änderungsantrag übernommen hat (siehe
 * antraege_functions.php: aenderungsantrag_als_uebernommen_markieren()).
 * Ohne hinterlegte Redaktions-Mailadressen (siehe get_redaktion_mailadressen())
 * unterbleibt die Mail lautlos - die Übernahme selbst bleibt trotzdem
 * wirksam, genau wie bei einem LDAP-Ausfall bei der Registrierung.
 */
function redaktion_ueber_aenderungsantrag_uebernahme_benachrichtigen(PDO $pdo, int $jahr, array $hauptantrag, array $aenderungsantrag): void {
    $adressen = get_redaktion_mailadressen($pdo, $jahr);
    if (!$adressen) {
        return;
    }
    $betreff = 'MV ' . $jahr . ': Änderungsantrag ' . $aenderungsantrag['Nr'] . ' wurde übernommen';
    $text = 'Die/der Antragsteller:in von Hauptantrag ' . $hauptantrag['Nr'] . ' ("' . $hauptantrag['Titeltext'] . '") '
        . 'hat den Änderungsantrag ' . $aenderungsantrag['Nr'] . ' übernommen.' . "\n\n"
        . 'Bitte im MV-Tool unter "Anträge zeigen/bearbeiten" -> ' . $hauptantrag['Nr']
        . ' die Übernahme manuell nachvollziehen (Abschnitt "Übernahme eines Änderungsantrags").';
    foreach ($adressen as $adresse) {
        @mail($adresse, $betreff, $text);
    }
}

/**
 * Automatische Anwesenheits-Simulation für Vereine ohne Einlasskontrolle
 * (siehe Vorgabe mitgliedschaftspruefung_aktiv, Admin-Installationsanleitung.md
 * Abschnitt 10e) - trägt beim Aufruf von index.php während des in den
 * Vorgaben eingestellten Versammlungs-Zeitfensters automatisch
 * AnwesendSeit/AnwesendArt='simuliert' ein, sofern noch nicht gesetzt.
 * Tut nichts, wenn die Mitgliedschaftsprüfung aktiv ist (dann zählt nur
 * die echte Einlasskontrolle, siehe ausweis_pruefen.php), kein
 * Zeitfenster eingetragen ist, oder die aktuelle Zeit außerhalb davon
 * liegt - so werden Aufrufe Tage vorher (z. B. zum Ausprobieren) bewusst
 * NICHT als Anwesenheit gewertet.
 */
function mitglied_anwesenheit_simulieren(PDO $pdo, int $jahr, array $config, array $mitglied): void {
    if (($config['mitgliedschaftspruefung_aktiv'] ?? '0') === '1') {
        return;
    }
    if (!empty($mitglied['AnwesendSeit'] ?? null)) {
        return;
    }
    $beginn = trim((string)($config['versammlung_beginn'] ?? ''));
    $ende = trim((string)($config['versammlung_ende'] ?? ''));
    if ($beginn === '' || $ende === '') {
        return;
    }
    $beginnTs = strtotime($beginn);
    $endeTs = strtotime($ende);
    $jetzt = time();
    if ($beginnTs === false || $endeTs === false || $jetzt < $beginnTs || $jetzt > $endeTs) {
        return;
    }
    $tabelle = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare(
        "UPDATE `$tabelle` SET AnwesendSeit = NOW(), AnwesendArt = 'simuliert' WHERE MNr = ? AND AnwesendSeit IS NULL"
    );
    $stmt->execute([$mitglied['MNr']]);
}
