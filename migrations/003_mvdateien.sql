-- Migration 003: Redaktionswerkzeug für "Allgemeine Unterlagen" (mvdateien)
--
-- Die bestehende Tabelle mvdateien wurde bisher nur per Hand per SQL
-- gepflegt und hat keinen echten Primärschlüssel (nur lfdnr als
-- Sortiernummer je Jahr) und kein Feld für eine Kurzbeschreibung.
-- Beides wird hier ergänzt - rein additiv, bestehende Zeilen/Spalten
-- bleiben unverändert, das alte mv.php liest weiterhin dieselben Felder.
--
-- Bitte einmalig ausführen, bevor die Unterlagen-Redaktionsmaske
-- genutzt wird.

ALTER TABLE mvdateien ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY;
ALTER TABLE mvdateien ADD COLUMN beschreibung VARCHAR(500) NULL AFTER bezeichnung;
