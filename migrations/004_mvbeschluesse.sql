-- Migration 004: MV-Beschluss-Sammlung (mvbeschluesse)
--
-- Diese Tabelle wird bereits von der Sitzungsverwaltung genutzt (dort:
-- tab_mv_beschluesse.php, Tabellenname per Konfiguration "mvbeschluesse"),
-- da beide Systeme dieselbe Datenbank verwenden. Das MV-Tool übernimmt ab
-- jetzt selbst die Pflege dieser Sammlung - deshalb hier bewusst
-- CREATE TABLE IF NOT EXISTS: existiert die Tabelle bereits (der
-- Normalfall), ändert diese Migration nichts an den vorhandenen Daten.
-- Nur bei einer komplett neuen Installation ohne Sitzungsverwaltung wird
-- die Tabelle hier frisch angelegt (Schema identisch zur dortigen Variante).
--
-- Sobald das MV-Tool produktiv läuft, sollte die Sitzungsverwaltung ihre
-- eigene Anzeige durch einen Link hierher ersetzen (nicht umgekehrt).

CREATE TABLE IF NOT EXISTS mvbeschluesse (
    antrnr VARCHAR(50) PRIMARY KEY COMMENT 'Antragsnummer (eindeutig)',
    titel VARCHAR(500) DEFAULT NULL COMMENT 'Titel des Beschlusses',
    text TEXT DEFAULT NULL COMMENT 'Beschlusstext',
    begr TEXT DEFAULT NULL COMMENT 'Begründung',
    ergebnis VARCHAR(100) DEFAULT NULL COMMENT 'Ergebnis (angenommen/abgelehnt)',
    dafuer INT DEFAULT 0 COMMENT 'Ja-Stimmen',
    dagegen INT DEFAULT 0 COMMENT 'Nein-Stimmen',
    enth INT DEFAULT 0 COMMENT 'Enthaltungen',
    Relevant TINYINT(1) DEFAULT 1 COMMENT 'Status: 1 gültig, 0 aufgehoben, -1 nicht beschlossen',
    kategorie VARCHAR(100) DEFAULT NULL COMMENT 'Kategorie/Themenbereich',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_kategorie (kategorie),
    INDEX idx_relevant (Relevant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MV-Beschlüsse (Mitgliederversammlung)';
