<?php
/**
 * tab_reihenfolge.php - Festlegung der vorgeschlagenen Behandlungsreihenfolge
 * (Rfolge) der Hauptanträge sowie der Gem-Gruppierung (welche Hauptanträge
 * voraussichtlich gemeinsam beraten werden, siehe antraege_functions.php).
 * Beides gehört inhaltlich zusammen: die Reihenfolge zeigt ja gerade, welche
 * Anträge im Zusammenhang stehen. Nur für die Redaktion (siehe tab_admin.php).
 *
 * Der Rang aus dem Stimmungsbild (siehe votum_functions.php:
 * get_rang_aus_stimmungsbild()) wird nur informativ angezeigt - er füllt
 * Reihenfolge/Gruppe nicht automatisch, sondern dient als Richtschnur.
 */

require_once __DIR__ . '/antraege_functions.php';
require_once __DIR__ . '/votum_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}

$hauptantraege = get_hauptantraege_mit_antragsteller($pdo, $jahr, 'rfolge');
$rang_werte = get_rang_aus_stimmungsbild($pdo, $jahr);
?>
<h3>Reihenfolge &amp; gemeinsame Behandlung festlegen</h3>
<p style="font-size:13px; color:var(--text-secondary);">
    <strong>Rang:</strong> zur Orientierung aus dem Stimmungsbild ermittelt - Rangzahl = 2 × Wichtigkeits-Rang + Votumsklarheits-Rang (je höher, umso wichtiger UND klarer entschieden - dafür oder dagegen - wurde der Antrag bislang eingeschätzt). Der Wichtigkeits-Rang kann negativ werden: Anträge, die überwiegend als unwichtig oder „Nichtbefassung“ eingeschätzt wurden, bekommen dadurch eine kleine bzw. negative Rangzahl und rutschen ans Ende. Die beiden Werte in Klammern zeigen Wichtigkeits-Rang und Votumsklarheits-Rang einzeln, damit die Rangzahl nachvollziehbar bleibt. Nur eine Richtschnur, keine Vorgabe: Anträge ohne Stimmungsbild zeigen „—“.<br>
    <strong>Reihenfolge:</strong> eine fortlaufende Zahl je Antrag (kleinere Zahl zuerst) - bestimmt die Sortierung „Vorgeschlagene Reihenfolge“ in Antragsliste und Kompaktliste. Hier trägst du auf Basis des Rangs (und eigenem Ermessen) die tatsächlich geplante Reihenfolge ein.<br>
    <strong>Gruppe:</strong> So erreichst du, dass mehrere Hauptanträge gemeinsam behandelt werden: Trage bei allen betroffenen Anträgen dieselbe Gruppennummer ein (z. B. bei drei zusammengehörigen Anträgen jeweils die 1). Anträge mit derselben Gruppennummer werden dann in der Kompaktliste zusammengefasst dargestellt und lassen sich auf dem Beamerbild über eine gemeinsame Folie anzeigen. Leer lassen bedeutet: Der Antrag wird einzeln behandelt.
</p>

<?php if (!$hauptantraege): ?>
    <div class="info-box">Für <?= h((string)$jahr) ?> liegen noch keine Hauptanträge vor.</div>
<?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="bereich" value="reihenfolge">
        <input type="hidden" name="aktion" value="reihenfolge_speichern">
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
            <tr style="text-align:left; border-bottom:1px solid var(--border-color);">
                <th style="padding:6px;">Antrag</th>
                <th style="padding:6px;">Antragsteller:in</th>
                <th style="padding:6px; width:160px;">Rang (Wichtigkeit / Votum)</th>
                <th style="padding:6px; width:110px;">Reihenfolge</th>
                <th style="padding:6px; width:90px;">Gruppe</th>
            </tr>
            <?php foreach ($hauptantraege as $a): ?>
                <?php $rang = $rang_werte[(int)$a['ID']] ?? null; ?>
                <tr style="border-bottom:1px dotted var(--border-color);">
                    <td style="padding:6px;"><?= h($a['Nr']) ?> — <?= h($a['Titeltext']) ?></td>
                    <td style="padding:6px;"><?= h(antragsteller_name($a)) ?></td>
                    <td style="padding:6px; color:var(--text-secondary);">
                        <?php if ($rang !== null): ?>
                            <strong><?= h((string)$rang['rangzahl']) ?></strong>
                            <span style="font-size:12px;">(<?= h((string)$rang['wichtigkeit']) ?> / <?= h((string)$rang['votum']) ?>)</span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="padding:6px;">
                        <input type="number" name="rfolge[<?= (int)$a['ID'] ?>]" value="<?= h((string)($a['Rfolge'] ?? '')) ?>" style="width:80px; padding:4px 6px;">
                    </td>
                    <td style="padding:6px;">
                        <input type="number" name="gem[<?= (int)$a['ID'] ?>]" value="<?= h((string)($a['Gem'] ?? '')) ?>" placeholder="—" style="width:70px; padding:4px 6px;">
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <button type="submit" class="btn" style="margin-top:14px;">Speichern</button>
    </form>
<?php endif; ?>
