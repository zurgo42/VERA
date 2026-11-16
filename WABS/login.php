<?php
declare(strict_types=1);

/**
 * login.php - einziger Einstiegspunkt für die zwei Wahlausschuss-Zugänge.
 * Kein SSO, kein Passwort-vergessen (bewusst: bei Verlust hilft nur, dass sich
 * das betroffene Mitglied in MISS abwählt und ein anderes Wahlausschuss-Mitglied
 * - oder es selbst erneut - übernimmt, siehe admin/wabs.php dort).
 */

require_once __DIR__ . '/auth.php';

if (!empty($_SESSION['wabs_user'])) {
    header('Location: index.php');
    exit;
}

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wabs_csrf_check();
    [$user, $fehler] = wabs_login_check(wabs_pdo(), (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($user !== null) {
        session_regenerate_id(true);
        $_SESSION['wabs_user'] = $user;
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wahlserver - Anmeldung</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<main class="card login">
<h1>Wahlserver</h1>
<p class="muted">Nur für die beiden vom Wahlausschuss festgelegten Zugänge.</p>
<?php if ($fehler !== ''): ?><p class="flash err"><?= h($fehler) ?></p><?php endif; ?>
<form method="post">
<?= wabs_csrf_field() ?>
<label class="block">Benutzername<input name="username" required autofocus></label>
<label class="block">Passwort<input type="password" name="password" required></label>
<button>Anmelden</button>
</form>
</main>
</body>
</html>
