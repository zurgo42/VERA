# Wahlserver (WABS)

Eigenständige Anwendung für die anonyme Online-Wahl/-Abstimmung. Baut auf den Tabellen `wabs_elections`/`wabs_tokens` auf, die MISS (Mitgliederverwaltung) per direkter Übertragung oder CSV-Export befüllt (siehe dort [`docs/WABS.md`](https://github.com/zurgo42/mitgliederverwaltung/blob/main/docs/WABS.md)).

**Status: Grundgerüst (Phase 5).** Login und Datenbankanbindung sind fertig; Wahl-/Abstimmungsinhalte, Token-Einlösung und Auszählung folgen in späteren Ausbaustufen (siehe die Liste "Noch nicht enthalten" in `docs/WABS.md`).

## Eigenständigkeit

- **Kein SSO.** Die Anmeldung ist komplett unabhängig von MISS/der Sitzungsverwaltung - eigener Benutzername, eigenes Passwort, eigene Sitzung.
- **Eigene Datenbank.** Drei schlanke Tabellen (`wabs_elections`, `wabs_tokens`, `wabs_committee_users`), die sich beim ersten Aufruf selbst anlegen (`db.php`). Kann auf demselben Server/derselben Datenbank-Instanz wie MISS liegen (dann schreibt MISS bei "direkter Übertragung" direkt hierhin) oder komplett getrennt sein.
- **Nur zwei Zugänge.** Wer Benutzername/Passwort bekommt, legt der Wahlausschuss selbst in MISS fest (Administration -> "Wahl und Abstimmung" -> "Zugang zum eigenständigen Wahlserver"). Von dort werden Benutzername und Passwort-Hash automatisch hierher übertragen (nur bei "direkter Übertragung" - siehe Einschränkung unten).

## Einrichtung

1. `config_example.php` nach `config.php` kopieren und die Zugangsdaten zur Wahlserver-Datenbank eintragen. Ist das wirklich dieselbe Datenbank wie MISS (dann in MISS "gleiche Datenbank wie MISS" ankreuzen, siehe unten) - einfach dieselben Werte wie in MISS' eigener `config.php` (dort `MD_DB_HOST`/`MD_DB_NAME`/`MD_DB_USER`/`MD_DB_PASS`). Das "gleiche Datenbank"-Ankreuzfeld in MISS erspart nur das erneute Eintippen dort - WABS ist trotzdem eine eigene Anwendung mit eigener `config.php` und braucht die Zugangsdaten hier weiterhin einmal.
2. Verzeichnis auf einen PHP-8-fähigen Webserver legen, `login.php` aufrufen.
3. In MISS unter "Wahl und Abstimmung" die Wahlserver-Verbindung eintragen (Administration -> Wahl und Abstimmung -> "Wahlserver-Verbindung") und mindestens zwei Wahlausschuss-Mitglieder sich selbst dort ankreuzen lassen (Abschnitt "Zugang zum eigenständigen Wahlserver").

## Einschränkung dieser Ausbaustufe

Die automatische Übertragung von Benutzername/Passwort aus MISS funktioniert nur bei **direkter Übertragung** (gleicher Server/gleiche Datenbank-Instanz wie MISS). Liegt der Wahlserver auf einem komplett externen Server (CSV-Weg), gibt es dafür noch kein Gegenstück - die Tabelle `wabs_committee_users` müsste in diesem Fall vorerst von Hand befüllt werden (`INSERT INTO wabs_committee_users (username, password_hash, updated_at) VALUES (...)`, Passwort-Hash z. B. mit `php -r "echo password_hash('...', PASSWORD_DEFAULT);"` erzeugen).
