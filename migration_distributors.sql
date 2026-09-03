-- ════════════════════════════════════════════════════════════════════════════
--  certV 2.4 — migration_distributors.sql
--  Tabelle Distributori con relazione N:M verso brands e ranking Primario/Secondario
--  Sicuro da eseguire più volte (IF NOT EXISTS / INSERT IGNORE)
-- ════════════════════════════════════════════════════════════════════════════

-- ══ 1. ANAGRAFICA DISTRIBUTORI ═══════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `distributors` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(200) NOT NULL       COMMENT 'Ragione sociale distributore',
  `type`              ENUM('Distributore','VAD','Rivenditore','Aggregatore') NOT NULL DEFAULT 'Distributore'
                      COMMENT 'Tipo: Distributore, Value Added Distributor, Rivenditore, Aggregatore',
  `website`           VARCHAR(255) DEFAULT NULL,
  `address`           VARCHAR(300) DEFAULT NULL,
  `city`              VARCHAR(100) DEFAULT NULL,
  `province`          VARCHAR(5)   DEFAULT NULL,
  `vat_number`        VARCHAR(30)  DEFAULT NULL    COMMENT 'P.IVA / VAT',
  `status`            ENUM('active','paused','inactive') NOT NULL DEFAULT 'active',

  -- Contatto Commerciale (generico del distributore)
  `commercial_name`   VARCHAR(150) DEFAULT NULL    COMMENT 'Referente commerciale principale',
  `commercial_email`  VARCHAR(150) DEFAULT NULL,
  `commercial_phone`  VARCHAR(30)  DEFAULT NULL,

  -- Contatto Academy / Formazione
  `academy_name`      VARCHAR(150) DEFAULT NULL    COMMENT 'Referente academy/formazione',
  `academy_email`     VARCHAR(150) DEFAULT NULL,
  `academy_phone`     VARCHAR(30)  DEFAULT NULL,

  `notes`             TEXT DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_distributor_name` (`name`),
  KEY `idx_dist_status` (`status`),
  KEY `idx_dist_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anagrafica distributori/VAD/rivenditori';

-- ══ 2. RELAZIONE N:M BRAND ↔ DISTRIBUTORE CON RANKING ═══════════════════════

CREATE TABLE IF NOT EXISTS `brand_distributors` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `brand_id`          INT NOT NULL                 COMMENT 'FK → brands.id',
  `distributor_id`    INT NOT NULL                 COMMENT 'FK → distributors.id',

  -- Ranking: Primario (priorità alta) vs Secondario
  `ranking`           ENUM('primary','secondary') NOT NULL DEFAULT 'primary'
                      COMMENT 'Primario = distributore di riferimento, Secondario = alternativo',
  `priority_order`    TINYINT NOT NULL DEFAULT 1
                      COMMENT 'Ordine all''interno dello stesso ranking (1 = primo)',

  -- Modello operativo partnership (profilazione multi-attributo)
  `is_volume`         TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Acquisto a Volume (grandi quantità, pricing aggressivo)',
  `is_value`          TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Acquisto a Valore (soluzioni, servizi professionali, margine)',
  `is_academy`        TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Academy (formazione, voucher esami, laboratori)',

  -- Contatti SPECIFICI per questo brand (sovrascrivono quelli generici del distributore)
  `commercial_ref`    VARCHAR(150) DEFAULT NULL    COMMENT 'Referente commerciale per questo brand',
  `commercial_email`  VARCHAR(150) DEFAULT NULL,
  `commercial_phone`  VARCHAR(30)  DEFAULT NULL,

  `academy_ref`       VARCHAR(150) DEFAULT NULL    COMMENT 'Referente academy per questo brand',
  `academy_email`     VARCHAR(150) DEFAULT NULL,
  `academy_phone`     VARCHAR(30)  DEFAULT NULL,

  `contract_ref`      VARCHAR(100) DEFAULT NULL    COMMENT 'Riferimento contratto/accordo quadro',
  `discount_pct`      DECIMAL(5,2) DEFAULT NULL    COMMENT 'Sconto % negoziato su questo brand',
  `notes`             TEXT DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand_dist` (`brand_id`, `distributor_id`),
  KEY `idx_bd_brand` (`brand_id`),
  KEY `idx_bd_dist` (`distributor_id`),
  KEY `idx_bd_ranking` (`ranking`),
  KEY `idx_bd_order` (`brand_id`, `ranking`, `priority_order`),
  CONSTRAINT `fk_bd_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bd_dist` FOREIGN KEY (`distributor_id`) REFERENCES `distributors`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Relazione N:M Brand↔Distributore con ranking Primario/Secondario';

-- ══ 3. PERMESSI ══════════════════════════════════════════════════════════════

INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
  (2, 'brand_distributors.php'),
  (3, 'brand_distributors.php'),
  (4, 'brand_distributors.php');

-- ══ 4. VERIFICA ══════════════════════════════════════════════════════════════

SHOW TABLES LIKE 'distributors';
SHOW TABLES LIKE 'brand_distributors';
