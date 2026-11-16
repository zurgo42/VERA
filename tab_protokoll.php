<?php
/**
 * tab_protokoll.php - Protokoll aller schreibenden Datenbankzugriffe des
 * aktuellen Jahres (mvlogs_<jahr>, siehe protokoll_functions.php), dazu das
 * Rohprotokoll des Beamerbilds (jede gezeigte Folie im Wortlaut, siehe
 * beamer_functions.php: beamer_protokoll_schreiben()) - Grundlage für das
 * offizielle Versammlungsprotokoll. Nur lesend, nur fuer Redaktion (siehe
 * tab_admin.php).
 */

require_once __DIR__ . '/beamer_functions.php';

$stichwort = trim($_GET['stichwort'] ?? '');
$eintraege = get_protokoll($pdo, $jahr, $stichwort);
$rohprotokoll_zeilen = beamer_protokoll_lesen($jahr, 50);
?>
<h3>Rohprotokoll des Beamerbilds <?= h((string)$jahr) ?></h3>
<p style="color:var(--text-secondary); font-size:13.5px;">
    Jede während der Versammlung gezeigte Folie im Wortlaut, mit Datum und Uhrzeit - Grundlage für das offizielle Versammlungsprotokoll.
    <?php if (beamer_protokoll_existiert($jahr)): ?>
        <a href="rohprotokoll.php?jahr=<?= $jahr ?>">Vollständige Datei herunterladen</a> (letzte <?= count($rohprotokoll_zeilen) ?> Zeilen unten, chronologisch - neueste zuletzt).
    <?php endif; ?>
</p>
<?php if (!$rohprotokoll_zeilen): ?>
    <div class="info-box">Noch keine Beamerbild-Änderung für <?= h((string)$jahr) ?> protokolliert.</div>
<?php else: ?>
    <pre id="rohprotokoll-vorschau" style="background:var(--bg-secondary, #f4f4f4); padding:10px; border-radius:6px; font-size:12px; white-space:pre-wrap; overflow-x:auto; max-height:320px; overflow-y:auto;"><?php foreach ($rohprotokoll_zeilen as $zeile) echo h($zeile) . "\n"; ?></pre>
    <script>
    // Neueste Zeile steht jetzt unten (chronologisch) - direkt dorthin scrollen,
    // damit der zuletzt protokollierte Eintrag ohne Scrollen sichtbar ist.
    (function () {
        var el = document.getElementById('rohprotokoll-vorschau');
        if (el) { el.scrollTop = el.scrollHeight; }
    })();
    </script>
<?php endif; ?>

<h3 style="margin-top:28px;">Protokoll <?= h((string)$jahr) ?></h3>
<p style="color:var(--text-secondary); font-size:13.5px;">
    Zeigt, wer wann was gespeichert hat (neueste zuerst, maximal 200 Einträge).
</p>

<form method="get" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
    <input type="hidden" name="tab" value="admin">
    <input type="hidden" name="bereich" value="protokoll">
    <input type="text" name="stichwort" placeholder="Suche in Mitgliedsnummer, Name, Aktion, Details" value="<?= h($stichwort) ?>" style="flex:1 1 260px; padding:6px 10px;">
    <button type="submit" class="btn btn-secondary">Filtern</button>
</form>

<?php if (!$eintraege): ?>
    <div class="info-box">Keine Protokolleinträge gefunden.</div>
<?php else: ?>
    <div style="overflow-x:auto;">
    <table style="width:100%; border-collapse:collapse; font-size:12.5px;">
        <thead>
            <tr style="text-align:left; border-bottom:2px solid var(--border-color);">
                <th style="padding:6px 8px;">Wann</th>
                <th style="padding:6px 8px;">Wer</th>
                <th style="padding:6px 8px;">Was</th>
                <th style="padding:6px 8px;">Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($eintraege as $e): ?>
                <tr style="border-bottom:1px solid var(--border-color);">
                    <td style="padding:6px 8px; white-space:nowrap; color:var(--text-muted);"><?= h($e['zeit']) ?></td>
                    <td style="padding:6px 8px; white-space:nowrap;"><?= h($e['KurzN'] ?: $e['MNr']) ?></td>
                    <td style="padding:6px 8px;"><?= h($e['was']) ?></td>
                    <td style="padding:6px 8px; font-family:monospace; font-size:11.5px; color:var(--text-secondary); word-break:break-all;"><?= h($e['string']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
