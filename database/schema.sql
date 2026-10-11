-- Wahl-Sportkurse Anmeldung – Schema für MariaDB 11
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS halbjahre (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sportkurse (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    halbjahr_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    kapazitaet INT UNSIGNED NOT NULL DEFAULT 20,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    sortierung INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_sportkurse_halbjahr
        FOREIGN KEY (halbjahr_id) REFERENCES halbjahre(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS anmeldungen (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    tutorium VARCHAR(200) NOT NULL,
    email VARCHAR(255) NOT NULL,
    hj1_kurs_id INT UNSIGNED NOT NULL,
    hj1_alt_id INT UNSIGNED NOT NULL,
    hj2_kurs_id INT UNSIGNED NOT NULL,
    hj2_alt_id INT UNSIGNED NOT NULL,
    hj3_kurs_id INT UNSIGNED NOT NULL,
    hj3_alt_id INT UNSIGNED NOT NULL,
    hj4_kurs_id INT UNSIGNED NOT NULL,
    hj4_alt_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    status ENUM('aktiv', 'storniert') NOT NULL DEFAULT 'aktiv',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at DATETIME NULL,
    CONSTRAINT fk_anmeldungen_hj1_kurs FOREIGN KEY (hj1_kurs_id) REFERENCES sportkurse(id) ON DELETE RESTRICT,
    CONSTRAINT fk_anmeldungen_hj1_alt FOREIGN KEY (hj1_alt_id) REFERENCES sportkurse(id) ON DELETE RESTRICT,
    CONSTRAINT fk_anmeldungen_hj2_kurs FOREIGN KEY (hj2_kurs_id) REFERENCES sportkurse(id) ON DELETE RESTRICT,
    CONSTRAINT fk_anmeldungen_hj2_alt FOREIGN KEY (hj2_alt_id) REFERENCES sportkurse(id) ON DELETE RESTRICT,
    CONSTRAINT fk_anmeldungen_hj3_kurs FOREIGN KEY (hj3_kurs_id) REFERENCES sportkurse(id) ON DELETE RESTRICT,
    CONSTRAINT fk_anmeldungen_hj3_alt FOREIGN KEY (hj3_alt_id) REFERENCES sportkurse(id) ON DELETE RESTRICT,
    CONSTRAINT fk_anmeldungen_hj4_kurs FOREIGN KEY (hj4_kurs_id) REFERENCES sportkurse(id) ON DELETE RESTRICT,
    CONSTRAINT fk_anmeldungen_hj4_alt FOREIGN KEY (hj4_alt_id) REFERENCES sportkurse(id) ON DELETE RESTRICT,
    INDEX idx_anmeldungen_status (status),
    INDEX idx_anmeldungen_hj1 (hj1_kurs_id, status),
    INDEX idx_anmeldungen_hj2 (hj2_kurs_id, status),
    INDEX idx_anmeldungen_hj3 (hj3_kurs_id, status),
    INDEX idx_anmeldungen_hj4 (hj4_kurs_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO halbjahre (id, name, sortierung, aktiv) VALUES
    (1, 'Halbjahr 1', 0, 1),
    (2, 'Halbjahr 2', 1, 1),
    (3, 'Halbjahr 3', 2, 1),
    (4, 'Halbjahr 4', 3, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

SET FOREIGN_KEY_CHECKS = 1;
