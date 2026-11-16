<?php
/**
 * tab_jahr.php - "Neues Jahr anlegen": legt alle jahr-gebundenen Tabellen
 * (mvmitglieder mit nur den Funktionstraeger:innen aus dem Vorjahr, sowie
 * leer: mvantraege, mvunterst, mvvotum, mvlogs - falls im Vorjahr
 * vorhanden) sowie das Dokumentenverzeichnis docs<jahr> an, dazu die
 * Jahreszeile in mvtexte.
 */

require_once __DIR__ . '/jahr_functions.php';

$vorjahr = get_aktuelles_jahr($pdo);
$vorschlag = $vorjahr + 1;
$vorjahr_freigegeben = jahr_ist_freigegeben($pdo, $vorjahr);
?>
<?php if (!$vorjahr_freigegeben): ?>
    <div class="info-box" style="border-left-color:var(--danger);">
        <strong>Jahr <?= h((string)$vorjahr) ?> ist noch gesperrt</strong> - normale Mitglieder sehen stattdessen weiterhin
        das letzte freigegebene Jahr, nur Admin/Redaktion können <?= h((string)$vorjahr) ?> schon sehen und bearbeiten.
        Sobald Termine/Texte fertig vorbereitet sind:
        <form method="post" style="margin-top:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="bereich" value="jahr">
            <input type="hidden" name="aktion" value="jahr_freigeben">
            <input type="hidden" name="freizugebendes_jahr" value="<?= h((string)$vorjahr) ?>">
            <button type="submit" class="btn">Jahr <?= h((string)$vorjahr) ?> für alle Mitglieder freigeben</button>
        </form>
    </div>
<?php endif; ?>
<h3>Neues Jahr anlegen</h3>
<p style="color:var(--text-secondary); font-size:13.5px;">
    Aktuelles Jahr: <strong><?= h((string)$vorjahr) ?></strong>. Ein neues Jahr übernimmt aus
    <?= h((string)$vorjahr) ?> nur die Mitglieder mit Rolle und/oder Vorstandsamt (mit ihren
    bisherigen Rechten), damit sie von Anfang an arbeiten können - alle anderen
    Mitgliedseinträge werden nicht übernommen, da sich die Mitgliederliste erst während des
    Antragsverfahrens aus den tatsächlichen Zugriffen neu bildet. Anträge, Unterstützungen,
    Voten und Protokolleinträge beginnen leer. Zusätzlich wird ein leeres
    Dokumentenverzeichnis <code>docs<?= h((string)$vorschlag) ?></code> für die Unterlagen des
    Jahres angelegt (wie bisher <code>docs<?= h((string)$vorjahr) ?></code>). Termine (Datum, Ort,
    Antragsschluss) müssen danach separat unter „Termine“ eingetragen werden.
</p>

<form method="post" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
    <?= csrf_field() ?>
    <input type="hidden" name="bereich" value="jahr">
    <div class="form-group">
        <label>Neues Jahr</label>
        <input type="number" name="neues_jahr" value="<?= h((string)$vorschlag) ?>" min="<?= $vorjahr + 1 ?>" style="width:120px; padding:7px 10px;">
    </div>
    <button type="submit" class="btn">Jahr anlegen</button>
</form>
