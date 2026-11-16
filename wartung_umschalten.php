<?php
declare(strict_types=1);

/**
 * wartung_umschalten.php - Wartungsmodus per Passwort an-/ausschalten,
 * OHNE Datenbankzugriff (bewusst kein require von db_functions.php
 * ausser der reinen Konstanten-Definition aus config.php, kein get_pdo()
 * irgendwo in diesem Skript) - funktioniert daher auch dann noch, wenn die
 * Datenbank selbst nicht erreichbar ist (genau der Stoerungsfall, in dem
 * der Wartungsmodus am ehesten gebraucht wird). Nutzt dasselbe
 * SYSTEM_ADMIN_PASSWORD wie die anderen "besonders folgenreichen
 * Systemfunktionen" (siehe config_example.php), nicht die normale
 * Redaktions-Anmeldung, die eine Datenbankabfrage braucht.
 *
 * Wirkung: legt/loescht die Markierungsdatei wartung.aktiv in diesem
 * Verzeichnis - .htaccess leitet bei vorhandener Datei JEDE Anfrage
 * (unabhaengig vom aufgerufenen Skript) auf die statische, komplett
 * PHP-freie Seite wartung.html um, ganz ohne dass PHP/die Datenbank dafuer
 * noch funktionieren muessen.
 *
 * Sicherheitshinweis: wie die anderen tools/-Skripte oeffentlich erreichbar,
 * aber durch das Passwort geschuetzt - OHNE gesetztes SYSTEM_ADMIN_PASSWORD
 * bleibt die Funktion gesperrt (siehe system_admin_passwort_pruefen()).
 */

require_once __DIR__ . '/db_functions.php'; // nur fuer system_admin_passwort_pruefen()/h() - KEIN get_pdo() in diesem Skript
require_once __DIR__ . '/functions_neu.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$flag_datei = __DIR__ . '/wartung.aktiv';
$fehler = '';
$erfolg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authentifiziert = !empty($_SESSION['system_admin_authenticated']) || system_admin_passwort_pruefen((string)($_POST['passwort'] ?? ''));
    if (!$authentifiziert) {
        $fehler = 'Falsches Passwort.';
    } elseif (($_POST['aktion'] ?? '') === 'aktivieren') {
        if (@file_put_contents($flag_datei, 'aktiv seit ' . date('Y-m-d H:i:s') . "\n") !== false) {
            $erfolg = 'Wartungsmodus ist jetzt AKTIV - jede Anfrage wird auf wartung.html umgeleitet.';
        } else {
            $fehler = 'Konnte wartung.aktiv nicht anlegen (Schreibrechte auf diesem Verzeichnis prüfen).';
        }
    } elseif (($_POST['aktion'] ?? '') === 'deaktivieren') {
        if (is_file($flag_datei) && !@unlink($flag_datei)) {
            $fehler = 'Konnte wartung.aktiv nicht löschen (Schreibrechte auf diesem Verzeichnis prüfen).';
        } else {
            $erfolg = 'Wartungsmodus ist jetzt AUS - die Seite ist wieder normal erreichbar.';
        }
    }
}

$aktiv = is_file($flag_datei);
$authentifiziert_fuer_anzeige = !empty($_SESSION['system_admin_authenticated']);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Wartungsmodus umschalten</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; max-width: 480px; margin: 60px auto; padding: 0 20px; color: #333; }
    .status { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; }
    .status.aktiv { background: #f8d7da; color: #721c24; }
    .status.aus { background: #d4edda; color: #155724; }
    .meldung { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
    .meldung.fehler { background: #f8d7da; color: #721c24; }
    .meldung.erfolg { background: #d4edda; color: #155724; }
    input[type=password] { width: 100%; padding: 10px; font-size: 16px; box-sizing: border-box; margin-bottom: 12px; }
    button { padding: 10px 18px; font-size: 15px; cursor: pointer; border: none; border-radius: 6px; color: white; }
    .btn-an { background: #dc3545; }
    .btn-aus { background: #28a745; }
</style>
</head>
<body>
<h2>Wartungsmodus umschalten</h2>

<div class="status <?= $aktiv ? 'aktiv' : 'aus' ?>">
    Aktuell: <?= $aktiv ? 'AKTIV - Seite ist für alle gesperrt' : 'AUS - Seite ist normal erreichbar' ?>
</div>

<?php if ($fehler): ?><div class="meldung fehler"><?= h($fehler) ?></div><?php endif; ?>
<?php if ($erfolg): ?><div class="meldung erfolg"><?= h($erfolg) ?></div><?php endif; ?>

<form method="post">
    <?php if (!$authentifiziert_fuer_anzeige): ?>
        <label>Admin-Passwort</label>
        <input type="password" name="passwort" autofocus>
    <?php endif; ?>
    <?php if ($aktiv): ?>
        <button type="submit" name="aktion" value="deaktivieren" class="btn-aus">Wartungsmodus ausschalten</button>
    <?php else: ?>
        <button type="submit" name="aktion" value="aktivieren" class="btn-an">Wartungsmodus einschalten</button>
    <?php endif; ?>
</form>

</body>
</html>
