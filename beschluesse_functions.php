<?php
/**
 * beschluesse_functions.php - Datenzugriff fuer die MV-Beschluss-Sammlung
 * (Tabelle mvbeschluesse, siehe migrations/004_mvbeschluesse.sql).
 *
 * Anders als Tagesordnung/Unterlagen NICHT jahr-gebunden: die Sammlung
 * ist ein fortlaufendes Archiv aller MV-Beschluesse ueber alle Jahre
 * hinweg (die Antragsnummer traegt das Jahr bereits im eigenen Format).
 */

const MV_BESCHLUSS_STATUS = [
    1  => 'Gültig',
    0  => 'Aufhebung',
    -1 => 'Nicht beschlossen',
];

/** $gueltig: 1/0/-1 filtert auf Relevant, 9 zeigt alle Status. */
function get_beschluesse(PDO $pdo, int $gueltig, string $stichwort, string $order): array {
    $bedingungen = ['1=1'];
    $params = [];

    if ($gueltig !== 9) {
        $bedingungen[] = 'Relevant = ?';
        $params[] = $gueltig;
    }

    if ($stichwort !== '') {
        $bedingungen[] = '(antrnr = ? OR titel LIKE ? OR text LIKE ? OR begr LIKE ? OR kategorie LIKE ?)';
        $wie = '%' . $stichwort . '%';
        $params[] = $stichwort;
        $params[] = $wie;
        $params[] = $wie;
        $params[] = $wie;
        $params[] = $wie;
    }

    $richtung = $order === 'alt' ? 'ASC' : 'DESC';
    $sql = 'SELECT * FROM mvbeschluesse WHERE ' . implode(' AND ', $bedingungen) . " ORDER BY antrnr $richtung";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_beschluss(PDO $pdo, string $antrnr): ?array {
    $stmt = $pdo->prepare('SELECT * FROM mvbeschluesse WHERE antrnr = ?');
    $stmt->execute([$antrnr]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Legt einen Beschluss an oder aktualisiert ihn. Wird die Nummer dabei
 * geaendert (Umbenennung), wird der alte Datensatz zuvor geloescht -
 * REPLACE INTO ersetzt sonst nur bei gleicher Nummer.
 */
function beschluss_speichern(PDO $pdo, string $alte_nr, array $daten): void {
    if ($alte_nr !== '' && $alte_nr !== $daten['antrnr']) {
        $pdo->prepare('DELETE FROM mvbeschluesse WHERE antrnr = ?')->execute([$alte_nr]);
    }
    $stmt = $pdo->prepare(
        'REPLACE INTO mvbeschluesse (antrnr, titel, text, begr, ergebnis, dafuer, dagegen, enth, Relevant, kategorie)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $daten['antrnr'],
        $daten['titel'],
        $daten['text'],
        $daten['begr'],
        $daten['ergebnis'],
        $daten['dafuer'],
        $daten['dagegen'],
        $daten['enth'],
        $daten['relevant'],
        $daten['kategorie'],
    ]);
}

function beschluss_loeschen(PDO $pdo, string $antrnr): void {
    $pdo->prepare('DELETE FROM mvbeschluesse WHERE antrnr = ?')->execute([$antrnr]);
}

function beschluss_highlight(string $html, string $suche): string {
    if (trim($suche) === '') {
        return $html;
    }
    return preg_replace('/(' . preg_quote($suche, '/') . ')/iu', '<mark class="hl">$1</mark>', $html);
}

function beschluss_linkify(string $html): string {
    $muster = '/(https?:\/\/[^\s<]+|www\.[^\s<]+)/i';
    return preg_replace_callback($muster, function ($treffer) {
        $url = rtrim($treffer[0], '.,;!?');
        $href = str_starts_with($url, 'http') ? $url : 'http://' . $url;
        return '<a href="' . h($href) . '" target="_blank" rel="noopener">' . h($url) . '</a>';
    }, $html);
}

/** Bereitet Wortlaut/Begruendung fuer die Anzeige auf: escapen, Suchwort hervorheben, Links klickbar machen, Zeilenumbrueche erhalten. */
function beschluss_darstellen(?string $text, string $suche): string {
    $html = h($text ?? '');
    $html = beschluss_highlight($html, $suche);
    $html = beschluss_linkify($html);
    return nl2br($html);
}
