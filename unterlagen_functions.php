<?php
/**
 * unterlagen_functions.php - Datenzugriff fuer "Unterlagen <Jahr>" (frueher
 * "Allgemeine Unterlagen"; Tabelle mvdateien, erweitert um id/beschreibung -
 * siehe migrations/003_mvdateien.sql) UND fuer "Weitere Unterlagen" (frueher
 * "Nützliche Links"): dieselbe Tabelle, aber mit der Jahreszahl 0 als
 * Sentinel fuer "jahresunabhaengig" - eine echte MV faengt nie bei Jahr 0
 * an, das Sentinel kann also nie mit einem echten Jahr kollidieren.
 *
 * schonda ("zeigen") ist eine echte Spalte aus dem Bestandssystem
 * (mv.php: "$da['schonda']==1" -> abrufbar, sonst "noch nicht da: ...") -
 * hier per Default 0 (ausgeblendet), da viele Unterlagen erst nach und
 * nach bereitgestellt werden.
 */

/** Gueltige Werte fuer die Spalte "farbe" (Gruppierung durch Hintergrundfarbe je Dokumentzeile). */
const UNTERLAGEN_FARBEN = [
    'weiss'    => 'Weiß (keine)',
    'hellblau' => 'Hellblau',
    'hellgrau' => 'Hellgrau',
];

/**
 * Stellt die Tabelle mvdateien sicher (CREATE TABLE IF NOT EXISTS) - bei
 * Mensa (Bestandssystem) immer ein No-Op, da dort laengst vorhanden; bei
 * einer komplett neuen Installation ohne Bestandssystem entsteht sie hier
 * zum allerersten Mal, mit denselben Spalten wie im Bestandssystem (siehe
 * migrations/003_mvdateien.sql fuer id/beschreibung).
 */
function mvdateien_spalten_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mvdateien (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jahr INT NOT NULL, lfdnr INT DEFAULT 0, datei VARCHAR(255) DEFAULT '',
            bezeichnung VARCHAR(150) DEFAULT '', beschreibung VARCHAR(500) NULL,
            schonda TINYINT(1) DEFAULT 0, farbe VARCHAR(10) DEFAULT 'weiss', spaltenumbruch TINYINT(1) DEFAULT 0,
            INDEX idx_jahr (jahr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!spalte_existiert($pdo, 'mvdateien', 'schonda')) {
        try {
            $pdo->exec('ALTER TABLE mvdateien ADD COLUMN schonda TINYINT(1) DEFAULT 0');
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    if (!spalte_existiert($pdo, 'mvdateien', 'farbe')) {
        try {
            $pdo->exec("ALTER TABLE mvdateien ADD COLUMN farbe VARCHAR(10) DEFAULT 'weiss'");
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    if (!spalte_existiert($pdo, 'mvdateien', 'spaltenumbruch')) {
        try {
            $pdo->exec('ALTER TABLE mvdateien ADD COLUMN spaltenumbruch TINYINT(1) DEFAULT 0');
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    $geprueft = true;
}

/** $nur_sichtbare = true blendet Eintraege mit schonda <> 1 aus (fuer die normale Ansicht, nicht die Redaktionsmaske). */
function get_unterlagen(PDO $pdo, int $jahr, bool $nur_sichtbare = false): array {
    mvdateien_spalten_sicherstellen($pdo);
    if ($nur_sichtbare) {
        $stmt = $pdo->prepare('SELECT * FROM mvdateien WHERE jahr = ? AND schonda = 1 ORDER BY lfdnr ASC, id ASC');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM mvdateien WHERE jahr = ? ORDER BY lfdnr ASC, id ASC');
    }
    $stmt->execute([$jahr]);
    return $stmt->fetchAll();
}

function unterlage_naechste_lfdnr(PDO $pdo, int $jahr): int {
    $stmt = $pdo->prepare('SELECT MAX(lfdnr) AS m FROM mvdateien WHERE jahr = ?');
    $stmt->execute([$jahr]);
    $m = $stmt->fetch()['m'];
    return $m === null ? 1 : (int)$m + 1;
}

function unterlage_hinzufuegen(PDO $pdo, int $jahr, string $titel, string $beschreibung, string $link): void {
    mvdateien_spalten_sicherstellen($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO mvdateien (jahr, lfdnr, datei, bezeichnung, beschreibung, schonda) VALUES (?, ?, ?, ?, ?, 0)'
    );
    $stmt->execute([$jahr, unterlage_naechste_lfdnr($pdo, $jahr), $link, $titel, $beschreibung]);
}

/**
 * $jahr wird mitgeprueft, damit sich kein Eintrag eines anderen Jahres
 * aendern laesst. $farbe steuert die Gruppierung per Hintergrundfarbe
 * (siehe UNTERLAGEN_FARBEN), $spaltenumbruch markiert das erste Dokument
 * der rechten Spalte in der zweispaltigen Ansicht (siehe tab_unterlagen.php).
 */
function unterlage_aktualisieren(PDO $pdo, int $jahr, int $id, string $titel, string $beschreibung, string $link, bool $zeigen, string $farbe, bool $spaltenumbruch): void {
    mvdateien_spalten_sicherstellen($pdo);
    if (!array_key_exists($farbe, UNTERLAGEN_FARBEN)) {
        $farbe = 'weiss';
    }
    $stmt = $pdo->prepare(
        'UPDATE mvdateien SET bezeichnung = ?, beschreibung = ?, datei = ?, schonda = ?, farbe = ?, spaltenumbruch = ? WHERE id = ? AND jahr = ?'
    );
    $stmt->execute([$titel, $beschreibung, $link, $zeigen ? 1 : 0, $farbe, $spaltenumbruch ? 1 : 0, $id, $jahr]);
}

function unterlage_loeschen(PDO $pdo, int $jahr, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM mvdateien WHERE id = ? AND jahr = ?');
    $stmt->execute([$id, $jahr]);
}

/** Vertauscht die Sortiernummer (lfdnr) eines Eintrags mit seinem direkten Nachbarn. */
function unterlage_verschieben(PDO $pdo, int $jahr, int $id, string $richtung): void {
    $stmt = $pdo->prepare('SELECT id, lfdnr FROM mvdateien WHERE jahr = ? ORDER BY lfdnr ASC, id ASC');
    $stmt->execute([$jahr]);
    $reihenfolge = $stmt->fetchAll();

    $index = null;
    foreach ($reihenfolge as $i => $row) {
        if ((int)$row['id'] === $id) { $index = $i; break; }
    }
    if ($index === null) return;

    $nachbar_index = $richtung === 'hoch' ? $index - 1 : $index + 1;
    if ($nachbar_index < 0 || $nachbar_index >= count($reihenfolge)) return;

    $a = $reihenfolge[$index];
    $b = $reihenfolge[$nachbar_index];

    $update = $pdo->prepare('UPDATE mvdateien SET lfdnr = ? WHERE id = ?');
    $update->execute([$b['lfdnr'], $a['id']]);
    $update->execute([$a['lfdnr'], $b['id']]);
}

/**
 * Saet fuer "Weitere Unterlagen" (jahr=0) einmalig den einen
 * jahresunabhaengigen Link, der im Bestandssystem innerhalb von
 * "Allgemeine Unterlagen" stand (mv.php: "Weitere Dokumente" -> MTool) -
 * nur wenn dort noch gar nichts hinterlegt ist, um bestehende
 * Redaktionsarbeit nie zu ueberschreiben.
 */
function weitere_unterlagen_seed_falls_leer(PDO $pdo): void {
    if (get_unterlagen($pdo, 0)) {
        return;
    }
    unterlage_hinzufuegen($pdo, 0, 'Weitere Dokumente', '', 'https://example.org/dokumente'   /* Beispiel-Link: in der Installation anpassen */);
}

/**
 * Teilt eine (bereits fertig sortierte) Liste von Unterlagen fuer die
 * zweispaltige Ansicht (ab Laptop-Breite, siehe tab_unterlagen.php) an der
 * Stelle des ersten Eintrags mit spaltenumbruch=1: alles davor bleibt in
 * der linken Spalte, dieser Eintrag und alles danach wandert in die
 * rechte. Ist kein Umbruch gesetzt, ist die rechte Spalte leer (einspaltige
 * Darstellung).
 *
 * @return array{0:array,1:array}
 */
function unterlagen_in_spalten(array $dateien): array {
    foreach ($dateien as $index => $d) {
        if (!empty($d['spaltenumbruch'])) {
            return [array_slice($dateien, 0, $index), array_slice($dateien, $index)];
        }
    }
    return [$dateien, []];
}

/**
 * Rendert die Redaktions-Bearbeitungszeilen einer Unterlagen-Gruppe
 * ($ziel_jahr = die echte Jahreszahl fuer "Unterlagen <Jahr>" oder 0 fuer
 * die allgemeinen, jahresuebergreifenden Dokumente) - je Zeile ein
 * Formular mit Titel/Kurzbeschreibung/Link/zeigen/Farbe/Spaltenumbruch
 * sowie ▲/▼ zum Verschieben. Der Löschen-Button referenziert das
 * gemeinsame externe Formular #loeschform (siehe
 * tab_unterlagen_bearbeiten.php) - Formulare duerfen in HTML nicht
 * verschachtelt werden, daher ein zentrales Loeschformular fuer beide
 * Gruppen statt eines pro Zeile.
 */
function render_unterlagen_bearbeiten_zeilen(array $dateien, int $ziel_jahr): void {
    foreach ($dateien as $i => $d) {
        ?>
        <div class="top-row" style="flex-wrap:wrap; gap:6px 10px;">
            <form method="post" style="display:contents;">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="aktualisieren">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="ziel_jahr" value="<?= $ziel_jahr ?>">

                <input type="text" name="titel" value="<?= h($d['bezeichnung']) ?>" placeholder="Titel" style="flex:1 1 140px; padding:5px 8px; font-size:13.5px;">
                <input type="text" name="beschreibung" value="<?= h($d['beschreibung'] ?? '') ?>" placeholder="Kurzbeschreibung" style="flex:1 1 160px; padding:5px 8px; font-size:13.5px;">
                <input type="text" name="link" value="<?= h($d['datei']) ?>" placeholder="Link" style="flex:1 1 160px; padding:5px 8px; font-size:13.5px;">
                <select name="farbe" style="padding:5px 6px; font-size:13px;">
                    <?php foreach (UNTERLAGEN_FARBEN as $wert => $label): ?>
                        <option value="<?= h($wert) ?>" <?= ($d['farbe'] ?? 'weiss') === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="font-weight:400; white-space:nowrap; font-size:13px;">
                    <input type="checkbox" name="zeigen" value="1" <?= !empty($d['schonda']) ? 'checked' : '' ?>> zeigen
                </label>
                <label style="font-weight:400; white-space:nowrap; font-size:13px;" title="Ab hier beginnt die rechte Spalte in der zweispaltigen Ansicht (Laptop)">
                    <input type="checkbox" name="spaltenumbruch" value="1" <?= !empty($d['spaltenumbruch']) ? 'checked' : '' ?>> neue Spalte ab hier
                </label>
                <button type="submit" class="btn" style="padding:5px 10px; font-size:12px;">Speichern</button>
            </form>

            <form method="post" style="display:contents;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="ziel_jahr" value="<?= $ziel_jahr ?>">
                <input type="hidden" name="richtung" value="hoch">
                <button type="submit" name="aktion" value="verschieben" class="btn btn-secondary" style="padding:5px 8px; font-size:12px;" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
            </form>
            <form method="post" style="display:contents;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="ziel_jahr" value="<?= $ziel_jahr ?>">
                <input type="hidden" name="richtung" value="runter">
                <button type="submit" name="aktion" value="verschieben" class="btn btn-secondary" style="padding:5px 8px; font-size:12px;" <?= $i === count($dateien) - 1 ? 'disabled' : '' ?>>▼</button>
            </form>

            <button type="submit" form="loeschform" class="btn-secondary" style="padding:5px 10px; font-size:12px; border-radius:6px; border:none; color:white; cursor:pointer;"
                    onclick="document.getElementById('loeschform_id').value='<?= (int)$d['id'] ?>'; document.getElementById('loeschform_ziel_jahr').value='<?= $ziel_jahr ?>';">🗑️</button>
        </div>
        <?php
    }
}

/** Rendert eine Liste von Unterlagen-Zeilen (Titel/Link + Kurzbeschreibung), mit Hintergrundfarbe je nach "farbe"-Feld - fuer eine Spalte der zweispaltigen Ansicht (siehe tab_unterlagen.php). */
function render_unterlagen_zeilen(array $dateien): void {
    foreach ($dateien as $d) {
        $farbe = $d['farbe'] ?? 'weiss';
        $klasse = $farbe === 'hellblau' ? ' unterlage-hellblau' : ($farbe === 'hellgrau' ? ' unterlage-hellgrau' : '');
        ?>
        <div class="top-row<?= $klasse ?>" style="flex-wrap:wrap; align-items:flex-start;">
            <div style="flex:1 1 auto;">
                <a href="<?= h($d['datei']) ?>" target="_blank" rel="noopener"><?= h($d['bezeichnung']) ?></a>
                <?php if (!empty($d['beschreibung'])): ?>
                    <div style="color:var(--text-muted); font-size:12.5px;"><?= h($d['beschreibung']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

/**
 * Uebernimmt alle Unterlagen-Eintraege aus $vorjahr nach $jahr - Titel,
 * Kurzbeschreibung und Farbgruppierung unveraendert, "zeigen" (schonda) und
 * Spaltenumbruch immer zurueckgesetzt (im neuen Jahr ist ja noch nichts
 * bestaetigt bereitgestellt, und der Umbruchpunkt passt bei einer anderen
 * Dokumentenzahl womoeglich nicht mehr), und die Jahreszahl in Links, die
 * auf das eigene Dokumentenverzeichnis zeigen (z. B.
 * "/docs2026/Einladung.pdf"), automatisch auf das neue Jahr angepasst.
 * Externe Links (andere Domains) bleiben unveraendert.
 * Gibt die Zahl der uebernommenen Eintraege zurueck.
 */
function unterlagen_aus_vorjahr_uebernehmen(PDO $pdo, int $vorjahr, int $jahr): int {
    $alte = get_unterlagen($pdo, $vorjahr);
    $stmt = $pdo->prepare(
        'INSERT INTO mvdateien (jahr, lfdnr, datei, bezeichnung, beschreibung, schonda, farbe) VALUES (?, ?, ?, ?, ?, 0, ?)'
    );
    foreach ($alte as $d) {
        $link = str_replace('docs' . $vorjahr, 'docs' . $jahr, $d['datei']);
        $stmt->execute([$jahr, $d['lfdnr'], $link, $d['bezeichnung'], $d['beschreibung'] ?? '', $d['farbe'] ?? 'weiss']);
    }
    return count($alte);
}
