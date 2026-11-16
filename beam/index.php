<?php
/**
 * index.php - Projektliste von BEAM (siehe dokumentation/BEAM.md): eigene
 * sowie, bei Admin-Rechten, alle Projekte; neue Projekte anlegen. Zugriff
 * setzt eine bestehende familienweite SSO-Sitzung voraus (kein eigenes
 * Login) und `aktiv > 13`/`is_admin` (siehe beam_aktuelle_rechte()).
 */

require_once __DIR__ . '/../session_config.php';
session_start();
require_once __DIR__ . '/beam_functions.php';

$pdo = get_pdo();
$rechte = beam_aktuelle_rechte($pdo);

if ($rechte['eingeloggt'] && $rechte['darf_steuern'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $titel = trim((string)($_POST['titel'] ?? ''));
    [$id, $fehler] = beam_projekt_anlegen($pdo, $rechte['member_id'], $titel);
    if ($fehler !== '') {
        $_SESSION['beam_fehler'] = $fehler;
        header('Location: index.php');
    } else {
        header('Location: steuerung.php?projekt=' . $id);
    }
    exit;
}

$projekte = ($rechte['eingeloggt'] && $rechte['darf_steuern']) ? beam_projekte_liste($pdo, $rechte) : [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BEAM - Projekte</title>
<link rel="stylesheet" href="beam_style.css">
</head>
<body>
<header class="beam-kopf"><h1>📽️ BEAM</h1></header>
<main class="beam-inhalt">
<?php if (!empty($_SESSION['beam_fehler'])): ?>
    <div class="info-box fehler"><?= h($_SESSION['beam_fehler']) ?></div>
    <?php unset($_SESSION['beam_fehler']); ?>
<?php endif; ?>

<?php if (!$rechte['eingeloggt']): ?>
    <div class="info-box">
        Keine aktive Anmeldung gefunden. Bitte zuerst über eine der Geschwisteranwendungen
        (z. B. Mitgliederverwaltung/Sitzungsverwaltung) anmelden - BEAM hat kein eigenes Login,
        sondern nutzt dieselbe Sitzung.
    </div>
<?php elseif (!$rechte['darf_steuern']): ?>
    <div class="info-box fehler">
        Keine Berechtigung für BEAM - dafür wird eine Funktionsstufe über 13 oder Admin-Rechte benötigt.
    </div>
<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0; font-size:16px;">Neues Projekt anlegen</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="text" name="titel" placeholder="Titel des Projekts …" required
                   style="width:100%; max-width:420px; padding:8px 10px; font-size:13.5px;">
            <button type="submit" class="btn" style="margin-top:8px;">Anlegen</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0; font-size:16px;"><?= $rechte['is_admin'] ? 'Alle Projekte' : 'Meine Projekte' ?></h2>
        <?php if (!$projekte): ?>
            <p style="color:var(--text-secondary); font-size:13.5px;">Noch keine Projekte vorhanden.</p>
        <?php else: ?>
            <table class="beam-tabelle">
                <tr><th>Titel</th><th>Angelegt am</th><th></th></tr>
                <?php foreach ($projekte as $p): ?>
                    <tr>
                        <td><?= h($p['titel']) ?></td>
                        <td><?= h(format_datum_zeit($p['erstellt_am'])) ?></td>
                        <td>
                            <a class="btn" style="padding:5px 10px; font-size:12.5px;" href="steuerung.php?projekt=<?= (int)$p['id'] ?>">Steuern</a>
                            <a class="btn btn-secondary" style="padding:5px 10px; font-size:12.5px;" href="beamerbild.php?projekt=<?= (int)$p['id'] ?>" target="_blank">Beamer-Fenster</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
</main>
</body>
</html>
