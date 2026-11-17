<?php
/**
 * abstimmung_functions.php - Abstimmungsmaschine (Live-Abstimmung während der
 * Versammlung, Ersatz für autarke Abstimmungsgeräte). Siehe
 * dokumentation/Abstimmungsmaschine.md für den Gesamtplan.
 *
 * Phase 1: anonyme Stimmkarten (Token) erzeugen und als PDF mit QR-Code
 * ausdrucken. Bewusst KEIN Bezug zu einer Mitgliedsnummer (anders als beim
 * Login-Modul, siehe login_functions.php) - die Karten werden am Einlass
 * frei aus einem Stapel gewählt, das System muss nie wissen, wer welche
 * Karte hat. Komplett unabhängig von WABS (zurgo42/MISS) -
 * eigene Tabellen, eigene Token-Logik, kein gemeinsamer Code.
 *
 * Phase 2: Abstimmungsrunde durchführen. Es läuft immer höchstens eine Runde
 * gleichzeitig (wie bei einer Handabstimmung auch). Der Fragetext einer
 * Runde ist eine Momentaufnahme des beim Start gerade gezeigten Beamerbilds
 * (mvtexte.beamer, siehe beamer_functions.php) - der Operator muss dafür
 * keine eigene "Abstimmungsfolie" anlegen, das ist der zentrale Vorteil
 * gegenüber autarken Abstimmungsgeräten (siehe dokumentation/
 * Abstimmungsmaschine.md). Eine Stimmkarte kann je Runde nur einmal
 * abstimmen (UNIQUE(round_id, token_hash)), in der nächsten Runde aber
 * erneut - genau deshalb kann dieselbe Karte (und damit ggf. dasselbe
 * Leihgerät) im Lauf des Abends von wechselnden Personen genutzt werden.
 */

function vote_tokens_tabelle_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_vote_tokens (
            token_id INT AUTO_INCREMENT PRIMARY KEY,
            token_hash VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY idx_token_hash (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Nachtraeglich ergaenzte Spalte (BEAM-Vereinheitlichung, siehe
    // dokumentation/BEAM.md) - macht den Stimmkarten-Pool kontextfaehig: bei
    // VERA das MV-Kalenderjahr, bei BEAM die beam_projekte.id. Bestehende
    // Zeilen bekommen 0 (kein bekannter Kontext) - siehe spalte_existiert()
    // in db_functions.php fuer das Muster.
    if (!spalte_existiert($pdo, 'mv_vote_tokens', 'jahr')) {
        try {
            $pdo->exec('ALTER TABLE mv_vote_tokens ADD COLUMN jahr INT NOT NULL DEFAULT 0');
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    // Vor der BEAM-Vereinheitlichung erzeugte (oder zwischen ADD COLUMN und
    // diesem Fix erzeugte) Stimmkarten haben als Default 0 bekommen - ohne
    // bekannten Kontext waren sie damit für jede jahr-gescopte Abfrage
    // (vote_tokens_anzahl()/vote_tokens_loeschen()/vote_token_gueltig())
    // unsichtbar: schon ausgegebene, physische Stimmkarten ließen sich weder
    // zählen noch löschen, und galten beim Abstimmen fälschlich als
    // ungültig. Einmalig (danach gibt es keine jahr=0-Zeilen mehr, da kein
    // Aufrufer seit der Vereinheitlichung noch ohne Jahr erzeugt) auf das
    // aktuelle VERA-Jahr nachgezogen - Race mit einer gleichzeitig laufenden
    // BEAM-Projekterstellung ist unkritisch, deren Token-Erzeugung schreibt
    // immer einen echten Kontext direkt beim INSERT, nie 0.
    if ((int)$pdo->query('SELECT COUNT(*) FROM mv_vote_tokens WHERE jahr = 0')->fetchColumn() > 0) {
        $pdo->prepare('UPDATE mv_vote_tokens SET jahr = ? WHERE jahr = 0')->execute([get_aktuelles_jahr($pdo)]);
    }
    $geprueft = true;
}

/** Gesamtzahl bisher erzeugter Stimmkarten dieses Kontexts (über alle Chargen hinweg). */
function vote_tokens_anzahl(PDO $pdo, int $jahr): int {
    vote_tokens_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mv_vote_tokens WHERE jahr = ?');
    $stmt->execute([$jahr]);
    return (int)$stmt->fetchColumn();
}

/**
 * Löscht den gesamten Stimmkarten-Pool dieses Kontexts (z. B. wenn er
 * "verbraucht" ist und für die nächste Abstimmung/Wahl neue, frische Karten
 * ausgegeben werden sollen) - nur zulässig, solange keine Runde läuft oder
 * auf Auswertung wartet, sonst würden gerade abstimmende Teilnehmer:innen
 * plötzlich eine ungültige Stimmkarte halten. Löscht dabei auch die
 * zugehörigen PDF-Sammeldateien (siehe vote_stimmkarten_erzeugen(): deren
 * Dateiname trägt seit der BEAM-Vereinheitlichung den Kontext, damit hier
 * gezielt nur die eigenen gelöscht werden können, nicht die eines anderen
 * VERA-Jahres/BEAM-Projekts im selben, gemeinsam genutzten Speicherort -
 * ältere, noch ohne Kontext im Namen erzeugte PDFs bleiben unberührt stehen
 * und müssen ggf. von Hand aufgeräumt werden). Gibt im Fehlerfall einen
 * Text zurück, sonst ''.
 */
function vote_tokens_loeschen(PDO $pdo, int $jahr): string {
    vote_tokens_tabelle_sicherstellen($pdo);
    if (vote_round_aktuell($pdo, $jahr) || vote_round_wartet_auf_auswertung($pdo, $jahr)) {
        return 'Es läuft noch eine Abstimmung/Wahl oder wartet auf Auswertung - bitte zuerst beenden und auswerten.';
    }
    $stmt = $pdo->prepare('DELETE FROM mv_vote_tokens WHERE jahr = ?');
    $stmt->execute([$jahr]);
    foreach (glob(vote_storage_dir() . '/stimmkarten-' . $jahr . '-*.pdf') ?: [] as $pfad) {
        @unlink($pfad);
    }
    return '';
}

/**
 * Macht eine einzelne Stimmkarte sofort ungültig - für die "Austritt"-
 * Funktion (siehe abstimmen.php/beam/abstimmen.php): Technik/Beamer-Team
 * scannt beim vorzeitigen Verlassen der Versammlung mit dem eigenen, bei
 * VERA/BEAM angemeldeten Smartphone den QR-Code der abgegebenen Stimmkarte
 * (denselben, der schon auf der gedruckten Karte steht - keine zweite
 * Funktion/kein zweiter QR-Code nötig). Verhindert, dass ein vorab
 * fotografierter QR-Code von außerhalb weiter genutzt werden kann, und dass
 * dieselbe physische Karte nach einem Wiedereintritt (mit neu ausgegebener
 * Karte) zu zwei gültigen Stimmen führt. Endgültig - ein gelöschter
 * Token-Hash lässt sich nicht wiederherstellen (es wird ohnehin nie der
 * Klartext gespeichert, siehe vote_tokens_erzeugen()).
 */
function vote_token_ungueltig_machen(PDO $pdo, string $token, int $jahr): void {
    vote_tokens_tabelle_sicherstellen($pdo);
    $pdo->prepare('DELETE FROM mv_vote_tokens WHERE token_hash = ? AND jahr = ?')->execute([hash('sha256', $token), $jahr]);
}

/** Ist dieser Klartext-Token eine gültige, erzeugte Stimmkarte dieses Kontexts? */
function vote_token_gueltig(PDO $pdo, string $token, int $jahr): bool {
    vote_tokens_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM mv_vote_tokens WHERE token_hash = ? AND jahr = ?');
    $stmt->execute([hash('sha256', $token), $jahr]);
    return $stmt->fetch() !== false;
}

/**
 * Zu welchem Kontext (VERA-Jahr bzw. BEAM-Projekt-ID) gehört dieser
 * Klartext-Token? Für öffentliche, login-freie Seiten (abstimmen.php bzw.
 * dessen BEAM-Gegenstück), die den Kontext sonst nirgends her wüssten - der
 * Token selbst ist dort der einzige Anhaltspunkt. Gibt null zurück, wenn der
 * Token unbekannt ist.
 */
function vote_token_kontext(PDO $pdo, string $token): ?int {
    vote_tokens_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT jahr FROM mv_vote_tokens WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $r = $stmt->fetch();
    return $r !== false ? (int)$r['jahr'] : null;
}

/**
 * Erzeugt $anzahl neue, zufällige Stimmkarten-Token für diesen Kontext. Nur
 * der SHA-256-Hash wird dauerhaft gespeichert (gleiches Prinzip wie bei
 * WABS) - die Klartext-Token werden hier zurückgegeben, nur um sofort die
 * QR-Codes zu erzeugen, und danach nirgends aufbewahrt.
 */
function vote_tokens_erzeugen(PDO $pdo, int $jahr, int $anzahl): array {
    vote_tokens_tabelle_sicherstellen($pdo);
    $raw = [];
    $stmt = $pdo->prepare('INSERT INTO mv_vote_tokens (token_hash, created_at, jahr) VALUES (?, NOW(), ?)');
    for ($i = 0; $i < $anzahl; $i++) {
        $t = bin2hex(random_bytes(24));
        $stmt->execute([hash('sha256', $t), $jahr]);
        $raw[] = $t;
    }
    return $raw;
}

/**
 * Sucht ein Verzeichnis (z. B. "fpdf", enthaelt "fpdf.php") von $von aus in
 * den Elternverzeichnissen nach oben, bis zu $maxEbenen Ebenen. FPDF/
 * phpqrcode liegen bewusst nicht im Repo, sondern als fertige Dateien
 * irgendwo oberhalb der Anwendung - gemeinsam nutzbar mit den
 * Geschwisteranwendungen KONA/MISS, siehe dort docs/WABS.md. Die genaue
 * Verschachtelungstiefe ist dabei kein verlaesslicher Fixwert (haengt vom
 * jeweiligen Installationspfad ab und aendert sich z. B. bei einem Umzug
 * der Anwendung an die Domainwurzel) - daher Suche statt fester Ebenenzahl.
 */
function vote_lib_verzeichnis_suchen(string $von, string $ordner, string $pruefdatei, int $maxEbenen = 5): ?string {
    $pfad = rtrim($von, '/');
    for ($i = 0; $i <= $maxEbenen; $i++) {
        $kandidat = $pfad . '/' . $ordner;
        if (is_file($kandidat . '/' . $pruefdatei)) {
            return $kandidat;
        }
        $eltern = dirname($pfad);
        if ($eltern === $pfad) break; // Dateisystemwurzel erreicht
        $pfad = $eltern;
    }
    return null;
}

/**
 * Leerer String, wenn FPDF und phpqrcode gefunden wurden, sonst eine
 * Fehlermeldung mit dem Hinweis, wo gesucht wurde.
 */
function vote_pdf_libs_fehlen(): string {
    if (vote_lib_verzeichnis_suchen(__DIR__, 'fpdf', 'fpdf.php') === null) {
        return 'FPDF wurde nicht gefunden (erwartet in einem Ordner "fpdf" oberhalb von ' . __DIR__ . ').';
    }
    if (vote_lib_verzeichnis_suchen(__DIR__, 'phpqrcode', 'qrlib.php') === null) {
        return 'phpqrcode wurde nicht gefunden (erwartet in einem Ordner "phpqrcode" oberhalb von ' . __DIR__ . ').';
    }
    return '';
}

/** Ablage der erzeugten Stimmkarten-PDFs - außerhalb der Versionskontrolle (siehe .gitignore: "storage/"). */
function vote_storage_dir(): string {
    $dir = __DIR__ . '/storage/abstimmung';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Ordner $dir kann nicht angelegt werden.");
    }
    return $dir;
}

/** FPDFs Kernschriften verstehen kein UTF-8, nur CP1252 - gleiches Problem, gleiche Lösung wie bei WABS. */
function vote_pdf_text(string $s): string {
    return iconv('UTF-8', 'CP1252//TRANSLIT', $s);
}

/**
 * Inhalt für einen WLAN-Beitritts-QR-Code (Standardformat, von aktuellen
 * iOS-/Android-Kamera-Apps direkt als "Diesem WLAN beitreten" erkannt, ganz
 * ohne eigene App) - für einen dedizierten Versammlungs-Server ohne
 * Internetanbindung, siehe Vereinsvorgaben wlan_ssid/wlan_passwort. Nur
 * WPA/WPA2 (Standardfall) - die Sonderzeichen \;,," müssen laut Format mit
 * vorangestelltem Backslash maskiert werden, Backslash zuerst, damit keine
 * bereits eingefügte Maskierung erneut maskiert wird.
 */
function wlan_qr_inhalt(string $ssid, string $passwort): string {
    $maskieren = fn(string $s): string => str_replace(
        ['\\', ';', ',', '"'],
        ['\\\\', '\\;', '\\,', '\\"'],
        $s
    );
    return 'WIFI:T:WPA;S:' . $maskieren($ssid) . ';P:' . $maskieren($passwort) . ';;';
}

/** Bisher erzeugte Stimmkarten-PDFs, neueste zuerst (Dateiname genügt für den Download-Link, siehe abstimmung_pdf.php). */
function vote_pdf_liste(): array {
    $dateien = glob(vote_storage_dir() . '/stimmkarten-*.pdf') ?: [];
    rsort($dateien);
    return array_map('basename', $dateien);
}

/**
 * Wandelt einen enthaltenen Internationalen Domainnamen (IDN, z. B. Umlaute)
 * in seine ASCII-Form (Punycode, "xn--…") um - für den im Repository
 * eingecheckten Code gilt ansonsten nur reines ASCII als strikt gültige URL
 * (RFC 3986); ein QR-Code mit rohen Nicht-ASCII-Zeichen im Hostnamen wird
 * nicht von jeder Scanner-App/jedem Browser zuverlässig als Link erkannt -
 * mit Punycode funktioniert es überall gleich, genau wie wenn man die
 * Adresse selbst in die Adresszeile eines Browsers eintippt (der wandelt
 * beim Tippen genauso um). Ohne die PHP-Erweiterung "intl" (idn_to_ascii)
 * bleibt die Adresse unverändert - selten, aber kein Abbruch.
 */
function vote_url_punycode(string $url): string {
    if (!function_exists('idn_to_ascii')) return $url;
    $teile = parse_url($url);
    if (!$teile || empty($teile['host']) || mb_check_encoding($teile['host'], 'ASCII')) return $url;
    $ascii = idn_to_ascii($teile['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    if ($ascii === false) return $url;
    $port = isset($teile['port']) ? ':' . $teile['port'] : '';
    return $teile['scheme'] . '://' . $ascii . $port . ($teile['path'] ?? '');
}

/** Position (x, y in mm) der vier Stimmkarten auf einer A4-Seite (2x2-Raster). */
const VOTE_KARTEN_POSITIONEN = [[8, 8], [108, 8], [8, 152], [108, 152]];

/**
 * Breite/Höhe (mm), mit denen ein Logo in eine Stimmkarte eingebettet
 * werden sollte, damit es innerhalb von $maxBreite x $maxHoehe bleibt, aber
 * sein Seitenverhältnis behält. Gibt [0, 0] zurück, wenn die Bildmaße nicht
 * ermittelt werden konnten (z. B. keine echte Bilddatei).
 */
function vote_pdf_logo_groesse(string $datei, float $maxBreite, float $maxHoehe): array {
    $info = @getimagesize($datei);
    if (!$info || $info[0] <= 0 || $info[1] <= 0) {
        return [0, 0];
    }
    $breite = $maxBreite;
    $hoehe = $breite * $info[1] / $info[0];
    if ($hoehe > $maxHoehe) {
        $hoehe = $maxHoehe;
        $breite = $hoehe * $info[0] / $info[1];
    }
    return [$breite, $hoehe];
}

/**
 * Eine einzelne Stimmkarte an der angegebenen Position - Titelleiste (wie
 * im Beamerbild/Kopfbereich eingestellt, inkl. Vereinslogo, falls
 * hinterlegt), kleiner QR-Code, eine kurze Anleitung in gut lesbarer
 * Schrift (wie scannen/aktualisieren funktioniert, was die grüne
 * Bestätigungsseite bedeutet, Akku-Tipp) sowie der Link als Text zum
 * Abtippen, falls der QR-Code einmal nicht lesbar ist.
 *
 * $logoDatei: absoluter Dateipfad (nicht die rohe Web-URL aus
 * config.logo_pfad - siehe vote_stimmkarten_erzeugen()) zu einer von FPDF
 * unterstützten Bilddatei (JPG/PNG/GIF), oder '' ohne Logo.
 * $wlanQrFile: absoluter Dateipfad zu einem vorab erzeugten WLAN-Beitritts-
 * QR-Code (siehe wlan_qr_inhalt()), oder '' ohne WLAN-Hinweis - für einen
 * dedizierten Versammlungs-Server ohne Internetanbindung, bei dem sich die
 * Teilnehmenden erst mit dem lokalen WLAN verbinden müssen, bevor der
 * Stimmkarten-Code überhaupt erreichbar ist.
 */
function vote_pdf_karte(FPDF $pdf, float $x, float $y, string $qrFile, string $url, string $titel, string $logoDatei = '', string $wlanQrFile = ''): void {
    $breite = 94; $hoehe = 136;
    $pdf->Rect($x, $y, $breite, $hoehe);
    $innenX = $x + 4;
    $innenBreite = $breite - 8;
    $cursorY = $y + 4;

    if ($logoDatei !== '') {
        [$logoBreite, $logoHoehe] = vote_pdf_logo_groesse($logoDatei, 20, 11);
        if ($logoBreite > 0) {
            $pdf->Image($logoDatei, $x + ($breite - $logoBreite) / 2, $cursorY, $logoBreite, $logoHoehe);
            $cursorY += $logoHoehe + 2;
        }
    }

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY($innenX, $cursorY);
    $pdf->MultiCell($innenBreite, 4.3, vote_pdf_text($titel), 0, 'C');
    $cursorY = $pdf->GetY() + 1;

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->SetXY($x, $cursorY);
    $pdf->Cell($breite, 7, vote_pdf_text('Stimmkarte'), 0, 0, 'C');
    $cursorY += 9;

    if ($wlanQrFile !== '') {
        // Zwei QR-Codes nebeneinander statt einem großen, nummeriert, damit
        // die Reihenfolge eindeutig ist - ohne WLAN-Verbindung läuft der
        // Stimmkarten-Code bei einem rein lokalen Server sonst ins Leere.
        $qrGroesse = 30;
        $abstand = 6;
        $startX = $x + ($breite - (2 * $qrGroesse + $abstand)) / 2;
        $pdf->Image($wlanQrFile, $startX, $cursorY, $qrGroesse, $qrGroesse);
        $pdf->Image($qrFile, $startX + $qrGroesse + $abstand, $cursorY, $qrGroesse, $qrGroesse);
        $cursorY += $qrGroesse + 2;
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY($startX, $cursorY);
        $pdf->Cell($qrGroesse, 4, vote_pdf_text('① WLAN'), 0, 0, 'C');
        $pdf->SetXY($startX + $qrGroesse + $abstand, $cursorY);
        $pdf->Cell($qrGroesse, 4, vote_pdf_text('② Scannen'), 0, 0, 'C');
        $cursorY += 7;
    } else {
        $qrGroesse = 40;
        $pdf->Image($qrFile, $x + ($breite - $qrGroesse) / 2, $cursorY, $qrGroesse, $qrGroesse);
        $cursorY += $qrGroesse + 4;
    }

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($innenX, $cursorY);
    $pdf->MultiCell($innenBreite, 4.2, vote_pdf_text(
        ($wlanQrFile !== ''
            ? "- Erst mit dem WLAN-Code ① verbinden (kein Internet nötig), dann den Stimmkarten-Code ② scannen.\n"
            : "- QR-Code mit der Kamera-App des Smartphones scannen.\n")
        . "- Bitte nicht weitergeben: diese Karte gilt für eine Stimme.\n"
        . "- Bei vorzeitigem Verlassen der Versammlung bitte am Eingang abgeben.\n"
        . "- Ohne laufende Abstimmung zeigt die Seite dasselbe Bild wie die Leinwand - bei Bedarf einfach neu laden.\n"
        . "- Grüne Seite = die Stimme wurde gezählt.\n"
        . "- Tipp: Bei längeren Diskussionen das Display ausschalten, um den Akku zu schonen."
    ), 0, 'L');
    $cursorY = $pdf->GetY() + 1;

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY($innenX, $cursorY);
    $pdf->MultiCell($innenBreite, 3.4, vote_pdf_text("Falls der QR-Code nicht scanbar ist:\n" . $url), 0, 'C');
}

/**
 * Erzeugt $anzahl neue Stimmkarten und eine PDF-Sammeldatei mit je vier
 * Karten pro DIN-A4-Seite zum Ausdrucken und Zerschneiden (QR-Code auf den
 * Link zur Abstimmungsseite, dazu der Link als Text zum Abtippen, falls der
 * QR-Code einmal nicht lesbar ist). Gibt [Dateiname, Fehler] zurück.
 *
 * $titel: Titelleiste der Karte - wie im Beamerbild/Kopfbereich eingestellt
 * (bei VERA z. B. "Vereinsname – MV-Bezeichnung Jahr", bei BEAM der
 * Projekttitel; siehe jeweiligen Aufrufer).
 * $logoPfad: derselbe Wert wie config.logo_pfad - eine relativ zu diesem
 * Verzeichnis (neu/) aufzulösende Web-URL, kein fertiger Dateipfad. Wird
 * nur eingebettet, wenn die Datei existiert UND eine von FPDF unterstützte
 * Bildart ist (JPG/PNG/GIF - kein SVG); sonst bleibt die Karte ohne Logo,
 * statt die ganze Erzeugung fehlschlagen zu lassen. '' = kein Logo.
 */
function vote_stimmkarten_erzeugen(PDO $pdo, int $jahr, int $anzahl, string $basisUrl, string $titel, string $logoPfad = '', string $wlanSsid = '', string $wlanPasswort = ''): array {
    if ($anzahl < 1 || $anzahl > 2000) return ['', 'Bitte eine Anzahl zwischen 1 und 2000 angeben.'];
    $basisUrl = rtrim(trim($basisUrl), '/');
    if ($basisUrl === '' || !preg_match('~^https?://~i', $basisUrl)) {
        return ['', 'Bitte zuerst unter „Vereinsspezifische Vorgaben“ die Basis-URL der Abstimmungsmaschine eintragen (http:// oder https://).'];
    }
    $basisUrl = vote_url_punycode($basisUrl);
    if ($fehler = vote_pdf_libs_fehlen()) return ['', $fehler];

    $fpdf_dir = vote_lib_verzeichnis_suchen(__DIR__, 'fpdf', 'fpdf.php');
    $qrcode_dir = vote_lib_verzeichnis_suchen(__DIR__, 'phpqrcode', 'qrlib.php');
    // Auch phpqrcode selbst wirft beim Einbinden auf PHP 8.x einige
    // Parameter-Reihenfolge-Deprecations (Fremdbibliothek, siehe Hinweis
    // weiter unten bei QRcode::png()) - hier aus demselben Grund mit
    // unterdrueckt.
    $reporting_vorher = error_reporting(E_ALL & ~E_DEPRECATED);
    require_once $fpdf_dir . '/fpdf.php';
    require_once $qrcode_dir . '/qrlib.php';
    error_reporting($reporting_vorher);

    $logoDatei = '';
    $logoPfad = trim($logoPfad);
    if ($logoPfad !== '' && preg_match('/\.(jpe?g|png|gif)$/i', $logoPfad)) {
        $kandidat = __DIR__ . '/' . ltrim($logoPfad, '/');
        if (is_file($kandidat)) {
            $logoDatei = $kandidat;
        }
    }

    // WLAN-QR-Code (falls Vereinsvorgabe gesetzt) ist für jede Karte
    // identisch - nur einmal erzeugen, nicht pro Karte wie den individuellen
    // Stimmkarten-QR-Code.
    $wlanQrFile = '';
    $dir = vote_storage_dir();
    if ($wlanSsid !== '') {
        $wlanQrFile = $dir . '/tmp-wlan-' . bin2hex(random_bytes(8)) . '.png';
        $reporting_vorher = error_reporting(E_ALL & ~E_DEPRECATED);
        QRcode::png(wlan_qr_inhalt($wlanSsid, $wlanPasswort), $wlanQrFile, QR_ECLEVEL_M, 4, 2);
        error_reporting($reporting_vorher);
    }

    $raw = vote_tokens_erzeugen($pdo, $jahr, $anzahl);
    $pdf = new FPDF();
    foreach (array_chunk($raw, 4) as $gruppe) {
        $pdf->AddPage();
        foreach ($gruppe as $i => $t) {
            $url = $basisUrl . '/abstimmen.php?token=' . $t;
            // Eigener Dateiname je Karte, nicht wiederverwendet: FPDF cacht
            // eingebettete Bilder intern anhand des Dateinamens (siehe
            // Image() in fpdf.php: "if (!isset($this->images[$file]))") und
            // liest eine bereits einmal verwendete Datei nicht erneut ein,
            // selbst wenn ihr Inhalt sich geändert hat - bei einem
            // wiederverwendeten Pfad wären ab der zweiten Karte überall
            // derselbe (der erste) QR-Code eingebettet gewesen, obwohl der
            // Text darunter korrekt den jeweils neuen Token zeigte. Datei
            // direkt nach dem Einbetten löschen (Image() liest den Inhalt
            // sofort vollständig ein, braucht die Datei danach nicht mehr).
            $tmpQr = $dir . '/tmp-' . bin2hex(random_bytes(8)) . '.png';
            // phpqrcode (Fremdbibliothek, nicht Teil dieses Repos) erzeugt
            // unter PHP 8.x eine "Creation of dynamic property"-Notice
            // (QRencode::$cmyk, siehe qrencode.php) - bei error_reporting(E_ALL)
            // (config.php) würde die vor der eigentlichen PDF-Ausgabe im
            // Output landen. Bewusst nur um genau diesen Aufruf unterdrückt,
            // nicht global - der Rest der Anwendung bleibt bei E_ALL.
            $reporting_vorher = error_reporting(E_ALL & ~E_DEPRECATED);
            QRcode::png($url, $tmpQr, QR_ECLEVEL_M, 4, 2);
            error_reporting($reporting_vorher);
            [$x, $y] = VOTE_KARTEN_POSITIONEN[$i];
            vote_pdf_karte($pdf, $x, $y, $tmpQr, $url, $titel, $logoDatei, $wlanQrFile);
            @unlink($tmpQr);
        }
    }
    if ($wlanQrFile !== '') {
        @unlink($wlanQrFile);
    }
    // Kontext im Dateinamen (seit BEAM-Vereinheitlichung) - damit
    // vote_tokens_loeschen() beim Löschen eines Stimmkarten-Pools gezielt
    // nur dessen eigene PDF-Sammeldateien mitlöschen kann, nicht die eines
    // anderen VERA-Jahres/BEAM-Projekts (derselbe Speicherort wird von allen
    // Kontexten gemeinsam genutzt, siehe vote_storage_dir()).
    $filename = 'stimmkarten-' . $jahr . '-' . date('Ymd-His') . '.pdf';
    $pdf->Output($dir . '/' . $filename, 'F');
    return [$filename, ''];
}

// ============================================
// PHASE 2: ABSTIMMUNGSRUNDE DURCHFÜHREN
// ============================================

const VOTE_VOTEN = ['ja', 'nein', 'enthaltung'];

function vote_rounds_tabelle_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_vote_rounds (
            round_id INT AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(12) NOT NULL DEFAULT 'offen',
            frage_text MEDIUMTEXT,
            started_at DATETIME NOT NULL,
            closed_at DATETIME DEFAULT NULL,
            ja_anzahl INT DEFAULT NULL,
            nein_anzahl INT DEFAULT NULL,
            enthaltung_anzahl INT DEFAULT NULL,
            csv_datei VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Nachtraeglich ergaenzte Spalten (Phase 4/5) - siehe spalte_existiert() in
    // db_functions.php: Installationen, die diese Tabelle schon vor Phase 4/5
    // angelegt haben (z. B. durch Ausprobieren von Phase 2/3), bekommen sie
    // erst hier ergaenzt - CREATE TABLE IF NOT EXISTS legt neue Spalten einer
    // bereits bestehenden Tabelle nicht automatisch nach.
    foreach ([
        ['ja_anzahl', 'INT DEFAULT NULL'],
        ['nein_anzahl', 'INT DEFAULT NULL'],
        ['enthaltung_anzahl', 'INT DEFAULT NULL'],
        ['csv_datei', 'VARCHAR(255) DEFAULT NULL'],
        // Phase 6 (Wahlen, siehe wahl_functions.php) - gesetzt, wenn diese
        // Runde eine Wahl statt einer einfachen Ja/Nein/Enthaltung-
        // Abstimmung ist. NULL bleibt das bisherige Verhalten unverändert.
        ['wahlgang_id', 'INT DEFAULT NULL'],
        // BEAM-Vereinheitlichung (siehe dokumentation/BEAM.md) - derselbe
        // Kontextwert wie bei mv_vote_tokens.jahr. Bestehende Zeilen bekommen
        // 0 (kein bekannter Kontext).
        ['jahr', 'INT NOT NULL DEFAULT 0'],
    ] as [$spalte, $ddl]) {
        if (!spalte_existiert($pdo, 'mv_vote_rounds', $spalte)) {
            try {
                $pdo->exec("ALTER TABLE mv_vote_rounds ADD COLUMN $spalte $ddl");
            } catch (PDOException $e) {
                // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
            }
        }
    }
    // Siehe dieselbe Nachziehung in vote_tokens_tabelle_sicherstellen() -
    // gleicher Grund, gleiche Einmaligkeit.
    if ((int)$pdo->query('SELECT COUNT(*) FROM mv_vote_rounds WHERE jahr = 0')->fetchColumn() > 0) {
        $pdo->prepare('UPDATE mv_vote_rounds SET jahr = ? WHERE jahr = 0')->execute([get_aktuelles_jahr($pdo)]);
    }
    $geprueft = true;
}

function vote_ballots_tabelle_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_vote_ballots (
            ballot_id INT AUTO_INCREMENT PRIMARY KEY,
            round_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            votum VARCHAR(12) NOT NULL,
            voted_at DATETIME NOT NULL,
            dauer_ms INT DEFAULT NULL,
            UNIQUE KEY idx_round_token (round_id, token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Nachtraeglich ergaenzte Spalte (Phase 3) - siehe Begruendung oben bei
    // vote_rounds_tabelle_sicherstellen().
    if (!spalte_existiert($pdo, 'mv_vote_ballots', 'dauer_ms')) {
        try {
            $pdo->exec('ALTER TABLE mv_vote_ballots ADD COLUMN dauer_ms INT DEFAULT NULL');
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    $geprueft = true;
}

/** Die aktuell offene Abstimmungsrunde dieses Kontexts, falls es eine gibt - es läuft immer nur eine zur Zeit. */
function vote_round_aktuell(PDO $pdo, int $jahr): ?array {
    vote_rounds_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare("SELECT * FROM mv_vote_rounds WHERE status = 'offen' AND jahr = ? ORDER BY round_id DESC LIMIT 1");
    $stmt->execute([$jahr]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** Eine beendete, aber noch nicht ausgewertete Runde dieses Kontexts, falls es eine gibt (siehe vote_round_auswerten()). */
function vote_round_wartet_auf_auswertung(PDO $pdo, int $jahr): ?array {
    vote_rounds_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare("SELECT * FROM mv_vote_rounds WHERE status = 'geschlossen' AND jahr = ? ORDER BY round_id DESC LIMIT 1");
    $stmt->execute([$jahr]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function vote_round_get(PDO $pdo, int $roundId): ?array {
    vote_rounds_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM mv_vote_rounds WHERE round_id = ?');
    $stmt->execute([$roundId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Startet eine neue Abstimmungsrunde mit einer Momentaufnahme des aktuell
 * gezeigten Beamerbilds als Fragetext (siehe get_mvtexte()['beamer']). Gibt
 * [round_id|null, Fehler] zurück.
 *
 * $wahlgangId: gesetzt, wenn diese Runde eine Wahl ist (siehe
 * wahl_functions.php, Phase 6) - bleibt sie null (Vorgabe), ist es wie
 * bisher eine einfache Ja/Nein/Enthaltung-Abstimmung.
 */
function vote_round_starten(PDO $pdo, int $jahr, string $frageText, ?int $wahlgangId = null): array {
    if (vote_round_aktuell($pdo, $jahr)) return [null, 'Es läuft bereits eine Abstimmung - bitte diese zuerst beenden.'];
    if (vote_round_wartet_auf_auswertung($pdo, $jahr)) return [null, 'Bitte zuerst die letzte Abstimmung auswerten.'];
    if (trim($frageText) === '') return [null, 'Das Beamerbild ist aktuell leer - bitte zuerst über die Beamersteuerung den abzustimmenden Antrag anzeigen.'];
    $stmt = $pdo->prepare("INSERT INTO mv_vote_rounds (status, frage_text, started_at, wahlgang_id, jahr) VALUES ('offen', ?, NOW(), ?, ?)");
    $stmt->execute([$frageText, $wahlgangId, $jahr]);
    return [(int)$pdo->lastInsertId(), ''];
}

/** Beendet die laufende Abstimmungsrunde - danach sind keine weiteren Stimmen mehr möglich (siehe vote_abstimmen()). */
function vote_round_beenden(PDO $pdo, int $roundId): string {
    $round = vote_round_get($pdo, $roundId);
    if (!$round) return 'Abstimmungsrunde nicht gefunden.';
    if ($round['status'] !== 'offen') return 'Diese Abstimmungsrunde ist nicht mehr offen.';
    $pdo->prepare("UPDATE mv_vote_rounds SET status = 'geschlossen', closed_at = NOW() WHERE round_id = ?")->execute([$roundId]);
    // Phase 5: Die Ausgabe-Haekchen dieser Runde (wer hat schon einen
    // Online-Zugang bekommen, siehe online_token_ausgeben()) werden nicht
    // mehr gebraucht - fuer eine geschlossene Runde kann ohnehin niemand
    // mehr einen neuen Zugang anfordern (vote_round_aktuell() liefert nur
    // offene Runden). WICHTIG: die Rundenbindung selbst
    // (mv_online_token_bindung) bleibt bewusst stehen und wird NICHT
    // geloescht - sie enthaelt nur Token-Hash+Runde, keine MNr, ist also
    // anonymitaetsunbedenklich, verhindert aber dauerhaft, dass ein nach
    // Rundenende abgefangener Online-Token in einer spaeteren Runde
    // "unrestricted" (wie eine physische Stimmkarte) weiterverwendet
    // werden koennte - siehe online_token_fuer_runde_gueltig(). Try/catch,
    // falls diese Tabelle (z. B. bei einer Installation ohne
    // Online-Teilnahme) noch nie angelegt wurde.
    try {
        $pdo->prepare('DELETE FROM mv_online_ausgaben WHERE round_id = ?')->execute([$roundId]);
    } catch (PDOException $e) {
        // unkritisch - siehe Kommentar oben.
    }
    return '';
}

/** Hat diese Stimmkarte in dieser Runde schon abgestimmt? Serverseitig geprüft, unabhängig vom Browserzustand - ein Neuladen zeigt zuverlässig dasselbe Ergebnis. */
function vote_hat_schon_abgestimmt(PDO $pdo, int $roundId, string $token): bool {
    vote_ballots_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM mv_vote_ballots WHERE round_id = ? AND token_hash = ?');
    $stmt->execute([$roundId, hash('sha256', $token)]);
    return $stmt->fetch() !== false;
}

/**
 * Gibt eine Stimme ab. Bereits gezählte Stimmen (gleicher Token, gleiche
 * Runde - z. B. durch erneutes Absenden nach einem Timeout, oder ein
 * seltener Gleichzeitigkeits-Fall zwischen Prüfung und Einfügen) gelten als
 * weiterhin erfolgreich, nicht als Fehler - genau das macht "erneut
 * absenden, bis der grüne Bildschirm kommt" robust, ohne eine Stimme
 * doppelt zu zählen (UNIQUE(round_id, token_hash) verhindert das ohnehin).
 *
 * $anfrageStart: microtime(true) vom Beginn der HTTP-Anfrage (üblicherweise
 * $_SERVER['REQUEST_TIME_FLOAT']), wird als dauer_ms mitgespeichert - bewusst
 * ab Anfragebeginn statt nur um den INSERT herum, damit eine etwaige
 * Warteschlange im Webserver/PHP-Prozess (das eigentliche Lastsymptom) mit
 * erfasst wird, nicht nur die (fast immer sehr kurze) reine Schreibzeit der
 * Datenbank. Siehe vote_round_lastschwaeche() für die Auswertung. Optional
 * (null = keine Zeitmessung, z. B. in Tests).
 *
 * Gibt [ok, Fehler] zurück.
 */
function vote_abstimmen(PDO $pdo, int $roundId, string $token, string $votum, ?float $anfrageStart = null): array {
    if (!in_array($votum, VOTE_VOTEN, true)) return [false, 'Bitte Ja, Nein oder Enthaltung wählen.'];
    $round = vote_round_get($pdo, $roundId);
    if (!$round || $round['status'] !== 'offen') return [false, 'Diese Abstimmung ist nicht mehr aktiv.'];
    if (!vote_token_gueltig($pdo, $token, (int)$round['jahr'])) return [false, 'Diese Stimmkarte ist nicht gültig.'];
    if (vote_hat_schon_abgestimmt($pdo, $roundId, $token)) return [true, ''];
    $dauerMs = $anfrageStart !== null ? (int)round((microtime(true) - $anfrageStart) * 1000) : null;
    try {
        $pdo->prepare('INSERT INTO mv_vote_ballots (round_id, token_hash, votum, voted_at, dauer_ms) VALUES (?,?,?,NOW(),?)')
            ->execute([$roundId, hash('sha256', $token), $votum, $dauerMs]);
        return [true, ''];
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000) return [true, '']; // Gleichzeitigkeits-Fall: schon gezählt, trotzdem Erfolg
        return [false, 'Die Stimme konnte nicht gespeichert werden - bitte erneut absenden.'];
    }
}

/** Zahl der in dieser Runde bisher abgegebenen Stimmen (für den Live-Zähler in Phase 3). */
function vote_round_zaehler(PDO $pdo, int $roundId): int {
    vote_ballots_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mv_vote_ballots WHERE round_id = ?');
    $stmt->execute([$roundId]);
    return (int)$stmt->fetchColumn();
}

/** Ab dieser mittleren Antwortzeit (ms) der letzten Stimmen gilt die Runde als auffällig verzögert (siehe vote_round_lastschwaeche()). */
const VOTE_LAST_SCHWELLE_MS = 800;

/**
 * Einfache Lasterkennung für den Operator-Bildschirm: mittlere Antwortzeit
 * der letzten 20 Stimmen dieser Runde (siehe dauer_ms in vote_abstimmen()).
 * Gibt null zurück, wenn noch keine aussagekräftige Zahl an Stimmen
 * vorliegt. Kein Ersatz für echtes Monitoring, nur ein grober Hinweis, der
 * dem Operator sagt "lieber noch etwas Zeit lassen, bevor beendet wird".
 */
function vote_round_lastschwaeche(PDO $pdo, int $roundId): ?array {
    vote_ballots_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT dauer_ms FROM mv_vote_ballots WHERE round_id = ? AND dauer_ms IS NOT NULL ORDER BY ballot_id DESC LIMIT 20');
    $stmt->execute([$roundId]);
    $werte = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($werte) < 5) return null;
    $mittel = (int)round(array_sum($werte) / count($werte));
    return ['mittel_ms' => $mittel, 'auffaellig' => $mittel >= VOTE_LAST_SCHWELLE_MS];
}

// ============================================
// PHASE 4: BEENDEN UND AUSWERTEN
// PHASE 5: PROTOKOLL UND AUFRÄUMEN
// ============================================

/** Ja/Nein/Enthaltung-Rohzahlen dieser Runde (vor dem Löschen in vote_round_auswerten() aufrufen, danach sind die Einzelstimmen weg). */
function vote_round_zaehlung(PDO $pdo, int $roundId): array {
    vote_ballots_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT votum, COUNT(*) n FROM mv_vote_ballots WHERE round_id = ? GROUP BY votum');
    $stmt->execute([$roundId]);
    $out = ['ja' => 0, 'nein' => 0, 'enthaltung' => 0];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['votum']] = (int)$r['n'];
    }
    return $out;
}

/**
 * Prozentsätze aus Ja/Nein/Enthaltung-Rohzahlen: bezogen auf die Gesamtzahl
 * (inkl. Enthaltungen) UND bezogen auf Ja+Nein ohne Enthaltungen (da
 * Enthaltungen bei der Mehrheitsfeststellung meist nicht mitzählen) - beide
 * Sichtweisen werden angezeigt, siehe dokumentation/Abstimmungsmaschine.md.
 */
function vote_round_ergebnis(array $zaehlung): array {
    $ja = $zaehlung['ja'] ?? 0;
    $nein = $zaehlung['nein'] ?? 0;
    $enthaltung = $zaehlung['enthaltung'] ?? 0;
    $gesamt = $ja + $nein + $enthaltung;
    $jaNein = $ja + $nein;
    $pct = fn(int $teil, int $basis) => $basis > 0 ? round($teil / $basis * 100, 1) : 0.0;
    return [
        'ja' => $ja, 'nein' => $nein, 'enthaltung' => $enthaltung, 'gesamt' => $gesamt,
        'ja_pct_gesamt' => $pct($ja, $gesamt), 'nein_pct_gesamt' => $pct($nein, $gesamt), 'enthaltung_pct_gesamt' => $pct($enthaltung, $gesamt),
        'ja_pct_janein' => $pct($ja, $jaNein), 'nein_pct_janein' => $pct($nein, $jaNein),
    ];
}

/** Ergebnis einer bereits ausgewerteten Runde, aus den dauerhaft gespeicherten Zahlen (die Einzelstimmen sind dann schon gelöscht). */
function vote_round_gespeichertes_ergebnis(array $round): array {
    return vote_round_ergebnis(['ja' => (int)$round['ja_anzahl'], 'nein' => (int)$round['nein_anzahl'], 'enthaltung' => (int)$round['enthaltung_anzahl']]);
}

/**
 * Die zwei Anzeigezeilen für Beamer/Operator-Bildschirm - an einer Stelle
 * formatiert, damit beide Anzeigeorte garantiert gleich aussehen. Ob
 * Enthaltungen bei der Prozentrechnung mitzählen, ist je nach Satzung
 * unterschiedlich geregelt (Vereinsvorgabe "enthaltung_zaehlt_mit", siehe
 * tab_vorgaben.php) - unangekreuzt (Vorgabe) zeigt die zweite Zeile nur
 * Ja/Nein-Prozente (Basis Ja+Nein ohne Enthaltungen), angekreuzt alle drei
 * (Basis Gesamtzahl inkl. Enthaltungen). Reine Zahlen, kein Nutzereingabe-
 * Inhalt - unbedenklich direkt auszugeben.
 */
function vote_ergebnis_zeilen(array $ergebnis, bool $enthaltungZaehltMit): array {
    $zeile1 = 'Ja: ' . $ergebnis['ja'] . ' - Nein: ' . $ergebnis['nein'] . ' - Enth.: ' . $ergebnis['enthaltung'];
    $zeile2 = $enthaltungZaehltMit
        ? 'Ja: ' . $ergebnis['ja_pct_gesamt'] . '% - Nein: ' . $ergebnis['nein_pct_gesamt'] . '% - Enth.: ' . $ergebnis['enthaltung_pct_gesamt'] . '%'
        : 'Ja: ' . $ergebnis['ja_pct_janein'] . '% - Nein: ' . $ergebnis['nein_pct_janein'] . '%';
    return [$zeile1, $zeile2];
}

/**
 * Schreibt das CSV-Protokoll dieser Runde: Fragetext als Kommentarzeile,
 * danach je eine Zeile pro Stimme (Token-Hash, Zeitpunkt, Votum - kein
 * Mitgliedsbezug, den kennt das System ja ohnehin nie). Gibt
 * [Dateiname, Fehler] zurück; die Datei wird erst als Erfolg gewertet, wenn
 * sie tatsächlich lesbar und nicht leer auf der Festplatte liegt (siehe
 * vote_round_auswerten() - erst danach werden die Einzelstimmen gelöscht).
 */
function vote_round_csv_erzeugen(PDO $pdo, int $roundId): array {
    $round = vote_round_get($pdo, $roundId);
    if (!$round) return ['', 'Abstimmungsrunde nicht gefunden.'];
    $dir = vote_storage_dir();
    $filename = 'abstimmung-' . $roundId . '-' . date('Ymd-His') . '.csv';
    $pfad = $dir . '/' . $filename;
    $fh = fopen($pfad, 'w');
    if (!$fh) return ['', 'Die Protokolldatei konnte nicht angelegt werden.'];
    $frage = trim(str_replace(["\r", "\n"], ' ', strip_tags((string)$round['frage_text'])));
    fwrite($fh, '# Frage: ' . str_replace(';', ',', $frage) . "\n");
    fwrite($fh, "token_hash;zeitpunkt;votum\n");
    $stmt = $pdo->prepare('SELECT token_hash, voted_at, votum FROM mv_vote_ballots WHERE round_id = ? ORDER BY ballot_id');
    $stmt->execute([$roundId]);
    foreach ($stmt as $zeile) {
        fwrite($fh, $zeile['token_hash'] . ';' . $zeile['voted_at'] . ';' . $zeile['votum'] . "\n");
    }
    fclose($fh);
    clearstatcache(true, $pfad);
    if (!is_file($pfad) || filesize($pfad) === 0) return ['', 'Die Protokolldatei scheint leer oder fehlerhaft zu sein - bitte erneut versuchen.'];
    return [$filename, ''];
}

/** Bisher erzeugte Protokoll-CSVs, neueste zuerst (siehe abstimmung_csv.php für den Download). */
function vote_csv_liste(): array {
    $dateien = glob(vote_storage_dir() . '/abstimmung-*.csv') ?: [];
    rsort($dateien);
    return array_map('basename', $dateien);
}

/**
 * Wertet eine beendete Runde aus: schreibt zuerst zuverlässig das
 * CSV-Protokoll (vote_round_csv_erzeugen()), speichert das Ergebnis
 * dauerhaft in mv_vote_rounds (Status "ausgewertet") und löscht erst DANACH
 * die Einzelstimmen dieser Runde (mv_vote_ballots) - der Stimmkarten-Pool
 * (mv_vote_tokens) bleibt für die nächste Runde unverändert bestehen.
 * Schlägt das Schreiben der CSV-Datei fehl, wird nichts geändert oder
 * gelöscht. Gibt [Ergebnis-Array|null, Fehler] zurück.
 */
function vote_round_auswerten(PDO $pdo, int $roundId): array {
    $round = vote_round_get($pdo, $roundId);
    if (!$round) return [null, 'Abstimmungsrunde nicht gefunden.'];
    if ($round['status'] !== 'geschlossen') return [null, 'Diese Abstimmungsrunde ist nicht abgeschlossen (erst "Abstimmung beenden").'];
    if (!empty($round['wahlgang_id'])) return [null, 'Dies ist eine Wahl - bitte über wahl_round_auswerten() auswerten (siehe wahl_functions.php).'];

    $ergebnis = vote_round_ergebnis(vote_round_zaehlung($pdo, $roundId));
    [$csvDatei, $fehler] = vote_round_csv_erzeugen($pdo, $roundId);
    if ($fehler !== '') return [null, $fehler];

    $pdo->prepare("UPDATE mv_vote_rounds SET status = 'ausgewertet', ja_anzahl = ?, nein_anzahl = ?, enthaltung_anzahl = ?, csv_datei = ? WHERE round_id = ?")
        ->execute([$ergebnis['ja'], $ergebnis['nein'], $ergebnis['enthaltung'], $csvDatei, $roundId]);
    $pdo->prepare('DELETE FROM mv_vote_ballots WHERE round_id = ?')->execute([$roundId]);

    return [$ergebnis, ''];
}

// ============================================
// Phase 5: Online-Abstimmungstoken (siehe online_abstimmen.php) - Mitglieder
// mit Teilnahmeform "online" bekommen keine gedruckte Stimmkarte, sondern
// einen frisch erzeugten Token pro Abstimmungsrunde. Zieht den Rohwert aus
// demselben anonymen Pool wie physische Stimmkarten (vote_tokens_erzeugen())
// - der Server merkt sich dabei nur ein Haekchen "diese MNr hat fuer diese
// Runde schon einen Zugang bekommen" (mv_online_ausgaben), nie eine
// dauerhafte Mitglied-Token-Zuordnung. Zusaetzlich wird der Token ueber
// mv_online_token_bindung an GENAU diese Runde gebunden (siehe
// online_token_fuer_runde_gueltig(), von abstimmen.php geprueft) - anders
// als eine physische Stimmkarte (die absichtlich den ganzen Tag ueber alle
// Runden gueltig bleibt) soll ein abgefangener Online-Token nicht auch noch
// fuer spaetere Runden missbraucht werden koennen. mv_online_ausgaben wird
// in vote_round_beenden() fuer die jeweilige Runde wieder geloescht (reine
// Ausgabe-Buchfuehrung, nicht mehr gebraucht); mv_online_token_bindung
// bleibt dagegen bestehen (enthaelt nur Token-Hash+Runde, keine MNr - siehe
// Kommentar dort), sonst wuerde die Rundenbindung nach Rundenende genau
// aufgehoben, die sie eigentlich dauerhaft verhindern soll.
// ============================================

function online_tabellen_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_online_ausgaben (
            round_id INT NOT NULL,
            MNr VARCHAR(12) NOT NULL,
            erstellt DATETIME NOT NULL,
            PRIMARY KEY (round_id, MNr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_online_token_bindung (
            token_hash VARCHAR(64) PRIMARY KEY,
            round_id INT NOT NULL,
            erstellt DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $geprueft = true;
}

/** Wurde fuer diese Runde/MNr bereits ein Online-Token ausgegeben? */
function online_bereits_ausgegeben(PDO $pdo, int $roundId, string $mnr): bool {
    online_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM mv_online_ausgaben WHERE round_id = ? AND MNr = ?');
    $stmt->execute([$roundId, $mnr]);
    return $stmt->fetch() !== false;
}

/**
 * Gibt fuer ein Online-Mitglied einen frischen, an GENAU diese Runde
 * gebundenen Abstimmungstoken aus - oder null, wenn fuer diese Runde
 * bereits einer ausgegeben wurde (verhindert mehrere Zugaenge pro Runde
 * und Mitglied, z. B. durch gleichzeitiges Anfordern auf zwei Geraeten -
 * siehe UNIQUE-Constraint auf mv_online_ausgaben).
 */
function online_token_ausgeben(PDO $pdo, int $jahr, int $roundId, string $mnr): ?string {
    online_tabellen_sicherstellen($pdo);
    try {
        $pdo->prepare('INSERT INTO mv_online_ausgaben (round_id, MNr, erstellt) VALUES (?, ?, NOW())')
            ->execute([$roundId, $mnr]);
    } catch (PDOException $e) {
        return null; // Primary-Key-Verletzung: fuer diese Runde/MNr bereits ausgegeben.
    }
    $token = vote_tokens_erzeugen($pdo, $jahr, 1)[0];
    $pdo->prepare('INSERT INTO mv_online_token_bindung (token_hash, round_id, erstellt) VALUES (?, ?, NOW())')
        ->execute([hash('sha256', $token), $roundId]);
    return $token;
}

/**
 * Ist dieser Token (falls es ein Online-Token ist) fuer GENAU diese Runde
 * gebunden? true auch fuer jeden Token OHNE Bindungseintrag (physische
 * Stimmkarten) - die Einschraenkung gilt ausschliesslich fuer online
 * ausgegebene Token, siehe Dateikopf-Kommentar oben.
 */
function online_token_fuer_runde_gueltig(PDO $pdo, string $token, int $roundId): bool {
    online_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT round_id FROM mv_online_token_bindung WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if ($row === false) {
        return true;
    }
    return (int)$row['round_id'] === $roundId;
}
