-- Bingo Sorpresa — esquema completo. Corre una sola vez, en la primera arrancada de MySQL con el
-- volumen vacío (docker-entrypoint-initdb.d). No hay migraciones: no había datos que migrar.
-- Fuente: «Esquema» del plan «Backoffice y Apertura Pública» (Brain).
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  google_id VARCHAR(255) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  picture VARCHAR(1024) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  storage_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,          -- suma de uploads.bytes
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  sha256 CHAR(64) NOT NULL,                                  -- del fichero ORIGINAL subido
  width SMALLINT UNSIGNED NOT NULL, height SMALLINT UNSIGNED NOT NULL,   -- tras reencodar
  bytes INT UNSIGNED NOT NULL,                               -- principal + miniatura en disco
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_upload (user_id, sha256),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bingos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(120) NOT NULL,
  share_token CHAR(32) NULL UNIQUE,                          -- bin2hex(random_bytes(16)); NULL = no compartido
  numeric_enabled TINYINT(1) NOT NULL DEFAULT 1,
  numeric_variant ENUM('spanish_90') NOT NULL DEFAULT 'spanish_90',
  music_enabled TINYINT(1) NOT NULL DEFAULT 0,
  music_label VARCHAR(40) NOT NULL DEFAULT 'Bingo Musical',
  music_rows TINYINT UNSIGNED NOT NULL DEFAULT 3, music_cols TINYINT UNSIGNED NOT NULL DEFAULT 3,
  image_enabled TINYINT(1) NOT NULL DEFAULT 0,
  image_label VARCHAR(40) NOT NULL DEFAULT 'Bingo de Fotos',
  image_rows TINYINT UNSIGNED NOT NULL DEFAULT 4, image_cols TINYINT UNSIGNED NOT NULL DEFAULT 5,
  lead_in TINYINT UNSIGNED NOT NULL DEFAULT 15,
  min_gap TINYINT UNSIGNED NOT NULL DEFAULT 4,
  spread_over TINYINT UNSIGNED NOT NULL DEFAULT 55,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_bingos_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bingo_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bingo_id INT NOT NULL,
  kind ENUM('music','image') NOT NULL,
  label VARCHAR(120) NOT NULL,
  sublabel VARCHAR(120) NULL,
  upload_id INT NULL,                                        -- kind=image
  youtube_video_id CHAR(11) NULL,                            -- kind=music (plan de YouTube)
  start_seconds SMALLINT UNSIGNED NULL, end_seconds SMALLINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_items_bingo (bingo_id, kind, id),                -- orden = id (inserción)
  FOREIGN KEY (bingo_id) REFERENCES bingos(id) ON DELETE CASCADE,
  FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bingo_prints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bingo_id INT NOT NULL,
  seed VARCHAR(64) NOT NULL,
  players TINYINT UNSIGNED NOT NULL,
  snapshot JSON NOT NULL,                                    -- PlayPayload completo en el momento de imprimir
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prints_bingo (bingo_id),
  FOREIGN KEY (bingo_id) REFERENCES bingos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tv_pairings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code CHAR(6) NOT NULL UNIQUE,                              -- 6 cifras; se borra al caducar
  device_token_hash CHAR(64) NOT NULL,                       -- sha256 del token que guarda la tele
  bingo_id INT NULL,                                         -- se rellena al reclamar
  claimed_by INT NULL,
  expires_at DATETIME NOT NULL,                              -- creación + 10 min
  FOREIGN KEY (bingo_id) REFERENCES bingos(id) ON DELETE CASCADE,
  FOREIGN KEY (claimed_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limits (
  bucket VARCHAR(120) NOT NULL,                              -- '<acción>:<ip|user_id|device>'
  window_start INT UNSIGNED NOT NULL,                        -- floor(time()/ventana)*ventana
  hits SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB;
