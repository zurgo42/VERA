<?php
/**
 * demo_daten_functions.php - Erzeugt bzw. ersetzt einen kompletten, in
 * sich stimmigen Demo-Datensatz (Tagesordnung, Anträge aller Kategorien,
 * Unterstützungen, Mitantragstellerschaft, Stimmungsbild) für ein
 * MV-Jahr - analog zu den Demo-Werkzeugen der Schwesterprojekte
 * Sitzungsverwaltung (KONA) und Mitgliederverwaltung (MISS, dort
 * tools/demo_build.php), damit potentielle Nutzer:innen sehen können,
 * was VERA leistet, ohne selbst erst Inhalte anlegen zu müssen.
 *
 * Nur im Demo-Modus erreichbar (siehe process_demo_daten.php) - das
 * Einspielen LÖSCHT beim Aufruf alle vorhandenen Anträge, Unterstützungen,
 * Stimmungsbild-Werte und die Tagesordnung des gewählten Jahres und
 * ersetzt sie durch den Demo-Satz. Niemals auf einer Installation mit
 * echten Daten anbieten.
 */

require_once __DIR__ . '/tagesordnung_functions.php';
require_once __DIR__ . '/antraege_functions.php';
require_once __DIR__ . '/unterstuetzung_functions.php';
require_once __DIR__ . '/votum_functions.php';
require_once __DIR__ . '/mitglieder_functions.php';
require_once __DIR__ . '/mitgliederquelle_functions.php';
require_once __DIR__ . '/wahl_functions.php';
require_once __DIR__ . '/db_functions.php';

/** Vorgabe-Willkommenstext für den Demo-Datensatz (siehe tab_willkommen.php). */
function demo_willkommenstext_vorlage(): string {
    return <<<TEXT
Auf dieser Seite finden Vereinsmitglieder alle wesentlichen Unterlagen zur jährlichen Mitgliederversammlung und sie können Anträge stellen, bearbeiten und bewerten.
Außerdem finden sich die Links auf
- das Diskussionstool, in dem die Anträge im Vorfeld erörtert werden
- das Wahlinfo-Tool, in dem sich die zur Wahl stehenden Personen vorstellen und in dem mit ihnen diskutiert werden kann
- alle wesentlichen Dokumente, Berichte, Finanzunterlagen und diverse allgemeine Unterlagen

Im Verlauf der Versammlung werden über dieses Tool die Folien publiziert und - wenn genutzt - Abstimmungen durchgeführt.

Die Seite bleibt auch nach der Mitgliederversammlung bis auf Weiteres online, damit Mitglieder ggf. noch Anträge/Beschlüsse und Dokumente nachvollziehen können.
TEXT;
}

/** Realistische Hauptanträge je Kategorie (1=Satzung, 2=Ordnung, 3=Sachantrag). */
function demo_hauptantraege_vorlage(): array {
    return [
        [1, 'Änderung der Beitragsordnung', 'Die Mitgliederversammlung möge beschließen, § 4 der Beitragsordnung wie folgt neu zu fassen: Der Jahresbeitrag für ordentliche Mitglieder beträgt künftig 96 €, für Familienmitgliedschaften 180 €.', 'Die bisherige Regelung ist seit 2018 unverändert und bildet die tatsächlichen Kosten des Vereins nicht mehr ab.', 'Mehreinnahmen von rund 8.000 € pro Jahr.'],
        [1, 'Verlängerung der Amtszeit des Vorstands', 'Die Mitgliederversammlung möge beschließen, § 7 Abs. 2 der Satzung zu ändern: Die Amtszeit des Vorstands wird von zwei auf drei Jahre verlängert.', 'Die bisher kurze Amtszeit erschwert eine kontinuierliche Vereinsarbeit und führt zu häufigem Wissensverlust.', 'Keine.'],
        [2, 'Einführung einer digitalen Mitgliederversammlung', 'Die Mitgliederversammlung möge beschließen, eine Durchführungsordnung für rein virtuelle oder hybride Mitgliederversammlungen zu beschließen.', 'Mehrere Mitglieder können aus beruflichen oder gesundheitlichen Gründen nicht regelmäßig persönlich teilnehmen.', 'Keine.'],
        [2, 'Anpassung der Geschäftsordnung für Anträge', 'Die Mitgliederversammlung möge beschließen, die Antragsfrist von zwei auf drei Wochen vor der Versammlung zu verlängern.', 'In der Praxis blieb zu wenig Zeit, Änderungsanträge sorgfältig vorzubereiten.', 'Keine.'],
        [3, 'Anschaffung neuer Trainingsgeräte', 'Die Mitgliederversammlung möge beschließen, für die Anschaffung neuer Trainingsgeräte 12.000 € aus der Vereinskasse zu bewilligen.', 'Die vorhandenen Geräte sind über zehn Jahre alt und teilweise reparaturbedürftig.', 'Einmalige Ausgabe von 12.000 €.'],
        [3, 'Einrichtung einer Jugendabteilung', 'Die Mitgliederversammlung möge beschließen, eine eigenständige Jugendabteilung einzurichten und hierfür eine Jugendwartin oder einen Jugendwart zu bestellen.', 'Der Verein verzeichnet seit zwei Jahren wachsenden Zulauf im Nachwuchsbereich.', 'Geschätzt 1.500 € für Ausstattung im ersten Jahr.'],
    ];
}

/**
 * Demo-Wahlgänge, einer je Modus (A/B/C, siehe wahl_functions.php) - mit
 * realistischen Namen, damit im Demo-Datensatz sichtbar wird, wie sich die
 * drei vereinsspezifischen Wahlmodi in der Bedienung unterscheiden.
 * Zeilenform: [Bezeichnung, Modus, Ämterzahl|null, Kandidat:innennamen].
 * Ämterzahl ist bei Modus A/C Pflicht (siehe wahlgang_eingabe_pruefen()) -
 * nur bei Modus B (immer genau ein Amt) bleibt sie null.
 */
function demo_wahlgaenge_vorlage(): array {
    return [
        ['Bestätigung der Kassenprüfer:innen', 'A', 2, ['Monika Brandt', 'Thorsten Vogel', 'Petra Lindner']],
        ['Wahl der/des 1. Vorsitzenden', 'B', null, ['Sabine Hoffmann', 'Jürgen Albrecht', 'Claudia Reinhardt']],
        ['Wahl der Beisitzer:innen (3 Sitze)', 'C', 3, ['Michael Krause', 'Nadine Fischer', 'Oliver Stein', 'Katrin Berger', 'Daniel Wolff']],
    ];
}

/** Änderungsanträge, jeweils bezogen auf den Hauptantrag an Index $zu_index (0-basiert, siehe demo_hauptantraege_vorlage()). */
function demo_aenderungsantraege_vorlage(): array {
    return [
        [0, 'Gestaffelte Beitragserhöhung statt Einmalschritt', 'Statt der im Hauptantrag vorgesehenen einmaligen Erhöhung wird eine Staffelung über zwei Jahre vorgeschlagen: 88 € im ersten, 96 € ab dem zweiten Jahr.', 'Eine gestaffelte Erhöhung ist für Mitglieder mit kleinem Budget leichter zu verkraften.', 'Geringere Mehreinnahmen im ersten Jahr.'],
        [4, 'Reduzierung auf 8.000 €', 'Der im Hauptantrag genannte Betrag wird auf 8.000 € reduziert, die Anschaffung erfolgt in zwei Tranchen.', 'Die Vereinskasse sollte nicht in einem Jahr vollständig belastet werden.', 'Einmalige Ausgabe von 8.000 € statt 12.000 €.'],
    ];
}

/**
 * Loescht alle Antraege/Unterstuetzungen/Stimmungsbild-Werte und die
 * Tagesordnung des gewaehlten Jahres und ersetzt sie durch einen
 * vollstaendigen Demo-Satz. Verwendet die Mitglieder der aktuell aktiven
 * Mitgliederquelle (siehe demo_mitgliederliste_laden()) als Antragsteller:innen,
 * Mitantragsteller:innen, Unterstuetzer:innen und Abstimmende - legt dafuer
 * bei Bedarf passende Eintraege in mvmitglieder_<jahr> an (wie ein
 * normaler Erstzugriff). Gibt die Anzahl angelegter Hauptantraege zurueck.
 *
 * @throws RuntimeException wenn die aktuelle Mitgliederquelle keine
 *         Mitglieder liefert (ohne Antragsteller:innen kein Demo-Datensatz
 *         moeglich).
 */
function demo_daten_einspielen(PDO $pdo, int $jahr, array $config): int {
    antrag_spalten_sicherstellen($pdo, $jahr);
    mvmitglieder_tabelle_sicherstellen($pdo, $jahr);
    unterst_tabelle_sicherstellen($pdo, $jahr);
    votum_tabelle_sicherstellen($pdo, $jahr);

    $demo_mitglieder = demo_mitgliederliste_laden($pdo, $config);
    if (!$demo_mitglieder) {
        throw new RuntimeException('Die aktuelle Mitgliederquelle liefert keine Mitglieder - ohne Antragsteller:innen lässt sich kein Demo-Datensatz erzeugen.');
    }
    $mnrs = array_values(array_unique(array_column($demo_mitglieder, 'mnr')));

    // Bestehende Inhalte dieses Jahres vollstaendig ersetzen.
    mvtop_tabellen_sicherstellen($pdo);
    $pdo->prepare('DELETE FROM `' . antrag_tabelle($jahr) . '`')->execute();
    $pdo->prepare('DELETE FROM `mvunterst_' . (int)$jahr . '`')->execute();
    $pdo->prepare('DELETE FROM `mvvotum_' . (int)$jahr . '`')->execute();
    $pdo->prepare('DELETE FROM mvtop WHERE jahr = ?')->execute([$jahr]); // mvtopdokumente folgt per ON DELETE CASCADE
    // Setzt die Tagesordnung samt der dort hinterlegten Beschlussentwurf-
    // Vorschläge (siehe tagesordnung_standard.php) - dieselbe Vorgabenliste
    // wie bei einem neuen echten MV-Jahr, kein eigener Demo-Text mehr nötig.
    standard_tagesordnung_laden($pdo, $jahr);

    mvtexte_spalten_sicherstellen($pdo);
    $pdo->prepare('UPDATE mvtexte SET willkommenstext = ? WHERE jahr = ?')->execute([demo_willkommenstext_vorlage(), $jahr]);

    wahlgaenge_tabellen_sicherstellen($pdo);
    $pdo->prepare('DELETE FROM mv_vote_wahlgaenge WHERE jahr = ?')->execute([$jahr]); // mv_vote_kandidaten folgt per ON DELETE CASCADE
    foreach (demo_wahlgaenge_vorlage() as [$bezeichnung, $modus, $aemter, $kandidatenNamen]) {
        wahlgang_anlegen($pdo, $jahr, $bezeichnung, $modus, $aemter, $kandidatenNamen);
    }

    // Jede verwendete MNr braucht einen mvmitglieder-Datensatz (Name/Mail/Schluessel).
    $schluessel_je_mnr = [];
    foreach ($mnrs as $mnr) {
        [$m, ] = mitglied_finden_oder_anlegen($pdo, $jahr, $mnr);
        $schluessel_je_mnr[$mnr] = $m['Schluessel'] ?? '';
    }
    $anzahl_mitglieder = count($mnrs);
    $pick = fn(int $i): string => $mnrs[$i % $anzahl_mitglieder];

    $schwellenwert = (int)($config['unterstuetzung_schwellenwert'] ?? 10);

    $hauptantrag_ids = [];
    $i = 0;
    foreach (demo_hauptantraege_vorlage() as $index => [$kat, $titel, $text, $begr, $fin]) {
        $ast = $pick($i++);
        $nr = hauptantrag_einreichen($pdo, $jahr, $ast, $kat, $titel, $text, $begr, $fin);
        $antrag = get_antrag_per_nr($pdo, $jahr, $nr);
        $hauptantrag_ids[$index] = $antrag;

        // Unterstuetzung: wechselnde Zahl, damit sowohl "Schwellenwert erreicht"
        // als auch "noch nicht erreicht" im Demo-Datensatz vorkommen.
        $ziel = match ($index % 3) {
            0 => max(1, (int)round($schwellenwert * 1.4)), // deutlich ueber dem Schwellenwert
            1 => max(1, (int)round($schwellenwert * 0.5)), // deutlich darunter
            default => $schwellenwert,                      // genau am Schwellenwert
        };
        for ($u = 0; $u < min($ziel, $anzahl_mitglieder); $u++) {
            $unterstuetzer = $pick($i + $u);
            if ($unterstuetzer === $ast) {
                continue;
            }
            unterstuetzung_setzen($pdo, $jahr, (int)$antrag['ID'], $unterstuetzer, true, $u % 4 !== 0);
        }
        $i += $ziel;

        // Jeder zweite Hauptantrag bekommt zusaetzlich eine:n Mitantragsteller:in.
        if ($index % 2 === 0 && $anzahl_mitglieder > 1) {
            $mitantragsteller = $pick($i++);
            if ($mitantragsteller !== $ast) {
                mitantragsteller_setzen($pdo, $jahr, (int)$antrag['ID'], $mitantragsteller, true);
            }
        }
    }

    foreach (demo_aenderungsantraege_vorlage() as [$zu_index, $titel, $text, $begr, $fin]) {
        if (!isset($hauptantrag_ids[$zu_index])) {
            continue;
        }
        $ast = $pick($i++);
        aenderungsantrag_einreichen($pdo, $jahr, $ast, $hauptantrag_ids[$zu_index], $titel, $text, $begr, $fin);
    }

    // Ein eigenstaendiger Verfahrensantrag (GO-Antrag) aus der mitgelieferten Vorlagenliste.
    $go_titel = GO_ANTRAEGE[0];
    verfahrensantrag_einreichen($pdo, $jahr, $pick($i++), null, $go_titel, 'Die Versammlung möge dem Antrag auf "' . $go_titel . '" stattgeben.', '');

    // Stimmungsbild: mehrere Mitglieder stimmen auf mehreren Hauptantraegen ab.
    $wichtig_werte = array_keys(VOTUM_WICHTIGKEIT);
    $votum_werte = array_keys(VOTUM_ZUSTIMMUNG);
    foreach ($hauptantrag_ids as $antrag) {
        $teilnehmer = min(max(3, (int)round($anzahl_mitglieder / 2)), $anzahl_mitglieder);
        for ($v = 0; $v < $teilnehmer; $v++) {
            $mnr = $pick($i + $v);
            $schluessel = $schluessel_je_mnr[$mnr] ?? '';
            if ($schluessel === '') {
                continue;
            }
            votum_abgeben($pdo, $jahr, (int)$antrag['ID'], $schluessel, $wichtig_werte[$v % count($wichtig_werte)], $votum_werte[$v % count($votum_werte)]);
        }
        $i += $teilnehmer;
    }

    return count($hauptantrag_ids);
}

/** Hilfsfunktion: laedt einen (gerade erst angelegten) Antrag anhand seiner Nr. */
function get_antrag_per_nr(PDO $pdo, int $jahr, string $nr): array {
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` WHERE Nr = ? LIMIT 1");
    $stmt->execute([$nr]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Gerade angelegter Antrag ' . $nr . ' wurde nicht gefunden.');
    }
    return $row;
}
