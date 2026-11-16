<?php
declare(strict_types=1);

/**
 * auth.php - Sitzung, CSRF-Schutz und Login-Bremse für WABS. Eigenständiges,
 * schlankes Login nur für die zwei vom Wahlausschuss selbst festgelegten
 * Zugänge (siehe MISS: admin/wabs.php "Zugang zum eigenständigen Wahlserver") -
 * kein SSO, keine Verbindung zu MISS oder der Sitzungsverwaltung zur Laufzeit.
 */

require_once __DIR__ . '/db.php';

const WABS_LOGIN_MAX_FAILS = 5;
const WABS_LOGIN_WINDOW_MIN = 15;

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function wabs_csrf_field(): string
{
    if (empty($_SESSION['wabs_csrf'])) $_SESSION['wabs_csrf'] = bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['wabs_csrf']) . '">';
}

function wabs_csrf_check(): void
{
    $ok = !empty($_SESSION['wabs_csrf']) && hash_equals($_SESSION['wabs_csrf'], (string)($_POST['csrf'] ?? ''));
    if (!$ok) { http_response_code(400); exit('Sitzung abgelaufen - bitte die Seite neu laden und erneut versuchen.'); }
}

function wabs_login_ident_hash(string $ident): string
{
    return hash('sha256', 'wabs-login|' . mb_strtolower(trim($ident)));
}

function wabs_login_throttled(PDO $pdo, string $ident): bool
{
    $since = date('Y-m-d H:i:s', time() - WABS_LOGIN_WINDOW_MIN * 60);
    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM wabs_login_attempts WHERE ident_hash = ? AND attempt_at >= ?');
    $stmt->execute([wabs_login_ident_hash($ident), $since]);
    return (int)$stmt->fetch()['c'] >= WABS_LOGIN_MAX_FAILS;
}

function wabs_login_fail(PDO $pdo, string $ident): void
{
    $pdo->prepare('INSERT INTO wabs_login_attempts (ident_hash, attempt_at) VALUES (?,?)')->execute([wabs_login_ident_hash($ident), date('Y-m-d H:i:s')]);
    $pdo->prepare('DELETE FROM wabs_login_attempts WHERE attempt_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
}

/** Prüft Benutzername/Passwort. Gibt [Benutzername|null, Fehlertext] zurück; der Text verrät nicht, ob der Benutzername existiert. */
function wabs_login_check(PDO $pdo, string $username, string $password): array
{
    if (wabs_login_throttled($pdo, $username)) return [null, 'Zu viele Fehlversuche. Bitte in ' . WABS_LOGIN_WINDOW_MIN . ' Minuten erneut versuchen.'];
    $stmt = $pdo->prepare('SELECT * FROM wabs_committee_users WHERE username = ?');
    $stmt->execute([trim($username)]);
    $u = $stmt->fetch();
    $hash = $u['password_hash'] ?? null;
    // Immer eine Prüfung durchführen, damit die Antwortzeit nicht verrät, ob der Benutzername existiert.
    $ok = password_verify($password, $hash ?: '$2y$10$usesomesillystringforsalt0u7c6mO1L0GmQz2v5oQz0n3H0e1iS');
    if (!$u || !$ok) {
        wabs_login_fail($pdo, $username);
        return [null, 'Anmeldung nicht möglich. Bitte Benutzername und Passwort prüfen.'];
    }
    $pdo->prepare('DELETE FROM wabs_login_attempts WHERE ident_hash = ?')->execute([wabs_login_ident_hash($username)]);
    return [$u['username'], ''];
}

/** Erzwingt eine angemeldete Sitzung, sonst Weiterleitung zu login.php. Für jede geschützte Seite an den Anfang stellen. */
function wabs_require_login(): string
{
    if (empty($_SESSION['wabs_user'])) {
        header('Location: login.php');
        exit;
    }
    return (string)$_SESSION['wabs_user'];
}
