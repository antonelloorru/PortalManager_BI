-- v1.9.58 — Tabella staging import Pratix
-- Idempotente: CREATE TABLE IF NOT EXISTS, INSERT IGNORE

CREATE TABLE IF NOT EXISTS `cm_pratix_import` (
  `id`                     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `order_code`             VARCHAR(64)      NOT NULL,
  `cliente_effettivo`      VARCHAR(255)     DEFAULT NULL,
  `cliente_fatturazione`   VARCHAR(255)     DEFAULT NULL,
  `progetto`               VARCHAR(255)     DEFAULT NULL,
  `descrizione_pratix`     TEXT             DEFAULT NULL,
  `stato_pratix`           VARCHAR(100)     DEFAULT NULL,
  `azienda`                VARCHAR(255)     DEFAULT NULL,
  `numero_documento`       VARCHAR(150)     DEFAULT NULL,
  `tipologia`              VARCHAR(100)     DEFAULT NULL,
  `totale`                 DECIMAL(15,2)    DEFAULT NULL,
  `firma_commerciale`      VARCHAR(255)     DEFAULT NULL,
  `firma_tecnica`          VARCHAR(255)     DEFAULT NULL,
  `linea_di_business`      VARCHAR(255)     DEFAULT NULL,
  `anno_solare`            SMALLINT UNSIGNED DEFAULT NULL,
  `stato_contratto`        VARCHAR(100)     DEFAULT NULL,
  `imported_at`            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `imported_by`            INT UNSIGNED     DEFAULT NULL,
  `import_batch_id`        INT UNSIGNED     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_code` (`order_code`),
  KEY `idx_imported_at` (`imported_at`),
  KEY `idx_anno` (`anno_solare`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggiunge colonna import_batch_id se la tabella cm_import_batches esiste
-- (nessuna FK per compatibilità MariaDB 10.4)
-- La colonna è già definita nella CREATE TABLE sopra; questo blocco è un reminder
-- che il collegamento logico avviene tramite import_batch_id → cm_import_batches.id

-- Registra il permesso per import_pratix.php
INSERT IGNORE INTO `permissions` (`name`, `label`, `description`, `module`)
VALUES ('view_import_pratix', 'Import Pratix', 'Importazione dati da file Excel Pratix', 'pratix');

-- Aggiunge role_permissions per ruolo 1 (admin) e 2 (superadmin)
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`)
VALUES
  (1, 'import_pratix.php', 1, 1, 1, 1, 1),
  (2, 'import_pratix.php', 1, 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE `can_view`=1, `can_create`=1;