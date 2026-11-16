<?php
/**
 * tab_admin.php - Admin-Bereich, aufgeteilt in Unterbereiche
 * (?tab=admin&bereich=...). Nur für Redaktionsteam sichtbar (siehe index.php).
 */

$bereich = $_GET['bereich'] ?? '';

$bereiche = [
    'rechte'   => ['icon' => '🔑', 'label' => 'Rechteverwaltung',  'fertig' => true],
    'vorgaben' => ['icon' => '📐', 'label' => 'Vereinsspezifische Vorgaben', 'fertig' => true],
    'termine'  => ['icon' => '📅', 'label' => 'Termine',            'fertig' => true],
    'jahr'     => ['icon' => '🗓️', 'label' => 'Neues Jahr anlegen', 'fertig' => true],
    'reihenfolge' => ['icon' => '🔢', 'label' => 'Reihenfolge & Gruppierung', 'fertig' => true],
    'protokoll' => ['icon' => '📜', 'label' => 'Protokoll',          'fertig' => true],
    'abstimmung' => ['icon' => '📲', 'label' => 'Abstimmungsmaschine: Stimmkarten', 'fertig' => true],
    'wahlgaenge' => ['icon' => '🗳️', 'label' => 'Abstimmungsmaschine: Wahlgänge', 'fertig' => true],
    'anwesenheit' => ['icon' => '📋', 'label' => 'Anwesenheitsliste', 'fertig' => true],
    'backup'   => ['icon' => '💾', 'label' => 'Datensicherung',      'fertig' => true],
];
// Nur sichtbar, wenn die Mitgliederquelle auf die lokale Tabelle gestellt
// ist (siehe tab_vorgaben.php) - bei LDAP (Mensa-Default) irrelevant.
if (($config['mitglieder_quelle'] ?? 'ldap') === 'tabelle') {
    $bereiche['mitgliederstamm'] = ['icon' => '👥', 'label' => 'Mitgliederstamm', 'fertig' => true];
}
?>
<div class="mv-redaktion-aktiv">
<h2>Admin-Funktionen</h2>

<?php if (!empty($_SESSION['admin_erfolg'])): ?>
    <div class="info-box"><?= h($_SESSION['admin_erfolg']) ?></div>
    <?php unset($_SESSION['admin_erfolg']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['admin_fehler'])): ?>
    <div class="info-box" style="border-left-color:var(--danger); color:var(--danger);"><?= h($_SESSION['admin_fehler']) ?></div>
    <?php unset($_SESSION['admin_fehler']); ?>
<?php endif; ?>

<div class="menu-grid" style="margin-bottom:20px;">
    <?php foreach ($bereiche as $key => $b): ?>
        <a class="menu-tile" href="<?= $b['fertig'] ? ('?tab=admin&bereich=' . $key) : '#' ?>"
           style="<?= $bereich === $key ? 'border-color:var(--primary);' : '' ?> <?= $b['fertig'] ? '' : 'opacity:.5; cursor:default;' ?>"
           <?= $b['fertig'] ? '' : 'onclick="return false;"' ?>>
            <span class="icon"><?= $b['icon'] ?></span>
            <span class="label"><?= h($b['label']) ?><?= $b['fertig'] ? '' : ' (bald)' ?></span>
        </a>
    <?php endforeach; ?>
    <!-- Bewusst kein $bereiche-Eintrag: wartung_umschalten.php ist eine
         eigenstaendige Seite mit eigener Passwort-Pruefung (kein
         Redaktions-Login, keine Datenbank noetig - siehe dort), kein
         einbettbares tab_*.php-Fragment. Oeffnet daher in einem neuen Tab,
         statt die Admin-Seite zu verlassen. -->
    <a class="menu-tile" href="wartung_umschalten.php" target="_blank" rel="noopener">
        <span class="icon">🚧</span>
        <span class="label">Wartungsmodus</span>
    </a>
</div>

<?php
$unterseite = __DIR__ . '/tab_' . $bereich . '.php';
if ($bereich !== '' && isset($bereiche[$bereich]) && $bereiche[$bereich]['fertig'] && is_file($unterseite)) {
    include $unterseite;
}
?>
</div>
