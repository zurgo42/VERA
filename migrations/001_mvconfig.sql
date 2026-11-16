-- Migration 001: Vereinsspezifische Vorgaben (mvconfig)
--
-- Kleine Key-Value-Tabelle fuer Einstellungen, die admin-bearbeitbar sein
-- sollen und nicht (wie Termine) je Jahr in mvtexte gehoeren, sondern
-- Verein-weit gelten. Rein additiv, keine bestehenden Tabellen werden
-- veraendert - kann gefahrlos auf der Produktivdatenbank ausgefuehrt werden.
--
-- Bitte einmalig ausfuehren, bevor der Admin-Bereich "Vereinsspezifische
-- Vorgaben" genutzt wird.

CREATE TABLE IF NOT EXISTS mvconfig (
    schluessel   VARCHAR(64)  NOT NULL PRIMARY KEY,
    wert         VARCHAR(255) NOT NULL,
    beschreibung VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mvconfig (schluessel, wert, beschreibung) VALUES
    ('max_hauptantraege_pro_mitglied', '2',  'Maximale Zahl Hauptanträge je Mitglied und Jahr'),
    ('unterstuetzung_schwellenwert',   '10', 'Mindestzahl Unterstützer, damit ein Antrag auf die Tagesordnung kommt'),
    ('unterstuetzung_frist_wochen',    '4',  'Frist in Wochen nach Antragsschluss für den Schwellenwert'),
    ('rueckzug_nur_bis_antragsschluss','1',  'Rückzug nur bis Antragsschluss möglich (1) oder jederzeit bis zur eigenen Abstimmung (0)');
