<?php
declare(strict_types=1);

/**
 * index.php - WABS-Grundgerüst (Phase 5): zeigt die vom Wahlserver übernommenen
 * Wahlen mit ihrer Token-Anzahl. Wahl-/Abstimmungsinhalte, Token-Einlösung und
 * Auszählung sind spätere Phasen (siehe docs/WABS.md in MISS) - hier gibt es
 * absichtlich noch nichts zu bearbeiten, nur die Bestandsübersicht als Nachweis,
 * dass Anmeldung und Datenbankanbindung funktionieren.
 */

require_once __DIR__ . '/auth.php';

$user = wabs_require_login();
$pdo = wabs_pdo();
$elections = $pdo->query('SELECT e.*, (SELECT COUNT(*) FROM wabs_tokens t WHERE t.election_id = e.election_id) AS token_count
                           FROM wabs_elections e ORDER BY e.transferred_at DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wahlserver</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="bar"><strong>Wahlserver</strong> <span class="muted">angemeldet als <?= h($user) ?></span> <a href="logout.php">Abmelden</a></header>
<main>
<section class="card">
<h1>Übertragene Wahlen</h1>
<?php if (!$elections): ?>
<p class="muted">Noch keine Wahl übertragen.</p>
<?php else: ?>
<table>
<thead><tr><th>Bezeichnung</th><th>Start</th><th>Ende</th><th>Übertragen am</th><th>Token</th></tr></thead>
<tbody>
<?php foreach ($elections as $e): ?>
<tr>
<td><?= h($e['title']) ?></td>
<td><?= h(date('d.m.Y H:i', strtotime($e['start_at']))) ?></td>
<td><?= h(date('d.m.Y H:i', strtotime($e['end_at']))) ?></td>
<td><?= h(date('d.m.Y H:i', strtotime($e['transferred_at']))) ?></td>
<td class="r"><?= (int)$e['token_count'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<p class="muted">Wahl-/Abstimmungsinhalte, Token-Einlösung und Auszählung folgen in späteren Ausbaustufen.</p>
</section>
</main>
</body>
</html>
