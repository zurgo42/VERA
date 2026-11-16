-- Migration 002: Tagesordnung mit Dokumenten je TOP
--
-- Ersetzt die bisher nur im Code (tagesordnung_standard.php) gehaltene
-- Standardliste durch eine echte, je Jahr editierbare Tagesordnung mit
-- zugeordneten Dokumenten/Links. Rein additiv - keine bestehenden
-- Tabellen werden veraendert.
--
-- Bitte einmalig ausfuehren, bevor die Tagesordnungs-Redaktionsmaske
-- genutzt wird.

CREATE TABLE IF NOT EXISTS mvtop (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    jahr     INT NOT NULL,
    position INT NOT NULL,
    titel    VARCHAR(255) NOT NULL DEFAULT '',
    aktiv    TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_jahr (jahr)
) ENGINE=InnoDB;

-- status: 'abrufbar' (fuer alle sichtbar), 'vorbereitung' (Platzhalter
-- "folgt noch" sichtbar, aber nicht anklickbar), 'verborgen' (nur in
-- der Redaktionsmaske sichtbar)
CREATE TABLE IF NOT EXISTS mvtopdokumente (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    top_id    INT NOT NULL,
    position  INT NOT NULL DEFAULT 0,
    titel     VARCHAR(255) NOT NULL,
    url       VARCHAR(500) NOT NULL DEFAULT '',
    ist_datei TINYINT(1) NOT NULL DEFAULT 0,
    status    VARCHAR(20) NOT NULL DEFAULT 'vorbereitung',
    KEY idx_top_id (top_id),
    CONSTRAINT fk_mvtopdokumente_top FOREIGN KEY (top_id) REFERENCES mvtop(id) ON DELETE CASCADE
) ENGINE=InnoDB;
