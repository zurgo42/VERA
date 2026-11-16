<?php
/**
 * votum_functions.php - Stimmungsbild (Kernprozess 06): Wichtigkeit und
 * Zustimmung/Ablehnung je Antrag - gilt für Haupt- UND Nebenanträge
 * (Änderungs-/Verfahrensanträge).
 *
 * Anonymisierung: mvvotum_<jahr> identifiziert die abstimmende Person
 * NICHT über MNr, sondern über die Spalte Schluessel aus
 * mvmitglieder_<jahr> (vergeben über erzeugekey() im Altsystem) - genau
 * wie im Bestandssystem (mvantraege.php: "WHERE Schluessel=..."). So
 * kann die Rohtabelle zur Kontrolle veröffentlicht werden, ohne dass
 * ersichtlich wird, wer wie gestimmt hat - auch Admins mit vollem
 * Datenbankzugriff können das nicht. Nach der Versammlung wird die
 * Spalte Schluessel aus mvmitglieder_<jahr> gelöscht, danach ist die
 * Zuordnung Schluessel/MNr unwiderruflich weg.
 *
 * WICHTIG: Ausserhalb dieser Datei darf für den Zugriff auf
 * mvvotum_<jahr> niemals MNr verwendet werden, nur der eigene
 * Schluessel des angemeldeten Mitglieds ($mitglied['Schluessel']) -
 * sonst wird die Anonymisierung ausgehebelt.
 */

require_once __DIR__ . '/antraege_functions.php'; // fuer antrag_tabelle()/ANTRAG_ART_HAUPTANTRAG
require_once __DIR__ . '/unterstuetzung_functions.php'; // fuer ist_unterstuetzungsfrist_abgelaufen() (siehe stimmungsbild_platzhalter())

/**
 * Reihenfolge hier ist die Anzeige-Reihenfolge der Optionen (Dafür zuerst).
 * Die Schlüssel (1/2/3) sind die in mvvotum_<jahr>.Votum gespeicherten
 * Werte und bleiben unveraendert - siehe get_rang_aus_stimmungsbild().
 */
const VOTUM_ZUSTIMMUNG = [
    3 => ['emoji' => '👍', 'label' => 'Dafür'],
    1 => ['emoji' => '👎', 'label' => 'Dagegen'],
    2 => ['emoji' => '🤔', 'label' => 'weiß noch nicht'],
];

/**
 * Vier Stufen wie im Bestandssystem (ifunktionen_mv.php: votum()) - inkl.
 * "Nichtbefassung" als eigene Ablehnungsstufe ("dieser Antrag sollte gar
 * nicht behandelt werden") und "Weiß noch nicht" als neutrale Stufe.
 * Die Werte 1-4 entsprechen exakt der Bedeutung im Altsystem, damit die
 * dortige Rang-Formel unveraendert uebernommen werden kann (siehe
 * get_rang_aus_stimmungsbild()). Reihenfolge hier ist nur die
 * Anzeige-Reihenfolge (Wichtig zuerst).
 */
const VOTUM_WICHTIGKEIT = [
    3 => ['emoji' => '👆', 'label' => 'Wichtig'],
    2 => ['emoji' => '🤏', 'label' => 'Eher unwichtig'],
    1 => ['emoji' => '🤷', 'label' => 'Weiß noch nicht'],
    4 => ['emoji' => '🙅', 'label' => 'Nichtbefassung'],
];

function votum_tabelle_sicherstellen(PDO $pdo, int $jahr): void {
    static $geprueft = [];
    if (isset($geprueft[$jahr]) || !selbstheilung_aktiv()) {
        return;
    }
    $tabelle = 'mvvotum_' . $jahr;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `$tabelle` (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            AnID INT, Schluessel VARCHAR(32), Wichtig INT, Votum INT, Datum DATETIME,
            INDEX idx_anid (AnID), INDEX idx_schluessel (Schluessel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!spalte_existiert($pdo, $tabelle, 'Schluessel')) {
        try {
            $pdo->exec("ALTER TABLE `$tabelle` ADD COLUMN Schluessel VARCHAR(32)");
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    $geprueft[$jahr] = true;
}

function get_eigenes_votum(PDO $pdo, int $jahr, int $antrag_id, string $schluessel): ?array {
    votum_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvvotum_' . $jahr;
    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` WHERE AnID = ? AND Schluessel = ? ORDER BY Datum DESC LIMIT 1");
    $stmt->execute([$antrag_id, $schluessel]);
    return $stmt->fetch() ?: null;
}

/** Anzahl Stimmen je Zustimmungswert (1/2/3) fuer einen Antrag. */
function get_votum_summe(PDO $pdo, int $jahr, int $antrag_id): array {
    votum_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvvotum_' . $jahr;
    $stmt = $pdo->prepare("SELECT Votum, COUNT(*) AS n FROM `$tabelle` WHERE AnID = ? GROUP BY Votum");
    $stmt->execute([$antrag_id]);

    $summe = [1 => 0, 2 => 0, 3 => 0];
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists((int)$row['Votum'], $summe)) {
            $summe[(int)$row['Votum']] = (int)$row['n'];
        }
    }
    return $summe;
}

/** Anzahl Stimmen je Wichtigkeitswert (1-4) fuer einen Antrag. */
function get_votum_wichtig_summe(PDO $pdo, int $jahr, int $antrag_id): array {
    votum_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvvotum_' . $jahr;
    $stmt = $pdo->prepare("SELECT Wichtig, COUNT(*) AS n FROM `$tabelle` WHERE AnID = ? GROUP BY Wichtig");
    $stmt->execute([$antrag_id]);

    $summe = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists((int)$row['Wichtig'], $summe)) {
            $summe[(int)$row['Wichtig']] = (int)$row['n'];
        }
    }
    return $summe;
}

/** Anzahl Teilnehmender am Stimmungsbild eines Antrags (Wichtig+Votum werden in diesem System immer gemeinsam abgegeben, daher eine gemeinsame Zahl fuer beide Fragen). */
function zaehle_votum_teilnehmer(PDO $pdo, int $jahr, int $antrag_id): int {
    votum_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvvotum_' . $jahr;
    $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM `$tabelle` WHERE AnID = ?");
    $stmt->execute([$antrag_id]);
    return (int)$stmt->fetch()['n'];
}

/**
 * Text, der statt der echten Stimmungsbild-Zahlen angezeigt werden soll,
 * weil die Ergebnisse fuer diesen Betrachter noch nicht sichtbar sind - oder
 * null, wenn sie sichtbar sind (dann werden die echten Zahlen gezeigt). Das
 * eigene Abstimmen bleibt in jedem Fall moeglich, auch wenn die Ergebnisse
 * noch verborgen sind. Regeln (siehe Projekt-Dokumentation):
 *
 * 1) Die Redaktion sieht Ergebnisse immer und sofort.
 * 2) Solange die Unterstützungsfrist noch läuft (dieselbe Frist wie bei der
 *    Unterstützung, siehe unterstuetzung_frist_wochen), sieht ein normales
 *    Mitglied die Ergebnisse eines Antrags erst, nachdem es selbst fuer
 *    DIESEN Antrag konkret abgestimmt hat (ein gespeichertes Votum - ein
 *    nicht gesetztes Häkchen zählt NICHT als Abstimmen, anders als bei der
 *    Unterstützung gibt es hier keine "Nein, zähl trotzdem als Entscheidung"-
 *    Option).
 * 3) Unabhängig davon werden Ergebnisse erst ab einer Mindestzahl an
 *    Teilnehmenden gezeigt (Admin-Einstellung stimmungsbild_mindestteilnehmer,
 *    Default 30). Wichtigkeit und Zustimmung werden in diesem System immer
 *    gemeinsam abgegeben (ein Formular, eine Zeile in mvvotum), daher gilt
 *    eine gemeinsame Teilnehmerzahl fuer beide Fragen.
 */
function stimmungsbild_platzhalter(PDO $pdo, int $jahr, int $antrag_id, bool $ist_redaktion, bool $unterstuetzungsfrist_abgelaufen, bool $hat_selbst_abgestimmt, array $config): ?string {
    if ($ist_redaktion) {
        return null;
    }
    if (!$unterstuetzungsfrist_abgelaufen && !$hat_selbst_abgestimmt) {
        return 'Ergebnis wird sichtbar, sobald du selbst abgestimmt hast.';
    }
    $mindest = (int)($config['stimmungsbild_mindestteilnehmer'] ?? 30);
    $teilnehmer = zaehle_votum_teilnehmer($pdo, $jahr, $antrag_id);
    if ($teilnehmer < $mindest) {
        return "Ergebnis wird ab $mindest Teilnehmenden gezeigt (aktuell $teilnehmer).";
    }
    return null;
}

/**
 * Ermittelt für alle Hauptanträge eines Jahres mit Stimmungsbild eine
 * "Rangzahl" - Formel wie im Bestandssystem (ifunktionen_mv.php/
 * mvliste.php), dort tatsächlich so berechnet (nicht nur im erklärenden
 * Kommentartext dort, der etwas anderes suggeriert):
 *
 *   Wichtigkeits-Rang = 2 × (wichtig − eher unwichtig − Nichtbefassung) / Gesamtzahl Wichtigkeits-Voten × 100
 *   Votumsklarheits-Rang = |dafür − dagegen| / Gesamtzahl Zustimmungs-Voten × 100
 *   Rangzahl = Wichtigkeits-Rang + Votumsklarheits-Rang
 *
 * Nur der Votumsklarheits-Anteil geht als Absolutwert ein, der
 * Wichtigkeits-Anteil bleibt vorzeichenbehaftet. Anträge, die überwiegend
 * als unwichtig/Nichtbefassung eingeschätzt wurden, erhalten dadurch
 * einen negativen Wichtigkeits-Rang und damit eine kleine bzw. negative
 * Rangzahl - sie rutschen ans Ende der Reihenfolge. Nur klar wichtige
 * UND klar entschiedene Anträge erreichen hohe positive Rangzahlen.
 *
 * ("Weiß noch nicht" zählt bei der Wichtigkeit neutral - nur im Nenner,
 * nicht im Zähler - genau wie im Altsystem.)
 *
 * Je höher die Rangzahl, umso eher sollte der Antrag zuerst behandelt
 * werden: wichtige UND klar entschiedene (ob dafür oder dagegen) Anträge
 * nach vorn, damit darüber ohne lange Diskussion abgestimmt werden kann.
 * Dient der Redaktion nur als Richtschnur bei der Festlegung von
 * Reihenfolge und Gruppierung (siehe tab_reihenfolge.php) - ersetzt nicht
 * die eigene Einschätzung, u. a. weil das Stimmungsbild freiwillig und
 * ggf. nur von wenigen Mitgliedern abgegeben ist.
 *
 * Gibt neben der Rangzahl auch die beiden Einzelwerte zurück (Wichtigkeits-
 * Rang, Votumsklarheits-Rang), damit die Berechnung für die Redaktion
 * nachvollziehbar bleibt statt nur die fertige Summe zu zeigen.
 *
 * @return array<int,array{rangzahl:float,wichtigkeit:float,votum:float}> Hauptantrag-ID => ..., absteigend nach Rangzahl sortiert
 */
function get_rang_aus_stimmungsbild(PDO $pdo, int $jahr): array {
    votum_tabelle_sicherstellen($pdo, $jahr);
    $votum_tabelle = 'mvvotum_' . $jahr;
    $antrag_tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare(
        "SELECT v.AnID,
                SUM(CASE WHEN v.Wichtig = 3 THEN 1 ELSE 0 END) AS wichtig,
                SUM(CASE WHEN v.Wichtig = 2 THEN 1 ELSE 0 END) AS eher_unwichtig,
                SUM(CASE WHEN v.Wichtig = 4 THEN 1 ELSE 0 END) AS nichtbefassung,
                SUM(CASE WHEN v.Wichtig IN (1,2,3,4) THEN 1 ELSE 0 END) AS wichtig_stimmen,
                SUM(CASE WHEN v.Votum = 3 THEN 1 ELSE 0 END) AS dafuer,
                SUM(CASE WHEN v.Votum = 1 THEN 1 ELSE 0 END) AS dagegen,
                SUM(CASE WHEN v.Votum IN (1,2,3) THEN 1 ELSE 0 END) AS votum_stimmen
         FROM `$votum_tabelle` v
         INNER JOIN `$antrag_tabelle` a ON a.ID = v.AnID AND a.Art = ?
         GROUP BY v.AnID"
    );
    $stmt->execute([ANTRAG_ART_HAUPTANTRAG]);

    $ergebnis = [];
    foreach ($stmt->fetchAll() as $zeile) {
        $wichtig_stimmen = (int)$zeile['wichtig_stimmen'];
        $votum_stimmen = (int)$zeile['votum_stimmen'];
        if ($wichtig_stimmen === 0 && $votum_stimmen === 0) {
            continue; // kein Stimmungsbild vorhanden
        }
        $rangw = $wichtig_stimmen > 0
            ? ((int)$zeile['wichtig'] - (int)$zeile['eher_unwichtig'] - (int)$zeile['nichtbefassung']) / $wichtig_stimmen * 100
            : 0;
        $rangv = $votum_stimmen > 0
            ? abs((int)$zeile['dafuer'] - (int)$zeile['dagegen']) / $votum_stimmen * 100
            : 0;
        $ergebnis[(int)$zeile['AnID']] = [
            'rangzahl'    => round(2 * $rangw + $rangv, 1),
            'wichtigkeit' => round($rangw, 1),
            'votum'       => round($rangv, 1),
        ];
    }
    uasort($ergebnis, fn($a, $b) => $b['rangzahl'] <=> $a['rangzahl']);
    return $ergebnis;
}

/**
 * Speichert mehrere Voten eines Mitglieds auf einmal (Sammel-Abstimmung,
 * siehe tab_stimmungsbild.php: "Alle öffnen" - einzeln abschicken ist bei
 * vielen Anträgen zu mühsam). $wichtig_werte/$votum_werte sind
 * Antrag-ID => eingegebener Wert, direkt aus $_POST['wichtig']/
 * $_POST['votum'] (Array-Feldnamen je Antrag-ID im Sammelformular).
 *
 * Nur vollständige, gültige Zeilen werden gespeichert: beide Werte müssen
 * gültige VOTUM_*-Werte sein, der Antrag muss im Jahr existieren und darf
 * noch nicht abgestimmt sein. Unvollständig ausgefüllte oder ungültige
 * Zeilen werden ohne Fehler übersprungen, damit man auch nur einen Teil
 * der Anträge abgeben und den Rest später nachholen kann.
 *
 * Gibt die Anzahl tatsächlich gespeicherter Voten zurück.
 */
function votum_alle_speichern(PDO $pdo, int $jahr, string $schluessel, array $wichtig_werte, array $votum_werte): int {
    if ($schluessel === '') {
        return 0;
    }
    $gespeichert = 0;
    foreach (array_unique(array_merge(array_keys($wichtig_werte), array_keys($votum_werte))) as $id) {
        $antrag_id = (int)$id;
        $wichtig = (int)($wichtig_werte[$id] ?? 0);
        $votum = (int)($votum_werte[$id] ?? 0);
        if (!array_key_exists($wichtig, VOTUM_WICHTIGKEIT) || !array_key_exists($votum, VOTUM_ZUSTIMMUNG)) {
            continue;
        }
        $antrag = get_antrag($pdo, $jahr, $antrag_id);
        if (!$antrag || antrag_wurde_abgestimmt($antrag)) {
            continue;
        }
        votum_abgeben($pdo, $jahr, $antrag_id, $schluessel, $wichtig, $votum);
        $gespeichert++;
    }
    return $gespeichert;
}

/** Gibt das eigene Votum ab oder aktualisiert es - kann jederzeit erneut abgegeben werden. */
function votum_abgeben(PDO $pdo, int $jahr, int $antrag_id, string $schluessel, int $wichtig, int $votum): void {
    votum_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvvotum_' . $jahr;
    $vorhanden = get_eigenes_votum($pdo, $jahr, $antrag_id, $schluessel);
    if ($vorhanden) {
        $pdo->prepare("UPDATE `$tabelle` SET Wichtig = ?, Votum = ?, Datum = ? WHERE AnID = ? AND Schluessel = ?")
            ->execute([$wichtig, $votum, date('Y-m-d H:i:s'), $antrag_id, $schluessel]);
    } else {
        $pdo->prepare("INSERT INTO `$tabelle` (AnID, Schluessel, Wichtig, Votum, Datum) VALUES (?, ?, ?, ?, ?)")
            ->execute([$antrag_id, $schluessel, $wichtig, $votum, date('Y-m-d H:i:s')]);
    }
}

/**
 * Rendert die beiden Stimmungsbild-Fragen (Wichtigkeit zuerst, dann
 * Zustimmung) mit dem bisherigen Gesamtbild (Tally) und - wenn
 * $darf_abstimmen - den Radios zur eigenen Eingabe direkt daneben. Erzeugt
 * bewusst KEIN eigenes &lt;form&gt;, damit dieselbe Darstellung sowohl im
 * Einzelformular (render_votum_block(), Feldnamen "wichtig"/"votum") als
 * auch im Sammelformular (tab_stimmungsbild.php, Array-Feldnamen je
 * Antrag-ID) verwendet werden kann.
 *
 * $ergebnis_platzhalter: wenn nicht null, wird dieser Text statt der echten
 * Zahlen angezeigt (siehe stimmungsbild_platzhalter()) - das eigene
 * Abstimmen bleibt davon unberührt und funktioniert immer, wenn
 * $darf_abstimmen.
 */
function render_votum_felder(array $summe_wichtig, array $summe_votum, ?array $eigenes, bool $darf_abstimmen, string $wichtig_name, string $votum_name, ?string $ergebnis_platzhalter = null): void {
    ?>
    <div style="margin-bottom:8px;">
        <div style="color:var(--text-secondary); margin-bottom:4px;">
            <strong>Ich finde diesen Antrag</strong>
            <?php if ($ergebnis_platzhalter !== null): ?>
                <span style="font-style:italic;"><?= h($ergebnis_platzhalter) ?></span>
            <?php else: ?>
                <?php foreach (VOTUM_WICHTIGKEIT as $wert => $w): ?>
                    <?= $w['emoji'] ?> <?= (int)$summe_wichtig[$wert] ?>&nbsp;&nbsp;
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if ($darf_abstimmen): ?>
            <?php foreach (VOTUM_WICHTIGKEIT as $wert => $w): ?>
                <label style="margin-right:10px; font-weight:400;">
                    <input type="radio" name="<?= h($wichtig_name) ?>" value="<?= $wert ?>" <?= ($eigenes && (int)$eigenes['Wichtig'] === $wert) ? 'checked' : '' ?>>
                    <?= $w['emoji'] ?> <?= h($w['label']) ?>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div style="margin-bottom:6px;">
        <div style="color:var(--text-secondary); margin-bottom:4px;">
            <strong>Meine Meinung dazu:</strong>
            <?php if ($ergebnis_platzhalter !== null): ?>
                <span style="font-style:italic;"><?= h($ergebnis_platzhalter) ?></span>
            <?php else: ?>
                <?php foreach (VOTUM_ZUSTIMMUNG as $wert => $z): ?>
                    <?= $z['emoji'] ?> <?= (int)$summe_votum[$wert] ?>&nbsp;&nbsp;
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if ($darf_abstimmen): ?>
            <?php foreach (VOTUM_ZUSTIMMUNG as $wert => $z): ?>
                <label style="margin-right:10px; font-weight:400;">
                    <input type="radio" name="<?= h($votum_name) ?>" value="<?= $wert ?>" <?= ($eigenes && (int)$eigenes['Votum'] === $wert) ? 'checked' : '' ?>>
                    <?= $z['emoji'] ?> <?= h($z['label']) ?>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Rendert das Stimmungsbild-Widget fuer einen einzelnen Antrag - wieder-
 * verwendet fuer den Hauptantrag selbst und fuer jeden einzelnen
 * Änderungs-/Verfahrensantrag (Kernprozess 06 gilt für Haupt- und
 * Nebenanträge). Zeigt zu beiden Fragen immer das bisherige Gesamtbild
 * (alle abgegebenen Stimmen) UND - solange das noch möglich ist - direkt
 * daneben das eigene Eingabeformular, genau wie im Altsystem in der
 * Antrag-Bearbeiten-Ansicht: man sieht das Stimmungsbild und gibt
 * gleichzeitig die eigene Stimme ab. Fuer die Abgabe mehrerer Voten auf
 * einmal siehe stattdessen tab_stimmungsbild.php ("Alle öffnen").
 *
 * $ist_redaktion und $unterstuetzungsfrist_abgelaufen werden fuer die
 * Sichtbarkeits-Regeln der Ergebnisse gebraucht (siehe
 * stimmungsbild_platzhalter()) - das eigene Abstimmen ist davon unberührt.
 */
function render_votum_block(PDO $pdo, int $jahr, array $antrag, ?array $mitglied, bool $ist_redaktion, bool $unterstuetzungsfrist_abgelaufen): void {
    $antrag_id = (int)$antrag['ID'];
    $summe_wichtig = get_votum_wichtig_summe($pdo, $jahr, $antrag_id);
    $summe_votum = get_votum_summe($pdo, $jahr, $antrag_id);
    $schluessel = $mitglied['Schluessel'] ?? '';
    $eigenes = ($mitglied && $schluessel !== '') ? get_eigenes_votum($pdo, $jahr, $antrag_id, $schluessel) : null;
    $abgestimmt = antrag_wurde_abgestimmt($antrag);
    $darf_abstimmen = $mitglied && $schluessel !== '' && !$abgestimmt;
    $config = get_config($pdo);
    $platzhalter = stimmungsbild_platzhalter($pdo, $jahr, $antrag_id, $ist_redaktion, $unterstuetzungsfrist_abgelaufen, $eigenes !== null, $config);
    ?>
    <div style="margin-top:10px; padding-top:8px; border-top:1px dotted var(--border-color); font-size:12.5px;">
        <?php if ($darf_abstimmen): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="votum">
            <input type="hidden" name="antrag_id" value="<?= $antrag_id ?>">
        <?php endif; ?>

        <?php render_votum_felder($summe_wichtig, $summe_votum, $eigenes, $darf_abstimmen, 'wichtig', 'votum', $platzhalter); ?>

        <?php if ($mitglied && $schluessel === '' && !$abgestimmt): ?>
            <p style="color:var(--danger); font-size:12.5px;">
                Für dein Konto liegt derzeit kein Stimmungsbild-Schlüssel vor - bitte wende dich an die Versammlungsleitung.
            </p>
        <?php endif; ?>

        <?php if ($darf_abstimmen): ?>
            <button type="submit" class="btn btn-secondary" style="padding:5px 12px; font-size:12px;">Abgeben</button>
        </form>
        <?php endif; ?>
    </div>
    <?php
}
