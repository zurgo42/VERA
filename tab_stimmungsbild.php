<?php
/**
 * tab_stimmungsbild.php - Alle Voten (und, solange die Frist läuft, auch
 * die Unterstützungs-Entscheidung) auf einer Seite abgeben (Sammel-
 * Abstimmung). Einzeln jeden Antrag zu öffnen und einzeln abzuschicken
 * (tab_antrag_liste.php -> tab_antrag_detail.php) ist bei vielen Anträgen
 * zu mühsam - hier stehen alle noch offenen Hauptanträge samt ihrer
 * Änderungs-/Verfahrensanträge eingeklappt untereinander, per "Alle
 * öffnen" auf einen Schlag aufklappbar, und werden über EIN gemeinsames
 * Formular auf einmal gespeichert (siehe process_antraege.php: Aktion
 * "stimmungsbild_alle").
 *
 * Die Mitantragstellerschaft gehört bewusst NICHT hierher - anders als die
 * Unterstützung ist sie immer namentlich und eine bewusstere Erklärung
 * ("ich trete als Mitantragsteller:in auf"), die weiterhin nur in der
 * Einzelansicht (tab_antrag_detail.php) abgegeben wird.
 */

require_once __DIR__ . '/votum_functions.php';
require_once __DIR__ . '/unterstuetzung_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

$schluessel = $mitglied['Schluessel'] ?? '';
$darf_teilnehmen = $mitglied && $schluessel !== '';

$config = get_config($pdo);
$frist_abgelaufen = ist_unterstuetzungsfrist_abgelaufen($mvtexte, $config);
$frist_datum_text = unterstuetzungsfrist_datum_text($mvtexte, $config);
$schwellenwert = (int)($config['unterstuetzung_schwellenwert'] ?? 10);

$sortierung = antrag_sortierung_aktuell();
$hauptantraege = array_filter(
    get_hauptantraege_mit_antragsteller($pdo, $jahr, $sortierung),
    fn($a) => !antrag_wurde_abgestimmt($a)
);
?>
<h2>Stimmungsbild abgeben</h2>
<p style="font-size:13.5px; color:var(--text-secondary);">
    Hier lassen sich alle noch offenen Anträge auf einmal bewerten<?= (!$frist_abgelaufen && $darf_teilnehmen) ? ' und - solange die Unterstützungsfrist läuft - auch gleich die Unterstützung erklären' : '' ?>,
    statt jeden einzeln zu öffnen und abzuschicken: Felder ausfüllen, unten einmal auf „Alle abgeben" klicken.
    Nicht ausgefüllte Anträge werden dabei übersprungen - du kannst also auch nur einen Teil abgeben und den Rest später nachholen.
</p>

<?php if (!$darf_teilnehmen): ?>
    <div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">
        Für dein Konto liegt derzeit kein Stimmungsbild-Schlüssel vor - bitte wende dich an die Versammlungsleitung.
    </div>
<?php endif; ?>

<?php if (!$hauptantraege): ?>
    <div class="info-box">Es liegen derzeit keine offenen Anträge zur Bewertung vor.</div>
<?php else: ?>
    <?php if ($darf_teilnehmen): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="stimmungsbild_alle">
    <?php endif; ?>

    <div style="display:flex; gap:8px; align-items:center; margin-bottom:14px; flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" id="stimmungsbildToggle" style="padding:6px 12px; font-size:13px;">📖 Alle öffnen</button>
        <?php if ($darf_teilnehmen): ?>
            <button type="submit" class="btn" style="padding:6px 14px; font-size:13px;">Alle abgeben</button>
        <?php endif; ?>
    </div>

    <div id="stimmungsbildListe">
        <?php foreach ($hauptantraege as $a): ?>
            <?php
            $antrag_id = (int)$a['ID'];
            $summe_wichtig = get_votum_wichtig_summe($pdo, $jahr, $antrag_id);
            $summe_votum = get_votum_summe($pdo, $jahr, $antrag_id);
            $eigenes = $darf_teilnehmen ? get_eigenes_votum($pdo, $jahr, $antrag_id, $schluessel) : null;
            $folgeantraege = array_filter(get_folgeantraege($pdo, $jahr, $antrag_id), fn($f) => !antrag_wurde_abgestimmt($f));

            $eigene_beteiligung = $darf_teilnehmen ? get_eigene_beteiligung($pdo, $jahr, $antrag_id, $mitglied['MNr']) : null;
            $anzahl_unterstuetzer = zaehle_unterstuetzungen($pdo, $jahr, $antrag_id);
            $platzhalter = stimmungsbild_platzhalter($pdo, $jahr, $antrag_id, $ist_redaktion, $frist_abgelaufen, $eigenes !== null, $config);
            ?>
            <details class="top-item" style="margin-bottom:10px;">
                <summary class="stimmungsbild-titel">
                    <?= h($a['Nr']) ?> — <?= h($a['Titeltext']) ?>
                    <span style="font-weight:400; font-size:12px;">(<?= h(antragsteller_name($a)) ?>)</span>
                </summary>
                <div class="stimmungsbild-text">
                    <?= mb_strlen($a['Text'] ?? '') < 300 ? format_antragstext($a['Text']) : format_antragstext(mb_substr($a['Text'], 0, 300) . ' …') ?>
                </div>

                <?php if (!$frist_abgelaufen && $darf_teilnehmen): ?>
                    <div style="margin-top:10px; padding-top:8px; border-top:1px dotted var(--border-color);">
                        <label style="display:block; margin-bottom:4px; font-weight:400;">
                            <input type="checkbox" name="unterstuetzung[<?= $antrag_id ?>]" <?= $eigene_beteiligung['unterstuetzer'] ? 'checked' : '' ?>>
                            Ich möchte, dass dieser Antrag auf der MV behandelt wird
                            <?php if ($frist_datum_text): ?>
                                (von den bis zum <?= h($frist_datum_text) ?> erforderlichen <?= $schwellenwert ?> Meldungen haben wir <?= $anzahl_unterstuetzer ?>)
                            <?php else: ?>
                                (es liegen bereits <?= $anzahl_unterstuetzer ?> von erforderlichen <?= $schwellenwert ?> Meldungen vor)
                            <?php endif; ?>
                        </label>
                        <label style="display:block; margin-left:24px; margin-bottom:4px; font-weight:400; font-size:12px;">
                            <input type="checkbox" name="namentlich[<?= $antrag_id ?>]" <?= $eigene_beteiligung['namentlich'] ? 'checked' : '' ?>>
                            Dies gern mit Namensnennung
                        </label>
                    </div>
                <?php endif; ?>

                <?php render_votum_felder($summe_wichtig, $summe_votum, $eigenes, $darf_teilnehmen, "wichtig[$antrag_id]", "votum[$antrag_id]", $platzhalter); ?>

                <?php foreach ($folgeantraege as $f): ?>
                    <?php
                    $folge_id = (int)$f['ID'];
                    $f_summe_wichtig = get_votum_wichtig_summe($pdo, $jahr, $folge_id);
                    $f_summe_votum = get_votum_summe($pdo, $jahr, $folge_id);
                    $f_eigenes = $darf_teilnehmen ? get_eigenes_votum($pdo, $jahr, $folge_id, $schluessel) : null;
                    $f_platzhalter = stimmungsbild_platzhalter($pdo, $jahr, $folge_id, $ist_redaktion, $frist_abgelaufen, $f_eigenes !== null, $config);
                    ?>
                    <div style="margin-top:10px; padding-top:8px; border-top:1px dotted var(--border-color);">
                        <div style="font-weight:600; font-size:12.5px; margin-bottom:4px;">
                            <?= (int)$f['Art'] === ANTRAG_ART_AENDERUNG ? 'Änderungsantrag' : 'Verfahrensantrag' ?>
                            <?= h($f['Nr']) ?> — <?= h($f['Titeltext']) ?>
                        </div>
                        <?php render_votum_felder($f_summe_wichtig, $f_summe_votum, $f_eigenes, $darf_teilnehmen, "wichtig[$folge_id]", "votum[$folge_id]", $f_platzhalter); ?>
                    </div>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>
    </div>

    <?php if ($darf_teilnehmen): ?>
        <button type="submit" class="btn" style="padding:6px 14px; font-size:13px; margin-top:6px;">Alle abgeben</button>
    </form>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    var button = document.getElementById('stimmungsbildToggle');
    if (!button) return;
    var offen = false;
    button.addEventListener('click', function () {
        offen = !offen;
        document.querySelectorAll('#stimmungsbildListe details').forEach(function (d) { d.open = offen; });
        button.textContent = offen ? '📕 Alle schließen' : '📖 Alle öffnen';
    });
})();
</script>
