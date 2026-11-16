<?php
/**
 * antraege_functions.php - Datenzugriff fuer die Kernprozesse 01-03 der
 * Antragsverwaltung (Hauptantrag / Aenderungsantrag / Verfahrensantrag
 * stellen), siehe Prozesskatalog "Kernprozesse Antragsverwaltung".
 *
 * Nutzt bewusst dieselbe Tabelle mvantraege_<jahr> wie das Bestandssystem
 * (Spalten Art/Kat/Nr/Zu/Ast/Titeltext/Text/Begr/Fin/Datum/Ergebnis) -
 * keine neue Datenstruktur, nur saubere PDO-Zugriffe statt String-
 * verkettung.
 */

require_once __DIR__ . '/jahr_functions.php'; // fuer jahr_docs_verzeichnis()/docs_verzeichnis_anlegen() (siehe antrag_pdf_speichern())

const ANTRAG_KATEGORIEN = [
    1 => ['praefix' => 'S', 'label' => 'Satzungsantrag'],
    2 => ['praefix' => 'O', 'label' => 'Antrag zu einer Ordnung'],
    3 => ['praefix' => 'A', 'label' => 'Sachantrag'],
];

const ANTRAG_KAT_VERFAHREN = 4; // Kategorie fuer eigenstaendige Verfahrensantraege
const ANTRAG_PRAEFIX_VERFAHREN = 'V';

const ANTRAG_ART_HAUPTANTRAG = 1;
const ANTRAG_ART_AENDERUNG = 2;
const ANTRAG_ART_VERFAHREN = 3;
// Archiviert: zurückgezogene Anträge aller Typen (Nr unveraendert), sowie -
// mit Nr-Suffix "o"/"x" - die beim Übernehmen eines Änderungsantrags
// entstehenden Archivfassungen (Ursprungsantrag bei Antragsschluss /
// übernommener Änderungsantrag, siehe weiter unten). Erscheint in keiner
// normalen Liste (WHERE Art=1 bzw. Art IN (2,3) filtert das automatisch
// heraus), nur auf der Beamerliste (siehe beamer_functions.php).
const ANTRAG_ART_ARCHIVIERT = 4;
// Hauptantrag hat nach Ablauf der Unterstützungsfrist nicht die
// erforderliche Zahl an Unterstützungen erreicht (siehe Kompaktliste).
const ANTRAG_ART_QUORUM_NICHT_ERREICHT = 5;

/** Vordefinierte Textvorlagen fuer haeufige Verfahrensantraege (GO-Antraege). */
const GO_ANTRAEGE = [
    'Vertagung der Versammlung',
    'Absetzen des Verhandlungsgegenstandes von der Tagesordnung',
    'Übergang zur Tagesordnung',
    'Nichtbefassung mit einem Antrag',
    'Vertagung eines Verhandlungsgegenstandes',
    'Sitzungsunterbrechung',
    'Schluss der Debatte bzw. Verzicht auf Aussprache',
    'Schluss der Rednerliste',
    'Begrenzung der Redezeit',
    'Verbindung der Beratung',
    'Besondere Form der Abstimmung',
    '(Wiederholung der) Auszählung der Stimmen',
];

function antrag_tabelle(int $jahr): string {
    return 'mvantraege_' . $jahr;
}

/**
 * Stellt die Tabelle mvantraege_<jahr> sicher: legt sie bei Bedarf per
 * CREATE TABLE IF NOT EXISTS mit den Bestandsspalten an (bei Mensa immer
 * ein No-Op, da dort laengst vorhanden) und ergaenzt die von der
 * Neukonzeption zusaetzlich genutzten Spalten additiv per ADD COLUMN IF
 * NOT EXISTS, damit keine manuelle Migration noetig ist.
 *
 * Gem: echte Bestandsspalte, im Altsystem nur per Hand in der DB gepflegt
 * (siehe mvliste.php: "grau hinterlegte werden voraussichtlich gemeinsam
 * beraten") - festgelegt unter Admin-Funktionen (siehe tab_reihenfolge.php),
 * nicht in der Kompaktliste.
 * Rfolge: ebenfalls Bestandsspalte, die vorgeschlagene Behandlungsreihenfolge
 * der Hauptanträge - dort ebenfalls festgelegt.
 * Forumlink: Slug fuer den Diskussionsforums-Thread eines Hauptantrags
 * (siehe antrag_forumslink_generieren()).
 * Uebernommen: markiert einen Änderungsantrag, den die/der Antragsteller:in
 * des Hauptantrags erklaertermaßen übernommen hat, aber den die Redaktion
 * noch nicht final in den Hauptantragstext eingearbeitet hat (siehe
 * Prozess "Übernahme eines Änderungsantrags" weiter unten).
 * Ergebnis/Ja/Nein/Enthaltung: Abstimmungsergebnis der Versammlung selbst
 * (von der Protokollführung eingetragen). Vorstand: Stellungnahme des
 * Vorstands (siehe tab_antrag_detail.php). Begrpdf/Pdf: optionale
 * PDF-Begründung bzw. -Anlage (siehe process_antraege.php: antrag_pdf_speichern()).
 * Bem: fortlaufendes internes Redaktions-Notizbuch, wird nur angehaengt,
 * nie ueberschrieben (siehe process_antraege.php: bem_anhaengen()).
 */
function antrag_spalten_sicherstellen(PDO $pdo, int $jahr): void {
    static $geprueft = [];
    if (isset($geprueft[$jahr]) || !selbstheilung_aktiv()) {
        return;
    }
    $tabelle = antrag_tabelle($jahr);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `$tabelle` (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            Ast VARCHAR(12), Art INT, Kat INT, Nr VARCHAR(12), Zu INT,
            Titeltext VARCHAR(255), Text TEXT, Begr TEXT, Fin VARCHAR(255),
            Datum DATETIME,
            INDEX idx_art (Art), INDEX idx_zu (Zu), INDEX idx_ast (Ast)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $spalten = [
        'Gem'         => 'INT NULL',
        'Rfolge'      => 'INT NULL',
        'Forumlink'   => 'VARCHAR(255) NULL',
        'Uebernommen' => 'TINYINT(1) DEFAULT 0',
        'Ergebnis'    => 'VARCHAR(100) NULL',
        'Ja'          => 'INT NULL',
        'Nein'        => 'INT NULL',
        'Enthaltung'  => 'INT NULL',
        'Vorstand'    => 'TEXT NULL',
        'Begrpdf'     => 'TEXT NULL',
        'Pdf'         => 'VARCHAR(255) NULL',
        'Bem'         => 'TEXT NULL',
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

/** Setzt/loescht (bei $gem === null) die Gem-Gruppennummer eines Hauptantrags. */
function antrag_gem_setzen(PDO $pdo, int $jahr, int $id, ?int $gem): void {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $stmt = $pdo->prepare('UPDATE `' . antrag_tabelle($jahr) . '` SET Gem = ? WHERE ID = ? AND Art = ?');
    $stmt->execute([$gem, $id, ANTRAG_ART_HAUPTANTRAG]);
}

/** Alle Hauptanträge einer Gem-Gruppe (gemeinsame Behandlung), inkl. Antragsteller-Name. */
function get_gem_gruppe(PDO $pdo, int $jahr, int $gem): array {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $mitglieder = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare(
        "SELECT a.*, m.Vorname AS ast_vorname, m.Name AS ast_name
         FROM `$tabelle` a LEFT JOIN `$mitglieder` m ON m.MNr = a.Ast
         WHERE a.Gem = ? AND a.Art = ?
         ORDER BY a.Nr ASC"
    );
    $stmt->execute([$gem, ANTRAG_ART_HAUPTANTRAG]);
    return $stmt->fetchAll();
}

function get_hauptantraege(PDO $pdo, int $jahr): array {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` WHERE Art = ? ORDER BY Nr ASC");
    $stmt->execute([ANTRAG_ART_HAUPTANTRAG]);
    return $stmt->fetchAll();
}

function get_antrag(PDO $pdo, int $jahr, int $id): ?array {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` WHERE ID = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Zaehlt die (noch aktiven, nicht zurueckgezogenen) Hauptanträge einer Mitgliedsnummer - Grundlage des Kontingents. */
function zaehle_hauptantraege_von(PDO $pdo, int $jahr, string $mnr): int {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM `$tabelle` WHERE Art = ? AND Ast = ?");
    $stmt->execute([ANTRAG_ART_HAUPTANTRAG, $mnr]);
    return (int)$stmt->fetch()['n'];
}

function antrag_wurde_abgestimmt(array $antrag): bool {
    return trim((string)($antrag['Ergebnis'] ?? '')) !== '';
}

/**
 * Darf $mitglied einen Änderungs- oder Verfahrensantrag bearbeiten bzw.
 * zurückziehen? Anders als beim Hauptantrag gilt hier keine Frist -
 * Antragsteller:in UND Redaktion duerfen jederzeit, solange ueber den
 * (Folge-)Antrag noch nicht abgestimmt wurde bzw. er nicht bereits
 * zurueckgezogen ist.
 */
function folgeantrag_darf_verwalten(array $antrag, ?array $mitglied, bool $ist_redaktion): bool {
    if (antrag_wurde_abgestimmt($antrag)) {
        return false;
    }
    if ($ist_redaktion) {
        return true;
    }
    return $mitglied && $mitglied['MNr'] === $antrag['Ast'];
}

/**
 * Darf $mitglied den Hauptantrag $antrag bearbeiten (Kernprozess 04)?
 * Redaktion darf jederzeit (solange nicht abgestimmt/zurückgezogen),
 * die/der Antragsteller:in nur bis zum Antragsschluss.
 */
function hauptantrag_darf_bearbeiten(array $antrag, ?array $mitglied, bool $ist_redaktion, ?array $mvtexte): bool {
    if (antrag_wurde_abgestimmt($antrag)) {
        return false;
    }
    if ($ist_redaktion) {
        return true;
    }
    if (!$mitglied || $mitglied['MNr'] !== $antrag['Ast']) {
        return false;
    }
    return !ist_antragsschluss_erreicht($mvtexte);
}

/**
 * Darf $mitglied den Hauptantrag $antrag zurückziehen? Redaktion darf
 * jederzeit, die/der Antragsteller:in je nach Vereinsvorgabe
 * (rueckzug_nur_bis_antragsschluss) nur bis zum Antragsschluss oder
 * jederzeit bis zur eigenen Abstimmung.
 */
function hauptantrag_darf_zurueckziehen(array $antrag, ?array $mitglied, bool $ist_redaktion, ?array $mvtexte, array $config): bool {
    if (antrag_wurde_abgestimmt($antrag)) {
        return false;
    }
    if ($ist_redaktion) {
        return true;
    }
    if (!$mitglied || $mitglied['MNr'] !== $antrag['Ast']) {
        return false;
    }
    $nur_bis_antragsschluss = ($config['rueckzug_nur_bis_antragsschluss'] ?? '1') === '1';
    return !$nur_bis_antragsschluss || !ist_antragsschluss_erreicht($mvtexte);
}

/** Sortierkriterien fuer die Antragsliste ("Anträge zeigen/bearbeiten"). */
const ANTRAG_SORTIERUNGEN = [
    'rfolge' => 'Vorgeschlagene Reihenfolge',
    'nr'     => 'Antragsnummer',
    'datum'  => 'Datum der Antragstellung',
];

/**
 * Ermittelt die aktuell gewaehlte Sortierung und merkt sie sich ueber die
 * Session hinweg, damit sie beim Zurueckgehen oder nach dem Speichern auf
 * allen Seiten (Antragsliste, Kompaktliste, ...) erhalten bleibt, statt bei
 * jedem Aufruf ohne den GET-Parameter auf den Default zurueckzufallen.
 */
function antrag_sortierung_aktuell(): string {
    if (isset($_GET['sortierung']) && array_key_exists($_GET['sortierung'], ANTRAG_SORTIERUNGEN)) {
        $_SESSION['antrag_sortierung'] = $_GET['sortierung'];
    }
    $gemerkt = $_SESSION['antrag_sortierung'] ?? 'rfolge';
    return array_key_exists($gemerkt, ANTRAG_SORTIERUNGEN) ? $gemerkt : 'rfolge';
}

/**
 * Alle Hauptanträge eines Jahres, inkl. Vor-/Nachname der Antragstellerin
 * (fuer "Anträge zeigen/bearbeiten"). Default-Sortierung ist die
 * vorgeschlagene Reihenfolge (Rfolge) - Anträge, für die noch keine Rfolge
 * festgelegt wurde, fallen dabei ans Ende und werden untereinander nach dem
 * Datum der Antragstellung sortiert (auch wenn noch für gar keinen Antrag
 * eine Rfolge existiert, sortiert das dann de facto rein nach Datum).
 */
function get_hauptantraege_mit_antragsteller(PDO $pdo, int $jahr, string $sortierung = 'rfolge'): array {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $mitglieder = 'mvmitglieder_' . $jahr;
    $order = match ($sortierung) {
        'datum'  => 'a.Datum ASC, a.Nr ASC',
        'rfolge' => '(a.Rfolge IS NULL) ASC, a.Rfolge ASC, a.Datum ASC, a.Nr ASC',
        default  => 'a.Nr ASC',
    };
    $stmt = $pdo->prepare(
        "SELECT a.*, m.Vorname AS ast_vorname, m.Name AS ast_name
         FROM `$tabelle` a
         LEFT JOIN `$mitglieder` m ON m.MNr = a.Ast
         WHERE a.Art = ?
         ORDER BY $order"
    );
    $stmt->execute([ANTRAG_ART_HAUPTANTRAG]);
    return $stmt->fetchAll();
}

/** Ein einzelner Antrag inkl. Vor-/Nachname der Antragstellerin. */
function get_antrag_mit_antragsteller(PDO $pdo, int $jahr, int $id): ?array {
    $tabelle = antrag_tabelle($jahr);
    $mitglieder = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare(
        "SELECT a.*, m.Vorname AS ast_vorname, m.Name AS ast_name
         FROM `$tabelle` a
         LEFT JOIN `$mitglieder` m ON m.MNr = a.Ast
         WHERE a.ID = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Änderungs- und Verfahrensanträge, die sich auf einen Hauptantrag beziehen, inkl. Antragsteller-Name. */
function get_folgeantraege(PDO $pdo, int $jahr, int $hauptantrag_id): array {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $mitglieder = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare(
        "SELECT a.*, m.Vorname AS ast_vorname, m.Name AS ast_name
         FROM `$tabelle` a
         LEFT JOIN `$mitglieder` m ON m.MNr = a.Ast
         WHERE a.Zu = ? AND a.Art IN (?, ?)
         ORDER BY a.Nr ASC"
    );
    $stmt->execute([$hauptantrag_id, ANTRAG_ART_AENDERUNG, ANTRAG_ART_VERFAHREN]);
    return $stmt->fetchAll();
}

/** Zeigt "Vorname Name" an, faellt auf die Mitgliedsnummer zurueck, falls kein Mitgliedsdatensatz (mehr) existiert. */
function antragsteller_name(array $antrag): string {
    $name = trim(($antrag['ast_vorname'] ?? '') . ' ' . ($antrag['ast_name'] ?? ''));
    return $name !== '' ? $name : (string)($antrag['Ast'] ?? '');
}

/** true, wenn der Antragsschluss (mvtexte.antragsende, Format TT.MM.JJJJ) bereits verstrichen ist. */
function ist_antragsschluss_erreicht(?array $mvtexte): bool {
    $datum = $mvtexte['antragsende'] ?? '';
    $stichtag = DateTime::createFromFormat('d.m.Y', $datum);
    if (!$stichtag) {
        return false; // kein Datum hinterlegt -> keine Sperre
    }
    $stichtag->setTime(23, 59, 59);
    return new DateTime() > $stichtag;
}

/** Naechste freie, zweistellig gepolsterte Nummer fuer ein Praefix (z. B. S01, S02, ...). */
function naechste_hauptantrag_nummer(PDO $pdo, int $jahr, string $praefix): string {
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("SELECT Nr FROM `$tabelle` WHERE Nr LIKE ?");
    $stmt->execute([$praefix . '__']);
    $max = 0;
    foreach ($stmt->fetchAll() as $row) {
        $n = (int)substr($row['Nr'], strlen($praefix));
        if ($n > $max) {
            $max = $n;
        }
    }
    return $praefix . str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT);
}

/** Naechste Unternummer (z. B. S01.02) zu einem Hauptantrag - fuer Aenderungs-/Verfahrensantraege. */
function naechste_unternummer(PDO $pdo, int $jahr, string $hauptantrag_nr): string {
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("SELECT Nr FROM `$tabelle` WHERE Nr LIKE ?");
    $stmt->execute([$hauptantrag_nr . '.%']);
    $max = 0;
    foreach ($stmt->fetchAll() as $row) {
        $n = (int)substr($row['Nr'], strlen($hauptantrag_nr) + 1);
        if ($n > $max) {
            $max = $n;
        }
    }
    return $hauptantrag_nr . '.' . str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT);
}

/** Eigenstaendige Nummer fuer einen Verfahrensantrag ohne Bezug zu einem Hauptantrag. */
function naechste_verfahrensantrag_nummer(PDO $pdo, int $jahr): string {
    return naechste_hauptantrag_nummer($pdo, $jahr, ANTRAG_PRAEFIX_VERFAHREN);
}

/**
 * Erzeugt den Diskussionsforums-Slug eines Hauptantrags nach dem Muster
 * "antrag-<nr>-<titel-in-bindestrich-schreibweise>", z. B.
 * "antrag-s06-einberufung-einer-ausserordentlichen-mv". Nur Kleinbuchstaben,
 * Umlaute werden uebernommen (ä->ae, ö->oe, ü->ue, ß->ss).
 *
 * ANNAHME: Dieses Muster gilt fuer die Mensa-Foren-Software - ob es bei
 * anderen Vereinen genauso ist, ist fraglich. Admins anderer Installationen
 * muessen diese Funktion ggf. anpassen (siehe Admin-Installationsanleitung).
 */
function antrag_forumslink_generieren(string $nr, string $titel): string {
    $roh = mb_strtolower($nr . ' ' . $titel, 'UTF-8');
    $roh = strtr($roh, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $roh);
    $slug = trim($slug, '-');
    return 'antrag-' . $slug;
}

function hauptantrag_einreichen(PDO $pdo, int $jahr, string $mnr, int $kat, string $titel, string $text, string $begr, string $fin): string {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $praefix = ANTRAG_KATEGORIEN[$kat]['praefix'];
    $nr = naechste_hauptantrag_nummer($pdo, $jahr, $praefix);
    $forumlink = antrag_forumslink_generieren($nr, $titel);
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare(
        "INSERT INTO `$tabelle` (Ast, Art, Kat, Nr, Titeltext, Text, Begr, Fin, Forumlink, Datum)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$mnr, ANTRAG_ART_HAUPTANTRAG, $kat, $nr, $titel, $text, $begr, $fin, $forumlink, date('Y-m-d H:i:s')]);
    return $nr;
}

function aenderungsantrag_einreichen(PDO $pdo, int $jahr, string $mnr, array $hauptantrag, string $titel, string $text, string $begr, string $fin): string {
    $nr = naechste_unternummer($pdo, $jahr, $hauptantrag['Nr']);
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare(
        "INSERT INTO `$tabelle` (Ast, Art, Kat, Nr, Zu, Titeltext, Text, Begr, Fin, Datum)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$mnr, ANTRAG_ART_AENDERUNG, $hauptantrag['Kat'], $nr, $hauptantrag['ID'], $titel, $text, $begr, $fin, date('Y-m-d H:i:s')]);
    return $nr;
}

/**
 * Aktualisiert beliebige Felder eines Antrags (Kernprozess 04 sowie die
 * Redaktions-Bearbeitung von Forumlink/Vorstandsstellungnahme).
 * @param array<string,mixed> $felder Spalte => neuer Wert
 */
function antrag_aktualisieren(PDO $pdo, int $jahr, int $id, array $felder): void {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $set = implode(', ', array_map(fn($spalte) => "`$spalte` = ?", array_keys($felder)));
    $stmt = $pdo->prepare("UPDATE `$tabelle` SET $set WHERE ID = ?");
    $stmt->execute([...array_values($felder), $id]);
}

/**
 * Haengt eine neue, datierte Bemerkung an den bisherigen Inhalt des
 * (echten Bestandssystem-) Felds "Bem" an, statt ihn zu ueberschreiben -
 * eine Art fortlaufendes internes Redaktions-Notizbuch je Antrag, nur in
 * der Redaktionsmaske sichtbar (siehe tab_antrag_detail.php). Gibt bei
 * leerem $neuer_text den bisherigen Inhalt unveraendert zurueck.
 */
function bem_anhaengen(string $bisherige_bem, string $neuer_text, string $autor_name): string {
    $neuer_text = trim($neuer_text);
    if ($neuer_text === '') {
        return $bisherige_bem;
    }
    $eintrag = '[' . date('d.m.Y H:i') . ', ' . $autor_name . ']' . "\n" . $neuer_text;
    $bisherige_bem = trim($bisherige_bem);
    return $bisherige_bem === '' ? $eintrag : $bisherige_bem . "\n\n" . $eintrag;
}

/**
 * Speichert einen als PDF eingereichten Antrag im Dokumentenverzeichnis
 * des Jahres (docs<jahr>, siehe jahr_functions.php: jahr_docs_verzeichnis())
 * - rein zu Dokumentationszwecken (z. B. eine per E-Mail eingereichte
 * Antragsdatei), NICHT zur Anzeige im Tool bestimmt (siehe
 * tab_antrag_detail.php: nur in der Redaktionsmaske verlinkt). Der
 * Dateiname beginnt bewusst mit der Antragsnummer, da das Verzeichnis
 * auch von Hand benannte Dateien enthaelt (Protokolle, Einladungen, ...).
 * Loescht bei Erfolg die zuvor hinterlegte Datei (falls vorhanden), damit
 * sich dort keine verwaisten Altdateien ansammeln. Gibt den neuen
 * relativen Pfad (z. B. "docs2027/S01_antrag.pdf") zurueck, oder null bei
 * fehlendem/ungueltigem Upload (dann bleibt der bisherige Wert erhalten).
 */
function antrag_pdf_speichern(array $datei, int $jahr, string $antrag_nr, ?string $bisheriger_pfad): ?string {
    if (!isset($datei['error']) || $datei['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (strtolower(pathinfo($datei['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        return null;
    }
    docs_verzeichnis_anlegen($jahr);
    $zielordner = jahr_docs_verzeichnis($jahr);

    $basisname = pathinfo($datei['name'], PATHINFO_FILENAME);
    $basisname = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $basisname);
    $basisname = trim($basisname, '_');
    if ($basisname === '') {
        $basisname = 'antrag';
    }
    $dateiname = $antrag_nr . '_' . $basisname . '.pdf';
    $zielpfad = $zielordner . '/' . $dateiname;

    if (!move_uploaded_file($datei['tmp_name'], $zielpfad)) {
        return null;
    }

    if ($bisheriger_pfad) {
        $alte_datei = __DIR__ . '/../' . $bisheriger_pfad;
        if (is_file($alte_datei) && realpath($alte_datei) !== realpath($zielpfad)) {
            unlink($alte_datei);
        }
    }

    return 'docs' . $jahr . '/' . $dateiname;
}

/**
 * Zieht einen Antrag zurück - fuer alle Antragstypen gleich: der Antrag
 * bekommt Art=ANTRAG_ART_ARCHIVIERT und erscheint danach in keiner
 * normalen Liste mehr (WHERE Art=1 bzw. Art IN (2,3) filtert automatisch).
 */
function antrag_zurueckziehen(PDO $pdo, int $jahr, int $id): void {
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("UPDATE `$tabelle` SET Art = ? WHERE ID = ?");
    $stmt->execute([ANTRAG_ART_ARCHIVIERT, $id]);
}

function verfahrensantrag_einreichen(PDO $pdo, int $jahr, string $mnr, ?array $hauptantrag, string $titel, string $text, string $begr): string {
    if ($hauptantrag) {
        $nr = naechste_unternummer($pdo, $jahr, $hauptantrag['Nr']);
        $zu = $hauptantrag['ID'];
    } else {
        $nr = naechste_verfahrensantrag_nummer($pdo, $jahr);
        $zu = null;
    }
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare(
        "INSERT INTO `$tabelle` (Ast, Art, Kat, Nr, Zu, Titeltext, Text, Begr, Datum)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$mnr, ANTRAG_ART_VERFAHREN, ANTRAG_KAT_VERFAHREN, $nr, $zu, $titel, $text, $begr, date('Y-m-d H:i:s')]);
    return $nr;
}

// ============================================
// ÜBERNAHME EINES ÄNDERUNGSANTRAGS DURCH DIE/DEN ANTRAGSTELLER:IN
// Zweistufiger Prozess (siehe Projekt-Dokumentation): (1) Antragsteller:in
// erklaert die Übernahme, die Redaktion wird per Mail informiert -
// automatisch, sofort wirksam als Hinweis. (2) Die Redaktion arbeitet den
// Änderungsantrag manuell in den Hauptantragstext ein (siehe
// aenderungsantrag_mergen()) - das geschieht bewusst NICHT automatisch,
// da Änderungsanträge sehr unterschiedlich gestaltet sein koennen.
// ============================================

/** Markiert einen Änderungsantrag als von der/dem Antragsteller:in des Hauptantrags übernommen (Stufe 1). */
function aenderungsantrag_als_uebernommen_markieren(PDO $pdo, int $jahr, int $id): void {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("UPDATE `$tabelle` SET Uebernommen = 1 WHERE ID = ? AND Art = ?");
    $stmt->execute([$id, ANTRAG_ART_AENDERUNG]);
}

/**
 * Archiviert die aktuelle Fassung eines Hauptantrags als "Ursprungsantrag"
 * (Art=ANTRAG_ART_ARCHIVIERT, Nr mit angehängtem "o") - nur wenn das fuer
 * diesen Hauptantrag noch nicht geschehen ist. Jedes Mitglied kann
 * verlangen, dass bei geänderten Anträgen über die veröffentlichte Fassung
 * abgestimmt wird, daher muss diese Fassung verfuegbar bleiben (erscheint
 * nur auf der Beamerliste, siehe beamer_functions.php).
 *
 * ANNAHME: Da dieses Tool keine Versionshistorie fuehrt, wird die zum
 * Aufrufzeitpunkt aktuelle Fassung archiviert (nicht rekonstruiert, wie sie
 * exakt am Tag des Antragsschlusses war) - das ist in aller Regel identisch,
 * da nach Antragsschluss nur noch die Redaktion editieren darf.
 */
function hauptantrag_ursprungsfassung_archivieren(PDO $pdo, int $jahr, array $hauptantrag): void {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $archiv_nr = $hauptantrag['Nr'] . 'o';

    $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM `$tabelle` WHERE Nr = ? AND Art = ?");
    $stmt->execute([$archiv_nr, ANTRAG_ART_ARCHIVIERT]);
    if ((int)$stmt->fetch()['n'] > 0) {
        return; // bereits archiviert
    }

    $insert = $pdo->prepare(
        "INSERT INTO `$tabelle` (Ast, Art, Kat, Nr, Zu, Titeltext, Text, Begr, Fin, Datum)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insert->execute([
        $hauptantrag['Ast'], ANTRAG_ART_ARCHIVIERT, $hauptantrag['Kat'], $archiv_nr, $hauptantrag['ID'],
        $hauptantrag['Titeltext'], $hauptantrag['Text'], $hauptantrag['Begr'], $hauptantrag['Fin'], date('Y-m-d H:i:s'),
    ]);
}

/** Änderungsanträge zu einem Hauptantrag, die übernommen, aber von der Redaktion noch nicht zusammengeführt wurden (Stufe 2, ausstehend). */
function get_uebernommene_aenderungsantraege(PDO $pdo, int $jahr, int $hauptantrag_id): array {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);
    $mitglieder = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare(
        "SELECT a.*, m.Vorname AS ast_vorname, m.Name AS ast_name
         FROM `$tabelle` a LEFT JOIN `$mitglieder` m ON m.MNr = a.Ast
         WHERE a.Zu = ? AND a.Art = ? AND a.Uebernommen = 1
         ORDER BY a.Nr ASC"
    );
    $stmt->execute([$hauptantrag_id, ANTRAG_ART_AENDERUNG]);
    return $stmt->fetchAll();
}

/**
 * Fuehrt die manuelle Übernahme durch die Redaktion durch (Stufe 2):
 * speichert den zusammengefuehrten Text/Begruendung im Hauptantrag und
 * markiert den Änderungsantrag als endgueltig übernommen (Art=
 * ANTRAG_ART_ARCHIVIERT, Nr mit angehängtem "x") - erscheint danach nur
 * noch auf der Beamerliste ("Übernommener Änderungsantrag").
 */
function aenderungsantrag_mergen(PDO $pdo, int $jahr, array $hauptantrag, int $aenderungsantrag_id, string $neuer_text, string $neue_begr): void {
    antrag_spalten_sicherstellen($pdo, $jahr);
    $tabelle = antrag_tabelle($jahr);

    antrag_aktualisieren($pdo, $jahr, (int)$hauptantrag['ID'], ['Text' => $neuer_text, 'Begr' => $neue_begr]);

    $stmt = $pdo->prepare("SELECT Nr FROM `$tabelle` WHERE ID = ?");
    $stmt->execute([$aenderungsantrag_id]);
    $nr = (string)($stmt->fetch()['Nr'] ?? '');

    $update = $pdo->prepare("UPDATE `$tabelle` SET Art = ?, Nr = ? WHERE ID = ?");
    $update->execute([ANTRAG_ART_ARCHIVIERT, $nr . 'x', $aenderungsantrag_id]);
}

// ============================================
// QUORUM (Art=5): Hauptanträge, die nach Ablauf der Unterstützungsfrist
// nicht die erforderliche Unterstuetzung erreicht haben (siehe Kompaktliste).
// ============================================

/** Markiert einen Hauptantrag als "Unterstützung nicht erreicht" (nur die Redaktion, siehe Kompaktliste). */
function antrag_quorum_nicht_erreicht_markieren(PDO $pdo, int $jahr, int $id): void {
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("UPDATE `$tabelle` SET Art = ? WHERE ID = ? AND Art = ?");
    $stmt->execute([ANTRAG_ART_QUORUM_NICHT_ERREICHT, $id, ANTRAG_ART_HAUPTANTRAG]);
}

/** Hauptanträge, die als "Unterstützung nicht erreicht" markiert wurden, inkl. Antragsteller-Name (fuer den Kompaktlisten-Filter). */
function get_hauptantraege_quorum_nicht_erreicht(PDO $pdo, int $jahr): array {
    $tabelle = antrag_tabelle($jahr);
    $mitglieder = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare(
        "SELECT a.*, m.Vorname AS ast_vorname, m.Name AS ast_name
         FROM `$tabelle` a LEFT JOIN `$mitglieder` m ON m.MNr = a.Ast
         WHERE a.Art = ? ORDER BY a.Nr ASC"
    );
    $stmt->execute([ANTRAG_ART_QUORUM_NICHT_ERREICHT]);
    return $stmt->fetchAll();
}
