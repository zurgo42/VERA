<?php
/**
 * tab_antrag_detail.php - Detailansicht eines Hauptantrags: Text,
 * Begründung, finanzielle Auswirkung, Vorstandsstellungnahme und
 * Ergebnis (falls vorhanden), dazu Mitantragsteller/Unterstützung
 * (Kernprozess 05), das Stimmungsbild (Kernprozess 06) sowie die
 * zugehörigen Änderungs- und Verfahrensanträge. Solange der Antrag noch
 * nicht abgestimmt ist, kann hier direkt ein neuer Änderungs-/
 * Verfahrensantrag dazu gestellt werden (Kernprozesse 02/03) -
 * eingebunden von tab_antrag_liste.php.
 */

require_once __DIR__ . '/unterstuetzung_functions.php';
require_once __DIR__ . '/votum_functions.php';

$antrag = get_antrag_mit_antragsteller($pdo, $jahr, $id);
if (!$antrag || (int)$antrag['Art'] !== ANTRAG_ART_HAUPTANTRAG) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">Antrag nicht gefunden.</div>';
    echo '<a href="?tab=antrag_liste" style="font-size:13px;">&larr; Zurück zur Liste</a>';
    return;
}

$folgeantraege = get_folgeantraege($pdo, $jahr, $id);
$abgestimmt = antrag_wurde_abgestimmt($antrag);
$kategorie_label = ANTRAG_KATEGORIEN[(int)$antrag['Kat']]['label'] ?? '';

$config = get_config($pdo);
$mitantragsteller = get_mitantragsteller($pdo, $jahr, $id);
$unterstuetzungen = get_unterstuetzungen($pdo, $jahr, $id);
$anzahl_unterstuetzer = count($unterstuetzungen);
$schwellenwert = (int)($config['unterstuetzung_schwellenwert'] ?? 10);
$frist_abgelaufen = ist_unterstuetzungsfrist_abgelaufen($mvtexte, $config);
$frist_datum_text = unterstuetzungsfrist_datum_text($mvtexte, $config);
$eigene_beteiligung = $mitglied ? get_eigene_beteiligung($pdo, $jahr, $id, $mitglied['MNr']) : null;

$ist_antragsteller = $mitglied && $mitglied['MNr'] === $antrag['Ast'];
$darf_bearbeiten = ($ist_antragsteller || $ist_redaktion) && hauptantrag_darf_bearbeiten($antrag, $mitglied, $ist_redaktion, $mvtexte);
$darf_zurueckziehen = ($ist_antragsteller || $ist_redaktion) && hauptantrag_darf_zurueckziehen($antrag, $mitglied, $ist_redaktion, $mvtexte, $config);
// Ist per Vereinsvorgabe (rueckzug_nur_bis_antragsschluss) der Rückzug nach
// Antragsschluss gesperrt, soll für normale Antragsteller:innen (nicht die
// Redaktion, die jederzeit darf) gar kein Hinweis mehr auf das Zurückziehen
// erscheinen - das Thema verschwindet dann komplett von der Seite.
$zurueckziehen_gesperrt_und_verborgen = !$ist_redaktion
    && ($config['rueckzug_nur_bis_antragsschluss'] ?? '1') === '1'
    && ist_antragsschluss_erreicht($mvtexte);
$uebernommene_aenderungsantraege = $ist_redaktion ? get_uebernommene_aenderungsantraege($pdo, $jahr, $id) : [];
?>

<?php if ($ist_redaktion): ?>
    <script>
    // Wird von allen "ast_modus"-Auswahlen auf dieser Seite genutzt (Beteiligung,
    // Änderungsantrag, Verfahrensantrag stellen): zeigt/versteckt das
    // Mitgliedsnummer-Feld und markiert das gesamte Formular per CSS-Klasse
    // (siehe style.css: form.mv-fremdname) dunkelrot, solange "für das
    // Mitglied Nr." gewählt ist - damit eine Eingabe im Namen eines anderen
    // Mitglieds nicht mit einer eigenen Eingabe verwechselt werden kann.
    function mvAstModusUmschalten(radio, mnrFeldId) {
        var fuerAnderes = radio.value === 'fuer';
        var mnrFeld = document.getElementById(mnrFeldId);
        if (mnrFeld) { mnrFeld.style.display = fuerAnderes ? 'inline-block' : 'none'; }
        if (radio.form) { radio.form.classList.toggle('mv-redaktion-aktiv', fuerAnderes); }
    }
    </script>
<?php endif; ?>

<a href="?tab=antrag_liste" style="font-size:13px; color:var(--text-secondary);">&larr; Zurück zur Liste</a>

<h2 style="margin-top:8px;"><?= h($antrag['Nr']) ?> — <?= h($antrag['Titeltext']) ?></h2>

<?php if ($frist_abgelaufen && $anzahl_unterstuetzer < $schwellenwert): ?>
    <p style="color:var(--danger); font-weight:600; font-size:14px;">
        Dieser Antrag hat die notwendige Zahl von <?= $schwellenwert ?> Unterstützungen nicht erhalten und wird daher nicht behandelt.
    </p>
<?php endif; ?>

<p style="color:var(--text-secondary); font-size:13px; margin-top:-8px;">
    <?= h($kategorie_label) ?> · Antragsteller:in: <?= h(antragsteller_name($antrag)) ?>
    <?php if (!empty($antrag['Datum'])): ?> · eingereicht am <?= h(format_datum_zeit($antrag['Datum'])) ?><?php endif; ?>
</p>
<?php if ($mitantragsteller): ?>
    <p style="color:var(--text-secondary); font-size:13px; margin-top:-8px;">
        Mitantragsteller:in: <?= h(implode(', ', array_map(fn($m) => trim($m['Vorname'] . ' ' . $m['Name']), $mitantragsteller))) ?>
    </p>
<?php endif; ?>

<div class="top-item">
    <div style="margin-bottom:10px;"><?= format_antragstext($antrag['Text']) ?></div>
    <?php if (!empty($antrag['Begr'])): ?>
        <div style="font-size:13px; color:var(--text-secondary); border-top:1px dotted var(--border-color); padding-top:8px; margin-bottom:8px;">
            <strong>Begründung:</strong><br><?= format_antragstext($antrag['Begr']) ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($antrag['Fin'])): ?>
        <div style="font-size:13px; color:var(--text-secondary);"><strong>Finanzielle Auswirkungen:</strong> <?= format_antragstext($antrag['Fin']) ?></div>
    <?php endif; ?>
</div>

<?php if (!empty($antrag['Vorstand'])): ?>
    <div class="info-box">
        <strong>Stellungnahme des Vorstands:</strong><br><?= format_antragstext($antrag['Vorstand']) ?>
    </div>
<?php endif; ?>

<?php if ($abgestimmt): ?>
    <div class="info-box">
        <strong>Ergebnis:</strong> <?= h($antrag['Ergebnis']) ?>
        <?php if ($antrag['Ja'] !== null && $antrag['Ja'] !== ''): ?>
            (Ja: <?= (int)$antrag['Ja'] ?>, Nein: <?= (int)$antrag['Nein'] ?>, Enthaltung: <?= (int)$antrag['Enthaltung'] ?>)
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$abgestimmt && ($ist_antragsteller || $ist_redaktion)): ?>
    <div class="top-item">
        <h3 style="margin-top:0;">Antrag verwalten</h3>

        <?php if ($darf_bearbeiten): ?>
            <details>
                <summary style="cursor:pointer; font-weight:600; color:var(--primary);">✏️ Antrag bearbeiten</summary>
                <form method="post" enctype="multipart/form-data" style="margin-top:12px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="hauptantrag_bearbeiten">
                    <input type="hidden" name="antrag_id" value="<?= (int)$antrag['ID'] ?>">

                    <div class="form-group" style="margin-bottom:12px;">
                        <label>Antragstitel</label>
                        <input type="text" name="titel" value="<?= h($antrag['Titeltext']) ?>" required style="width:100%; padding:7px 10px;">
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label>Antragstext</label>
                        <textarea name="text" rows="6" required style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($antrag['Text']) ?></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label>Begründung</label>
                        <textarea name="begr" rows="3" required style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($antrag['Begr']) ?></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label>Finanzielle Auswirkungen</label>
                        <textarea name="fin" rows="2" required style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($antrag['Fin']) ?></textarea>
                    </div>
                    <?php if ($ist_redaktion): ?>
                        <div class="mv-redaktion-aktiv">
                        <div class="form-group" style="margin-bottom:12px; padding-bottom:8px; border-bottom:1px dotted var(--border-color);">
                            <label>Mitgliedsnummer der/des Antragstellenden</label>
                            <input type="text" name="ast_mnr_korrektur" value="<?= h($antrag['Ast']) ?>" style="max-width:220px; padding:7px 10px;">
                            <p style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                                Nur bei versehentlicher Falscheingabe ändern - der Antrag wird dann diesem Mitglied zugeordnet. Existiert dafür noch kein Datensatz bzw. steht dort kein Name, wird er automatisch über die aktuelle Mitgliederquelle nachgetragen.
                            </p>
                        </div>
                        <div class="form-group" style="margin-bottom:12px;">
                            <label>Stellungnahme des Vorstands</label>
                            <textarea name="vorstand" rows="3" style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($antrag['Vorstand'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group" style="margin-bottom:16px;">
                            <label>Diskussionsforum-Link (Slug)</label>
                            <input type="text" name="forumlink" value="<?= h($antrag['Forumlink'] ?? '') ?>" style="width:100%; padding:7px 10px;">
                        </div>

                        <div class="form-group" style="margin-bottom:12px; padding-top:8px; border-top:1px dotted var(--border-color);">
                            <label>Bemerkung Redaktionsteam (intern, nicht öffentlich sichtbar)</label>
                            <?php if (!empty($antrag['Bem'])): ?>
                                <textarea readonly rows="4" style="width:100%; padding:8px 10px; font-family:inherit; background:var(--bg-tertiary); color:var(--text-secondary); margin-bottom:6px;"><?= h($antrag['Bem']) ?></textarea>
                            <?php endif; ?>
                            <textarea name="bem_neu" rows="2" placeholder="Neue Bemerkung hinzufügen - wird mit Datum an die bisherigen angehängt, nichts wird überschrieben." style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
                        </div>

                        <div class="form-group" style="margin-bottom:12px;">
                            <label>Als PDF eingereichter Antrag (intern, nur zu Dokumentationszwecken - kein Download im Tool)</label>
                            <?php if (!empty($antrag['Pdf'])): ?>
                                <p style="font-size:12.5px; margin:2px 0 6px;">Aktuell hinterlegt: <a href="../<?= h($antrag['Pdf']) ?>" target="_blank" rel="noopener"><?= h(basename($antrag['Pdf'])) ?></a></p>
                            <?php endif; ?>
                            <input type="file" name="pdf" accept="application/pdf">
                            <p style="font-size:12px; color:var(--text-muted); margin-top:4px;">Wird im Dokumentenverzeichnis des Jahres abgelegt; eine bereits hinterlegte Datei wird dabei ersetzt.</p>
                        </div>
                        <div class="form-group" style="margin-bottom:16px;">
                            <label>Erläuterung dazu (z. B. Text der Übersendungsmail)</label>
                            <textarea name="begrpdf" rows="3" style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($antrag['Begrpdf'] ?? '') ?></textarea>
                        </div>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn">Änderungen speichern</button>
                </form>
            </details>
        <?php elseif ($ist_antragsteller && !$ist_redaktion): ?>
            <p style="color:var(--text-muted); font-size:13px;">Der Antragsschluss ist erreicht — eine Bearbeitung ist nicht mehr möglich.</p>
        <?php endif; ?>

        <?php if ($darf_zurueckziehen): ?>
            <form method="post" style="margin-top:12px;" class="<?= $ist_redaktion ? 'mv-redaktion-aktiv' : '' ?>" onsubmit="return confirm('Bist du sicher?');">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="hauptantrag_zurueckziehen">
                <input type="hidden" name="antrag_id" value="<?= (int)$antrag['ID'] ?>">
                <label style="font-weight:400; font-size:13.5px;">
                    <input type="checkbox" required>
                    Hiermit ziehe ich diesen Antrag zurück
                </label>
                <button type="submit" class="btn-secondary" style="margin-top:8px; padding:6px 12px; border-radius:6px; border:none; color:white; cursor:pointer; font-size:13px;">Zurückziehen bestätigen</button>
            </form>
        <?php elseif ($ist_antragsteller && !$ist_redaktion && !$zurueckziehen_gesperrt_und_verborgen): ?>
            <p style="color:var(--text-muted); font-size:13px; margin-top:10px;">Ein Rückzug ist nicht mehr möglich.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($anzahl_unterstuetzer > 0 || !$frist_abgelaufen): ?>
    <?php
    $namentlich_genannt = array_filter($unterstuetzungen, fn($u) => !empty($u['namentlich']));
    $anonym_anzahl = count($unterstuetzungen) - count($namentlich_genannt);
    $namen_teile = array_map(fn($u) => h(trim($u['Vorname'] . ' ' . $u['Name'])), $namentlich_genannt);
    if ($anonym_anzahl > 0) {
        $namen_teile[] = $anonym_anzahl . ' ohne Namensnennung';
    }
    ?>
    <p style="font-size:13.5px;">
        <?php if ($namen_teile): ?>
            <?= implode(', ', $namen_teile) ?> —
        <?php endif; ?>
        insgesamt befürworten <?= $anzahl_unterstuetzer ?> von <?= $schwellenwert ?> Mitgliedern die Behandlung dieses Antrags.
    </p>
<?php endif; ?>

<?php if ($mitglied && !$abgestimmt): ?>
    <div class="top-item">
        <h3 style="margin-top:0;"><?= $ist_redaktion ? 'Beteiligung an diesem Antrag' : 'Deine Beteiligung an diesem Antrag' ?></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="beteiligung">
            <input type="hidden" name="antrag_id" value="<?= (int)$antrag['ID'] ?>">

            <?php if ($ist_redaktion): ?>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-weight:400; margin-right:14px;">
                        <input type="radio" name="ast_modus" value="eigen" checked
                            onchange="beteiligungAstModusGeaendert(this);">
                        für mich selbst
                    </label>
                    <label style="font-weight:400;">
                        <input type="radio" name="ast_modus" value="fuer"
                            onchange="beteiligungAstModusGeaendert(this);">
                        für das Mitglied Nr.
                        <input type="text" name="ast_mnr" id="beteiligung_ast_mnr" style="display:none; width:110px; padding:4px 6px;" placeholder="Mitgliedsnr.">
                    </label>
                </div>
            <?php endif; ?>

            <?php if (!$ist_antragsteller || $ist_redaktion): ?>
                <label style="display:block; margin-bottom:10px; font-weight:400;">
                    <input type="checkbox" name="mitantragsteller" id="beteiligung_mitantragsteller" <?= $eigene_beteiligung['mitantragsteller'] ? 'checked' : '' ?>>
                    <?= $ist_redaktion ? 'Als Mitantragsteller:in auftreten (mit voller Namensnennung).' : 'Ich möchte für diesen Antrag als Mitantragsteller auftreten (mit voller Namensnennung).' ?>
                </label>
            <?php endif; ?>

            <?php if (!$frist_abgelaufen): ?>
                <label style="display:block; margin-bottom:6px; font-weight:400;">
                    <input type="checkbox" name="unterstuetzung" id="beteiligung_unterstuetzung" <?= $eigene_beteiligung['unterstuetzer'] ? 'checked' : '' ?>>
                    Ich möchte, dass dieser Antrag auf der MV behandelt wird
                    <?php if ($frist_datum_text): ?>
                        (von den bis zum <?= h($frist_datum_text) ?> erforderlichen <?= $schwellenwert ?> Meldungen haben wir <?= $anzahl_unterstuetzer ?>)
                    <?php else: ?>
                        (es liegen bereits <?= $anzahl_unterstuetzer ?> von erforderlichen <?= $schwellenwert ?> Meldungen vor)
                    <?php endif; ?>
                </label>
                <label style="display:block; margin-bottom:14px; margin-left:24px; font-weight:400; font-size:13px;">
                    <input type="checkbox" name="namentlich" id="beteiligung_namentlich" <?= $eigene_beteiligung['namentlich'] ? 'checked' : '' ?>>
                    Dies gern mit Namensnennung
                </label>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:13px; margin-bottom:14px;">Die Unterstützungsfrist ist abgelaufen — eine neue Meldung ist nicht mehr möglich.</p>
            <?php endif; ?>

            <button type="submit" class="btn">Speichern</button>
        </form>
    </div>
    <?php if ($ist_redaktion): ?>
        <script>
        // Die Häkchen oben sind beim Laden mit der eigenen Beteiligung der
        // Redaktionsperson vorbelegt (nur diese ist ohne Neuladen der Seite
        // bekannt). Wird "für das Mitglied Nr." gewählt, ist diese Vorbelegung
        // für das Zielmitglied bedeutungslos und wird deshalb zurückgesetzt -
        // sonst würde z.B. eine für die Redaktionsperson selbst bereits
        // angehakte Unterstützung ungewollt auch beim eingetragenen
        // Mitantragsteller mitgespeichert.
        (function () {
            var ids = ['beteiligung_mitantragsteller', 'beteiligung_unterstuetzung', 'beteiligung_namentlich'];
            var ursprung = {};
            ids.forEach(function (id) {
                var el = document.getElementById(id);
                if (el) { ursprung[id] = el.checked; }
            });
            window.beteiligungAstModusGeaendert = function (radio) {
                mvAstModusUmschalten(radio, 'beteiligung_ast_mnr');
                var fuerAnderes = radio.value === 'fuer';
                ids.forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) { el.checked = fuerAnderes ? false : ursprung[id]; }
                });
            };
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php render_votum_block($pdo, $jahr, $antrag, $mitglied, $ist_redaktion, $frist_abgelaufen); ?>

<?php if ($folgeantraege): ?>
    <h3>Änderungs- und Verfahrensanträge zu diesem Antrag</h3>
    <div class="top-list">
        <?php foreach ($folgeantraege as $f): ?>
            <div class="top-row" style="display:block; padding:10px 12px;">
                <div style="display:flex; justify-content:space-between; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                    <strong><?= h($f['Nr']) ?> — <?= (int)$f['Art'] === ANTRAG_ART_AENDERUNG ? 'Änderungsantrag' : 'Verfahrensantrag' ?></strong>
                    <span class="badge"><?= antrag_wurde_abgestimmt($f) ? h($f['Ergebnis']) : 'Offen' ?></span>
                </div>
                <div style="font-size:12px; color:var(--text-muted); margin-bottom:6px;">
                    von <?= h(antragsteller_name($f)) ?><?php if (!empty($f['Datum'])): ?> · <?= h(format_datum_zeit($f['Datum'])) ?><?php endif; ?>
                </div>
                <div style="margin-bottom:6px;"><?= format_antragstext($f['Text']) ?></div>
                <?php if (!empty($f['Begr'])): ?>
                    <div style="font-size:12.5px; color:var(--text-secondary);"><strong>Begründung:</strong> <?= format_antragstext($f['Begr']) ?></div>
                <?php endif; ?>
                <?php if ((int)$f['Art'] === ANTRAG_ART_AENDERUNG && !empty($f['Uebernommen'])): ?>
                    <div style="margin-top:6px; font-size:12.5px; color:var(--danger); font-weight:600;">Der Antragsteller hat diesen Antrag übernommen</div>
                <?php endif; ?>

                <?php if ((int)$f['Art'] === ANTRAG_ART_AENDERUNG && $ist_antragsteller && !$abgestimmt && !antrag_wurde_abgestimmt($f) && empty($f['Uebernommen'])): ?>
                    <form method="post" style="margin-top:8px; padding-top:8px; border-top:1px dotted var(--border-color);" onsubmit="return confirm('Bist du sicher?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="aenderungsantrag_uebernehmen">
                        <input type="hidden" name="antrag_id" value="<?= (int)$f['ID'] ?>">
                        <label style="font-weight:400; font-size:12.5px;">
                            <input type="checkbox" required>
                            Als Antragsteller übernehme ich diesen Änderungsantrag
                        </label>
                        <button type="submit" class="btn" style="margin-left:8px; padding:4px 10px; font-size:12px;">Bestätigen</button>
                    </form>
                <?php endif; ?>

                <?php if (folgeantrag_darf_verwalten($f, $mitglied, $ist_redaktion)): ?>
                    <div style="margin-top:8px; padding-top:8px; border-top:1px dotted var(--border-color); display:flex; gap:14px; flex-wrap:wrap; align-items:baseline;" class="<?= $ist_redaktion ? 'mv-redaktion-aktiv' : '' ?>">
                        <details style="flex:1 1 100%;">
                            <summary style="cursor:pointer; color:var(--primary); font-size:12.5px; font-weight:600;">✏️ Bearbeiten</summary>
                            <form method="post" style="margin-top:10px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="aktion" value="folgeantrag_bearbeiten">
                                <input type="hidden" name="antrag_id" value="<?= (int)$f['ID'] ?>">

                                <?php if ($ist_redaktion): ?>
                                    <div class="form-group mv-redaktion-aktiv" style="margin-bottom:10px; padding-bottom:6px; border-bottom:1px dotted var(--border-color);">
                                        <label>Mitgliedsnummer der/des Antragstellenden</label>
                                        <input type="text" name="ast_mnr_korrektur" value="<?= h($f['Ast']) ?>" style="max-width:200px; padding:6px 8px; font-size:13px;">
                                        <p style="font-size:11.5px; color:var(--text-muted); margin-top:3px;">Nur bei versehentlicher Falscheingabe ändern.</p>
                                    </div>
                                <?php endif; ?>
                                <div class="form-group" style="margin-bottom:10px;">
                                    <label>Titel</label>
                                    <input type="text" name="titel" value="<?= h($f['Titeltext']) ?>" required style="width:100%; padding:6px 8px; font-size:13px;">
                                </div>
                                <div class="form-group" style="margin-bottom:10px;">
                                    <label>Text</label>
                                    <textarea name="text" rows="4" required style="width:100%; padding:6px 8px; font-family:inherit; font-size:13px;"><?= h($f['Text']) ?></textarea>
                                </div>
                                <div class="form-group" style="margin-bottom:10px;">
                                    <label>Begründung<?= (int)$f['Art'] === ANTRAG_ART_VERFAHREN ? ' (optional)' : '' ?></label>
                                    <textarea name="begr" rows="2" <?= (int)$f['Art'] === ANTRAG_ART_AENDERUNG ? 'required' : '' ?> style="width:100%; padding:6px 8px; font-family:inherit; font-size:13px;"><?= h($f['Begr']) ?></textarea>
                                </div>
                                <?php if ((int)$f['Art'] === ANTRAG_ART_AENDERUNG): ?>
                                    <div class="form-group" style="margin-bottom:10px;">
                                        <label>Finanzielle Auswirkungen</label>
                                        <textarea name="fin" rows="2" required style="width:100%; padding:6px 8px; font-family:inherit; font-size:13px;"><?= h($f['Fin']) ?></textarea>
                                    </div>
                                <?php endif; ?>
                                <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px;">Änderungen speichern</button>
                            </form>
                        </details>
                        <form method="post" onsubmit="return confirm('Bist du sicher?');" style="display:flex; align-items:center; gap:6px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="aktion" value="folgeantrag_zurueckziehen">
                            <input type="hidden" name="antrag_id" value="<?= (int)$f['ID'] ?>">
                            <label style="font-weight:400; font-size:12px;">
                                <input type="checkbox" required>
                                Hiermit ziehe ich diesen Antrag zurück
                            </label>
                            <button type="submit" class="btn-secondary" style="padding:4px 10px; border-radius:6px; border:none; color:white; cursor:pointer; font-size:12px;">Bestätigen</button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php render_votum_block($pdo, $jahr, $f, $mitglied, $ist_redaktion, $frist_abgelaufen); ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($uebernommene_aenderungsantraege): ?>
    <?php foreach ($uebernommene_aenderungsantraege as $u): ?>
        <div class="top-item" style="border-left:3px solid var(--danger);">
            <h3 style="margin-top:0;">Übernahme eines Änderungsantrags: <?= h($u['Nr']) ?></h3>
            <p style="font-size:12.5px; color:var(--text-secondary);">Ein Änderungsantrag wurde durch den Antragsteller übernommen. Bitte den Hauptantragstext (links) so bearbeiten, dass die Änderung sinngemäß eingearbeitet ist - der Text des Änderungsantrags (rechts) steht zum Kopieren bereit.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="aenderungsantrag_mergen">
                <input type="hidden" name="hauptantrag_id" value="<?= (int)$antrag['ID'] ?>">
                <input type="hidden" name="antrag_id" value="<?= (int)$u['ID'] ?>">

                <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:12px;">
                    <div style="flex:1 1 280px;">
                        <label>Antragstext des Hauptantrags (zu bearbeiten)</label>
                        <textarea name="text" rows="8" required style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($antrag['Text']) ?></textarea>
                    </div>
                    <div style="flex:1 1 280px;">
                        <label>Text des Änderungsantrags (zum Kopieren)</label>
                        <textarea readonly rows="8" style="width:100%; padding:8px 10px; font-family:inherit; background:var(--bg-tertiary);"><?= h($u['Text']) ?></textarea>
                    </div>
                </div>

                <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:12px;">
                    <div style="flex:1 1 280px;">
                        <label>Begründung des Hauptantrags (zu bearbeiten)</label>
                        <textarea name="begr" rows="4" style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($antrag['Begr']) ?></textarea>
                    </div>
                    <div style="flex:1 1 280px;">
                        <label>Begründung des Änderungsantrags (zum Kopieren)</label>
                        <textarea readonly rows="4" style="width:100%; padding:8px 10px; font-family:inherit; background:var(--bg-tertiary);"><?= h($u['Begr']) ?></textarea>
                    </div>
                </div>

                <button type="submit" class="btn">Übernahme abschließen</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!$abgestimmt && $mitglied): ?>
    <details style="margin-top:20px;">
        <summary style="cursor:pointer; font-weight:600; color:var(--primary);">➕ Änderungsantrag zum Antrag <?= h($antrag['Nr']) ?> stellen</summary>
        <form method="post" style="margin-top:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="aenderungsantrag">
            <input type="hidden" name="hauptantrag_id" value="<?= (int)$antrag['ID'] ?>">

            <?php if ($ist_redaktion): ?>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-weight:400; margin-right:14px;">
                        <input type="radio" name="ast_modus" value="eigen" checked
                            onchange="mvAstModusUmschalten(this, 'aenderung_ast_mnr');">
                        in eigenem Namen
                    </label>
                    <label style="font-weight:400;">
                        <input type="radio" name="ast_modus" value="fuer"
                            onchange="mvAstModusUmschalten(this, 'aenderung_ast_mnr');">
                        für das Mitglied Nr.
                        <input type="text" name="ast_mnr" id="aenderung_ast_mnr" style="display:none; width:110px; padding:4px 6px;" placeholder="Mitgliedsnr.">
                    </label>
                </div>
            <?php endif; ?>

            <div class="form-group" style="margin-bottom:12px;">
                <label>Titel des Änderungsantrags</label>
                <input type="text" name="titel" required style="width:100%; padding:7px 10px;">
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label>Änderungstext</label>
                <textarea name="text" rows="5" required style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label>Begründung</label>
                <textarea name="begr" rows="3" required style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label>Finanzielle Auswirkungen</label>
                <textarea name="fin" rows="2" required style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
            </div>
            <button type="submit" class="btn">Änderungsantrag einreichen</button>
        </form>
    </details>

    <details style="margin-top:14px;">
        <summary style="cursor:pointer; font-weight:600; color:var(--primary);">➕ Verfahrensantrag zum Antrag <?= h($antrag['Nr']) ?> stellen</summary>
        <p style="color:var(--text-secondary); font-size:13px; margin-top:10px;">Ein Verfahrensantrag betrifft nur den Ablauf der Versammlung — er darf keine inhaltliche Stellungnahme zu diesem Antrag enthalten.</p>
        <form method="post" style="margin-top:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="verfahrensantrag">
            <input type="hidden" name="hauptantrag_id" value="<?= (int)$antrag['ID'] ?>">

            <?php if ($ist_redaktion): ?>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-weight:400; margin-right:14px;">
                        <input type="radio" name="ast_modus" value="eigen" checked
                            onchange="mvAstModusUmschalten(this, 'verfahren_ast_mnr');">
                        in eigenem Namen
                    </label>
                    <label style="font-weight:400;">
                        <input type="radio" name="ast_modus" value="fuer"
                            onchange="mvAstModusUmschalten(this, 'verfahren_ast_mnr');">
                        für das Mitglied Nr.
                        <input type="text" name="ast_mnr" id="verfahren_ast_mnr" style="display:none; width:110px; padding:4px 6px;" placeholder="Mitgliedsnr.">
                    </label>
                </div>
            <?php endif; ?>

            <div class="form-group" style="margin-bottom:12px;">
                <label>Vorlage (optional)</label>
                <select class="goVorlage" style="width:100%; padding:7px 10px;">
                    <option value="">Freier Text</option>
                    <?php foreach (GO_ANTRAEGE as $vorlage): ?>
                        <option value="<?= h($vorlage) ?>"><?= h($vorlage) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label>Titel</label>
                <input type="text" class="verfTitel" name="titel" required style="width:100%; padding:7px 10px;">
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label>Text</label>
                <textarea class="verfText" name="text" rows="4" required style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label>Begründung (optional, rein verfahrensbezogen)</label>
                <textarea name="begr" rows="2" style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
            </div>
            <button type="submit" class="btn">Verfahrensantrag einreichen</button>
        </form>
    </details>

    <script>
    document.querySelectorAll('.goVorlage').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var form = this.closest('form');
            if (this.value) {
                form.querySelector('.verfTitel').value = this.value;
                form.querySelector('.verfText').value = this.value;
            }
        });
    });
    </script>
<?php endif; ?>
