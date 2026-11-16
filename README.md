> **Dieses Repository enthält zwei Anwendungen:**
> - **VERA** (Hauptverzeichnis): Anträge, Tagesordnung, Abstimmungen und Wahlen für die Mitgliederversammlung.
> - **WABS** (Ordner `WABS/`): eigenständiger Wahlserver für anonyme Online-Wahlen und -Abstimmungen. Er wird getrennt von VERA
>   installiert (eigene `config.php`, eigene Datenbank).
>
> Die Dokumentation liegt in `dokumentation/`. Pfadangaben wie `mv/neu/` in älteren Dokumenten meinen den Hauptordner dieses Repositories.
> Konfiguration: `config_example.php` nach `config.php` kopieren (liegt nicht im Git).

# VERA – Versammlungs- und Antragsorganisation (MV-Tool)

VERA ist das System für die rechtssichere Abwicklung von Anträgen vor und während der Mitgliederversammlung.

Ein Web-Tool zur digitalen Vorbereitung und Durchführung der jährlichen Mitgliederversammlung (MV) eines Vereins: Anträge stellen, diskutieren, unterstützen und per Stimmungsbild vorab einschätzen; Tagesordnung, Unterlagen, Beschluss-Sammlung und Wahl-Informationen bereitstellen; während der Versammlung selbst die Beamer-Anzeige steuern.

Das Tool ist **für jeden Verein nutzbar**, nicht an eine bestimmte Mitgliederverwaltung oder Anmeldeinfrastruktur gebunden – siehe [„Für wen ist das geeignet?“](#für-wen-ist-das-geeignet) unten.

## Funktionsumfang

- **Anträge**: Haupt-, Änderungs- und Verfahrensanträge stellen, bearbeiten, zurückziehen – inkl. automatischer Nummerierung je Kategorie.
- **Mitantragstellerschaft & Unterstützung**: mit konfigurierbarem Schwellenwert und Frist, Unterstützung wahlweise anonym.
- **Stimmungsbild**: Wichtigkeits- und Zustimmungs-Einschätzung vor der eigentlichen Abstimmung, mit durchdachten Sichtbarkeitsregeln gegen Mitläufer-Effekte (siehe Projektdokumentation).
- **Tagesordnung** mit zugeordneten Dokumenten je Tagesordnungspunkt.
- **Unterlagen**: jahresspezifische und jahresübergreifende Dokumente, zweispaltig gruppierbar.
- **MV-Beschluss-Sammlung**: durchsuchbares Archiv vergangener Beschlüsse.
- **Wahl-Information**, **Diskussionsforum-Anbindung** – je Verein einzeln ein-/ausblendbar.
- **Kompaktliste**, eigene **Beamersteuerung** (Technik/Beamer-Team, Redaktionsteam) und **Abstimmungsmaschine** (Live-Abstimmungen/Wahlen per Smartphone) für die Versammlung selbst, inkl. öffentlicher Beamer-Anzeige.
- **Admin-Bereich**: Rechteverwaltung, vereinsspezifische Vorgaben, Termine, neues MV-Jahr anlegen, Reihenfolge & Gruppierung, Protokoll aller schreibenden Zugriffe.

## Für wen ist das geeignet?

Jeder Verein, der seine Mitgliederversammlung strukturiert digital abwickeln möchte – mit oder ohne eigene Mitglieder-IT:

- **Mitgliederquelle** wählbar: eine einfache, vom Tool selbst verwaltete Mitgliedertabelle (CSV-Import möglich) oder Anbindung an ein bestehendes LDAP.
- **Anmeldung** wählbar: ein eigenes passwortloses Magic-Link-Login per Mail, oder Anmeldung über ein bereits vorhandenes Single-Sign-On.

Für einen Verein ohne eigene IT-Infrastruktur reicht damit ein normales PHP/MySQL-Webhosting, wie es die meisten Vereine für ihre Webseite ohnehin schon haben.

## Technische Eckpunkte

- Rein prozedurales PHP, **keine Frameworks, keine Composer-Abhängigkeiten** – auch ein technisch versierter Vorstand/Admin ohne Framework-Kenntnisse kann den Code lesen und im Notfall selbst reparieren.
- PDO mit Prepared Statements, CSRF-Schutz auf jedem zustandsändernden Formular.
- Selbstheilendes Datenbankschema: zusätzliche Spalten/Tabellen legt sich das Tool beim ersten Zugriff selbst an – nur wenige, dokumentierte Migrationen sind einmalig von Hand auszuführen.
- Vollständige Protokollierung aller schreibenden Zugriffe.
- Strikte Trennung der Daten je MV-Jahr.

## Voraussetzungen

- Webserver mit PHP 8.x.
- MySQL- oder MariaDB-Datenbank.
- Für den empfohlenen Standardweg (lokale Mitgliederverwaltung + Login-Modul): funktionierender `mail()`-Versand auf dem Server.

## Installation

Eine ausführliche, schrittweise Anleitung – durchgehend am Beispiel eines einfachen Sportvereins ohne eigenes LDAP/SSO, inkl. Checkliste – steht in [`dokumentation/Admin-Installationsanleitung.md`](../dokumentation/Admin-Installationsanleitung.md).

## Hintergrund & Architektur

Warum das Tool so aufgebaut ist, wie es aufgebaut ist (Designentscheidungen, Sicherheits-/Datenschutzprinzipien, die einzelnen Kernprozesse der Antragsverwaltung im Detail): siehe [`dokumentation/Projekt-Dokumentation.md`](../dokumentation/Projekt-Dokumentation.md).
