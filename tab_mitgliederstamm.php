<?php
/**
 * tab_mitgliederstamm.php - Pflege der lokalen Mitgliederstamm-Tabelle
 * (siehe mitgliederquelle_functions.php). Nur sinnvoll, wenn die
 * Vereinsvorgabe mitglieder_quelle auf "tabelle" steht (siehe
 * tab_admin.php, das diesen Bereich nur dann in die Kachelliste aufnimmt).
 */
require_once __DIR__ . '/mitgliederquelle_functions.php';

$liste = get_mitgliederstamm_liste($pdo);
?>
<h3 style="margin-top:0;">Mitgliederstamm (lokale Tabelle)</h3>
<p style="font-size:12.5px; color:var(--text-secondary); margin-top:-8px;">
    Startet mit zwei Musterdatensätzen - zum Ausprobieren. Für den echten Betrieb entweder hier einzeln pflegen oder per CSV-Import unten ersetzen.
</p>

<!-- Gemeinsames verstecktes Formular fuer die Loeschen-Buttons unten. -->
<form method="post" onsubmit="return confirm('Diesen Eintrag wirklich löschen?');" id="loeschform">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="loeschen">
    <input type="hidden" name="mnr" id="loeschform_mnr" value="">
</form>

<?php if (!$liste): ?>
    <div class="info-box">Noch keine Einträge.</div>
<?php else: ?>
    <div class="top-list" style="margin-bottom:20px;">
        <?php foreach ($liste as $m): ?>
            <div class="top-row" style="flex-wrap:wrap; gap:6px 10px;">
                <form method="post" style="display:contents;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="speichern">
                    <input type="hidden" name="mnr" value="<?= h($m['MNr']) ?>">
                    <span class="top-title" style="flex:0 0 100px;"><?= h($m['MNr']) ?></span>
                    <input type="text" name="vorname" value="<?= h($m['Vorname']) ?>" placeholder="Vorname" style="flex:1 1 120px; padding:5px 8px; font-size:13.5px;">
                    <input type="text" name="name" value="<?= h($m['Name']) ?>" placeholder="Name" style="flex:1 1 120px; padding:5px 8px; font-size:13.5px;">
                    <input type="email" name="mail" value="<?= h($m['Mail']) ?>" placeholder="Mailadresse" style="flex:1 1 180px; padding:5px 8px; font-size:13.5px;">
                    <button type="submit" class="btn" style="padding:5px 10px; font-size:12px;">Speichern</button>
                </form>
                <button type="submit" form="loeschform" class="btn-secondary" style="padding:5px 10px; font-size:12px; border-radius:6px; border:none; color:white; cursor:pointer;"
                        onclick="document.getElementById('loeschform_mnr').value='<?= h($m['MNr']) ?>';">🗑️</button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h4>Neuen Eintrag anlegen</h4>
<form method="post" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:24px;">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="text" name="mnr" placeholder="Mitgliedsnummer" required style="flex:0 0 140px; padding:7px 10px;">
    <input type="text" name="vorname" placeholder="Vorname" style="flex:1 1 140px; padding:7px 10px;">
    <input type="text" name="name" placeholder="Name" style="flex:1 1 140px; padding:7px 10px;">
    <input type="email" name="mail" placeholder="Mailadresse" style="flex:1 1 180px; padding:7px 10px;">
    <button type="submit" class="btn">Anlegen</button>
</form>

<h4>CSV-Import</h4>
<p style="font-size:12.5px; color:var(--text-secondary); margin-top:-6px;">
    Eine Zeile je Mitglied im Format <code>Mitgliedsnummer;Vorname;Name;Mailadresse</code> - bestehende Mitgliedsnummern werden überschrieben. Eine Kopfzeile wird automatisch erkannt und übersprungen.
</p>
<form method="post" enctype="multipart/form-data" style="max-width:420px;">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="csv_import">
    <div class="form-group" style="margin-bottom:10px;">
        <input type="file" name="csv" accept=".csv,text/csv,text/plain" required>
    </div>
    <button type="submit" class="btn">Importieren</button>
</form>
