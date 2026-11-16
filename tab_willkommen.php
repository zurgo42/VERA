<?php
/**
 * tab_willkommen.php - Willkommensseite: erklärende Texte zum MV-Tool
 * (frei redigierbar durch das Redaktionsteam, jahresabhängig, siehe
 * mvtexte.willkommenstext). Landet hier, wer sich gerade neu in
 * mvmitglieder_<jahr> registriert hat (siehe index.php) - ab dem
 * nächsten Aufruf ist die Tagesordnung wieder die Einstiegsseite.
 */

const WILLKOMMEN_DEFAULTTEXT = 'Hier erscheinen erläuternde Texte';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

if ($ist_redaktion && ($_GET['modus'] ?? '') === 'bearbeiten') {
    include __DIR__ . '/tab_willkommen_bearbeiten.php';
    return;
}

$text = trim((string)($mvtexte['willkommenstext'] ?? ''));
?>

<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>Willkommen</h2>
    <?php if ($ist_redaktion): ?>
        <a class="btn btn-secondary" href="?tab=willkommen&modus=bearbeiten" style="padding:7px 14px; font-size:12.5px;">✏️ Diesen einleitenden Text verändern</a>
    <?php endif; ?>
</div>

<div class="top-item">
    <?= format_antragstext($text !== '' ? $text : WILLKOMMEN_DEFAULTTEXT) ?>
</div>
