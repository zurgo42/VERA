<?php
/**
 * tab_kompaktliste.php - Kompakte Antragsliste mit wählbaren Zusätzen
 * (Ablösung von mvliste.php/mvauswahl.php) - für alle Mitglieder lesbar.
 * Die Beamer- und Abstimmungssteuerung steht seit Einführung der
 * Beamerbilder für TOPs/Beschlussentwürfe/Wahlgänge und der
 * Abstimmungsmaschine nicht mehr hier, sondern auf einer eigenen Seite
 * (siehe tab_beamersteuerung.php, nur Technik/Beamer-Team und
 * Redaktionsteam). Die Markierung von Anträgen ohne ausreichende
 * Unterstützung (Art=5, siehe unten) bleibt dagegen hier - das ist eine
 * inhaltliche Entscheidung der Antragsliste, keine Beamer-Bedienung, und
 * bleibt der Redaktion vorbehalten. Die Reihenfolge- und
 * Gem-Gruppierungsfestlegung erfolgt nicht hier, sondern unter
 * Admin-Funktionen (siehe tab_reihenfolge.php).
 */

require_once __DIR__ . '/unterstuetzung_functions.php';
require_once __DIR__ . '/votum_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

$sortierung = antrag_sortierung_aktuell();

$auswahl_felder = [
    'kurz'  => 'Kurztext (bis 1000 Zeichen, sonst abgebrochen)',
    'text'  => 'vollständiger Antragstext',
    'begr'  => 'Begründung und finanzielle Auswirkungen',
    'vorst' => 'Vorstandsstellungnahme',
    'votum' => 'Stimmungsbild-Ergebnisse',
    'andk'  => 'vorliegende Änderungs-/Verfahrensanträge (Nr., Titel, Antragsteller:in)',
    'anda'  => 'Text der Änderungs-/Verfahrensanträge',
    'andb'  => 'Begründung dazu',
    'andv'  => 'Stimmungsbild dazu',
];
$auswahl = [];
foreach ($auswahl_felder as $feld => $label) {
    $auswahl[$feld] = isset($_GET[$feld]);
}
$zeige_quorum = isset($_GET['zeige_quorum']);

$rueck_query = http_build_query(array_merge(['sortierung' => $sortierung], array_filter($auswahl), $zeige_quorum ? ['zeige_quorum' => 1] : []));
?>
<h2>Kompaktliste</h2>

<form method="get" style="margin-bottom:18px;">
    <input type="hidden" name="tab" value="kompaktliste">
    <p style="font-size:13.5px; color:var(--text-secondary);">
        Immer dabei: Nummer, Titel und Antragsteller:in aller Hauptanträge. Zusätzlich einblenden:
    </p>
    <?php foreach ($auswahl_felder as $feld => $label): ?>
        <label style="display:block; font-weight:400; font-size:13.5px; margin-bottom:4px;">
            <input type="checkbox" name="<?= h($feld) ?>" value="1" <?= $auswahl[$feld] ? 'checked' : '' ?>> <?= h($label) ?>
        </label>
    <?php endforeach; ?>
    <label style="display:block; font-weight:400; font-size:13.5px; margin-bottom:4px;">
        <input type="checkbox" name="zeige_quorum" value="1" <?= $zeige_quorum ? 'checked' : '' ?>>
        Anträge zeigen, die die notwendige Unterstützung nicht erreicht haben
    </label>

    <div style="margin-top:10px;">
        <label style="font-size:13.5px;">Sortierung
            <select name="sortierung" onchange="this.form.submit()" style="padding:5px 8px;">
                <?php foreach (ANTRAG_SORTIERUNGEN as $wert => $label): ?>
                    <option value="<?= h($wert) ?>" <?= $sortierung === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <button type="submit" class="btn" style="margin-top:10px;">Liste aktualisieren</button>
</form>

<?php
$config_quorum = get_config($pdo);
$schwellenwert_quorum = (int)($config_quorum['unterstuetzung_schwellenwert'] ?? 10);
$unter_quorum = [];
if ($ist_redaktion && ist_unterstuetzungsfrist_abgelaufen($mvtexte, $config_quorum)) {
    foreach (get_hauptantraege_mit_antragsteller($pdo, $jahr) as $a) {
        if (zaehle_unterstuetzungen($pdo, $jahr, (int)$a['ID']) < $schwellenwert_quorum) {
            $unter_quorum[] = $a;
        }
    }
}
?>
<?php if ($ist_redaktion && $unter_quorum): ?>
    <div class="top-item">
        <h3 style="margin-top:0;">⚠️ Anträge ohne erforderliche Unterstützung</h3>
        <p style="font-size:13px; color:var(--text-secondary);">Die Unterstützungsfrist ist abgelaufen. Diese Hauptanträge haben die notwendigen <?= $schwellenwert_quorum ?> Unterstützungen nicht erreicht und sollen daher regulär nicht mehr in der Antragsliste erscheinen.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="quorum_markieren">
            <input type="hidden" name="rueck_query" value="<?= h($rueck_query) ?>">
            <?php foreach ($unter_quorum as $a): ?>
                <label style="display:block; font-weight:400; font-size:13px; margin-bottom:4px;">
                    <input type="checkbox" name="quorum[]" value="<?= (int)$a['ID'] ?>" checked>
                    <?= h($a['Nr']) ?> — <?= h($a['Titeltext']) ?> (<?= zaehle_unterstuetzungen($pdo, $jahr, (int)$a['ID']) ?> von <?= $schwellenwert_quorum ?>) — Nicht in die Antragsliste aufnehmen
                </label>
            <?php endforeach; ?>
            <button type="submit" class="btn" style="margin-top:8px; padding:6px 12px; font-size:12.5px;">Markierung übernehmen</button>
        </form>
    </div>
<?php endif; ?>

<?php
$hauptantraege = get_hauptantraege_mit_antragsteller($pdo, $jahr, $sortierung);
$config = get_config($pdo);
$schwellenwert = (int)($config['unterstuetzung_schwellenwert'] ?? 10);
$quorum_antraege = $zeige_quorum ? get_hauptantraege_quorum_nicht_erreicht($pdo, $jahr) : [];
// Fuer die Stimmungsbild-Sichtbarkeitsregeln (siehe votum_functions.php: stimmungsbild_platzhalter()).
$frist_abgelaufen_sb = ist_unterstuetzungsfrist_abgelaufen($mvtexte, $config);
$schluessel_sb = $mitglied['Schluessel'] ?? '';
?>

<?php if (!$hauptantraege && !$quorum_antraege): ?>
    <div class="info-box">Für <?= h((string)$jahr) ?> liegen noch keine Anträge vor.</div>
<?php else: ?>
    <div class="top-list">
        <?php foreach ($quorum_antraege as $a): ?>
            <div class="top-row" style="display:block; padding:10px 12px; background:#fdeaea; border-left:3px solid var(--danger);">
                <strong><?= h($a['Nr']) ?>&nbsp;&nbsp;<?= h($a['Titeltext']) ?></strong>
                <div style="font-size:12px; color:var(--text-muted);">von <?= h(antragsteller_name($a)) ?></div>
                <p style="color:var(--danger); font-weight:600; font-size:13px; margin:6px 0 0;">
                    Dieser Antrag hat die notwendige Unterstützung nicht erreicht und wird daher nicht behandelt.
                </p>
            </div>
        <?php endforeach; ?>
        <?php foreach ($hauptantraege as $a): ?>
            <?php $gem = $a['Gem'] !== null ? (int)$a['Gem'] : null; ?>
            <div class="top-row" style="display:block; padding:10px 12px;<?= $gem !== null ? ' background:var(--bg-tertiary);' : '' ?>">
                <div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; align-items:baseline;">
                    <span>
                        <strong><?= h($a['Nr']) ?>&nbsp;&nbsp;<?= h($a['Titeltext']) ?></strong>
                        <?php if ($gem !== null): ?>
                            <span class="badge" style="margin-left:6px;">Gruppe <?= $gem ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div style="font-size:12px; color:var(--text-muted);">von <?= h(antragsteller_name($a)) ?></div>

                <?php if ($auswahl['kurz'] || $auswahl['text']): ?>
                    <div style="margin-top:6px; font-size:13px;">
                        <?php if ($auswahl['kurz']): ?>
                            <?= mb_strlen($a['Text'] ?? '') < 1000 ? format_antragstext($a['Text']) : format_antragstext(mb_substr($a['Text'], 0, 1000) . ' …') ?>
                        <?php else: ?>
                            <?= format_antragstext($a['Text']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($auswahl['begr']): ?>
                    <div style="margin-top:6px; font-size:12.5px; color:var(--text-secondary);">
                        <strong>Begründung:</strong> <?= format_antragstext($a['Begr']) ?><br>
                        <strong>Finanzielle Auswirkungen:</strong> <?= format_antragstext($a['Fin']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($auswahl['vorst']): ?>
                    <div style="margin-top:6px; font-size:12.5px; color:var(--text-secondary);">
                        <strong>Stellungnahme Vorstand:</strong> <?= !empty($a['Vorstand']) ? format_antragstext($a['Vorstand']) : '(liegt noch nicht vor)' ?>
                    </div>
                <?php endif; ?>

                <?php if ($auswahl['votum']): ?>
                    <?php
                    $summe = get_votum_summe($pdo, $jahr, (int)$a['ID']);
                    $hat_selbst_abgestimmt = $schluessel_sb !== '' && get_eigenes_votum($pdo, $jahr, (int)$a['ID'], $schluessel_sb) !== null;
                    $sb_platzhalter = stimmungsbild_platzhalter($pdo, $jahr, (int)$a['ID'], $ist_redaktion, $frist_abgelaufen_sb, $hat_selbst_abgestimmt, $config);
                    ?>
                    <div style="margin-top:6px; font-size:12.5px;">
                        <strong>Stimmungsbild:</strong>
                        <?php if ($sb_platzhalter !== null): ?>
                            <span style="font-style:italic; color:var(--text-muted);"><?= h($sb_platzhalter) ?></span>
                        <?php else: ?>
                            <?php foreach (VOTUM_ZUSTIMMUNG as $wert => $z): ?>
                                <?= $z['emoji'] ?> <?= (int)$summe[$wert] ?>&nbsp;&nbsp;
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($auswahl['andk']):
                    $folgeantraege = get_folgeantraege($pdo, $jahr, (int)$a['ID']); ?>
                    <?php foreach ($folgeantraege as $f): ?>
                        <div style="margin-top:8px; padding-top:6px; border-top:1px dotted var(--border-color); font-size:12.5px;">
                            <div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                                <span><strong><?= h($f['Nr']) ?></strong> — <?= (int)$f['Art'] === ANTRAG_ART_AENDERUNG ? 'Änderungsantrag' : 'Verfahrensantrag' ?> — <?= h($f['Titeltext']) ?> (<?= h(antragsteller_name($f)) ?>)</span>
                            </div>
                            <?php if ($auswahl['anda']): ?>
                                <div style="margin-top:4px;"><?= format_antragstext($f['Text']) ?></div>
                            <?php endif; ?>
                            <?php if ($auswahl['andb'] && !empty($f['Begr'])): ?>
                                <div style="margin-top:4px; color:var(--text-secondary);"><strong>Begründung:</strong> <?= format_antragstext($f['Begr']) ?></div>
                            <?php endif; ?>
                            <?php if ($auswahl['andv']):
                                $fsumme = get_votum_summe($pdo, $jahr, (int)$f['ID']);
                                $f_hat_selbst_abgestimmt = $schluessel_sb !== '' && get_eigenes_votum($pdo, $jahr, (int)$f['ID'], $schluessel_sb) !== null;
                                $f_sb_platzhalter = stimmungsbild_platzhalter($pdo, $jahr, (int)$f['ID'], $ist_redaktion, $frist_abgelaufen_sb, $f_hat_selbst_abgestimmt, $config);
                                ?>
                                <div style="margin-top:4px;">
                                    <?php if ($f_sb_platzhalter !== null): ?>
                                        <span style="font-style:italic; color:var(--text-muted);"><?= h($f_sb_platzhalter) ?></span>
                                    <?php else: ?>
                                        <?php foreach (VOTUM_ZUSTIMMUNG as $wert => $z): ?>
                                            <?= $z['emoji'] ?> <?= (int)$fsumme[$wert] ?>&nbsp;&nbsp;
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
