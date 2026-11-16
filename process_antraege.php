<?php
/**
 * process_antraege.php - Verarbeitet die Kernprozesse 01-04 der
 * Antragsverwaltung. "hauptantrag" kommt von tab=antrag_stellen,
 * "aenderungsantrag"/"verfahrensantrag"/"hauptantrag_bearbeiten"/
 * "hauptantrag_zurueckziehen" von tab=antrag_liste (direkt beim
 * jeweiligen Hauptantrag, siehe tab_antrag_detail.php) - siehe
 * index.php. Das dortige Gating prueft bereits, dass ein Mitglied
 * angemeldet ist - hier zusaetzlich zur Sicherheit noch einmal.
 *
 * "im Namen von": Mitglieder des Redaktionsteams koennen ueber die
 * Formularfelder ast_modus ('eigen'/'fuer') und ast_mnr Anträge und
 * Beteiligungen im Namen eines anderen Mitglieds einstellen (siehe
 * mitglieder_functions.php: antrag_ziel_mitglied_ermitteln()) - das
 * Zielmitglied wird bei Bedarf neu angelegt. Fuer Nicht-Redaktionsmitglieder
 * ist $ziel_mnr immer die eigene Mitgliedsnummer.
 */

require_once __DIR__ . '/antraege_functions.php';
require_once __DIR__ . '/mitglieder_functions.php';
require_once __DIR__ . '/unterstuetzung_functions.php';
require_once __DIR__ . '/votum_functions.php';

if (!$mitglied) {
    http_response_code(403);
    die('Bitte zuerst anmelden.');
}

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

$aktion = $_POST['aktion'] ?? '';
$mnr = $mitglied['MNr'];
$redirect = '?tab=antrag_stellen';

switch ($aktion) {

    case 'hauptantrag':
        if (ist_antragsschluss_erreicht($mvtexte)) {
            $_SESSION['admin_fehler'] = 'Der Antragsschluss ist bereits erreicht - neue Hauptanträge sind nicht mehr möglich.';
            break;
        }

        [$ziel_mitglied, $ziel_fehler] = antrag_ziel_mitglied_ermitteln(
            $pdo, $jahr, $mitglied, $ist_redaktion, $_POST['ast_modus'] ?? 'eigen', $_POST['ast_mnr'] ?? ''
        );
        if ($ziel_fehler) {
            $_SESSION['admin_fehler'] = $ziel_fehler;
            break;
        }
        $ziel_mnr = $ziel_mitglied['MNr'];

        $config = get_config($pdo);
        $limit = (int)($config['max_hauptantraege_pro_mitglied'] ?? 2);
        if (zaehle_hauptantraege_von($pdo, $jahr, $ziel_mnr) >= $limit) {
            $_SESSION['admin_fehler'] = ($ziel_mnr === $mnr ? 'Du hast dein' : 'Dieses Mitglied hat sein')
                . ' Kontingent von ' . $limit . ' Hauptanträgen bereits ausgeschöpft.';
            break;
        }

        $kat = (int)($_POST['kategorie'] ?? 0);
        $titel = trim($_POST['titel'] ?? '');
        $text = trim($_POST['text'] ?? '');
        $begr = trim($_POST['begr'] ?? '');
        $fin = trim($_POST['fin'] ?? '');

        if (!array_key_exists($kat, ANTRAG_KATEGORIEN) || $titel === '' || $text === '' || $begr === '' || $fin === '') {
            $_SESSION['admin_fehler'] = 'Bitte Kategorie, Titel, Antragstext, Begründung und finanzielle Auswirkung angeben.';
            break;
        }

        $nr = hauptantrag_einreichen($pdo, $jahr, $ziel_mnr, $kat, $titel, $text, $begr, $fin);
        mv_log($pdo, $jahr, $mitglied, 'Hauptantrag eingereicht' . ($ziel_mnr !== $mnr ? ' (im Namen von ' . $ziel_mnr . ')' : ''),
            sql_insert('mvantraege_' . $jahr, [
                'Ast' => $ziel_mnr, 'Art' => ANTRAG_ART_HAUPTANTRAG, 'Kat' => $kat, 'Nr' => $nr,
                'Titeltext' => $titel, 'Text' => $text, 'Begr' => $begr, 'Fin' => $fin,
            ]));
        $_SESSION['admin_erfolg'] = 'Antrag ' . $nr . ' wurde eingereicht.';
        break;

    // Kernprozess 04: Hauptantrag bearbeiten - Antragsteller:in nur bis
    // Antragsschluss, Redaktion jederzeit (solange nicht abgestimmt/zurückgezogen).
    // Vorstandsstellungnahme und Forumlink sind ausschließlich von der
    // Redaktion editierbar.
    case 'hauptantrag_bearbeiten':
        $antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $antrag_alt = get_antrag($pdo, $jahr, $antrag_id);
        if (!$antrag_alt || (int)$antrag_alt['Art'] !== ANTRAG_ART_HAUPTANTRAG) {
            $_SESSION['admin_fehler'] = 'Der gewählte Antrag wurde nicht gefunden.';
            $redirect = '?tab=antrag_liste';
            break;
        }
        $redirect = '?tab=antrag_liste&id=' . $antrag_id;

        if (!hauptantrag_darf_bearbeiten($antrag_alt, $mitglied, $ist_redaktion, $mvtexte)) {
            $_SESSION['admin_fehler'] = 'Dieser Antrag kann nicht (mehr) bearbeitet werden - der Antragsschluss ist erreicht.';
            break;
        }

        $titel = trim($_POST['titel'] ?? '');
        $text = trim($_POST['text'] ?? '');
        $begr = trim($_POST['begr'] ?? '');
        $fin = trim($_POST['fin'] ?? '');

        if ($titel === '' || $text === '' || $begr === '' || $fin === '') {
            $_SESSION['admin_fehler'] = 'Bitte Titel, Antragstext, Begründung und finanzielle Auswirkung angeben.';
            break;
        }

        $felder = ['Titeltext' => $titel, 'Text' => $text, 'Begr' => $begr, 'Fin' => $fin];
        $ast_korrektur_alte_mnr = null;
        if ($ist_redaktion) {
            $felder['Vorstand'] = trim($_POST['vorstand'] ?? '');
            $felder['Forumlink'] = trim($_POST['forumlink'] ?? '');
            // Bem ist ein fortlaufendes internes Notizbuch - wird angehaengt,
            // nie ueberschrieben (siehe bem_anhaengen()).
            $felder['Bem'] = bem_anhaengen(
                (string)($antrag_alt['Bem'] ?? ''),
                $_POST['bem_neu'] ?? '',
                trim($mitglied['Vorname'] . ' ' . $mitglied['Name'])
            );
            $felder['Begrpdf'] = trim($_POST['begrpdf'] ?? '');
            // Pdf nur ersetzen, wenn tatsaechlich eine neue Datei hochgeladen wurde -
            // sonst bleibt die bisherige Datei/der bisherige Wert unangetastet.
            $neuer_pdf_pfad = antrag_pdf_speichern($_FILES['pdf'] ?? [], $jahr, $antrag_alt['Nr'], $antrag_alt['Pdf'] ?? null);
            if ($neuer_pdf_pfad !== null) {
                $felder['Pdf'] = $neuer_pdf_pfad;
            }
            // Korrektur einer versehentlich falsch eingegebenen Mitgliedsnummer:
            // Zielmitglied bei Bedarf anlegen und - falls dort noch kein Name
            // hinterlegt ist - ueber die Mitgliederquelle nachtragen. Die alte
            // (falsche) MNr wird unten, NACH dem Speichern des Antrags, aus der
            // Mitgliederliste geloescht - aber nur, wenn sie wirklich verwaist ist.
            $neue_ast_mnr = trim($_POST['ast_mnr_korrektur'] ?? '');
            if ($neue_ast_mnr !== '' && $neue_ast_mnr !== $antrag_alt['Ast']) {
                mitglied_laden_und_namen_nachtragen($pdo, $jahr, $neue_ast_mnr, $mitglied);
                $felder['Ast'] = $neue_ast_mnr;
                $ast_korrektur_alte_mnr = $antrag_alt['Ast'];
                mv_log($pdo, $jahr, $mitglied, 'Mitgliedsnummer des Antrags korrigiert',
                    'Antrag ' . $antrag_alt['Nr'] . ': ' . $antrag_alt['Ast'] . ' -> ' . $neue_ast_mnr);
            }
        }
        antrag_aktualisieren($pdo, $jahr, $antrag_id, $felder);
        mv_log($pdo, $jahr, $mitglied, 'Hauptantrag bearbeitet', sql_update('mvantraege_' . $jahr, $felder, ['ID' => $antrag_id]));
        if ($ast_korrektur_alte_mnr !== null && mvmitglieder_eintrag_loeschen_falls_verwaist($pdo, $jahr, $ast_korrektur_alte_mnr)) {
            mv_log($pdo, $jahr, $mitglied, 'Verwaisten Mitgliedseintrag gelöscht',
                'MNr ' . $ast_korrektur_alte_mnr . ' (nach Korrektur bei Antrag ' . $antrag_alt['Nr'] . ')');
        }
        $_SESSION['admin_erfolg'] = 'Antrag ' . $antrag_alt['Nr'] . ' wurde aktualisiert.';
        break;

    // Zurückziehen eines Hauptantrags - Antragsteller:in je nach Vereinsvorgabe
    // (rueckzug_nur_bis_antragsschluss) nur bis Antragsschluss oder jederzeit
    // bis zur eigenen Abstimmung, Redaktion jederzeit.
    case 'hauptantrag_zurueckziehen':
        $antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $antrag_alt = get_antrag($pdo, $jahr, $antrag_id);
        if (!$antrag_alt || (int)$antrag_alt['Art'] !== ANTRAG_ART_HAUPTANTRAG) {
            $_SESSION['admin_fehler'] = 'Der gewählte Antrag wurde nicht gefunden.';
            $redirect = '?tab=antrag_liste';
            break;
        }
        $redirect = '?tab=antrag_liste&id=' . $antrag_id;

        $config = get_config($pdo);
        if (!hauptantrag_darf_zurueckziehen($antrag_alt, $mitglied, $ist_redaktion, $mvtexte, $config)) {
            $_SESSION['admin_fehler'] = 'Dieser Antrag kann nicht (mehr) zurückgezogen werden.';
            break;
        }

        antrag_zurueckziehen($pdo, $jahr, $antrag_id);
        mv_log($pdo, $jahr, $mitglied, 'Hauptantrag zurückgezogen', sql_update('mvantraege_' . $jahr,
            ['Art' => ANTRAG_ART_ARCHIVIERT], ['ID' => $antrag_id]));
        $_SESSION['admin_erfolg'] = 'Antrag ' . $antrag_alt['Nr'] . ' wurde zurückgezogen.';
        break;

    // Änderungs-/Verfahrensantrag bearbeiten - anders als beim Hauptantrag
    // gilt hier keine Frist: Antragsteller:in UND Redaktion duerfen jederzeit.
    case 'folgeantrag_bearbeiten':
        $antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $antrag_alt = get_antrag($pdo, $jahr, $antrag_id);
        if (!$antrag_alt || !in_array((int)$antrag_alt['Art'], [ANTRAG_ART_AENDERUNG, ANTRAG_ART_VERFAHREN], true)) {
            $_SESSION['admin_fehler'] = 'Der gewählte Antrag wurde nicht gefunden.';
            $redirect = '?tab=antrag_liste';
            break;
        }
        $redirect = '?tab=antrag_liste' . ($antrag_alt['Zu'] ? '&id=' . $antrag_alt['Zu'] : '');

        if (!folgeantrag_darf_verwalten($antrag_alt, $mitglied, $ist_redaktion)) {
            $_SESSION['admin_fehler'] = 'Dieser Antrag kann nicht (mehr) bearbeitet werden.';
            break;
        }

        $titel = trim($_POST['titel'] ?? '');
        $text = trim($_POST['text'] ?? '');
        $begr = trim($_POST['begr'] ?? '');
        // Verfahrensanträge kennen kein Feld "finanzielle Auswirkung" - bestehenden Wert (i. d. R. leer) beibehalten.
        $fin = (int)$antrag_alt['Art'] === ANTRAG_ART_AENDERUNG ? trim($_POST['fin'] ?? '') : (string)($antrag_alt['Fin'] ?? '');

        $fin_erforderlich = (int)$antrag_alt['Art'] === ANTRAG_ART_AENDERUNG;
        if ($titel === '' || $text === '' || ($fin_erforderlich && $fin === '')) {
            $_SESSION['admin_fehler'] = 'Bitte Titel und Text angeben' . ($fin_erforderlich ? ' (sowie die finanziellen Auswirkungen)' : '') . '.';
            break;
        }

        $felder = ['Titeltext' => $titel, 'Text' => $text, 'Begr' => $begr, 'Fin' => $fin];
        $ast_korrektur_alte_mnr = null;
        if ($ist_redaktion) {
            // Korrektur einer versehentlich falsch eingegebenen Mitgliedsnummer
            // (siehe "hauptantrag_bearbeiten" oben) - nur die Redaktion darf das,
            // nicht die/der Antragsteller:in selbst. Die alte (falsche) MNr wird
            // unten, NACH dem Speichern des Antrags, aus der Mitgliederliste
            // geloescht - aber nur, wenn sie wirklich verwaist ist.
            $neue_ast_mnr = trim($_POST['ast_mnr_korrektur'] ?? '');
            if ($neue_ast_mnr !== '' && $neue_ast_mnr !== $antrag_alt['Ast']) {
                mitglied_laden_und_namen_nachtragen($pdo, $jahr, $neue_ast_mnr, $mitglied);
                $felder['Ast'] = $neue_ast_mnr;
                $ast_korrektur_alte_mnr = $antrag_alt['Ast'];
                mv_log($pdo, $jahr, $mitglied, 'Mitgliedsnummer des Antrags korrigiert',
                    'Antrag ' . $antrag_alt['Nr'] . ': ' . $antrag_alt['Ast'] . ' -> ' . $neue_ast_mnr);
            }
        }
        antrag_aktualisieren($pdo, $jahr, $antrag_id, $felder);
        mv_log($pdo, $jahr, $mitglied, 'Änderungs-/Verfahrensantrag bearbeitet', sql_update('mvantraege_' . $jahr, $felder, ['ID' => $antrag_id]));
        if ($ast_korrektur_alte_mnr !== null && mvmitglieder_eintrag_loeschen_falls_verwaist($pdo, $jahr, $ast_korrektur_alte_mnr)) {
            mv_log($pdo, $jahr, $mitglied, 'Verwaisten Mitgliedseintrag gelöscht',
                'MNr ' . $ast_korrektur_alte_mnr . ' (nach Korrektur bei Antrag ' . $antrag_alt['Nr'] . ')');
        }
        $_SESSION['admin_erfolg'] = 'Antrag ' . $antrag_alt['Nr'] . ' wurde aktualisiert.';
        break;

    // Änderungs-/Verfahrensantrag zurückziehen - jederzeit, Antragsteller:in oder Redaktion.
    case 'folgeantrag_zurueckziehen':
        $antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $antrag_alt = get_antrag($pdo, $jahr, $antrag_id);
        if (!$antrag_alt || !in_array((int)$antrag_alt['Art'], [ANTRAG_ART_AENDERUNG, ANTRAG_ART_VERFAHREN], true)) {
            $_SESSION['admin_fehler'] = 'Der gewählte Antrag wurde nicht gefunden.';
            $redirect = '?tab=antrag_liste';
            break;
        }
        $redirect = '?tab=antrag_liste' . ($antrag_alt['Zu'] ? '&id=' . $antrag_alt['Zu'] : '');

        if (!folgeantrag_darf_verwalten($antrag_alt, $mitglied, $ist_redaktion)) {
            $_SESSION['admin_fehler'] = 'Dieser Antrag kann nicht (mehr) zurückgezogen werden.';
            break;
        }

        antrag_zurueckziehen($pdo, $jahr, $antrag_id);
        mv_log($pdo, $jahr, $mitglied, 'Änderungs-/Verfahrensantrag zurückgezogen', sql_update('mvantraege_' . $jahr,
            ['Art' => ANTRAG_ART_ARCHIVIERT], ['ID' => $antrag_id]));
        $_SESSION['admin_erfolg'] = 'Antrag ' . $antrag_alt['Nr'] . ' wurde zurückgezogen.';
        break;

    case 'aenderungsantrag':
        $hauptantrag_id = (int)($_POST['hauptantrag_id'] ?? 0);
        $titel = trim($_POST['titel'] ?? '');
        $text = trim($_POST['text'] ?? '');
        $begr = trim($_POST['begr'] ?? '');
        $fin = trim($_POST['fin'] ?? '');

        $hauptantrag = get_antrag($pdo, $jahr, $hauptantrag_id);
        if (!$hauptantrag || (int)$hauptantrag['Art'] !== ANTRAG_ART_HAUPTANTRAG) {
            $_SESSION['admin_fehler'] = 'Der gewählte Hauptantrag wurde nicht gefunden.';
            $redirect = '?tab=antrag_liste';
            break;
        }
        $redirect = '?tab=antrag_liste&id=' . $hauptantrag['ID'];
        if (antrag_wurde_abgestimmt($hauptantrag)) {
            $_SESSION['admin_fehler'] = 'Über diesen Antrag wurde bereits abgestimmt - ein Änderungsantrag ist nicht mehr möglich.';
            break;
        }
        if ($titel === '' || $text === '' || $begr === '') {
            $_SESSION['admin_fehler'] = 'Bitte Titel, Änderungstext und Begründung angeben.';
            break;
        }

        [$ziel_mitglied, $ziel_fehler] = antrag_ziel_mitglied_ermitteln(
            $pdo, $jahr, $mitglied, $ist_redaktion, $_POST['ast_modus'] ?? 'eigen', $_POST['ast_mnr'] ?? ''
        );
        if ($ziel_fehler) {
            $_SESSION['admin_fehler'] = $ziel_fehler;
            break;
        }
        $ziel_mnr = $ziel_mitglied['MNr'];

        $nr = aenderungsantrag_einreichen($pdo, $jahr, $ziel_mnr, $hauptantrag, $titel, $text, $begr, $fin);
        mv_log($pdo, $jahr, $mitglied, 'Änderungsantrag eingereicht' . ($ziel_mnr !== $mnr ? ' (im Namen von ' . $ziel_mnr . ')' : ''),
            sql_insert('mvantraege_' . $jahr, [
                'Ast' => $ziel_mnr, 'Art' => ANTRAG_ART_AENDERUNG, 'Kat' => $hauptantrag['Kat'], 'Nr' => $nr, 'Zu' => $hauptantrag['ID'],
                'Titeltext' => $titel, 'Text' => $text, 'Begr' => $begr, 'Fin' => $fin,
            ]));
        $_SESSION['admin_erfolg'] = 'Änderungsantrag ' . $nr . ' wurde eingereicht.';
        break;

    case 'verfahrensantrag':
        $hauptantrag_id = (int)($_POST['hauptantrag_id'] ?? 0);
        $hauptantrag = $hauptantrag_id > 0 ? get_antrag($pdo, $jahr, $hauptantrag_id) : null;
        if ($hauptantrag_id > 0) {
            $redirect = '?tab=antrag_liste&id=' . $hauptantrag_id;
        }
        if ($hauptantrag_id > 0 && (!$hauptantrag || antrag_wurde_abgestimmt($hauptantrag))) {
            $_SESSION['admin_fehler'] = 'Der gewählte Antrag ist für einen Verfahrensantrag nicht (mehr) verfügbar.';
            break;
        }

        $titel = trim($_POST['titel'] ?? '');
        $text = trim($_POST['text'] ?? '');
        $begr = trim($_POST['begr'] ?? '');

        if ($titel === '' || $text === '') {
            $_SESSION['admin_fehler'] = 'Bitte Titel und Text des Verfahrensantrags angeben.';
            break;
        }

        [$ziel_mitglied, $ziel_fehler] = antrag_ziel_mitglied_ermitteln(
            $pdo, $jahr, $mitglied, $ist_redaktion, $_POST['ast_modus'] ?? 'eigen', $_POST['ast_mnr'] ?? ''
        );
        if ($ziel_fehler) {
            $_SESSION['admin_fehler'] = $ziel_fehler;
            break;
        }
        $ziel_mnr = $ziel_mitglied['MNr'];

        $nr = verfahrensantrag_einreichen($pdo, $jahr, $ziel_mnr, $hauptantrag, $titel, $text, $begr);
        mv_log($pdo, $jahr, $mitglied, 'Verfahrensantrag eingereicht' . ($ziel_mnr !== $mnr ? ' (im Namen von ' . $ziel_mnr . ')' : ''),
            sql_insert('mvantraege_' . $jahr, [
                'Ast' => $ziel_mnr, 'Art' => ANTRAG_ART_VERFAHREN, 'Kat' => ANTRAG_KAT_VERFAHREN, 'Nr' => $nr,
                'Zu' => $hauptantrag['ID'] ?? null, 'Titeltext' => $titel, 'Text' => $text, 'Begr' => $begr,
            ]));
        $_SESSION['admin_erfolg'] = 'Verfahrensantrag ' . $nr . ' wurde eingereicht.';
        break;

    // Kernprozess 05: Unterstützung / Mitantragstellerschaft - nur für Hauptanträge.
    // Die/der Antragsteller:in des Hauptantrags bekommt hier bewusst keine
    // Mitantragsteller-Eingabe (siehe tab_antrag_detail.php) - zur Sicherheit
    // wird das auch serverseitig erzwungen.
    case 'beteiligung':
        $antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $hauptantrag = get_antrag($pdo, $jahr, $antrag_id);
        if (!$hauptantrag || (int)$hauptantrag['Art'] !== ANTRAG_ART_HAUPTANTRAG) {
            $_SESSION['admin_fehler'] = 'Der gewählte Hauptantrag wurde nicht gefunden.';
            $redirect = '?tab=antrag_liste';
            break;
        }
        $redirect = '?tab=antrag_liste&id=' . $antrag_id;

        [$ziel_mitglied, $ziel_fehler] = antrag_ziel_mitglied_ermitteln(
            $pdo, $jahr, $mitglied, $ist_redaktion, $_POST['ast_modus'] ?? 'eigen', $_POST['ast_mnr'] ?? ''
        );
        if ($ziel_fehler) {
            $_SESSION['admin_fehler'] = $ziel_fehler;
            break;
        }
        $ziel_mnr = $ziel_mitglied['MNr'];

        $mitantragsteller_aktiv = isset($_POST['mitantragsteller']) && $ziel_mnr !== $hauptantrag['Ast'];
        mitantragsteller_setzen($pdo, $jahr, $antrag_id, $ziel_mnr, $mitantragsteller_aktiv);

        $config = get_config($pdo);
        if (!ist_unterstuetzungsfrist_abgelaufen($mvtexte, $config)) {
            $unterstuetzung_aktiv = isset($_POST['unterstuetzung']);
            $namentlich = isset($_POST['namentlich']);
            unterstuetzung_setzen($pdo, $jahr, $antrag_id, $ziel_mnr, $unterstuetzung_aktiv, $namentlich);
        }

        mv_log($pdo, $jahr, $mitglied, 'Beteiligung an Antrag geändert (Mitantragsteller/Unterstützung)' . ($ziel_mnr !== $mnr ? ' (im Namen von ' . $ziel_mnr . ')' : ''),
            sql_update('mvunterst_' . $jahr, ['MNr' => $ziel_mnr, 'mitantragsteller' => $mitantragsteller_aktiv], ['AnID' => $antrag_id]));
        $_SESSION['admin_erfolg'] = 'Die Angaben wurden gespeichert.';
        break;

    // Übernahme eines Änderungsantrags, Stufe 1 (siehe Projekt-Dokumentation
    // und antraege_functions.php): die/der Antragsteller:in des Hauptantrags
    // erklaert, dass sie/er den Änderungsantrag übernimmt. Die Redaktion wird
    // per Mail informiert und arbeitet die Übernahme danach manuell ein
    // (Stufe 2, siehe Aktion "aenderungsantrag_mergen" unten).
    case 'aenderungsantrag_uebernehmen':
        $antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $aenderungsantrag = get_antrag($pdo, $jahr, $antrag_id);
        if (!$aenderungsantrag || (int)$aenderungsantrag['Art'] !== ANTRAG_ART_AENDERUNG || !$aenderungsantrag['Zu']) {
            $_SESSION['admin_fehler'] = 'Der gewählte Änderungsantrag wurde nicht gefunden.';
            $redirect = '?tab=antrag_liste';
            break;
        }
        $hauptantrag = get_antrag($pdo, $jahr, (int)$aenderungsantrag['Zu']);
        $redirect = '?tab=antrag_liste&id=' . (int)$aenderungsantrag['Zu'];
        if (!$hauptantrag || $hauptantrag['Ast'] !== $mnr) {
            $_SESSION['admin_fehler'] = 'Nur die/der Antragsteller:in des Hauptantrags kann einen Änderungsantrag übernehmen.';
            break;
        }
        if (antrag_wurde_abgestimmt($aenderungsantrag)) {
            $_SESSION['admin_fehler'] = 'Über diesen Änderungsantrag wurde bereits abgestimmt.';
            break;
        }

        aenderungsantrag_als_uebernommen_markieren($pdo, $jahr, $antrag_id);
        if (ist_antragsschluss_erreicht($mvtexte)) {
            hauptantrag_ursprungsfassung_archivieren($pdo, $jahr, $hauptantrag);
        }
        redaktion_ueber_aenderungsantrag_uebernahme_benachrichtigen($pdo, $jahr, $hauptantrag, $aenderungsantrag);

        mv_log($pdo, $jahr, $mitglied, 'Änderungsantrag durch Antragsteller:in übernommen',
            sql_update('mvantraege_' . $jahr, ['Uebernommen' => 1], ['ID' => $antrag_id]));
        $_SESSION['admin_erfolg'] = 'Danke - die Übernahme wurde vermerkt, die Redaktion wurde informiert.';
        break;

    // Übernahme eines Änderungsantrags, Stufe 2 - nur Redaktion: der
    // zusammengeführte Text/Begründung wird im Hauptantrag gespeichert, der
    // Änderungsantrag verschwindet aus den normalen Listen (Art=ARCHIVIERT,
    // Nr-Suffix "x", siehe antraege_functions.php: aenderungsantrag_mergen()).
    case 'aenderungsantrag_mergen':
        if (!$ist_redaktion) {
            http_response_code(403);
            die('Keine Berechtigung.');
        }
        $hauptantrag_id = (int)($_POST['hauptantrag_id'] ?? 0);
        $antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $hauptantrag = get_antrag($pdo, $jahr, $hauptantrag_id);
        $aenderungsantrag = get_antrag($pdo, $jahr, $antrag_id);
        $redirect = '?tab=antrag_liste&id=' . $hauptantrag_id;
        if (!$hauptantrag || (int)$hauptantrag['Art'] !== ANTRAG_ART_HAUPTANTRAG
            || !$aenderungsantrag || (int)$aenderungsantrag['Art'] !== ANTRAG_ART_AENDERUNG
            || (int)$aenderungsantrag['Zu'] !== $hauptantrag_id || !$aenderungsantrag['Uebernommen']) {
            $_SESSION['admin_fehler'] = 'Diese Übernahme ist nicht (mehr) gültig.';
            break;
        }

        $neuer_text = trim($_POST['text'] ?? '');
        $neue_begr = trim($_POST['begr'] ?? '');
        if ($neuer_text === '') {
            $_SESSION['admin_fehler'] = 'Bitte den zusammengeführten Antragstext angeben.';
            break;
        }

        aenderungsantrag_mergen($pdo, $jahr, $hauptantrag, $antrag_id, $neuer_text, $neue_begr);
        mv_log($pdo, $jahr, $mitglied, 'Übernahme eines Änderungsantrags abgeschlossen', sql_update('mvantraege_' . $jahr,
            ['Text' => $neuer_text, 'Begr' => $neue_begr], ['ID' => $hauptantrag_id]));
        $_SESSION['admin_erfolg'] = 'Der Änderungsantrag wurde in den Hauptantrag übernommen.';
        break;

    // Kernprozess 06: Stimmungsbild - gilt für Haupt- UND Nebenanträge.
    case 'votum':
        $antrag_id = (int)($_POST['antrag_id'] ?? 0);
        $wichtig = (int)($_POST['wichtig'] ?? 0);
        $votum = (int)($_POST['votum'] ?? 0);

        $antrag_ziel = get_antrag($pdo, $jahr, $antrag_id);
        if (!$antrag_ziel) {
            $_SESSION['admin_fehler'] = 'Der gewählte Antrag wurde nicht gefunden.';
            $redirect = '?tab=antrag_liste';
            break;
        }
        $redirect = '?tab=antrag_liste&id=' . ((int)$antrag_ziel['Art'] === ANTRAG_ART_HAUPTANTRAG ? $antrag_id : $antrag_ziel['Zu']);

        if (!array_key_exists($wichtig, VOTUM_WICHTIGKEIT) || !array_key_exists($votum, VOTUM_ZUSTIMMUNG)) {
            $_SESSION['admin_fehler'] = 'Bitte Zustimmung und Wichtigkeit auswählen.';
            break;
        }

        $schluessel = $mitglied['Schluessel'] ?? '';
        if ($schluessel === '') {
            $_SESSION['admin_fehler'] = 'Für dein Konto liegt derzeit kein Stimmungsbild-Schlüssel vor - bitte wende dich an die Versammlungsleitung.';
            break;
        }

        votum_abgeben($pdo, $jahr, $antrag_id, $schluessel, $wichtig, $votum);
        // Bewusst OHNE Mitglied protokolliert: mvvotum_<jahr> identifiziert Stimmen
        // ausschliesslich ueber den anonymen Schluessel, nie ueber MNr - ein Logeintrag
        // mit MNr wuerde diese Anonymisierung sofort wieder aufheben (siehe votum_functions.php).
        mv_log($pdo, $jahr, null, 'Stimmungsbild abgegeben (anonymisiert)', sql_update('mvvotum_' . $jahr,
            ['Wichtig' => $wichtig, 'Votum' => $votum], ['AnID' => $antrag_id]));
        $_SESSION['admin_erfolg'] = 'Dein Stimmungsbild wurde gespeichert.';
        break;

    // Sammel-Abstimmung (siehe tab_stimmungsbild.php: "Alle öffnen") - speichert
    // mehrere Voten UND (solange die Frist laeuft) Unterstuetzungs-Entscheidungen
    // auf einmal, statt jeden Antrag einzeln abschicken zu muessen. Die beiden
    // Teile sind unabhaengig voneinander gueltig: fehlt der Stimmungsbild-
    // Schluessel, wird die Unterstuetzung trotzdem gespeichert und umgekehrt.
    case 'stimmungsbild_alle':
        $redirect = '?tab=stimmungsbild';
        $config = get_config($pdo);
        $schluessel = $mitglied['Schluessel'] ?? '';
        $anzahl_voten = 0;
        $anzahl_unterstuetzungen = 0;

        if ($schluessel !== '') {
            $wichtig_werte = is_array($_POST['wichtig'] ?? null) ? $_POST['wichtig'] : [];
            $votum_werte = is_array($_POST['votum'] ?? null) ? $_POST['votum'] : [];
            $anzahl_voten = votum_alle_speichern($pdo, $jahr, $schluessel, $wichtig_werte, $votum_werte);
            if ($anzahl_voten > 0) {
                // Bewusst OHNE Mitglied protokolliert - siehe Begruendung beim Einzelvotum oben.
                mv_log($pdo, $jahr, null, 'Stimmungsbild abgegeben (anonymisiert, Sammelabstimmung)', "$anzahl_voten Votum/Voten gespeichert");
            }
        }

        $unterstuetzung_werte = is_array($_POST['unterstuetzung'] ?? null) ? $_POST['unterstuetzung'] : [];
        $namentlich_werte = is_array($_POST['namentlich'] ?? null) ? $_POST['namentlich'] : [];
        $anzahl_unterstuetzungen = unterstuetzung_alle_speichern($pdo, $jahr, $mnr, $mvtexte, $config, $unterstuetzung_werte, $namentlich_werte);
        if ($anzahl_unterstuetzungen > 0) {
            mv_log($pdo, $jahr, $mitglied, 'Beteiligung an mehreren Anträgen geändert (Unterstützung, Sammelabstimmung)',
                "$anzahl_unterstuetzungen Entscheidung(en) gespeichert");
        }

        $teile = [];
        if ($anzahl_voten > 0) {
            $teile[] = $anzahl_voten === 1 ? 'ein Votum' : "$anzahl_voten Voten";
        }
        if ($anzahl_unterstuetzungen > 0) {
            $teile[] = 'deine Unterstützungsangaben';
        }
        if ($teile) {
            $_SESSION['admin_erfolg'] = ucfirst(implode(' und ', $teile)) . ' gespeichert.';
        } else {
            $_SESSION['admin_fehler'] = 'Es wurde nichts Vollständiges zum Speichern gefunden.';
        }
        break;
}

header('Location: ' . $redirect);
exit;
