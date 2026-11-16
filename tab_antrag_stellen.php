<?php
/**
 * tab_antrag_stellen.php - Anträge stellen (Kernprozess 01: Hauptantrag
 * einreichen). Bearbeiten und Zurückziehen eines bereits eingereichten
 * Hauptantrags (Kernprozess 04) gehören bewusst NICHT hierher, sondern zu
 * "Anträge zeigen/bearbeiten", direkt beim jeweiligen Antrag (siehe
 * tab_antrag_detail.php). Siehe Prozesskatalog "Kernprozesse Antragsverwaltung".
 *
 * Änderungs- und Verfahrensanträge (Kernprozesse 02/03) gehören bewusst
 * NICHT hierher: sie beziehen sich immer auf einen bestehenden
 * Hauptantrag und werden daher künftig von "Anträge zeigen/bearbeiten"
 * aus gestellt, direkt beim jeweiligen Hauptantrag (siehe
 * antraege_functions.php: aenderungsantrag_einreichen/
 * verfahrensantrag_einreichen sowie process_antraege.php stehen dafür
 * bereits bereit).
 */

require_once __DIR__ . '/antraege_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

if (!$mitglied) {
    echo '<h2>Neuen Antrag stellen</h2>';
    echo '<div class="info-box">Bitte melde dich an, um einen Antrag zu stellen.</div>';
    return;
}

$anzahl_gestellt = zaehle_hauptantraege_von($pdo, $jahr, $mitglied['MNr']);
$config = get_config($pdo);
$limit = (int)($config['max_hauptantraege_pro_mitglied'] ?? 2);
$deadline_erreicht = ist_antragsschluss_erreicht($mvtexte);
?>

<h2>Neuen Antrag stellen</h2>
<?php if ($ist_redaktion): ?>
    <p style="color:var(--text-secondary); font-size:13.5px;">Du hast <?= $anzahl_gestellt ?> von <?= $limit ?> möglichen Hauptanträgen in eigenem Namen gestellt. Als Redaktionsteam-Mitglied kannst du außerdem Anträge im Namen anderer Mitglieder einstellen (Auswahl unten) - existiert das Mitglied noch nicht, wird es dabei automatisch angelegt.</p>
<?php else: ?>
    <p style="color:var(--text-secondary); font-size:13.5px;">Du hast <?= $anzahl_gestellt ?> von <?= $limit ?> möglichen Hauptanträgen gestellt.</p>
<?php endif; ?>

<?php if (!$ist_redaktion && $anzahl_gestellt >= $limit): ?>
    <div class="info-box">Dein Kontingent an Hauptanträgen ist ausgeschöpft.</div>
<?php elseif ($deadline_erreicht): ?>
    <div class="info-box">Der Antragsschluss (<?= h($mvtexte['antragsende'] ?? '') ?>) ist erreicht — neue Hauptanträge sind nicht mehr möglich.</div>
<?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="hauptantrag">

        <?php if ($ist_redaktion): ?>
            <script>
            // Siehe style.css: form.mv-fremdname - markiert das Formular
            // dunkelrot, solange "für das Mitglied Nr." gewählt ist, damit
            // eine Eingabe im Namen eines anderen Mitglieds nicht mit einer
            // eigenen Eingabe verwechselt werden kann.
            function mvAstModusUmschalten(radio, mnrFeldId) {
                var fuerAnderes = radio.value === 'fuer';
                var mnrFeld = document.getElementById(mnrFeldId);
                if (mnrFeld) { mnrFeld.style.display = fuerAnderes ? 'inline-block' : 'none'; }
                if (radio.form) { radio.form.classList.toggle('mv-redaktion-aktiv', fuerAnderes); }
            }
            </script>
            <div class="form-group" style="margin-bottom:14px;">
                <label>Ich stelle den Antrag</label><br>
                <label style="font-weight:400; margin-right:14px;">
                    <input type="radio" name="ast_modus" value="eigen" checked
                        onchange="mvAstModusUmschalten(this, 'ast_mnr_feld');">
                    in eigenem Namen (maximal <?= $limit ?>)
                </label>
                <label style="font-weight:400;">
                    <input type="radio" name="ast_modus" value="fuer"
                        onchange="mvAstModusUmschalten(this, 'ast_mnr_feld');">
                    für das Mitglied Nr.
                    <input type="text" name="ast_mnr" id="ast_mnr_feld" style="display:none; width:110px; padding:4px 6px;" placeholder="Mitgliedsnr.">
                </label>
            </div>
        <?php endif; ?>

        <div class="form-group" style="margin-bottom:12px;">
            <label>Der Antrag betrifft</label><br>
            <?php foreach (ANTRAG_KATEGORIEN as $wert => $kat): ?>
                <label style="margin-right:14px; font-weight:400;">
                    <input type="radio" name="kategorie" value="<?= $wert ?>" required> <?= h($kat['label']) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-group" style="margin-bottom:12px;">
            <label>Antragstitel</label>
            <input type="text" name="titel" required style="width:100%; padding:7px 10px;">
        </div>

        <div class="form-group" style="margin-bottom:12px;">
            <label>Antragstext</label>
            <textarea name="text" rows="6" required style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
        </div>

        <div class="form-group" style="margin-bottom:12px;">
            <label>Begründung</label>
            <textarea name="begr" rows="3" required style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
            <label>Finanzielle Auswirkungen</label>
            <textarea name="fin" rows="2" required style="width:100%; padding:8px 10px; font-family:inherit;"></textarea>
        </div>

        <button type="submit" class="btn">Antrag einreichen</button>
    </form>
<?php endif; ?>
