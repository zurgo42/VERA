<?php
/**
 * tab_anwesenheit.php - Anwesenheitsliste für das Protokoll (siehe
 * Admin-Installationsanleitung.md, Abschnitt 10e, Phase 4): alle
 * Mitglieder des aktuellen Jahres mit gesetztem AnwesendSeit (egal ob per
 * Einlasskontrolle oder automatischer Simulation erfasst), chronologisch.
 * Nur lesend, nur für Redaktion (siehe tab_admin.php).
 */

$tabelle = 'mvmitglieder_' . $jahr;
$stmt = $pdo->prepare(
    "SELECT MNr, Vorname, Name, Teilnahmeform, StimmkarteAusgegeben, AnwesendSeit, AnwesendArt
     FROM `$tabelle` WHERE AnwesendSeit IS NOT NULL ORDER BY AnwesendSeit ASC"
);
$stmt->execute();
$anwesende = $stmt->fetchAll();
?>
<h3>Anwesenheitsliste <?= h((string)$jahr) ?></h3>
<p style="color:var(--text-secondary); font-size:13.5px;">
    Alle Mitglieder, deren Anwesenheit erfasst wurde - per Einlasskontrolle (QR-Scan) oder automatisch simuliert (siehe Vorgaben, Abschnitt „Einlasskontrolle &amp; Anwesenheit“). Grundlage für das offizielle Versammlungsprotokoll.
    <?php if ($anwesende): ?><a href="anwesenheit_csv.php">Als CSV herunterladen</a> (<?= count($anwesende) ?> Einträge).<?php endif; ?>
</p>

<?php if (!$anwesende): ?>
    <div class="info-box">Noch keine Anwesenheit für <?= h((string)$jahr) ?> erfasst.</div>
<?php else: ?>
    <table class="tabelle" style="width:100%; border-collapse:collapse; font-size:13.5px;">
        <thead>
            <tr style="text-align:left; border-bottom:2px solid var(--border, #ddd);">
                <th style="padding:6px 8px;">Zeit</th>
                <th style="padding:6px 8px;">Mitgl.-Nr.</th>
                <th style="padding:6px 8px;">Name</th>
                <th style="padding:6px 8px;">Teilnahmeform</th>
                <th style="padding:6px 8px;">Stimmkarte</th>
                <th style="padding:6px 8px;">Erfassung</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($anwesende as $a): ?>
                <tr style="border-bottom:1px solid var(--border, #eee);">
                    <td style="padding:6px 8px;"><?= h(date('d.m.Y H:i', strtotime((string)$a['AnwesendSeit']))) ?></td>
                    <td style="padding:6px 8px;"><?= h((string)$a['MNr']) ?></td>
                    <td style="padding:6px 8px;"><?= h(trim($a['Vorname'] . ' ' . $a['Name'])) ?></td>
                    <td style="padding:6px 8px;"><?= $a['Teilnahmeform'] === 'online' ? '💻 Online' : ($a['Teilnahmeform'] === 'vor_ort' ? '🪑 Vor Ort' : '–') ?></td>
                    <td style="padding:6px 8px;"><?= ((int)$a['StimmkarteAusgegeben'] === 1) ? '✓' : '–' ?></td>
                    <td style="padding:6px 8px;"><?= $a['AnwesendArt'] === 'einlasskontrolle' ? 'Einlasskontrolle' : ($a['AnwesendArt'] === 'simuliert' ? 'Simuliert' : h((string)($a['AnwesendArt'] ?? ''))) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
