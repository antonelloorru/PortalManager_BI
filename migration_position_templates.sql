-- ════════════════════════════════════════════════════════════════════════════
--  certV 2.4 — migration_position_templates.sql
--  Templates per scheda posizione + storicizzazione master text
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. NUOVI CAMPI SU job_positions ─────────────────────────────────────────
ALTER TABLE `job_positions`
  ADD COLUMN `presentation_text`    TEXT DEFAULT NULL COMMENT 'Presentazione azienda (master, modificabile)',
  ADD COLUMN `gender_disclaimer`    TEXT DEFAULT NULL COMMENT 'Riferimenti di genere (master, modificabile)',
  ADD COLUMN `offer_info`           TEXT DEFAULT NULL COMMENT 'Informazioni offerta lavoro (highlight in preview)',
  ADD COLUMN `hard_skills`          TEXT DEFAULT NULL COMMENT 'Hard skills (libero/da template)',
  ADD COLUMN `soft_skills`          TEXT DEFAULT NULL COMMENT 'Soft skills (libero/da template)',
  ADD COLUMN `we_offer`             TEXT DEFAULT NULL COMMENT 'Cosa offriamo (libero/da template)',
  ADD COLUMN `master_version_id`    INT DEFAULT NULL COMMENT 'Versione master text usata';

-- ── 2. TEMPLATE TESTI MASTER (presentazione, disclaimer genere) ─────────────
CREATE TABLE IF NOT EXISTS `position_master_texts` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `text_type`    ENUM('presentation','gender_disclaimer') NOT NULL,
  `version`      INT NOT NULL DEFAULT 1,
  `is_current`   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=versione attuale, 0=storico',
  `content`      TEXT NOT NULL,
  `notes`        VARCHAR(255) DEFAULT NULL COMMENT 'Note sulla versione',
  `created_by`   INT DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `superseded_at` DATETIME DEFAULT NULL,
  `superseded_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pmt_type_current` (`text_type`,`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Storico versionato testi master per posizioni';

-- ── 3. TEMPLATE RIUTILIZZABILI (hard/soft skills, we offer, offer info) ─────
CREATE TABLE IF NOT EXISTS `position_templates` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `template_type` ENUM('hard_skills','soft_skills','we_offer','offer_info','description','nice_to_have') NOT NULL,
  `name`         VARCHAR(150) NOT NULL COMMENT 'Nome breve template (es. "Sviluppatore Backend Senior")',
  `content`      TEXT NOT NULL,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `usage_count`  INT NOT NULL DEFAULT 0 COMMENT 'Quante volte è stato usato',
  `created_by`   INT DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_ptpl_type` (`template_type`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Template riutilizzabili per sezioni scheda posizione';

-- ── 4. SOFT DELETE candidates ───────────────────────────────────────────────
ALTER TABLE `candidates`
  ADD COLUMN `deleted_at` DATETIME DEFAULT NULL COMMENT 'Soft delete timestamp',
  ADD COLUMN `deleted_by` INT DEFAULT NULL COMMENT 'FK → users.id';

-- ── 5. MASTER TEXTS DEFAULT ─────────────────────────────────────────────────
INSERT INTO `position_master_texts` (`text_type`,`version`,`is_current`,`content`,`notes`,`created_by`) VALUES
('presentation', 1, 1,
 'Siamo un''azienda italiana specializzata nei servizi IT con presenza consolidata sul territorio nazionale. Ci occupiamo di consulenza tecnologica, sistemi informativi e servizi gestiti per clienti enterprise di vari settori. Il nostro team è composto da professionisti qualificati che lavorano in un ambiente dinamico orientato all''innovazione e alla crescita professionale.',
 'Versione iniziale presentazione azienda', 1),
('gender_disclaimer', 1, 1,
 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91), nel rispetto dei principi di pari opportunità e non discriminazione. I dati personali saranno trattati ai sensi del Regolamento UE 679/2016 (GDPR) per finalità di selezione del personale.',
 'Disclaimer GDPR e parità di genere', 1);

-- ── 6. TEMPLATES DI ESEMPIO ─────────────────────────────────────────────────
INSERT INTO `position_templates` (`template_type`,`name`,`content`,`created_by`) VALUES
('hard_skills', 'Sviluppatore Web Senior',
 '• Conoscenza approfondita di HTML5, CSS3, JavaScript ES6+\n• Esperienza con framework moderni (React, Vue.js o Angular)\n• Familiarità con backend Node.js, PHP o Python\n• Database relazionali (MySQL, PostgreSQL) e NoSQL (MongoDB)\n• Strumenti di versioning (Git/GitHub)\n• Esperienza con metodologie Agile/Scrum', 1),
('hard_skills', 'System Administrator',
 '• Amministrazione Windows Server e Linux (CentOS/RHEL/Ubuntu)\n• Active Directory, GPO, DNS, DHCP\n• Virtualizzazione (VMware vSphere, Hyper-V)\n• Backup e disaster recovery (Veeam, Acronis)\n• Networking (TCP/IP, VLAN, firewall)\n• Scripting Bash/PowerShell\n• Conoscenza ITIL framework', 1),
('soft_skills', 'Standard team player',
 '• Capacità di lavoro in team\n• Problem solving e pensiero analitico\n• Buone capacità comunicative\n• Orientamento al risultato\n• Proattività e autonomia\n• Capacità di gestione del tempo e delle priorità', 1),
('soft_skills', 'Profilo Senior/Leadership',
 '• Leadership e capacità di gestione team\n• Mentoring di colleghi junior\n• Capacità di prendere decisioni in autonomia\n• Eccellenti doti comunicative anche con stakeholder C-level\n• Gestione di progetti complessi multi-team\n• Vision strategica', 1),
('we_offer', 'Pacchetto standard CCNL Metalmeccanico',
 '• Contratto a tempo indeterminato CCNL Metalmeccanico\n• Inquadramento e RAL commisurati all''esperienza\n• Smart working ibrido (3 giorni in sede / 2 da remoto)\n• Ticket restaurant\n• Formazione continua e certificazioni professionali a carico azienda\n• Piano welfare aziendale\n• Ambiente di lavoro dinamico e in crescita', 1),
('we_offer', 'Pacchetto Senior con MBO',
 '• Contratto a tempo indeterminato\n• Pacchetto retributivo competitivo con MBO annuale\n• Smart working full flessibile\n• Auto aziendale o car allowance\n• Welfare e fringe benefit\n• Percorso di crescita professionale strutturato\n• Formazione su tecnologie all''avanguardia', 1),
('offer_info', 'Standard offerta IT',
 '📍 Sede di lavoro: da definire in base al candidato\n💼 Tipo contratto: Indeterminato CCNL Metalmeccanico\n💰 RAL: commisurata all''esperienza\n🏠 Smart working: ibrido\n⏰ Orario: full-time, dal lunedì al venerdì\n🎯 Inizio collaborazione: immediato', 1);
