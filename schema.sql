-- Schemat bazy KPiR (MySQL, InnoDB, utf8mb4). Import przez phpMyAdmin w panelu cal.pl.
-- Kwoty pieniezne trzymamy w GROSZACH (BIGINT), nigdy zmiennoprzecinkowo.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Uzytkownicy (tozsamosc z Google Sign-In: `sub` jest stabilnym identyfikatorem konta Google).
CREATE TABLE IF NOT EXISTS users (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  google_sub VARCHAR(255) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ksiega (jedna KPiR). UUID nadaje klient/serwer przy tworzeniu.
CREATE TABLE IF NOT EXISTS ksiega (
  id CHAR(36) PRIMARY KEY,
  owner_user_id BIGINT NOT NULL,
  nazwa VARCHAR(255) NOT NULL,
  created_at BIGINT NOT NULL,
  FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Czlonkostwo w ksiedze z rola. Wspoldzielenie z ksiegowa = wpis EDITOR/VIEWER.
CREATE TABLE IF NOT EXISTS ksiega_membership (
  ksiega_id CHAR(36) NOT NULL,
  user_id BIGINT NOT NULL,
  role ENUM('OWNER','EDITOR','VIEWER') NOT NULL,
  PRIMARY KEY (ksiega_id, user_id),
  FOREIGN KEY (ksiega_id) REFERENCES ksiega(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zaproszenia po e-mailu (gdy zapraszany nie ma jeszcze konta w systemie).
CREATE TABLE IF NOT EXISTS ksiega_invite (
  id CHAR(36) PRIMARY KEY,
  ksiega_id CHAR(36) NOT NULL,
  email VARCHAR(255) NOT NULL,
  role ENUM('OWNER','EDITOR','VIEWER') NOT NULL,
  created_at BIGINT NOT NULL,
  accepted TINYINT NOT NULL DEFAULT 0,
  FOREIGN KEY (ksiega_id) REFERENCES ksiega(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Globalny, monotoniczny licznik do kursora delty (server_seq).
CREATE TABLE IF NOT EXISTS counters (
  name VARCHAR(64) PRIMARY KEY,
  value BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO counters (name, value) VALUES ('kpir_seq', 0)
  ON DUPLICATE KEY UPDATE value = value;

-- Wpisy KPiR. `id` = klientowy UUID (rezerwacja przy tworzeniu). Kwoty w groszach.
-- `rev` = optymistyczna kontrola wersji; `server_seq` = kursor delty; `deleted` = tombstone.
CREATE TABLE IF NOT EXISTS kpir_entries (
  id CHAR(36) PRIMARY KEY,
  ksiega_id CHAR(36) NOT NULL,
  year INT NOT NULL,
  data_zdarzenia DATE NOT NULL,
  nr_ksef VARCHAR(255) NULL,
  nr_dowodu VARCHAR(255) NULL,
  kontrahent_id VARCHAR(64) NULL,
  kontrahent_nazwa VARCHAR(255) NULL,
  kontrahent_adres VARCHAR(255) NULL,
  opis TEXT NULL,
  przychod_sprzedaz BIGINT NOT NULL DEFAULT 0,
  przychod_pozostaly BIGINT NOT NULL DEFAULT 0,
  zakup_towarow BIGINT NOT NULL DEFAULT 0,
  koszty_uboczne BIGINT NOT NULL DEFAULT 0,
  wynagrodzenia BIGINT NOT NULL DEFAULT 0,
  pozostale_wydatki BIGINT NOT NULL DEFAULT 0,
  wolna TEXT NULL,
  koszty_br BIGINT NOT NULL DEFAULT 0,
  uwagi TEXT NULL,
  document_id BIGINT NULL,
  extras TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  rev BIGINT NOT NULL,
  deleted TINYINT NOT NULL DEFAULT 0,
  server_seq BIGINT NOT NULL,
  FOREIGN KEY (ksiega_id) REFERENCES ksiega(id),
  INDEX idx_ksiega_seq (ksiega_id, server_seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
