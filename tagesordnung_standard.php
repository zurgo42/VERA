<?php
/**
 * tagesordnung_standard.php - Die vorgegebene Standard-Tagesordnung.
 *
 * Wird sowohl von der Anzeige (tab_tagesordnung.php) als auch spaeter
 * von der Redaktionsmaske zum Bearbeiten verwendet, damit beide Seiten
 * garantiert dieselbe Grundliste kennen.
 *
 * 'frei' => true markiert die drei freien Zusatzpunkte aus der Vorgabe.
 * Jeder Punkt bekommt serverseitig eine 'aktiv' => true Voreinstellung -
 * das Redaktionsteam kann Punkte spaeter per Checkbox abwaehlen.
 *
 * 'beschlussentwurf' => Vorbelegter Beschlussentwurf-Text (siehe
 * top_inhalte_speichern()), den standard_tagesordnung_laden() beim Anlegen
 * eines neuen Jahres direkt mit einsetzt - '' = kein Vorschlag (z. B. bei
 * reinen Ablaufpunkten wie "Eröffnung und Begrüßung" oder bei Wahlen, die
 * ohnehin über die Wahlgänge-Funktion laufen statt über einen Beschlusstext).
 * Gilt fuer jedes neu angelegte Jahr (process_tagesordnung.php) UND fuer den
 * Demo-Datensatz (demo_daten_functions.php nutzt dieselbe Funktion) - beide
 * Wege teilen sich deshalb bewusst dieselbe Vorgabenliste statt zweier
 * getrennt gepflegter Textsammlungen.
 */

function get_standard_tagesordnung(): array {
    return [
        ['titel' => 'Eröffnung und Begrüßung',                                        'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die MV stimmt der Zulassung der Gäste gemaß mündlichem Vortrag zu'],
        ['titel' => 'Feststellung der Beschlussfähigkeit',                            'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Zur MV wurde form- und fristgerecht eingeladen, sie ist damit beschlussfähig'],
        ['titel' => 'Wahl der Versammlungsleitung',                                   'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die MV wählt XXX als Versammlungsleiterin'],
        ['titel' => 'Wahl der Protokollführung',                                      'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die MV wählt XXX als Protokollführer'],
        ['titel' => 'Genehmigung der Tagesordnung',                                   'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die Mitgliederversammlung genehmigt die vorliegende Tagesordnung.'],
        ['titel' => 'Genehmigung des Protokolls der vorherigen MV',                   'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die Mitgliederversammlung genehmigt das Protokoll der letzten Mitgliederversammlung.'],
        ['titel' => 'Bericht des Vorstandes über das abgelaufene Geschäftsjahr',      'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die MV nimmt den Bericht des Vorstands zur Kenntnis'],
        ['titel' => 'Jahresabschluss, Bericht der Kassenprüfer',                      'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die MV nimmt den Bericht der Kassenprüfer zur Kenntnis'],
        ['titel' => 'Entlastung der Mitglieder des Vorstandes',                       'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die Mitgliederversammlung entlastet die Mitglieder des Vorstandes für das abgelaufene Geschäftsjahr.'],
        ['titel' => 'Wahl des Vorstands',                                             'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Als Vorstandsmitglied stehen zur Wahl:'],
        ['titel' => 'Wahl der Kassenprüfer',                                          'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Als Kassenprüfer stehen zur Wahl:'],
        ['titel' => 'Weitere Wahlen ...',                                             'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Als XXX stehen zur Wahl:'],
        ['titel' => 'Feststellung und Bekanntgabe der Ergebnisse der Wahlen',         'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Gewählt sind:'],
        ['titel' => 'Anträge gemäß der untenstehenden Liste',                        'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die MV stimmt der Behandlung der Anträge in der vorgeschlagenen Reihenfolge zu'],
        ['titel' => 'Durchführung künftiger Mitgliederversammlungen',                'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Die nächste Mitgliederversammlung findert am XX.XX.XXX in XXX statt.'],
        ['titel' => 'Verschiedenes',                                                  'aktiv' => true, 'frei' => false, 'beschlussentwurf' => 'Im TOP Verschiedenes dürfen keine Beschlüsse gefasst werden'],
        ['titel' => '',                                                               'aktiv' => false, 'frei' => true, 'beschlussentwurf' => ''],
        ['titel' => '',                                                               'aktiv' => false, 'frei' => true, 'beschlussentwurf' => ''],
        ['titel' => '',                                                               'aktiv' => false, 'frei' => true, 'beschlussentwurf' => ''],
    ];
}
