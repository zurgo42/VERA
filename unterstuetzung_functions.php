<?php
/**
 * unterstuetzung_functions.php - Unterstützung / Mitantragstellerschaft
 * (Kernprozess 05), ausschließlich für Hauptanträge. Nutzt mvunterst_<jahr>
 * (Altsystem-Spalten AnID/MNr/Unterst/Bem/Datum sind direkt aus dem
 * Bestandscode uebernommen: Unterst=1 Unterstuetzung, Unterst=2
 * Mitantragstellerschaft). Die Spalte "namentlich" ist neu (siehe
 * Prozesskatalog: Unterstuetzung soll waehlbar anonym sein,
 * Mitantragstellerschaft bleibt immer namentlich) und wird bei Bedarf
 * automatisch ergaenzt, falls die Tabelle aus dem Altsystem stammt.
 */

require_once __DIR__ . '/antraege_functions.php'; // fuer get_antrag()/antrag_wurde_abgestimmt()/ANTRAG_ART_HAUPTANTRAG

const UNTERST_UNTERSTUETZER = 1;
const UNTERST_MITANTRAGSTELLER = 2;

function unterst_tabelle_sicherstellen(PDO $pdo, int $jahr): void {
    static $geprueft = [];
    if (isset($geprueft[$jahr]) || !selbstheilung_aktiv()) {
        return;
    }
    $tabelle = 'mvunterst_' . $jahr;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `$tabelle` (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            AnID INT, MNr VARCHAR(12), Unterst INT, Bem VARCHAR(300), Datum DATETIME,
            namentlich TINYINT(1) DEFAULT 1,
            INDEX idx_anid (AnID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!spalte_existiert($pdo, $tabelle, 'namentlich')) {
        try {
            $pdo->exec("ALTER TABLE `$tabelle` ADD COLUMN namentlich TINYINT(1) DEFAULT 1");
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    $geprueft[$jahr] = true;
}

/** Reine Unterstützer:innen (Unterst=1) eines Hauptantrags, inkl. Namen. */
function get_unterstuetzungen(PDO $pdo, int $jahr, int $antrag_id): array {
    unterst_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvunterst_' . $jahr;
    $mitglieder = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare(
        "SELECT u.*, m.Vorname, m.Name
         FROM `$tabelle` u
         LEFT JOIN `$mitglieder` m ON m.MNr = u.MNr
         WHERE u.AnID = ? AND u.Unterst = ?
         ORDER BY u.Datum ASC"
    );
    $stmt->execute([$antrag_id, UNTERST_UNTERSTUETZER]);
    return $stmt->fetchAll();
}

/** Mitantragsteller:innen (Unterst=2) eines Hauptantrags, inkl. Namen. */
function get_mitantragsteller(PDO $pdo, int $jahr, int $antrag_id): array {
    unterst_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvunterst_' . $jahr;
    $mitglieder = 'mvmitglieder_' . $jahr;
    $stmt = $pdo->prepare(
        "SELECT u.*, m.Vorname, m.Name
         FROM `$tabelle` u
         LEFT JOIN `$mitglieder` m ON m.MNr = u.MNr
         WHERE u.AnID = ? AND u.Unterst = ?
         ORDER BY u.Datum ASC"
    );
    $stmt->execute([$antrag_id, UNTERST_MITANTRAGSTELLER]);
    return $stmt->fetchAll();
}

/** Eigene Beteiligung eines Mitglieds an einem Hauptantrag (fuer die Checkbox-Vorbelegung). */
function get_eigene_beteiligung(PDO $pdo, int $jahr, int $antrag_id, string $mnr): array {
    unterst_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvunterst_' . $jahr;
    $stmt = $pdo->prepare("SELECT Unterst, namentlich FROM `$tabelle` WHERE AnID = ? AND MNr = ?");
    $stmt->execute([$antrag_id, $mnr]);

    $ergebnis = ['mitantragsteller' => false, 'unterstuetzer' => false, 'namentlich' => true];
    foreach ($stmt->fetchAll() as $row) {
        if ((int)$row['Unterst'] === UNTERST_MITANTRAGSTELLER) {
            $ergebnis['mitantragsteller'] = true;
        }
        if ((int)$row['Unterst'] === UNTERST_UNTERSTUETZER) {
            $ergebnis['unterstuetzer'] = true;
            $ergebnis['namentlich'] = (bool)$row['namentlich'];
        }
    }
    return $ergebnis;
}

function zaehle_unterstuetzungen(PDO $pdo, int $jahr, int $antrag_id): int {
    unterst_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvunterst_' . $jahr;
    $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM `$tabelle` WHERE AnID = ? AND Unterst = ?");
    $stmt->execute([$antrag_id, UNTERST_UNTERSTUETZER]);
    return (int)$stmt->fetch()['n'];
}

/** Mitantragstellerschaft ein-/austragen - keine Frist, jederzeit aenderbar. */
function mitantragsteller_setzen(PDO $pdo, int $jahr, int $antrag_id, string $mnr, bool $aktiv): void {
    unterst_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvunterst_' . $jahr;
    if ($aktiv) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM `$tabelle` WHERE AnID = ? AND MNr = ? AND Unterst = ?");
        $stmt->execute([$antrag_id, $mnr, UNTERST_MITANTRAGSTELLER]);
        if ((int)$stmt->fetch()['n'] === 0) {
            $pdo->prepare("INSERT INTO `$tabelle` (AnID, MNr, Unterst, Datum, namentlich) VALUES (?, ?, ?, ?, 1)")
                ->execute([$antrag_id, $mnr, UNTERST_MITANTRAGSTELLER, date('Y-m-d H:i:s')]);
        }
    } else {
        $pdo->prepare("DELETE FROM `$tabelle` WHERE AnID = ? AND MNr = ? AND Unterst = ?")
            ->execute([$antrag_id, $mnr, UNTERST_MITANTRAGSTELLER]);
    }
}

/** Unterstützung ein-/austragen bzw. Namensnennung aendern - nur innerhalb der Unterstützungsfrist erlaubt (siehe Aufrufer). */
function unterstuetzung_setzen(PDO $pdo, int $jahr, int $antrag_id, string $mnr, bool $aktiv, bool $namentlich): void {
    unterst_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvunterst_' . $jahr;
    if ($aktiv) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM `$tabelle` WHERE AnID = ? AND MNr = ? AND Unterst = ?");
        $stmt->execute([$antrag_id, $mnr, UNTERST_UNTERSTUETZER]);
        if ((int)$stmt->fetch()['n'] === 0) {
            $pdo->prepare("INSERT INTO `$tabelle` (AnID, MNr, Unterst, Datum, namentlich) VALUES (?, ?, ?, ?, ?)")
                ->execute([$antrag_id, $mnr, UNTERST_UNTERSTUETZER, date('Y-m-d H:i:s'), $namentlich ? 1 : 0]);
        } else {
            $pdo->prepare("UPDATE `$tabelle` SET namentlich = ? WHERE AnID = ? AND MNr = ? AND Unterst = ?")
                ->execute([$namentlich ? 1 : 0, $antrag_id, $mnr, UNTERST_UNTERSTUETZER]);
        }
    } else {
        $pdo->prepare("DELETE FROM `$tabelle` WHERE AnID = ? AND MNr = ? AND Unterst = ?")
            ->execute([$antrag_id, $mnr, UNTERST_UNTERSTUETZER]);
    }
}

/**
 * Speichert die Unterstützungs-Entscheidung für mehrere Hauptanträge auf
 * einmal (Sammel-Abstimmung, siehe tab_stimmungsbild.php). Ermittelt sich
 * die dafür infrage kommenden (noch offenen) Hauptanträge des Jahres selbst
 * - dieselbe Liste, die tab_stimmungsbild.php anzeigt - und setzt für jeden
 * davon die Unterstützung anhand von $unterstuetzung_werte/$namentlich_werte
 * (Antrag-ID => Checkbox angekreuzt?). Nur solange die Unterstützungsfrist
 * insgesamt noch läuft. Gibt die Anzahl der verarbeiteten Hauptanträge zurück.
 */
function unterstuetzung_alle_speichern(PDO $pdo, int $jahr, string $mnr, ?array $mvtexte, array $config, array $unterstuetzung_werte, array $namentlich_werte): int {
    if (ist_unterstuetzungsfrist_abgelaufen($mvtexte, $config)) {
        return 0;
    }
    $offene_hauptantraege = array_filter(
        get_hauptantraege_mit_antragsteller($pdo, $jahr),
        fn($a) => !antrag_wurde_abgestimmt($a)
    );
    $verarbeitet = 0;
    foreach ($offene_hauptantraege as $a) {
        $antrag_id = (int)$a['ID'];
        $aktiv = isset($unterstuetzung_werte[$antrag_id]);
        $namentlich = isset($namentlich_werte[$antrag_id]);
        unterstuetzung_setzen($pdo, $jahr, $antrag_id, $mnr, $aktiv, $namentlich);
        $verarbeitet++;
    }
    return $verarbeitet;
}

/**
 * Fristende der Unterstützung oder null, falls sich keins ermitteln laesst.
 * Ist unter Termine ein explizites Enddatum (mvtexte.unterstuetzungsende)
 * gesetzt, hat das Vorrang - sonst wird es wie bisher aus Antragsschluss +
 * X Wochen (Vereinsvorgabe unterstuetzung_frist_wochen) berechnet.
 */
function unterstuetzungsfrist_stichtag(?array $mvtexte, array $config): ?DateTime {
    $explizit = trim($mvtexte['unterstuetzungsende'] ?? '');
    if ($explizit !== '') {
        $stichtag = DateTime::createFromFormat('d.m.Y', $explizit);
        if ($stichtag) {
            return $stichtag->setTime(23, 59, 59);
        }
    }

    $antragsende = $mvtexte['antragsende'] ?? '';
    $stichtag = DateTime::createFromFormat('d.m.Y', $antragsende);
    if (!$stichtag) {
        return null;
    }
    $wochen = (int)($config['unterstuetzung_frist_wochen'] ?? 4);
    return $stichtag->modify("+{$wochen} weeks")->setTime(23, 59, 59);
}

/** true, wenn die Unterstützungsfrist (Antragsschluss + X Wochen, siehe Vereinsvorgaben) bereits verstrichen ist. */
function ist_unterstuetzungsfrist_abgelaufen(?array $mvtexte, array $config): bool {
    $stichtag = unterstuetzungsfrist_stichtag($mvtexte, $config);
    if (!$stichtag) {
        return false; // kein Antragsschluss hinterlegt -> keine Sperre
    }
    return new DateTime() > $stichtag;
}

/** Fristende der Unterstützung in deutscher Langform, z. B. "06. März 2027" - leer, falls kein Antragsschluss hinterlegt ist. */
function unterstuetzungsfrist_datum_text(?array $mvtexte, array $config): string {
    static $monatsnamen = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];
    $stichtag = unterstuetzungsfrist_stichtag($mvtexte, $config);
    if (!$stichtag) {
        return '';
    }
    return $stichtag->format('d') . '. ' . $monatsnamen[(int)$stichtag->format('n')] . ' ' . $stichtag->format('Y');
}
