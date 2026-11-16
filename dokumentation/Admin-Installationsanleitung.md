# MV-Tool – Admin-Installationsanleitung

Stand: Oktober 2026. Diese Anleitung wächst mit jeder neuen Funktion des Tools – bitte bei künftigen Erweiterungen nachführen.

Diese Anleitung richtet sich an technische Admins (häufig: der/die Webmaster:in des Vereins), die das Tool für ihren Verein neu einrichten. Grundkenntnisse in PHP/MySQL und Serveradministration werden vorausgesetzt, Vorerfahrung mit dem Tool selbst nicht.

**Durchgängiges Beispiel:** Diese Anleitung begleitet einen fiktiven, einfachen Sportverein – den *TSV Musterstadt e.V.* – von der ersten Installation bis zur ersten damit durchgeführten Mitgliederversammlung. Der TSV Musterstadt hat **kein** eigenes LDAP, **kein** Single-Sign-On und **keine** IT-Abteilung – nur eine Webseite mit PHP/MySQL-Hosting und einen ehrenamtlichen Webmaster. Genau für diesen (häufigsten) Fall ist der unten beschriebene Standardweg gedacht. Wer stattdessen ein bestehendes Vereins-Mitgliedersystem mit eigenem LDAP anbinden möchte, findet die dafür nötigen Abweichungen in Abschnitt 6.

## Inhaltsübersicht

1. Voraussetzungen
2. Dateien bereitstellen
3. `config.php` einrichten
4. Datenbank-Migrationen ausführen
5. Mitgliederverwaltung einrichten (lokale Tabelle oder LDAP)
6. Anmeldung einrichten (Login-Modul, vorgelagertes SSO oder familienweite Sitzung über MISS/KONA/KAND)
7. Erste Redaktionsperson freischalten (Henne-Ei-Problem)
8. Vereinsspezifische Vorgaben setzen
9. Erstes MV-Jahr anlegen und Termine eintragen
10. Optionale Funktionen (Diskussionsforum, Beamer, Mail-Benachrichtigungen, Einlasskontrolle/hybride Versammlung)
11. Laufender Betrieb: ein weiteres MV-Jahr anlegen
12. Bekannte Einschränkungen und Umgebungsannahmen
13. Checkliste für die Ersteinrichtung

## 1. Voraussetzungen

- Ein Webserver mit PHP (Version 8.x empfohlen). Das Tool ist bewusst rein prozedural ohne Composer-Abhängigkeiten gehalten – ein normales PHP-Webhosting reicht, wie es die meisten Vereine für ihre Vereinswebseite ohnehin schon haben.
- Eine MySQL- oder MariaDB-Datenbank mit einem Datenbankbenutzer, der Tabellen anlegen und ändern darf (Rechte `CREATE`, `ALTER`, `INSERT`, `UPDATE`, `DELETE`, `SELECT`) – das Tool legt sich einen erheblichen Teil seiner Tabellen und Spalten beim ersten Zugriff selbst an (siehe Abschnitt 4).
- Für den **empfohlenen Standardweg** (lokale Mitgliederverwaltung + Login-Modul, siehe Abschnitte 5 und 6) wird zusätzlich benötigt:
  - PHP's eingebaute `mail()`-Funktion muss auf dem Server funktionieren (lokaler Mailversand, z. B. über Postfix/Sendmail des Hosters) – darüber verschickt das Tool die Anmeldelinks und optionale Benachrichtigungen. Bei den meisten Webhosting-Paketen funktioniert das bereits ohne weitere Einrichtung; ein kurzer Testversand lohnt sich trotzdem vor dem Echtbetrieb (siehe Abschnitt 6).
- **Nicht** benötigt für den Standardweg: ein eigenes LDAP, ein Single-Sign-On-System, ein Reverse-Proxy mit vorgelagerter Authentifizierung. Diese Dinge sind nur relevant, falls Ihr Verein bereits eine eigene Mitglieder-IT mit Verzeichnisdienst betreibt und diese anbinden möchte (Abschnitt 6).

## 2. Dateien bereitstellen

Das Verzeichnis `mv/neu/` enthält das komplette Tool **inklusive seiner eigenen Konfigurationsdatei** – es ist bewusst eigenständig und lässt sich als Ganzes an eine beliebige Stelle kopieren, z. B. direkt nach `https://www.tsv-musterstadt.de/mv/` (dann `index.php` dort erreichbar). Eine Installation ohne älteres Bestandssystem braucht nur den Inhalt von `neu/` – keine weitere Verzeichnisebene darüber.

## 3. `config.php` einrichten

Im selben Verzeichnis wie die übrigen Skripte liegt `config_example.php` als Vorlage. Kopieren nach `config.php` (diese Datei ist bewusst in `.gitignore` eingetragen und wird nie versioniert, da sie Zugangsdaten enthält) und mit den eigenen Datenbank-Zugangsdaten ausfüllen:

```php
define('MYSQL_HOST',     'localhost');
define('MYSQL_USER',     '...');
define('MYSQL_PASS',     '...');
define('MYSQL_DATABASE', 'tsv_mv');   // frei wählbarer Datenbankname

// Nur relevant bei LDAP-Mitgliederquelle, siehe Abschnitt 5b - beim
// Standardweg (lokale Tabelle) kann dieser Wert unverändert bleiben.
define('LDAP_BIND_PASS', 'xxx');
```

**Optional** - analog zu den Schwesterprojekten Sitzungsverwaltung (KONA) und Mitgliederverwaltung (MISS) lassen sich Vereinsname/Logo sowie der Demo-Modus auch direkt hier per Konstante setzen, statt über die admin-editierbare Vereinsvorgabe (Abschnitt 8):

```php
define('ORG_NAME', 'TSV Musterstadt e. V.');
define('ORG_LOGO', '');   // Pfad/URL zum Logo, leer = Standardsymbol
define('DEMO_MODE', true);
```

Gesetzt, hat die Konstante immer Vorrang - das entsprechende Feld unter „Vereinsspezifische Vorgaben" wird dann nur noch informativ angezeigt (ausgegraut). Besonders nützlich für `DEMO_MODE`: löst das Henne-Ei-Problem beim Demo-Modus (siehe Abschnitt 6c und Abschnitt 7) - der Demo-Modus lässt sich so schon bereitstellen, bevor überhaupt jemand angemeldet war, ganz ohne einen Datenbankeintrag von Hand anzulegen. Ungesetzt bleibt alles unverändert wie bisher admin-editierbar - der Vereinsname für die Kopfzeile ist dann weiterhin eine admin-editierbare Vereinsvorgabe (Tabelle `mvconfig`, siehe Abschnitt 8), unter „Admin-Funktionen → Vereinsspezifische Vorgaben" im Tool selbst änderbar, keine Code-Anpassung nötig.

**Nur nötig, wenn der Demo-Modus genutzt wird** - wie bei den Schwesterprojekten Sitzungsverwaltung (KONA) und Mitgliederverwaltung (MISS) schützt ein zusätzliches Admin-Passwort den Button „Demo-Daten einspielen" (siehe Abschnitt 6c):

```php
define('SYSTEM_ADMIN_PASSWORD', 'xxx');
```

Ohne gesetzte Konstante bleibt der Button gesperrt (sicherer Standard) - die Redaktionsrolle (`Red = 9`) allein reicht für diese unwiderrufliche Aktion nicht mehr aus.

**Ebenfalls optional** - die Fußzeile (Copyright/Impressum/Datenschutz), wie bei Sitzungsverwaltung (KONA) und Mitgliederverwaltung (MISS):

```php
define('FOOTER_COPYRIGHT', '&copy; Dr. Max Mustermann, Musterstr. 1, 12345 Musterstadt Tel. 0123 456789 eMail meier@beispiel.de');
define('FOOTER_IMPRESSUM_URL', 'https://www.tsv-musterstadt.de/impressum');
define('FOOTER_DATENSCHUTZ_URL', 'https://www.tsv-musterstadt.de/datenschutz');
```

`FOOTER_COPYRIGHT` darf HTML enthalten (wird wie bei den Schwesterprojekten ungefiltert ausgegeben). Ungesetzt erscheint stattdessen wie bisher „© \<Jahr\> \<Vereinsname\>" ohne Impressum-/Datenschutz-Links.

`db_functions.php` bindet diese Datei automatisch ein und verbindet sich per PDO. (Bei einer älteren Installation, bei der `config.php` noch wie früher vorgesehen eine Verzeichnisebene über `neu/` liegt, wird sie dort ebenfalls noch gefunden – für eine Neuinstallation ist aber die Ablage direkt neben den Skripten der Standardweg.)

## 4. Datenbank-Migrationen ausführen

**Kürzester Weg:** `neu/tools/init-db.php` einmal im Browser aufrufen - das Skript ruft alle Selbstheilungsfunktionen des Tools (die sonst erst beim jeweils ersten tatsächlichen Gebrauch greifen) sowie die beiden noch nicht selbstheilenden Migrationen (`mvconfig`, `mvbeschluesse`) der Reihe nach auf, legt dabei auch gleich das erste MV-Jahr an und zeigt am Ende eine Zusammenfassung. Alle Operationen sind idempotent (mehrfacher Aufruf richtet nichts an) - **nach der Ersteinrichtung trotzdem löschen oder per Dateirechte/.htaccess schützen**, da es sonst Interna der Datenbankstruktur offenlegt. Deckt dieselben Tabellen ab wie die manuellen Schritte unten - wer lieber von Hand/nachvollziehbar vorgeht, nutzt stattdessen die folgende Beschreibung.

**Tabellen/Inhalte per Datenbank-Tool (Navicat, phpMyAdmin, ...) von einer bestehenden Installation in eine neue kopieren:** Erst `neu/tools/fix-collation.php` auf der QUELLDATENBANK aufrufen. Grund: Manche Installationen (insbesondere lokale MySQL-8-Umgebungen) legen Tabellen mit der Collation `utf8mb4_0900_ai_ci` an - die kennt MariaDB nicht, ein Kopier-Tool, das diese Collation mitkopiert, scheitert auf einer MariaDB-Zieldatenbank mit "1273 - Unknown collation". `fix-collation.php` stellt alle Tabellen der aktuellen Datenbank (und die Datenbank-Vorgabe selbst) auf das überall unterstützte `utf8mb4_unicode_ci` um, bevor man kopiert - rein strukturell, die Inhalte bleiben unverändert. Ebenfalls idempotent, ebenfalls danach löschen/schützen.

Unter `mv/neu/migrations/` liegen SQL-Dateien für Tabellen, die eine komplette Neuinstallation benötigt. Diese **einmalig** und **in Reihenfolge** gegen die Datenbank ausführen, z. B.:

```bash
mysql -u tsv_mv_user -p tsv_mv < mv/neu/migrations/001_mvconfig.sql
mysql -u tsv_mv_user -p tsv_mv < mv/neu/migrations/002_tagesordnung.sql
mysql -u tsv_mv_user -p tsv_mv < mv/neu/migrations/003_mvdateien.sql
mysql -u tsv_mv_user -p tsv_mv < mv/neu/migrations/004_mvbeschluesse.sql
```

| Datei | Legt an | Zweck |
|---|---|---|
| `001_mvconfig.sql` | `mvconfig` | Vereinsspezifische Vorgaben (Key-Value), siehe Abschnitt 8 |
| `002_tagesordnung.sql` | `mvtop`, `mvtopdokumente` | Redaktionell editierbare Tagesordnung mit Dokumenten je TOP |
| `003_mvdateien.sql` | `mvdateien` (bzw. erweitert sie) | „Unterlagen <Jahr>" – hochgeladene/verlinkte Dokumente |
| `004_mvbeschluesse.sql` | `mvbeschluesse` | MV-Beschluss-Sammlung (durchsuchbares Archiv vergangener Beschlüsse) |

Alle **jahresabhängigen** Tabellen (`mvtexte`, `mvmitglieder_<jahr>`, `mvantraege_<jahr>`, `mvunterst_<jahr>`, `mvvotum_<jahr>`, `mvlogs_<jahr>`) legt sich das Tool **selbst** beim ersten Zugriff vollständig an (inkl. Basistabelle, nicht nur zusätzliche Spalten) bzw. ergänzt fehlende Spalten automatisch per `ALTER TABLE` – dafür ist **keine** manuelle Migration nötig. Die Prüfung, ob eine Spalte schon existiert, läuft bewusst über `SHOW COLUMNS` (Funktion `spalte_existiert()` in `db_functions.php`) statt über `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` – letzteres wird erst ab MySQL 8.0.29 unterstützt und schlägt auf älteren, auf Vereins-Webhosting verbreiteten MySQL-Versionen mit einem Syntaxfehler fehl, wodurch die Spalte dauerhaft fehlen würde. `SHOW COLUMNS` funktioniert dagegen auf jeder MySQL-/MariaDB-Version.

Für das erste MV-Jahr braucht es deshalb **keinen** eigenen Bootstrap-Schritt: `mvtexte`/`mvmitglieder_<jahr>`/`mvantraege_<jahr>` entstehen automatisch beim ersten Aufruf von `index.php`, auch ganz ohne vorheriges „Neues Jahr anlegen" (das dafür nötige Jahr wird dabei einfach als laufendes Kalenderjahr angenommen, siehe `get_aktuelles_jahr()`). „Neues Jahr anlegen" (Abschnitt 9) bleibt trotzdem der empfohlene Weg, um Datum/Ort/Antragsschluss von Anfang an korrekt zu setzen, funktioniert aber jetzt auch dann, wenn es noch gar kein Vorjahr gibt.

**Hinweis zu `mvtexte.timer`:** Diese Spalte speichert bewusst nur eine reine Uhrzeit (`H:i:s`, max. 8 Zeichen), kein vollständiges Datum. Die Selbstheilung legt sie bei einer komplett neuen Installation als `VARCHAR(10)` an – das genügt im Normalfall, eine manuelle Anpassung ist nur in seltenen Sonderfällen nötig (z. B. bei Wiederverwendung einer sehr alten, enger typisierten Spalte aus einem Fremdsystem).

## 5. Mitgliederverwaltung einrichten

Damit das Tool weiß, wer Mitglied ist (für Vorname/Name/E-Mail bei der Anmeldung), braucht es eine **Mitgliederquelle**. Steuerbar über die Vereinsvorgabe `mitglieder_quelle` (Admin-Funktionen → Vereinsspezifische Vorgaben, Abschnitt 8) – drei Varianten:

### 5a. Lokale Mitgliederstamm-Tabelle (Standardweg, empfohlen für Vereine ohne eigenes LDAP)

Das ist der Normalfall für einen Verein wie den TSV Musterstadt: Die Mitgliederdaten (Mitgliedsnummer, Vorname, Name, Mailadresse) liegen in einer einfachen, vom Tool selbst verwalteten Tabelle `mitglieder_stamm`. Einrichtung:

1. Unter Admin-Funktionen → Vereinsspezifische Vorgaben die Mitgliederquelle auf „Lokale Mitgliederstamm-Tabelle" stellen und speichern.
2. Im Admin-Menü erscheint jetzt zusätzlich die Kachel „Mitgliederstamm". Dort liegen bereits **zwei Musterdatensätze** (zum Ausprobieren) – diese können einzeln bearbeitet/gelöscht oder komplett per CSV-Import ersetzt werden.
3. Für den Echtbetrieb die eigene Mitgliederliste als CSV-Datei hochladen, eine Zeile je Mitglied im Format:

   ```
   MNr;Vorname;Name;Mail
   1001;Erika;Musterfrau;erika.musterfrau@beispiel.de
   1002;Max;Mustermann;max.mustermann@beispiel.de
   ```

   Eine Kopfzeile wird automatisch erkannt und übersprungen. Als „MNr" (Mitgliedsnummer) eignet sich jede eindeutige, stabile Kennung – z. B. die ohnehin schon geführte Mitgliedsnummer aus der Vereinsverwaltung, oder notfalls einfach fortlaufende Nummern. Bestehende Mitgliedsnummern werden beim erneuten Import überschrieben (z. B. um eine E-Mail-Adresse zu korrigieren), nicht dupliziert.
4. Neue Mitglieder lassen sich jederzeit einzeln nachtragen oder per erneutem CSV-Import ergänzen – es ist **nicht** nötig, vor der ersten MV bereits alle Mitglieder einzupflegen: Ein Mitglied ohne Eintrag in `mitglieder_stamm` kann sich zwar nicht per Login-Modul anmelden (siehe Abschnitt 6), eine Mitgliedsnummer taucht aber auch ohne Stammdaten in `mvmitglieder_<jahr>` auf, sobald sie das erste Mal verwendet wird (z. B. wenn die Redaktion einen Antrag „im Namen von" dieser Person einstellt) – nur eben ohne automatisch befüllten Namen/Mail.

### 5b. LDAP (für Vereine mit eigenem Verzeichnisdienst)

Betreibt Ihr Verein bereits ein eigenes LDAP für die Mitgliederverwaltung, kann das Tool stattdessen direkt darauf zugreifen. Dafür:

1. Mitgliederquelle auf „LDAP" stellen.
2. Die LDAP-Zugangsdaten (Bind-Passwort) in `config.php` hinterlegen (Konstante `LDAP_BIND_PASS`, siehe Abschnitt 3).
3. **Wichtig:** Server-Adresse, Bind-DN und die abgefragten Attributnamen (`givenName`/`sn`/`mail`) sind **fest im Code** hinterlegt, in `mitglieder_functions.php`, Funktion `ldapident_neu()` – ursprünglich für die Mensa-LDAP-Struktur geschrieben (`cn=aktive,ou=applications,dc=mensa,dc=de`). Für jeden anderen Verein mit abweichender Verzeichnisstruktur muss diese Funktion im Code angepasst werden; es gibt dafür keine Admin-Einstellung. Für einen Verein ohne eigenes LDAP (wie den TSV Musterstadt) entfällt dieser Aufwand komplett durch den Standardweg (Abschnitt 5a).

Ist die PHP-`ldap`-Erweiterung nicht installiert oder der Server nicht erreichbar, bricht die Registrierung **nicht** ab – Vorname/Name/E-Mail bleiben dann leer und können später manuell nachgetragen werden.

### 5c. Tabelle „svmembers" (für den Betrieb neben Sitzungsverwaltung „KONA" oder Mitgliederverwaltung „MISS")

Teilt sich die VERA-Installation eine Datenbank mit der Sitzungsverwaltung (KONA) und/oder der Mitgliederverwaltung (MISS) derselben Produktfamilie, führen diese bereits eine Tabelle `svmembers` mit den Mitgliederdaten. In diesem Fall braucht VERA keine eigene Mitgliederliste zu pflegen, sondern liest dort nur mit:

1. Mitgliederquelle auf „Tabelle ‚svmembers'" stellen.
2. Es ist **keine** weitere Konfiguration nötig – VERA nutzt dieselbe Datenbankverbindung (`config.php`) wie für alle anderen Tabellen und greift rein lesend auf `svmembers` zu (Spalten `membership_number`, `first_name`, `last_name`, `email`). VERA legt diese Tabelle nicht an und schreibt nie in sie – das bleibt vollständig Aufgabe von KONA/MISS.
3. Ist `svmembers` in der verbundenen Datenbank (noch) nicht vorhanden, etwa weil versehentlich diese Option gewählt wurde, obwohl VERA allein betrieben wird, verhält sich die Abfrage wie bei einer unbekannten Mitgliedsnummer: Vorname/Name/E-Mail bleiben leer, die Registrierung bricht nicht ab.

Für einen Verein wie den TSV Musterstadt ohne KONA/MISS-Umgebung ist diese Option nicht relevant – hier bleibt Abschnitt 5a der Standardweg.

## 6. Anmeldung einrichten

Mitglieder müssen sich identifizieren können, bevor sie Anträge stellen oder abstimmen. Auch hier zwei Varianten, unabhängig von der in Abschnitt 5 gewählten Mitgliederquelle wählbar:

### 6a. Login-Modul (Standardweg, empfohlen für Vereine ohne eigenes SSO)

Ein einfaches, passwortloses Anmeldeverfahren per Magic-Link: Mitglied gibt auf `login.php` seine Mitgliedsnummer ein, bekommt per Mail einen 15 Minuten gültigen, nur einmal verwendbaren Anmeldelink zugeschickt, Klick darauf meldet an. Kein Passwort zu vergeben, zu vergessen oder zurückzusetzen – für einen ehrenamtlich geführten Verein ohne eigene IT-Hotline der pragmatischste Weg.

Einrichtung:

1. Unter Admin-Funktionen → Vereinsspezifische Vorgaben den Haken „Eigenes Anmeldeformular (Magic-Link per Mail) zusätzlich zum SSO anbieten" setzen und speichern.
2. Im Kopfbereich erscheint jetzt für nicht angemeldete Besucher:innen ein Link „Anmelden", der zu `login.php` führt.
3. Voraussetzung: Das jeweilige Mitglied braucht eine hinterlegte Mailadresse (aus `mitglieder_stamm` bzw. `mvmitglieder_<jahr>`, siehe Abschnitt 5) – ohne Mailadresse kann kein Link verschickt werden. Aus Datenschutzgründen zeigt das Tool bewusst **immer dieselbe** Rückmeldung an („falls diese Mitgliedsnummer bekannt ist …"), unabhängig davon, ob die Mitgliedsnummer tatsächlich existiert oder eine Mailadresse hinterlegt ist.
4. **Vor dem Echtbetrieb unbedingt mit der eigenen Mitgliedsnummer testen**, ob die Anmeldemail tatsächlich ankommt (ggf. auch im Spam-Ordner nachsehen) – schlägt der Mailversand auf dem Server fehl, merkt das Mitglied selbst davon nichts (die Rückmeldung bleibt dieselbe), nur der Link kommt nie an.
5. Einfacher Schutz gegen Mail-Flut: Pro Mitgliedsnummer werden höchstens drei Anmeldemails pro Minute verschickt.

### 6b. Vorgelagertes SSO (für Vereine mit bestehender Zugangskontrolle)

Hat Ihr Verein bereits eine eigene Anmeldung (z. B. über den Webserver/Reverse-Proxy, der `$_SERVER['REMOTE_USER']` auf die Mitgliedsnummer setzt), erkennt das Tool das automatisch – ganz ohne das Login-Modul zu aktivieren. REMOTE_USER hat dabei **immer Vorrang** vor dem Login-Modul: Ist beides gleichzeitig aktiv, wird eine per SSO identifizierte Person nie zusätzlich zur Eingabe der Mitgliedsnummer aufgefordert. In diesem Fall muss die eigentliche Authentifizierung (Passwortprüfung o. ä.) vollständig von der vorgelagerten Instanz übernommen werden – das Tool selbst prüft REMOTE_USER nicht weiter nach.

Für den SSO-Logout: Unter Vereinsspezifische Vorgaben lässt sich ein „SSO-Logout-Link" hinterlegen, zu dem der Klick auf „Abmelden" führt (ohne Angabe wird nur die lokale PHP-Sitzung beendet – eine vorgelagerte SSO-Anmeldung kann das Tool selbst nicht beenden).

### 6c. Demo-Modus (nur für Test-/Demo-Installationen)

Für lokales Testen oder eine öffentliche Demo-Installation lässt sich der Demo-Modus setzen - entweder unter Vereinsspezifische Vorgaben (Haken „Demo-Modus") oder, empfohlen, direkt per `DEMO_MODE`-Konstante in `config.php` (siehe Abschnitt 3). `login.php` zeigt dann - optisch identisch zur Demo-Startseite der Schwesterprojekte Sitzungsverwaltung (KONA) und Mitgliederverwaltung (MISS) - eine vollständige Startseite mit kurzer Feature-Übersicht und darunter bis zu 12 Mitglieder als anklickbare Karten – ein Klick meldet sofort an, ganz ohne Passwort, Mail-Bestätigung oder Anmeldelink. Nicht angemeldete Gäste werden bei aktivem Demo-Modus automatisch von `index.php` auf diese Startseite geleitet, statt die normale (im Demo-Fall meist noch leere) Gast-Ansicht zu sehen. Ist zusätzlich das Login-Modul aktiv, führt ein Link auf der Demo-Startseite zu `login.php?echt=1` - dem schlichten, echten Anmeldeformular, zum Ausprobieren des tatsächlichen Anmeldewegs.

**Feste Admin-Demo-Figur „DEMO0001":** Ist unter den Mitgliedern der aktuellen Mitgliederquelle eine Mitgliedsnummer `DEMO0001` vorhanden, erscheint sie auf der Demo-Startseite immer als **letzte** Karte (nicht Teil der zufälligen Auswahl) und bekommt bei jedem Login automatisch Redaktionsrechte (`Red = 9`) - ganz ohne SQL von Hand (siehe Abschnitt 7 unten). Für eine aussagekräftige Demo sollte `DEMO0001` daher in den Demo-Mitgliederdaten als eigene Person angelegt sein.

**Über die Konstante `DEMO_MODE` gesetzt** (statt nur über die Vereinsvorgabe) wird zugleich automatisch die Mitgliederquelle „svmembers" erzwungen - ganz ohne weiteren Admin-Schritt. Das ist die empfohlene Variante für eine echte Demo-Installation: `svmembers` enthält dort die eigens dafür erzeugten Demo-Mitglieder derselben Datenbank (siehe Abschnitt 5c) - sobald diese Demodaten vorhanden sind, braucht es in VERA selbst keine weitere Konfiguration mehr.

**⚠️ Nur für Test-/Demo-Installationen.** Der Demo-Modus umgeht jede Authentifizierung vollständig – niemals auf einer Installation mit echten Mitgliederdaten aktivieren, da sich darüber jede und jeder als jedes gelistete Mitglied anmelden kann. Default ist aus, bestehende Installationen ändern sich dadurch nicht.

Wie beim normalen Login entsteht dabei nur für das tatsächlich angemeldete Mitglied ein Eintrag in `mvmitglieder_<jahr>` - keine Massenübernahme aller in `svmembers` vorhandenen Mitglieder. Eine Rechtevergabe per SQL-UPDATE (Abschnitt 7 unten) funktioniert deshalb erst, nachdem sich die betroffene Person mindestens einmal angemeldet hat - genau wie sonst auch (Ausnahme: `DEMO0001`, siehe oben).

**Demo-Inhalte einspielen:** Bei aktivem Demo-Modus erscheint unter Admin-Funktionen → Vereinsspezifische Vorgaben zusätzlich der Button „Demo-Daten einspielen". Er erzeugt für das aktuelle MV-Jahr einen kompletten, realistischen Demo-Satz - Tagesordnung, mehrere Hauptanträge aller drei Kategorien mit Änderungs-/Verfahrensanträgen, Unterstützungen (mit und ohne erreichten Schwellenwert) und Stimmungsbild-Werten - analog zu den Demo-Daten der Schwesterprojekte (`demo_daten_functions.php: demo_daten_einspielen()`). Als Antragsteller:innen/Unterstützer:innen werden die Mitglieder der aktuellen Mitgliederquelle verwendet. **Löscht dabei alle bereits vorhandenen Anträge und die Tagesordnung des gewählten Jahres unwiderruflich** und ersetzt sie durch den Demo-Satz - beliebig oft wiederholbar, jeder Durchlauf überschreibt den vorherigen vollständig.

Zusätzlich zur Redaktionsrolle (`Red = 9`) verlangt der Button ein separates **Admin-Passwort** (Konstante `SYSTEM_ADMIN_PASSWORD` in `config.php`, siehe Abschnitt 3) - wie bei den Schwesterprojekten Sitzungsverwaltung (KONA) und Mitgliederverwaltung (MISS). Ohne gesetzte Konstante bleibt der Button gesperrt (sicherer Standard). Einmal pro Browser-Sitzung richtig eingegeben, wird es für den Rest der Sitzung nicht erneut abgefragt.

### 6d. Familienweite Sitzung (automatische Anmeldung über MISS/KONA/KAND)

Steht die Mitgliederquelle auf „Tabelle ‚svmembers'" (Abschnitt 5c), erkennt VERA zusätzlich automatisch eine Anmeldung, die bereits **in einer Schwesteranwendung** derselben Produktfamilie stattgefunden hat - Mitgliederverwaltung „MISS", Sitzungsverwaltung „KONA" oder Kandidatentool „KAND" - ganz ohne eigenes Login-Modul oder vorgelagertes SSO. Das ist der typische Weg für Vereine, die MISS als ihre eigentliche Mitgliederverwaltung/Anmeldung nutzen (anders als beim TSV Musterstadt, der ohne diese Umgebung auskommt und Abschnitt 6a nutzt).

**Wie es funktioniert:** MISS/KONA/KAND schreiben bei erfolgreicher Anmeldung eine gemeinsame PHP-Sitzungsvariable (`$_SESSION['member_id']`, die interne ID aus `svmembers`). VERA liest diese Variable aus und übersetzt sie über `svmembers_id_von_mnr()`/`svmembers_mnr_von_id()` in die Mitgliedsnummer, die es selbst durchgehend verwendet. Meldet sich jemand stattdessen zuerst in VERA an (eigener Magic-Link, Abschnitt 6a, oder Demo-Klick, Abschnitt 6c), schreibt VERA dieselbe Variable ebenfalls - die Erkennung funktioniert also in beide Richtungen, nicht nur MISS → VERA.

Reihenfolge, in der VERA die angemeldete Person ermittelt (`get_aktuelle_mnr()` in `functions_neu.php`):

1. `$_SERVER['REMOTE_USER']` (vorgelagertes SSO, Abschnitt 6b) - höchste Priorität.
2. VERAs eigene, in dieser Sitzung bereits aufgelöste Mitgliedsnummer (schneller Weg bei wiederholten Aufrufen, kein erneuter Datenbankzugriff).
3. Die familienweite Sitzungsvariable `member_id`, übersetzt über `svmembers`.

Ist keiner der drei Wege erfolgreich, gilt die Person als nicht angemeldet - genau wie ohne svmembers-Anbindung.

**⚠️ Wichtige Voraussetzung, rein infrastrukturell - keine Einstellung in VERA selbst:** Das funktioniert nur, wenn MISS/KONA/KAND und VERA tatsächlich **dieselbe** PHP-Sitzung teilen - nicht nur denselben Cookie-Namen/-Pfad (`session_config.php` ist in allen vier Anwendungen bewusst identisch gehalten), sondern auch **dieselbe serverseitige Sitzungsablage** (`session.save_path`). Laufen die Anwendungen z. B. unter getrennten PHP-FPM-Pools mit je eigenem Sitzungsverzeichnis oder gar auf unterschiedlichen Servern, kommt derselbe Cookie zwar an, die zugehörigen Sitzungsdaten sind für die jeweils andere Anwendung aber nicht einsehbar - die Erkennung bleibt dann stillschweigend aus (kein Fehler, die Person landet einfach auf der normalen Anmeldeseite). Bei der Ersteinrichtung einer gemeinsamen Umgebung für mehrere dieser vier Anwendungen daher empfehlenswert:

- Alle vier Anwendungen auf demselben Server/derselben PHP-Umgebung betreiben (gleicher PHP-FPM-Pool bzw. zumindest gleich konfigurierter `session.save_path`).
- Nach der Einrichtung einmal testweise in einer Anwendung anmelden und prüfen, ob eine andere die Anmeldung tatsächlich automatisch übernimmt (z. B. in MISS anmelden, dann VERA aufrufen, ohne sich dort gesondert anzumelden).

Für einen Verein wie den TSV Musterstadt ohne KONA/MISS/KAND-Umgebung ist dieser Abschnitt nicht relevant.

### 6e. Wichtig: Die Abstimmungsmaschine nutzt ein eigenes, unabhängiges Berechtigungssystem

Alles in Abschnitt 6a-6d beschreibt, wie VERA eine **Mitgliedsnummer** erkennt - nötig für Anträge, Tagesordnung, Meinungsbilder, Admin-Bereich usw. Die **Abstimmungsmaschine** (`abstimmen.php`, aufgerufen über den QR-Code auf der gedruckten Stimmkarte) ist davon bewusst komplett unabhängig:

- Die Berechtigung zum Abstimmen ist ausschließlich das Stimmkarten-**Token** selbst (ein zufälliger, 192 Bit langer Wert, siehe `dokumentation/Abstimmungsmaschine.md`) - keine Anmeldung, keine Mitgliedsnummer, keine Sitzung nötig. Ein Mitglied kann seinen QR-Code scannen und abstimmen, ganz ohne sich jemals irgendwo im Tool angemeldet zu haben (frisches Smartphone, keine Cookies, egal).
- Die Stimmkarten-Token werden beim Erzeugen **bewusst nicht** mit einer Mitgliedsnummer verknüpft - am Einlass wird eine Karte frei aus einem Stapel gewählt, das System muss nie wissen, wer welche Karte hält (Anonymität der Abstimmung).
- Die einzige Berührung mit der Mitglieds-Anmeldung: Das optionale „Austritt"-Feature (Stimmkarte am Ausgang entwerten, siehe Abschnitt 10d) erscheint zusätzlich nur dann, wenn die scannende Person selbst (über einen der Wege 6a-6d) mit Ein-/Ausgang-Rechten angemeldet ist - für die normale Stimmabgabe ist das nie erforderlich.

### 6f. Aus Mitgliedersicht: Wie melde ich mich an, um die Anträge zu sehen?

Die Stimmkarte und die normale Anmeldung sind **zwei verschiedene Adressen** - der QR-Code auf der Stimmkarte führt direkt zur anonymen Abstimmungsseite (Abschnitt 6e), nicht zu den Anträgen. Wer die Anträge, die Tagesordnung oder den eigenen Mitgliederbereich sehen will, ruft stattdessen die **normale VERA-Adresse** auf (die Adresse, die z. B. in der Einladung zur Versammlung oder auf der Vereinswebsite steht) und meldet sich dort an - je nach eingerichteter Variante so:

- **Vorgelagertes SSO (Abschnitt 6b):** Einfach die normale VERA-Adresse aufrufen. Ist noch keine gültige Sitzung vorhanden, fragt die vorgelagerte Instanz automatisch nach den gewohnten Zugangsdaten (z. B. demselben Login wie für andere Dienste des Vereins) - kein separates VERA-Passwort, keine Einrichtung nötig.
- **Familienweite Sitzung über MISS (Abschnitt 6d):** Zuerst bei MISS anmelden (Mitgliedsnummer oder E-Mail-Adresse + Passwort, beim allerersten Mal über „Passwort vergessen" einen Einmal-Link anfordern). Anschließend die VERA-Adresse aufrufen - die Anmeldung wird automatisch übernommen.
- **Login-Modul ohne SSO (Abschnitt 6a):** Die VERA-Adresse aufrufen, Mitgliedsnummer eingeben, auf den per Mail zugeschickten Anmeldelink klicken (15 Minuten gültig) - kein Passwort nötig.

**Für die Einladung/das Infoblatt zur Versammlung:** Am einfachsten für die Mitglieder ist es, wenn dort beide Adressen klar benannt und unterschieden werden - die Stimmkarte selbst reicht fürs Abstimmen, für alles andere (Anträge lesen, Meinungsbild abgeben, Beschlüsse nachlesen) braucht es den separaten Anmeldeweg oben. Als **dritte Adresse** gehört dort auch der Link zur Übungsseite (`uebung.php`, siehe Abschnitt 6g) hinein - am besten schon einige Tage vor dem Termin, damit Mitglieder den Ablauf der Abstimmung vorab in Ruhe mit ihrem eigenen Smartphone ausprobieren können.

### 6g. Übungsseite: Abstimmung vorab ausprobieren

`uebung.php` ist eine öffentliche, login-freie Seite (wie `abstimmen.php`), auf der Mitglieder schon vor dem Versammlungstermin den kompletten Ablauf einer Abstimmung durchspielen können: ein frei erfundener Beispielantrag mit kurzem "Diskussion läuft"-Hinweis, dann ein echter Stimmzettel zum Antippen (Ja/Nein/Enthaltung), dann ein klar als Beispiel gekennzeichnetes Ergebnis. Technisch läuft dabei wirklich die echte Abstimmungsmaschine-Engine mit - jede Person bekommt beim Aufruf automatisch ihre eigene, isolierte Testrunde (kollidiert nie mit einer echten Abstimmung oder mit anderen gleichzeitig Übenden), die nach der Testabstimmung sofort wieder aufgeräumt wird. Keine Einrichtung nötig - die Seite funktioniert ohne jede Vereinsvorgabe, sobald die Datenbank-Migrationen (Abschnitt 4) gelaufen sind.

**Empfehlung:** Den Link zu `uebung.php` in die Einladung/das Infoblatt zur Versammlung aufnehmen (siehe oben, Abschnitt 6f) - idealerweise mit ein, zwei Sätzen Erklärung, dass es sich um eine unverbindliche Übung handelt ("so sieht später die echte Abstimmung aus - probier es gerne vorher aus").

## 7. Erste Redaktionsperson freischalten (Henne-Ei-Problem)

Die Rechteverwaltung (Admin-Funktionen → Rechteverwaltung, Rolle „Redaktionsteam" = `Red = 9`) ist selbst nur für Mitglieder mit dieser Rolle erreichbar. **Bei einer komplett neuen Installation muss deshalb die erste Redaktionsperson einmalig direkt in der Datenbank gesetzt werden** - außer im Demo-Modus: dort reicht ein Klick auf die Demo-Figur `DEMO0001` (siehe Abschnitt 6c), die automatisch Redaktionsrechte bekommt, ganz ohne SQL.

1. Sich einmal ganz normal am Tool anmelden (per Login-Modul, SSO oder - im Demo-Modus - per Demo-Button, siehe Abschnitt 6) – dadurch legt das Tool automatisch einen Datensatz in `mvmitglieder_<jahr>` an.
2. Diesen Datensatz per SQL auf die Rolle Redaktionsteam setzen:

   ```sql
   UPDATE mvmitglieder_<jahr> SET Red = 9 WHERE MNr = '<eigene Mitgliedsnummer>';
   ```

Danach kann diese Person über die normale Rechteverwaltung im Tool weitere Redaktionsteam-Mitglieder und sonstige Rollen (Technik, Versammlungsleitung, Protokollführung, Ein-/Ausgang-Team – siehe Abschnitt 10d) sowie das unabhängige Vorstandsamt-Flag vergeben – ohne weitere Datenbankzugriffe.

## 8. Vereinsspezifische Vorgaben setzen

Unter **Admin-Funktionen → Vereinsspezifische Vorgaben** im Tool selbst pflegbar (keine Datenbankbearbeitung nötig):

| Einstellung | Bedeutung | Für den TSV Musterstadt |
|---|---|---|
| Vereinsname | Erscheint im Kopfbereich und in der Seitenüberschrift | „TSV Musterstadt e.V." |
| Bezeichnung der Versammlung | Manche Vereine nennen sie anders als „Mitgliederversammlung" | z. B. „Hauptversammlung" |
| Maximale Zahl Hauptanträge je Mitglied | Kontingent pro Person und Jahr (Standard 2) | meist unverändert lassen |
| Unterstützungs-Schwellenwert | Mindestzahl Unterstützer, damit ein Antrag behandelt wird (Standard 10) | bei einem kleinen Verein ggf. niedriger ansetzen, z. B. 3–5 |
| Frist dafür (Wochen nach Antragsschluss) | Standard 4 | je nach Satzung |
| Stimmungsbild-Mindestteilnehmerzahl | Ab wann normale Mitglieder Stimmungsbild-Ergebnisse überhaupt sehen (Standard 30) | bei einem kleinen Verein ggf. niedriger ansetzen |
| Antragsrückzug nur bis Antragsschluss | An/Aus – sonst jederzeit bis zur eigenen Abstimmung möglich | je nach Satzung |
| Logo-Datei / Favicon-Datei | Pfad/URL zum Vereinslogo bzw. Browser-Tab-Symbol (kein eigener Datei-Upload – Datei muss selbst auf dem Server liegen bzw. extern erreichbar sein) | `logo-tsv.png` |
| Sichtbare Navigationspunkte | Diskussionsforum/MV-Beschluss-Sammlung/Wahl-Information einzeln ein-/ausblendbar | ein kleiner Verein lässt ungenutzte Punkte meist aus |
| **Mitgliederquelle** | „LDAP", „Lokale Mitgliederstamm-Tabelle" oder „Tabelle ‚svmembers'" (siehe Abschnitt 5) | „Lokale Mitgliederstamm-Tabelle" |
| **Login-Modul aktivieren** | Eigenes Magic-Link-Anmeldeformular an/aus (siehe Abschnitt 6) | aktiviert |
| SSO-Logout-Link | Nur relevant bei vorgelagertem SSO (Abschnitt 6b) | leer lassen |
| **Demo-Modus** | Direkt-Login-Auswahl ohne Mail-Versand (siehe Abschnitt 6c) – nur für Test-/Demo-Installationen | aus (nie bei echten Mitgliederdaten) |

Unter **Admin-Funktionen → Termine** werden je MV-Jahr gepflegt: Versammlungsdatum, Beginnzeit, Ort, Antragsschluss, Enddatum der Unterstützungs-Voten, sowie die beiden Wahl-Info-Links (Wahlverfahren, Info & Kandidierende).

Unter **Admin-Funktionen → Reihenfolge & Gruppierung** legt die Redaktion für alle Hauptanträge die vorgeschlagene Behandlungsreihenfolge sowie die Gruppierung gemeinsam zu beratender Anträge fest – optional, meist erst kurz vor der Versammlung relevant.

## 9. Erstes MV-Jahr anlegen und Termine eintragen

Über **Admin-Funktionen → Neues Jahr anlegen** (nur Redaktionsteam) wird das erste MV-Jahr eingerichtet: alle jahr-gebundenen Tabellen werden angelegt, und ein leeres Dokumentenverzeichnis `docs<jahr>` wird erstellt. Direkt danach unter „Termine" Datum, Ort und Antragsschluss eintragen – ohne Antragsschluss funktionieren einige Fristenberechnungen (Unterstützungsfrist, Stimmungsbild-Sichtbarkeit) nicht korrekt.

Anschließend unter „Tagesordnung bearbeiten" entweder die mitgelieferte Standard-Tagesordnung laden oder eine eigene Tagesordnung aus TOPs (Tagesordnungspunkten) zusammenstellen. Die Standard-Tagesordnung bringt für einige TOPs (z. B. „Genehmigung der Tagesordnung", „Entlastung der Mitglieder des Vorstandes") bereits einen Beschlussentwurf-Textvorschlag mit (`tagesordnung_standard.php: get_standard_tagesordnung()`) - bei reinen Ablaufpunkten und Wahlen bewusst leer. Jeder Vorschlag lässt sich unter „Tagesordnung bearbeiten" wie gewohnt anpassen oder löschen; weitere Standard-Vorschläge lassen sich bei Bedarf direkt in `tagesordnung_standard.php` ergänzen.

## 10. Optionale Funktionen

### 10a. Diskussionsforum-Link-Muster (nur falls „Diskussionsforum" aktiviert ist)

Beim Einreichen eines Hauptantrags erzeugt `antraege_functions.php: antrag_forumslink_generieren()` automatisch einen Vorschlag für einen Forums-Slug nach dem Muster `antrag-<nummer>-<titel-in-bindestrich-schreibweise>` (Kleinbuchstaben, Umlaute als ae/oe/ue/ss), z. B. `antrag-s06-einberufung-einer-ausserordentlichen-mv`. **Dieses Muster ist nur ein Vorschlag** und auf keine bestimmte Forensoftware zugeschnitten – betreibt Ihr Verein (wie typischerweise ein kleiner Sportverein) gar kein eigenes Diskussionsforum, bleibt dieser Navigationspunkt einfach deaktiviert (siehe Abschnitt 8) und die Funktion ist irrelevant. Wird ein Forum genutzt, kann die Redaktion den erzeugten Wert pro Antrag unter „Antrag bearbeiten" jederzeit manuell überschreiben.

### 10b. Mail-Benachrichtigung bei Übernahme eines Änderungsantrags

Erklärt eine/ein Antragsteller:in die Übernahme eines Änderungsantrags, benachrichtigt das Tool automatisch alle Mitglieder mit Rolle Redaktionsteam (`Red=9`), die eine Mailadresse hinterlegt haben (`mitglieder_functions.php: redaktion_ueber_aenderungsantrag_uebernahme_benachrichtigen()`). Es wird bewusst **kein separates Mail-Konfigurationsfeld** benötigt – die vorhandene Rollenzuweisung und die hinterlegte Mailadresse reichen aus. Voraussetzung ist wie beim Login-Modul (Abschnitt 6a) ein funktionierender `mail()`-Versand auf dem Server. Fehlt eine Mailadresse bei allen Redaktionsteam-Mitgliedern, unterbleibt die Benachrichtigung lautlos (kein Fehler) – die Übernahme selbst bleibt davon unberührt, nur die Redaktion muss dann anderweitig davon erfahren.

### 10c. Beamersteuerung während der Versammlung

Die Beamersteuerung ist eine eigene Seite im Hauptmenü (Folie auswählen, Uhrzeit, Timer, Abstimmungsmaschine) – erreichbar für Mitglieder mit der Rolle **Technik** oder **Redaktionsteam** unter Rechteverwaltung, nicht für Versammlungsleitung oder Protokollführung: in der Praxis bedient meist das Technik-Team den Beamer während der Versammlung, die beiden anderen Rollen brauchen diese Bedienung nicht. Das dazugehörige Beamerbild (`beamerbild.php`) ist eine eigenständige Seite ohne Zugriffsschutz über das normale Rechtesystem hinaus – sie verlangt lediglich eine gültige Anmeldung (kein Gast-Zugriff), keine bestimmte Rolle, da sie während der Versammlung öffentlich auf einer Projektionsfläche sichtbar ist.

### 10d. Stimmkarten am Ausgang entwerten ("Austritt")

Damit niemand eine Stimmkarte vorab fotografiert und nach dem Verlassen des Saals von außen weiter abstimmt (oder beim Wiedereintritt mit einer neu ausgegebenen Karte zwei gültige Stimmen hätte), lässt sich jede Stimmkarte einzeln entwerten. Dafür braucht es eine eigene Rolle **Ein-/Ausgang-Team** (`Red = 6`), unter Rechteverwaltung wie jede andere Rolle vergeben – bewusst getrennt von der Beamersteuerung, da in der Praxis ein anderes Team am Einlass/Ausgang steht.

Bedienung: eine mit dieser Rolle angemeldete Person scannt am Ausgang mit dem eigenen Smartphone denselben QR-Code, der schon auf der abgegebenen Stimmkarte steht (keine zweite App/Funktion nötig) – `abstimmen.php` erkennt die Rolle an der mitgeschickten Anmeldung und zeigt statt des normalen Stimmzettels einen Button „🚪 Jetzt ungültig machen (Austritt)" mit Sicherheitsabfrage. Die Karte ist danach endgültig und unwiderruflich ungültig.

### 10e. Einlasskontrolle & hybride Versammlung (im Aufbau)

Für Vereine mit hybriden oder reinen Online-Versammlungen gibt es ein mehrteiliges Feature: ein elektronischer Mitgliedsausweis mit QR-Code, eine Einlasskontrolle-Seite, eine Teilnahmeform-Auswahl (vor Ort/online) und ein anonymes Abstimmungstoken für Online-Teilnehmende. Alle fünf Phasen sind fertig.

**Bereits vorhanden (Phase 1 – Grundlagen):**

- Neue Vorgaben unter „Vereinsspezifische Vorgaben" (siehe Abschnitt 8), Bereich „Einlasskontrolle & Anwesenheit":
  - **Mitgliedschaft am Einlass prüfen** – Schalter, ob überhaupt eine Einlasskontrolle-Seite genutzt wird (folgt in Phase 3). Unangekreuzt (Vorgabe): keine Prüfung nötig, die Anwesenheit wird stattdessen automatisch simuliert (siehe nächster Punkt) – passend für Vereine, in denen sich die Mitglieder ohnehin persönlich kennen.
  - **Versammlungsbeginn/-ende** – Zeitfenster für die automatische Anwesenheits-Simulation: nur VERA-Aufrufe innerhalb dieses Fensters zählen später als „wirklich anwesend". Wer die Seite Tage vorher aufruft (z. B. auf der Übungsseite, siehe Abschnitt 6g), wird dadurch **nicht** fälschlich als anwesend geführt.
  - **Foto-Quelle für den Mitgliedsausweis** – URL-Muster mit Platzhalter `{mnr}`, z. B. `https://miss.verein.de/foto.php?mnr={mnr}` (siehe Phase 2 unten).
- Neue Spalten in `mvmitglieder_<jahr>` (werden wie gewohnt automatisch beim ersten Zugriff angelegt, siehe Selbstheilung in Abschnitt 4): `Teilnahmeform` (`vor_ort`/`online`/leer), `StimmkarteAusgegeben`, `AnwesendSeit`, `AnwesendArt`.

**Bereits vorhanden (Phase 2 – Elektronischer Mitgliedsausweis):**

Nur sichtbar, wenn die Vorgabe „Mitgliedschaft am Einlass... prüfen" (siehe Phase 1) angekreuzt ist – ohne Einlasskontrolle (z. B. Vereine mit externer Mitgliederverwaltung/Adapter-Betrieb statt MISS) ergibt der elektronische Ausweis keinen Sinn: kein Foto (kommt nur von MISS), kein Status (ebenfalls nur MISS), kein Einlass-Scan, der ihn je läse. Ist die Vorgabe gesetzt, erreicht jedes angemeldete Mitglied über den Button „🪪 Mein Mitgliedsausweis" (neben „Abmelden") die Seite `ausweis.php`: zeigt Name, Foto (falls die Foto-Quelle-Vorgabe gesetzt ist – sonst ein neutraler Platzhalter) und einen QR-Code. Der QR-Code kodiert einen Link zu `ausweis_pruefen.php` mit einem Token - **keine** vCard direkt, da eine Handykamera eine vCard nur als „Kontakt hinzufügen"-Dialog erkennt, nicht als klickbaren Link (wichtig für Phase 3, siehe unten). Was beim Öffnen dieses Links angezeigt wird, hängt davon ab, wer ihn öffnet (siehe Phase 3).

Sicherheit: Wie bei der Abstimmungsmaschine wird nur ein zufälliger Rohwert herausgegeben, in der Datenbank (`mv_ausweis_tokens`) steht nur dessen Hash – ein Datenbank-Leak allein reicht also nicht, um gültige Ausweise zu fälschen. Jeder Aufruf der Ausweis-Seite erzeugt einen frischen, 24 Stunden gültigen Token; anders als ein Abstimmungstoken wird er beim Scannen nicht verbraucht (derselbe Ausweis darf beliebig oft gescannt werden). Voraussetzung: Die Vorgabe „Basis-URL der Abstimmungsmaschine" (Abschnitt „Abstimmungsmaschine") muss gesetzt sein, sonst kann der QR-Code nicht erzeugt werden (die Ausweis-Seite zeigt dann einen Hinweis statt eines kaputten Bildes).

**Bereits vorhanden (Phase 3 – Einlasskontrolle):**

Scannt eine Person mit Einlasskontrolle-Rolle (dieselbe Rolle wie die Austritt-Funktion, siehe Abschnitt 10d: **Ein-/Ausgang-Team**, `Red > 5`) den Ausweis-QR-Code eines Mitglieds mit der eigenen Handykamera, öffnet sich `ausweis_pruefen.php` und zeigt direkt die Mitgliedschaftsprüfung statt der normalen Kontaktkarte - genau das Muster der Austritt-Funktion: ein gemeinsamer Link, dessen Inhalt von der anmeldenden Person abhängt, nicht vom Token.

Die Seite zeigt eine Einschätzung (grün „Stimmberechtigt", blau „Teilnahmeberechtigt ohne Stimmrecht", rot „Nicht zugelassen" mit Begründung, oder orange „Status unbekannt", falls MISS noch keinen Status liefert) - das ist aber nur eine **Empfehlung**, keine automatische Entscheidung. Das Personal bestätigt immer manuell über einen von drei Buttons: „Einlass + Stimmkarte", „Nur Einlass (keine Stimmkarte)" oder „Abweisen". Erst der Klick trägt etwas ein: `AnwesendSeit`/`AnwesendArt = 'einlasskontrolle'` (und bei „+ Stimmkarte" zusätzlich `StimmkarteAusgegeben = 1`) in `mvmitglieder_<jahr>`, protokolliert über `mv_log()`. Ein Mitglied mit der Teilnahmeform „online" (siehe Phase 4) wird unabhängig vom Status als „Nicht zugelassen" markiert - keine Stimmkarte vor Ort für online angemeldete Teilnehmende. Ein bereits eingelassenes Mitglied wird beim erneuten Scannen mit Uhrzeit angezeigt, lässt sich aber bei Bedarf erneut bearbeiten (z. B. zur Korrektur).

Scannt dagegen eine normale Person (kein Einlasskontrolle-Personal, z. B. ein anderes Mitglied) denselben QR-Code, zeigt dieselbe Seite stattdessen Name/Telefon/Mail des Ausweis-Inhabers als Text plus einen Download-Button „📇 Als Kontakt speichern" (lädt eine vCard-Datei über `ausweis_vcf.php`) - bewusst mit vollständiger Transparenz: genau das, was im Download steckt, steht auch auf der Seite selbst in Textform, das rechtfertigt den einen zusätzlichen Tap gegenüber einer direkten vCard im QR-Code.

**Bereits vorhanden (Phase 4 – Teilnahmeform & Anwesenheitsliste):**

Neue Vorgabe „Teilnahmeform-Auswahl (vor Ort/online) für Mitglieder anbieten" (Bereich „Einlasskontrolle & Anwesenheit"). Nur bei hybriden oder reinen Online-Versammlungen ankreuzen - für reine Präsenzversammlungen bleibt sie unangekreuzt (Vorgabe), dann ist die ganze Teilnahmeform-Auswahl unsichtbar.

Angekreuzt: Mitglieder sehen auf der Startseite einen zusätzlichen Button „🪑 Teilnahmeform wählen" (`teilnahmeform.php`) und legen sich auf „Vor Ort im Saal" oder „Online per Videokonferenz" fest. Die Wahl lässt sich jederzeit ändern - **bis** die Einlasskontrolle das Mitglied am Einlass bearbeitet hat (`AnwesendSeit` gesetzt): danach ist sie gesperrt, damit niemand nach Erhalt einer Stimmkarte nachträglich auf „online" wechseln und zusätzlich ein Online-Token bekommen könnte (siehe Phase 5). Diese Festlegung ist genau das, was die Einlasskontrolle (Phase 3) prüft, um Doppelteilnahme zu verhindern.

Neuer Admin-Bereich **„📋 Anwesenheitsliste"** (unter Admin-Funktionen): zeigt alle Mitglieder mit erfasster Anwesenheit (egal ob per Einlasskontrolle oder automatisch simuliert), mit Zeitpunkt, Teilnahmeform, ob eine Stimmkarte ausgegeben wurde, und der Erfassungsart - als CSV-Datei herunterladbar, Grundlage für das offizielle Versammlungsprotokoll.

Die automatische Anwesenheits-Simulation (für Vereine ohne Einlasskontrolle, siehe Phase 1) ist jetzt tatsächlich aktiv: Ruft ein angemeldetes Mitglied `index.php` innerhalb des eingestellten Zeitfensters (Versammlungsbeginn/-ende) auf und ist die Mitgliedschaftsprüfung nicht aktiviert, wird automatisch `AnwesendSeit`/`AnwesendArt = 'simuliert'` eingetragen (`mitglied_anwesenheit_simulieren()` in `mitglieder_functions.php`) - einmalig, bereits erfasste Mitglieder werden nicht überschrieben.

**Was MISS (Mitgliederverwaltung) noch liefern muss, bevor die Status-Prüfung verlässlich funktioniert:**

VERA liest die Mitgliedschaftstabelle `svmembers` schon heute rein lesend mit (siehe Abschnitt 5c) – für die Einlasskontrolle (Phase 3) braucht es dort zusätzlich eine neue Spalte `status` mit genau diesen sieben Werten, von MISS gepflegt (Vorstand/Admin-Rolle in MISS) und automatisch berechnet. Fehlt diese Spalte noch (wie aktuell), zeigt die Einlasskontrolle-Seite orange „Status unbekannt" statt grün/rot - das Personal entscheidet dann bewusst manuell, ob die Mitgliedschaft anderweitig geprüft wird:

- Gast
- Aufnahme beantragt
- Vorläufig aufgenommen
- Teilnahmeberechtigt (ohne Stimmrecht)
- Stimmberechtigt
- Gesperrt
- Ausgetreten

Automatik (MISS-seitig, nicht VERA): „Stimmberechtigt" ist, wer zu einem in MISS einstellbaren Stichtag (z. B. Tag vor der Versammlung) den Beitrag vollständig bezahlt hat – sonst „Gesperrt". Ebenfalls in MISS einstellbar: ab welchem Lebensjahr ein Mitglied stimmberechtigt statt nur teilnahmeberechtigt ist. VERA selbst führt keine Beitrags- oder Geburtsdatumslogik – es liest am Ende nur den fertigen `status`-Wert, genau wie heute schon bei `membership_number`/`first_name`/`last_name`/`email`.

Für den Mitgliedsausweis (Phase 2, bereits umgesetzt) liefert MISS außerdem das erste in MISS hinterlegte Foto des Mitglieds über die oben konfigurierte Foto-Quelle-URL – VERA speichert/cached kein Foto selbst, sondern bindet es nur per `<img>` direkt von dort ein. Die Telefonnummer auf der Visitenkarte (Phase 3) liest VERA direkt aus MISS' eigener Kontakttabelle `md_contacts` (Kind `phone`/`mobile`) mit – **nicht** aus `svmembers` – und nur, wenn das Mitglied sie dort in den eigenen Sichtbarkeitseinstellungen auf „Alle" freigegeben hat (MISS-Standard: „Nur ich"); sonst bleibt sie auf der Visitenkarte leer.

**Phase 5 – Online-Abstimmungstoken (ohne Drucker, ohne echte Push-Benachrichtigung):**

Mitglieder mit Teilnahmeform „online" (siehe Phase 4) erreichen über den Button „💻 Online abstimmen" (nur sichtbar, wenn diese Festlegung getroffen wurde) die Seite `online_abstimmen.php`. Solange die Versammlungsleitung keine Abstimmung eröffnet hat, zeigt sie nur einen Wartehinweis, der sich automatisch alle 5 Sekunden neu lädt (einfaches Meta-Refresh, kein JavaScript/Service Worker/Benachrichtigungs-Erlaubnis nötig). Sobald eine Runde eröffnet wird, erzeugt der nächste automatische Neuladen serverseitig einen frischen Abstimmungstoken für genau diese Runde und leitet direkt zum normalen, vollständig anonymen Stimmzettel weiter (`abstimmen.php`) - identisch zum Stimmzettel der Mitglieder vor Ort.

Sicherheits-/Anonymitätsmodell (bewusst anders als bei physischen Stimmkarten, die absichtlich den ganzen Tag über alle Runden gültig bleiben):

- Der Token kommt aus demselben anonymen Pool wie die gedruckten Stimmkarten - der Server speichert dabei nur ein Häkchen „diese Mitgliedsnummer hat für diese Runde schon einen Zugang bekommen" (Tabelle `mv_online_ausgaben`), nie eine dauerhafte Verknüpfung von Mitglied und Tokenwert.
- Zusätzlich wird der Token über `mv_online_token_bindung` an GENAU diese eine Runde gebunden (enthält nur Token-Hash und Rundennummer, keine Mitgliedsnummer) - fängt jemand den Token während der Übertragung ab, funktioniert er nicht mehr in einer späteren Runde. Diese Bindung bleibt dauerhaft bestehen (im Gegensatz zum Ausgabe-Häkchen, das nach Rundenende gelöscht wird) - sie verrät nichts über die wählende Person.
- Der Tokenwert selbst wird ausschließlich in der eigenen Sitzung des Mitglieds gemerkt. Geht diese Sitzung während einer laufenden Runde verloren (Browser geschlossen, Gerät gewechselt), wird bewusst **kein** zweiter Zugang für dieselbe Runde automatisch ausgegeben (sonst wäre das ein Schlupfloch für doppelte Stimmen) - betroffene Mitglieder wenden sich an die Versammlungsleitung. Im schlimmsten Fall ist davon also nur eine einzelne Abstimmung betroffen, nicht die ganze Versammlung.
- Mitglieder mit Teilnahmeform „vor Ort" (oder ohne Festlegung) haben keinen Zugriff auf diese Seite - verhindert, dass sich jemand zusätzlich zu einer physischen Stimmkarte auch noch online eine Stimme verschafft.

**Noch offen:** nichts mehr auf VERA-Seite. Die MISS-seitige Status-Automatik (Spalte `svmembers.status`, Admin-Seite „Stimmberechtigung (VERA)") ist bereits im MISS-Repository umgesetzt.

Ein systematisches Testschema für alle fünf Phasen (inkl. Rollenwechsel, Status-Fälle und Online-Abstimmung) steht in `Testplan-Hybride-Versammlung.md`.

## 11. Laufender Betrieb: ein weiteres MV-Jahr anlegen

Für jede weitere Versammlung (z. B. im Folgejahr) erneut über **Admin-Funktionen → Neues Jahr anlegen** vorgehen. Die Mitgliederliste wird dabei **nicht** vollständig aus dem Vorjahr kopiert, sondern nur mit den bisherigen Funktionsträger:innen (Redaktionsteam, Vorstand, sonstige Rollen) vorbefüllt – alle übrigen Mitglieder registrieren sich beim nächsten Zugriff automatisch neu (über die in Abschnitt 5 eingerichtete Mitgliederquelle). Direkt danach wieder unter „Termine" die Angaben für das neue Jahr eintragen.

## 12. Bekannte Einschränkungen und Umgebungsannahmen

- Logo/Favicon werden nur als Pfad/URL referenziert (kein eigener Datei-Upload dafür) – die Dateien müssen selbst auf den Server gelegt bzw. extern gehostet werden. Echten Datei-Upload gibt es an drei Stellen im Tool: Tagesordnungspunkt-Dokumente (`neu/uploads/<jahr>`), „Unterlagen <Jahr>"-Dokumente und die interne PDF-Ablage bei Anträgen (`docs<jahr>`, nur Redaktion sichtbar).
- Die Oberfläche ist ausschließlich auf Deutsch (keine Mehrsprachigkeit).
- Das Antrags-/Abstimmungsverfahren selbst (Mitantragsteller, Unterstützungsschwellen, Stimmungsbild-Sichtbarkeitsregeln) bildet einen bestimmten, in der Praxis verbreiteten Ablauf ab und lässt sich über die Vereinsvorgaben in weiten Teilen anpassen bzw. abschalten (Abschnitt 8) – entspricht die Satzung Ihres Vereins in einzelnen Punkten einem grundlegend anderen Verfahren, das sich nicht über vorhandene Einstellungen abbilden lässt, ist eine Anpassung im Code nötig.
- Für die LDAP-Mitgliederquelle (Abschnitt 5b) gilt weiterhin: Server-Adresse, Bind-DN und Attributnamen sind fest im Code hinterlegt und müssen bei abweichender Verzeichnisstruktur dort angepasst werden.

## 13. Checkliste für die Ersteinrichtung

Zum Abhaken bei einer Neuinstallation wie der des TSV Musterstadt:

- [ ] `mv/neu/` auf den Webserver kopiert
- [ ] `config.php` aus `config_example.php` erstellt und mit echten DB-Zugangsdaten befüllt
- [ ] Migrationen 001–004 ausgeführt
- [ ] Mitgliederquelle gewählt (Abschnitt 5) – bei „Lokale Tabelle": eigene Mitgliederliste per CSV importiert
- [ ] Anmeldung eingerichtet (Abschnitt 6) – bei Login-Modul: Testmail an die eigene Adresse erfolgreich erhalten
- [ ] Erste Redaktionsperson per SQL freigeschaltet (Abschnitt 7)
- [ ] Vereinsname, MV-Bezeichnung, Logo und weitere Vorgaben gesetzt (Abschnitt 8)
- [ ] Erstes MV-Jahr angelegt, Termine eingetragen, Tagesordnung zusammengestellt (Abschnitt 9)
- [ ] Mit einem zweiten Testaccount (nicht Redaktion) einmal den kompletten Ablauf durchgespielt: anmelden, Antrag stellen, Stimmungsbild abgeben
