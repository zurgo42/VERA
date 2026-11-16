<?php
/**
 * tab_wahlgaenge.php - Admin-Funktion "Wahlgänge verwalten" (Abstimmungsmaschine,
 * Phase 6, siehe dokumentation/Abstimmungsmaschine.md und wahl_functions.php).
 * Hier legt die Redaktion im Vorfeld fest, welche Wahlgänge es gibt, mit
 * welchem Modus (A/B/C) und welchen Kandidat:innen - genau wie bei den
 * Anträgen/der Tagesordnung. Die Wahl selbst (starten/beenden/auswerten)
 * läuft während der Versammlung über die eigene Beamersteuerung-Seite,
 * siehe tab_beamersteuerung.php, Abschnitt "Abstimmungsmaschine".
 */

require_once __DIR__ . '/wahl_functions.php';

$wahlgaenge = get_wahlgaenge($pdo, $jahr);
?>
<h3>Wahlgänge</h3>
<p style="color:var(--text-secondary); font-size:13.5px;">
    Bis zu <?= WAHL_MAX_KANDIDATEN ?> Kandidat:innen je Wahlgang. Drei Modi, je nach Satzung:
</p>
<ul style="color:var(--text-secondary); font-size:13px; margin:0 0 18px 18px;">
    <li><strong>A</strong> - jede:r Kandidat:in einzeln mit Ja/Nein/Enthaltung; gewählt ist, wer ein positives Stimmendelta (Ja minus Nein) hat.</li>
    <li><strong>B</strong> - eine Stimme pro Stimmkarte für eine:n Kandidat:in; gewählt ist, wer die meisten Stimmen bekommt (ein Amt).</li>
    <li><strong>C</strong> - so viele Stimmen pro Stimmkarte wie Ämter zu besetzen sind; gewählt sind die Kandidat:innen mit den meisten Stimmen.</li>
</ul>

<?php foreach ($wahlgaenge as $w): ?>
    <details style="margin-bottom:10px; border:1px solid var(--border-color); border-radius:6px; padding:10px 14px;">
        <summary style="cursor:pointer;">
            🗳️ <strong><?= h($w['bezeichnung']) ?></strong>
            — Modus <?= h($w['modus']) ?><?= $w['anzahl_aemter'] !== null ? ', ' . (int)$w['anzahl_aemter'] . ' Amt/Ämter' : '' ?>,
            <?= count($w['kandidaten']) ?> Kandidat:in(nen)
        </summary>
        <form method="post" style="margin-top:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="wahlgang_aktualisieren">
            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <?php include __DIR__ . '/tab_wahlgang_felder.php'; ?>
            <button type="submit" class="btn">Speichern</button>
        </form>
        <form method="post" style="margin-top:10px;" onsubmit="return confirm('Diesen Wahlgang wirklich löschen?');">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="wahlgang_loeschen">
            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <button type="submit" class="btn btn-secondary" style="color:var(--danger);">Wahlgang löschen</button>
        </form>
    </details>
<?php endforeach; ?>

<h3 style="margin-top:24px;">Neuen Wahlgang anlegen</h3>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="wahlgang_anlegen">
    <?php $w = ['bezeichnung' => '', 'modus' => 'B', 'anzahl_aemter' => null, 'kandidaten' => []]; include __DIR__ . '/tab_wahlgang_felder.php'; ?>
    <button type="submit" class="btn">Wahlgang anlegen</button>
</form>
