<?php
/**
 * jahr_functions.php - "Neues Jahr anlegen" (Admin-Funktion).
 *
 * Legt fuer ein neues MV-Jahr die jahr-gebundenen Tabellen an, indem die
 * Struktur des Vorjahres per CREATE TABLE ... LIKE uebernommen wird -
 * dadurch muss das genaue Bestandsschema hier nicht dupliziert werden
 * und bleibt exakt kompatibel (Spalten, Typen, Indizes, Engine).
 *
 * mvmitglieder_<neu> wird NICHT komplett vom Vorjahr uebernommen: die
 * Mitgliederliste eines Jahres bildet sich erst waehrend des laufenden
 * Antragsverfahrens aus den tatsaechlichen Zugriffen (siehe
 * get_aktuelles_mitglied()) - so faellt automatisch weg, wer
 * zwischenzeitlich ausgeschieden ist, und die Tabelle zeigt zugleich, wie
 * viele Mitglieder das Tool im jeweiligen Jahr tatsaechlich genutzt haben.
 * Uebernommen werden nur die Funktionstraeger:innen (Rolle und/oder
 * Vorstandsamt, siehe mv.php: "Vorstand und Sitzungsfunktionen
 * einkopieren"), mit ihren bisherigen Rechten, damit sie von Anfang an
 * arbeiten koennen - alle anderen Mitgliedseintraege des Vorjahres werden
 * beim Anlegen eines neuen Jahres nicht uebernommen. Alle anderen
 * jahr-gebundenen Tabellen werden bewusst leer angelegt.
 */

/** Fuer die Neukonzeption zwingend erforderliche jahr-gebundene Tabellen. */
const JAHR_TABELLEN_PFLICHT = ['mvmitglieder', 'mvantraege'];

/**
 * Weitere jahr-gebundene Tabellen des Altsystems (siehe mv.php: "betrifft
 * die Tabellen mvantraege, mvlogs, mvmitglieder, mvunterst, mvvotum").
 * Werden nur mit angelegt, wenn es sie im Vorjahr auch gibt - so bricht
 * das Anlegen eines neuen Jahres nicht an einer Tabelle ab, die die
 * Neukonzeption (noch) nicht selbst nutzt.
 */
const JAHR_TABELLEN_OPTIONAL = ['mvunterst', 'mvvotum', 'mvlogs'];

function jahr_existiert(PDO $pdo, int $jahr): bool {
    mvtexte_spalten_sicherstellen($pdo); // stellt bei einer komplett neuen Installation ueberhaupt erst die Tabelle sicher
    $stmt = $pdo->prepare('SELECT 1 FROM mvtexte WHERE jahr = ?');
    $stmt->execute([$jahr]);
    return (bool)$stmt->fetch();
}

function tabelle_existiert(PDO $pdo, string $tabelle): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$tabelle]);
    return (bool)$stmt->fetch();
}

/**
 * Prueft alle Voraussetzungen und legt bei Erfolg das neue Jahr an.
 * Gibt im Fehlerfall eine Fehlermeldung zurueck, sonst null.
 *
 * Normalfall (Mensa, oder jede Installation mit mindestens einem
 * bestehenden Jahr): die Pflicht-/optionalen Tabellen werden per
 * CREATE TABLE ... LIKE vom Vorjahr uebernommen (wie bisher). Fehlt das
 * Vorjahr komplett (ganz neue Installation ohne Bestandssystem, noch nie
 * "Neues Jahr anlegen" ausgefuehrt), entstehen die Pflicht-Tabellen
 * stattdessen ueber dieselben Selbstheilungsfunktionen, die sie sonst beim
 * ersten tatsaechlichen Zugriff anlegen wuerden (CREATE TABLE IF NOT
 * EXISTS, daher unschaedlich, falls durch einen vorherigen Gast-Zugriff
 * bereits leer vorhanden) - ohne Funktionstraeger:innen zu uebernehmen, da
 * es keine gibt.
 */
function neues_jahr_anlegen(PDO $pdo, int $vorjahr, int $neu): ?string {
    if (jahr_existiert($pdo, $neu)) {
        return 'Das Jahr ' . $neu . ' existiert bereits.';
    }
    if ($neu <= $vorjahr) {
        return 'Das neue Jahr muss größer als das bisherige Jahr (' . $vorjahr . ') sein.';
    }

    $vorjahr_vorhanden = tabelle_existiert($pdo, 'mvmitglieder_' . $vorjahr) && tabelle_existiert($pdo, 'mvantraege_' . $vorjahr);

    if (!$vorjahr_vorhanden) {
        antrag_spalten_sicherstellen($pdo, $neu);
        mvmitglieder_tabelle_sicherstellen($pdo, $neu);
    } else {
        foreach (JAHR_TABELLEN_PFLICHT as $basis) {
            $neu_name = $basis . '_' . $neu;
            if (tabelle_existiert($pdo, $neu_name)) {
                return 'Für ' . $neu . ' besteht bereits die Tabelle ' . $neu_name . ' - bitte manuell prüfen.';
            }
        }
        foreach (JAHR_TABELLEN_PFLICHT as $basis) {
            $alt = $basis . '_' . $vorjahr;
            $neu_name = $basis . '_' . $neu;
            $pdo->exec("CREATE TABLE `$neu_name` LIKE `$alt`");
            if ($basis === 'mvmitglieder') {
                mvmitglieder_funktionstraeger_uebernehmen($pdo, $alt, $neu_name);
                mvmitglieder_schluessel_neu_erzeugen($pdo, $neu_name);
            }
        }
        foreach (JAHR_TABELLEN_OPTIONAL as $basis) {
            $alt = $basis . '_' . $vorjahr;
            $neu_name = $basis . '_' . $neu;
            if (!tabelle_existiert($pdo, $alt) || tabelle_existiert($pdo, $neu_name)) {
                continue; // optionale Tabelle im Vorjahr nicht vorhanden bzw. Zieltabelle existiert schon
            }
            $pdo->exec("CREATE TABLE `$neu_name` LIKE `$alt`");
        }
    }

    // freigegeben=0: ein frisch angelegtes Jahr ist zunaechst nur fuer
    // Admin/Redaktion sichtbar (siehe get_sichtbares_jahr() in
    // db_functions.php), damit es erst in Ruhe vorbereitet werden kann -
    // ueberschreibt bewusst den Spalten-Default 1 aus
    // mvtexte_spalten_sicherstellen() (der nur bereits bestehende Jahre
    // beim Nachruesten der Spalte unveraendert oeffentlich haelt).
    mvtexte_spalten_sicherstellen($pdo);
    $stmt = $pdo->prepare('INSERT INTO mvtexte (jahr, freigegeben) VALUES (?, 0)');
    $stmt->execute([$neu]);

    return null;
}

/**
 * Gibt ein zuvor gesperrtes Jahr fuer alle Mitglieder frei (siehe
 * get_sichtbares_jahr()/freigegeben-Spalte) - einmaliger, bewusster
 * Admin-Schritt (z. B. nachdem Termine/Texte fuer das neue Jahr fertig
 * vorbereitet sind), siehe process_jahr.php.
 */
function jahr_freigeben(PDO $pdo, int $jahr): void {
    mvtexte_spalten_sicherstellen($pdo);
    $stmt = $pdo->prepare('UPDATE mvtexte SET freigegeben = 1 WHERE CAST(jahr AS UNSIGNED) = ?');
    $stmt->execute([$jahr]);
}

/**
 * Uebernimmt aus dem Vorjahr nur die Mitglieder mit Rolle und/oder
 * Vorstandsamt (Red <> 0 ODER Vorstand <> 0) ins neue Jahr - mit ihren
 * bisherigen Rechten, aber Datum = jetzt (Erstellungsdatum des neuen
 * Jahres statt des alten Zugriffsdatums). Alle anderen Mitgliedseintraege
 * des Vorjahres werden bewusst NICHT uebernommen (siehe Klassenkommentar
 * oben) - Schluessel wird hier noch nicht gesetzt, das uebernimmt
 * anschliessend mvmitglieder_schluessel_neu_erzeugen().
 */
function mvmitglieder_funktionstraeger_uebernehmen(PDO $pdo, string $alt, string $neu_name): void {
    $stmt = $pdo->prepare(
        "INSERT INTO `$neu_name` (MNr, Vorname, Name, Mail, Red, Vorstand, Datum)
         SELECT MNr, Vorname, Name, Mail, Red, Vorstand, ?
         FROM `$alt` WHERE Red <> 0 OR Vorstand <> 0"
    );
    $stmt->execute([date('Y-m-d H:i:s')]);
}

/**
 * Erzeugt fuer jedes Mitglied im neu angelegten Jahr einen frischen,
 * zufaelligen Schluessel (genau wie erzeugekey() im Altsystem,
 * ifunktionen_mv.php). Der Schluessel aus dem Vorjahr darf NICHT einfach
 * mitkopiert werden: er dient der Anonymisierung des Stimmungsbilds
 * (siehe votum_functions.php) und wird nach jeder Versammlung geloescht -
 * jedes MV-Jahr braucht seinen eigenen, frischen Schluessel-Zyklus.
 * Wuerde stattdessen der alte Wert weiterverwendet, koennte eine bereits
 * geloeschte Zuordnung Schluessel/MNr aus dem Vorjahr (z. B. aus einem
 * frueheren Backup) die Anonymisierung des neuen Jahres untergraben.
 */
function mvmitglieder_schluessel_neu_erzeugen(PDO $pdo, string $tabelle): void {
    $ids = $pdo->query("SELECT ID FROM `$tabelle`")->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare("UPDATE `$tabelle` SET Schluessel = ? WHERE ID = ?");
    foreach ($ids as $id) {
        $stmt->execute([erzeugekey_neu(), $id]);
    }
}

/** Wie erzeugekey() im Altsystem (ifunktionen_mv.php) - langer zufaelliger alphanumerischer Schluessel. */
function erzeugekey_neu(int $length = 32): string {
    $zeichen = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $ergebnis = '';
    for ($i = 0; $i < $length; $i++) {
        $ergebnis .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $ergebnis;
}

/** Pfad zum Dokumentenverzeichnis eines Jahres (Geschwisterverzeichnis von neu/, siehe mv/docs2021 ... docs2026). */
function jahr_docs_verzeichnis(int $jahr): string {
    return __DIR__ . '/../docs' . $jahr;
}

/**
 * Legt das Dokumentenverzeichnis fuer ein Jahr an (falls noch nicht
 * vorhanden) - dorthin laedt die Redaktion/der Vorstand die Unterlagen
 * des Jahres manuell hoch (PDF-Anlagen, Protokolle, Berichte, ...),
 * genau wie bisher fuer docs2021 bis docs2026. Eine .htaccess schaltet
 * die PHP-Ausfuehrung darin defensiv ab.
 */
function docs_verzeichnis_anlegen(int $jahr): void {
    $pfad = jahr_docs_verzeichnis($jahr);
    if (!is_dir($pfad)) {
        mkdir($pfad, 0755, true);
    }
    $htaccess = $pfad . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "php_flag engine off\n");
    }
}
