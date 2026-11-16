<?php
/**
 * tab_einstellungen.php - Jahr- und Anzeige-Einstellungen fuer alle Nutzer.
 */
// Nur echte VERA-Jahre, keine BEAM-Projekte (siehe BEAM_PROJEKT_ID_START in
// db_functions.php). CAST(jahr AS UNSIGNED), da mvtexte.jahr (altes System)
// als VARCHAR angelegt ist - siehe Kommentar an get_aktuelles_jahr().
// Nicht privilegierte Nutzer:innen sehen ein noch nicht freigegebenes,
// frisch angelegtes Jahr gar nicht erst in der Auswahl (siehe
// get_sichtbares_jahr() in index.php) - Admin/Redaktion sehen alle Jahre.
$jahr_sql = 'SELECT jahr FROM mvtexte WHERE CAST(jahr AS UNSIGNED) < ?' . ($ist_redaktion ? '' : ' AND freigegeben = 1') . ' ORDER BY CAST(jahr AS UNSIGNED) DESC';
$stmt = $pdo->prepare($jahr_sql);
$stmt->execute([BEAM_PROJEKT_ID_START]);
$jahre = array_column($stmt->fetchAll(), 'jahr');
$aktuelles_jahr = get_aktuelles_jahr($pdo);
?>
<h2>Jahr ändern</h2>

<h3>Jahr</h3>
<p style="color:var(--text-secondary); font-size:13.5px;">
    Die Auswahl gilt für die gesamte Sitzung (auch beim Wechsel zwischen den Menüpunkten), bis sie hier
    wieder geändert wird — praktisch z. B. zum Testen in einem anderen Jahr als dem aktuellen
    (<strong><?= h((string)$aktuelles_jahr) ?></strong>).
</p>
<form method="get">
    <input type="hidden" name="tab" value="einstellungen">
    <select name="jahr" onchange="this.form.submit()">
        <?php foreach ($jahre as $j): ?>
            <option value="<?= h((string)$j) ?>" <?= (int)$j === $jahr ? 'selected' : '' ?>>
                <?= h((string)$j) ?><?= (int)$j === $aktuelles_jahr ? ' (aktuell)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<h3>Anzeige</h3>
<div class="info-box">Dunkelmodus lässt sich oben rechts über das Mond-Symbol umschalten.</div>
