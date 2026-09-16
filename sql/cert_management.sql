-- ════════════════════════════════════════════════════════════════════════════
--  certV 2.4 — cert_management_v2.4.sql
--  Script COMPLETO di creazione database
--  31 tabelle + dati di default + ruoli + permessi + impostazioni SMTP
--
--  USO:
--  1. Aprire phpMyAdmin → SQL
--  2. Incollare ed eseguire TUTTO questo file
--  3. Il database cert_management verrà creato automaticamente
--
--  NOTA: Se il database esiste già, usare upgrade_to_v2.4.sql
-- ════════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `cert_management`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cert_management`;

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Versione del server: 10.4.32-MariaDB
-- Versione PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cert_manager`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `agencies`
--

CREATE TABLE `agencies` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `type` enum('Headhunting','Somministrazione','RPO','Misto') DEFAULT 'Misto',
  `website` varchar(255) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `vat_number` varchar(30) DEFAULT NULL,
  `status` enum('active','paused','blacklisted') NOT NULL DEFAULT 'active',
  `rating` tinyint(1) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `agencies`
--

INSERT INTO `agencies` (`id`, `name`, `type`, `website`, `email`, `phone`, `address`, `vat_number`, `status`, `rating`, `notes`, `created_at`) VALUES
(1, 'Adecco', 'Misto', NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, '2026-03-30 08:47:12'),
(2, 'Michael Page', 'Misto', NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, '2026-03-30 08:47:33'),
(3, 'DRD Recruiting', 'Misto', NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, '2026-03-30 08:48:47'),
(4, 'Etjca', 'Misto', NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, '2026-03-30 08:49:28'),
(5, 'MAW', 'Misto', NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, '2026-03-30 08:49:38');

-- --------------------------------------------------------

--
-- Struttura della tabella `agency_contacts`
--

CREATE TABLE `agency_contacts` (
  `id` int(11) NOT NULL,
  `agency_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `agency_contracts`
--

CREATE TABLE `agency_contracts` (
  `id` int(11) NOT NULL,
  `agency_id` int(11) NOT NULL,
  `contract_ref` varchar(50) DEFAULT NULL,
  `type` enum('Quadro','Puntuale','Somministrazione') DEFAULT 'Quadro',
  `fee_percent` decimal(5,2) DEFAULT NULL,
  `fee_hourly` decimal(8,2) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `auto_renewal` tinyint(1) NOT NULL DEFAULT 0,
  `notice_days` int(11) DEFAULT 30,
  `document_path` varchar(255) DEFAULT NULL,
  `status` enum('active','expired','terminated') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `app_logs`
--

CREATE TABLE `app_logs` (
  `id` int(11) NOT NULL,
  `category` varchar(50) NOT NULL,
  `level` enum('info','success','warning','error') NOT NULL DEFAULT 'info',
  `message` text NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'FK → users.id (chi ha eseguito l''azione)',
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`) or `context` is null),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `app_logs`
--

INSERT INTO `app_logs` (`id`, `category`, `level`, `message`, `user_id`, `context`, `ip_address`, `created_at`) VALUES
(1, 'Auth', 'warning', 'Login fallito: admin@certv.local', NULL, NULL, '192.168.230.1', '2026-03-28 21:00:09'),
(2, 'Auth', 'warning', 'Login fallito: admin@certv.local', NULL, NULL, '192.168.230.1', '2026-03-28 21:01:17'),
(3, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-03-29 18:21:37'),
(4, 'Auth', 'success', 'Login', 1, NULL, '::1', '2026-03-29 18:29:46'),
(5, 'Permissions', 'success', 'Permessi ruolo #2 aggiornati', 1, NULL, '192.168.230.1', '2026-03-29 20:21:51'),
(6, 'Permissions', 'success', 'Permessi ruolo #2 aggiornati', 1, NULL, '192.168.230.1', '2026-03-29 20:25:42'),
(7, 'Permissions', 'success', 'Permessi ruolo #6 aggiornati', 1, NULL, '192.168.230.1', '2026-03-29 20:26:19'),
(8, 'Settings', 'success', 'Impostazioni aggiornate', 1, NULL, '192.168.230.1', '2026-03-29 20:29:59'),
(9, 'Companies', 'success', 'Rinominata azienda principale id=1', 1, NULL, '192.168.230.1', '2026-03-29 20:49:02'),
(10, 'Companies', 'success', 'Rinominata azienda principale id=1', 1, NULL, '192.168.230.1', '2026-03-29 20:49:36'),
(11, 'Companies', 'success', 'Nuova azienda: Mips Informatica', 1, NULL, '192.168.230.1', '2026-03-29 20:56:08'),
(12, 'Companies', 'success', 'Nuova azienda: Antea srl', 1, NULL, '192.168.230.1', '2026-03-29 20:57:57'),
(13, 'Settings', 'success', 'Impostazioni aggiornate', 1, NULL, '192.168.230.1', '2026-03-29 21:11:00'),
(14, 'Employees', 'success', 'Nuovo dipendente #2', 1, NULL, '192.168.230.1', '2026-03-29 21:15:19'),
(15, 'Permissions', 'success', 'Permessi ruolo #2 aggiornati', 1, NULL, '192.168.230.1', '2026-03-29 21:24:36'),
(16, 'Employees', 'success', 'Modifica dipendente #2', 1, NULL, '192.168.230.1', '2026-03-29 21:25:35'),
(17, 'Permissions', 'success', 'Permessi ruolo #2 aggiornati', 1, NULL, '192.168.230.1', '2026-03-30 04:46:39'),
(18, 'Recruiting', 'success', 'Candidato aggiunto id=1', 1, NULL, '192.168.230.1', '2026-03-30 07:42:49'),
(19, 'Agencies', 'success', 'Agenzia salvata id=0', 1, NULL, '192.168.230.1', '2026-03-30 08:47:12'),
(20, 'Agencies', 'success', 'Agenzia salvata id=0', 1, NULL, '192.168.230.1', '2026-03-30 08:47:33'),
(21, 'Agencies', 'success', 'Agenzia salvata id=0', 1, NULL, '192.168.230.1', '2026-03-30 08:48:47'),
(22, 'Agencies', 'success', 'Agenzia salvata id=0', 1, NULL, '192.168.230.1', '2026-03-30 08:49:28'),
(23, 'Agencies', 'success', 'Agenzia salvata id=0', 1, NULL, '192.168.230.1', '2026-03-30 08:49:38'),
(24, 'Recruiting', 'success', 'Candidato aggiunto id=2', 1, NULL, '192.168.230.1', '2026-03-30 08:50:38'),
(25, 'Import', 'success', 'Import brand: ins=39 upd=0', 1, NULL, '192.168.230.1', '2026-03-30 15:21:47'),
(26, 'Import', 'success', 'Import brand: ins=39 upd=0', 1, NULL, '192.168.230.1', '2026-03-30 15:27:12'),
(27, 'Import', 'success', 'Import dipendenti: ins=0 upd=0', 1, NULL, '192.168.230.1', '2026-03-31 07:30:05'),
(28, 'Brand', 'success', 'Referente assegnato: user=2 brand=44 ruolo=referente_formazione', 1, NULL, '192.168.230.1', '2026-03-31 07:43:51'),
(29, 'Brand', 'success', 'Referente assegnato: user=2 brand=45 ruolo=referente_formazione', 1, NULL, '192.168.230.1', '2026-03-31 07:44:02'),
(30, 'Brand', 'success', 'Referente assegnato: user=2 brand=46 ruolo=referente_formazione', 1, NULL, '192.168.230.1', '2026-03-31 07:44:29'),
(31, 'Brand', 'success', 'Referente assegnato: user=2 brand=47 ruolo=referente_formazione', 1, NULL, '192.168.230.1', '2026-03-31 07:44:48'),
(32, 'Employees', 'success', 'Nuovo dipendente #3', 1, NULL, '192.168.230.1', '2026-03-31 07:46:44'),
(33, 'Employees', 'success', 'Modifica dipendente #3', 1, NULL, '192.168.230.1', '2026-03-31 07:47:23'),
(34, 'Employees', 'success', 'Nuovo dipendente #4', 1, NULL, '192.168.230.1', '2026-03-31 07:48:41'),
(35, 'Companies', 'success', 'Rinominata azienda principale id=1', 1, NULL, '192.168.230.1', '2026-03-31 07:50:39'),
(36, 'Employees', 'success', 'Modifica dipendente #2', 1, NULL, '192.168.230.1', '2026-03-31 13:54:11'),
(37, 'Import', 'success', 'Import dipendenti (sep=\',\'): ins=0 upd=0', 1, NULL, '192.168.230.1', '2026-03-31 22:07:02'),
(38, 'Auth', 'warning', 'Login fallito: admin@servicedesk.local', NULL, NULL, '192.168.230.1', '2026-04-01 16:28:55'),
(39, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-01 16:29:01'),
(40, 'Auth', 'warning', 'Login fallito: admin@certv.local', NULL, NULL, '::1', '2026-04-02 10:28:47'),
(41, 'Migration', 'success', 'schema_check_upgrade.php: applicato aggiornamento v2.2', NULL, NULL, '::1', '2026-04-02 10:30:07'),
(42, 'Auth', 'warning', 'Login fallito: admin@certv.local', NULL, NULL, '::1', '2026-04-02 10:30:39'),
(43, 'Auth', 'success', 'Login', 1, NULL, '::1', '2026-04-02 10:30:46');

-- --------------------------------------------------------

--
-- Struttura della tabella `app_settings`
--

CREATE TABLE `app_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(500) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `app_settings`
--

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `description`) VALUES
('agency_contract_alert_days', '60', 'Alert scadenza contratti agenzie'),
('app_name', 'Portale Integrato Governance, Competenze & Recruiting', 'Nome applicazione'),
('app_version', '2.4', 'Versione build'),
('company_apply_url', '', 'URL candidatura esterna (es. https://careers.azienda.it)'),
('company_website', '', 'URL sito web azienda (usato nei post)'),
('compliance_critical_pct', '60', 'Soglia compliance rossa (%)'),
('compliance_warning_pct', '80', 'Soglia compliance gialla (%)'),
('employee_code_prefix', 'EMP-', 'Prefisso matricola dipendenti'),
('linkedin_access_token', '', 'OAuth2 Access Token (generato via publish_posizione.php)'),
('linkedin_client_id', '', 'LinkedIn App Client ID (da developer.linkedin.com)'),
('linkedin_client_secret', '', 'LinkedIn App Client Secret'),
('linkedin_company_id', '', 'LinkedIn Company ID (numerico, da URL pagina azienda)'),
('mail_from', 'certv@example.com', 'Email mittente notifiche'),
('mail_from_name', 'certV System', 'Nome mittente notifiche'),
('notify_days_1', '90', '1° alert scadenza cert'),
('notify_days_2', '60', '2° alert scadenza cert'),
('notify_days_3', '30', '3° alert scadenza cert'),
('notify_days_4', '7', 'Alert critico scadenza cert'),
('primary_color', '#0ea5e9', 'Colore primario UI'),
('smtp_auth', '1', 'Richiede autenticazione (1=si, 0=no)'),
('smtp_debug', '0', 'Log debug SMTP nel log di sistema (1=si)'),
('smtp_enabled', '0', 'Abilita invio email via SMTP (1=si, 0=no/fallback mail())'),
('smtp_encryption', 'tls', 'Crittografia: tls (STARTTLS), ssl (implicita), none'),
('smtp_host', '', 'Server SMTP (es. smtp.gmail.com, smtp.office365.com)'),
('smtp_pass', '', 'Password SMTP o App Password'),
('smtp_port', '587', 'Porta SMTP (25=plain, 465=SSL, 587=STARTTLS)'),
('smtp_test_email', '', 'Indirizzo per il test di invio'),
('smtp_timeout', '15', 'Timeout connessione in secondi'),
('smtp_user', '', 'Username SMTP (solitamente l\'email completa)'),
('smtp_verified', '0', 'Ultimo test SMTP riuscito (timestamp o 0)');

-- --------------------------------------------------------

--
-- Struttura della tabella `brands`
--

CREATE TABLE `brands` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `partnership_level` varchar(50) DEFAULT 'Registered',
  `req_company` int(11) DEFAULT 0,
  `req_commercial` int(11) DEFAULT 0,
  `req_technical` int(11) DEFAULT 0,
  `pam_name` varchar(100) DEFAULT NULL,
  `pam_email` varchar(100) DEFAULT NULL,
  `pam_phone` varchar(20) DEFAULT NULL,
  `pam_phone2` varchar(20) DEFAULT NULL,
  `internal_bm_name` varchar(100) DEFAULT NULL,
  `internal_bm_email` varchar(100) DEFAULT NULL,
  `internal_bm_phone` varchar(20) DEFAULT NULL,
  `brand_sl_name` varchar(100) DEFAULT NULL,
  `brand_sl_email` varchar(100) DEFAULT NULL,
  `brand_sl_phone` varchar(20) DEFAULT NULL,
  `internal_sl_name` varchar(100) DEFAULT NULL,
  `internal_sl_email` varchar(100) DEFAULT NULL,
  `internal_sl_phone` varchar(20) DEFAULT NULL,
  `learning_link` text DEFAULT NULL,
  `tech_doc_link` text DEFAULT NULL,
  `partner_portal_link` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `priority` tinyint(1) NOT NULL DEFAULT 3 COMMENT 'Priorità/importanza 1 (max) - 5 (min)',
  `priority_color` varchar(7) NOT NULL DEFAULT '#3b82f6' COMMENT 'Colore HEX per codifica visiva priorità'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `brands`
--

INSERT INTO `brands` (`id`, `name`, `description`, `logo_path`, `partnership_level`, `req_company`, `req_commercial`, `req_technical`, `pam_name`, `pam_email`, `pam_phone`, `pam_phone2`, `internal_bm_name`, `internal_bm_email`, `internal_bm_phone`, `brand_sl_name`, `brand_sl_email`, `brand_sl_phone`, `internal_sl_name`, `internal_sl_email`, `internal_sl_phone`, `learning_link`, `tech_doc_link`, `partner_portal_link`, `created_at`, `priority`, `priority_color`) VALUES
(44, '3CX', 'ALLNET   - 90gg', NULL, 'SILVER', 0, 0, 0, 'LORIS SARETTA', 'ls@3cx.com', '596280001', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(45, 'ASUS', 'ESPRINET', NULL, 'SILVER', 0, 0, 0, 'DAVIDE VITULLI', 'davide_vitulli@asus.com', '3355991600', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(46, 'AXIS', 'ZELIATECH', NULL, 'GOLD', 0, 0, 0, 'PIERANGELO BERTINO', 'pierangelo.bertino@axis.com', '3458791006', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(47, 'CISCO', 'TD SYNNEX - INGRAM', NULL, 'PREMIER', 0, 0, 0, 'DESIA PIERVENANZI', 'mdesiapi@cisco.com', '3351882604', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(48, 'COHESITY', 'TD SYNNEX', NULL, 'PREFERRED', 0, 0, 0, 'MATTEO GHIELMI', 'matteo.ghielmi@cohesity.com', '3357803552', NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(49, 'COMMVAULT', 'ICOS', NULL, 'AUTHORIZED', 0, 0, 0, 'MASSIMO MOLES', 'mmoles@commvault.com', '3490722946', NULL, 'PRUNAI GUIA', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(50, 'CROWDSTRIKE', 'WESTCON', NULL, 'AUTHORIZED', 0, 0, 0, NULL, NULL, NULL, NULL, 'GENOVESI UMBERTO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(51, 'CYBEROO', 'ICOS', NULL, 'AUTHORIZED', 0, 0, 0, 'MASSIMILIANO BOSCO', 'massimiliano.bosco@cyberoo.com', '3475281136', NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(52, 'DATALOGIC', 'JARLTECH', NULL, 'DIAMOND', 0, 0, 0, 'DANIEL D\'ACCARDI', 'daniel.daccardi@datalogic.com', '3442047183', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(53, 'DELL', 'DIRETTO', NULL, 'TITANIUM', 0, 0, 0, 'ANGELO BAGNARDI', 'angelo_bagnardi@dell.com', '3409210950', NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(54, 'EIZO', 'DIRETTO', NULL, 'AUTHORIZED', 0, 0, 0, 'ROBERTO FALSINI', 'roberto.falsini@eizo.com', '3393716752', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(55, 'EPSON', 'ESPRINET', NULL, 'PLATINUM', 0, 0, 0, 'MARIANGELA CRISPO', 'mariangela_crispo@epson.it', '3483153159', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(56, 'FORTINET', 'EXCLUSIVE', NULL, 'EXPERT', 0, 0, 0, 'ANDREA COZZOLINO', 'acozzolino@fortinet.com', '3351311500', NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(57, 'H3C', 'ALLNET', NULL, '', 0, 0, 0, 'CARLO RANAGLIA', 'gw.carloranaglia@h3c.com', '3338124107', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(58, 'HONEYWELL', 'JARLTECH / BLUESTAR / DACOM', NULL, 'BUSINESS PARTNER', 0, 0, 0, 'BARBARA MELLONI', 'barbara.melloni@honeywell.com', '3427422550', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(59, 'HP ARUBA', 'V VALLEY - INGRAM', NULL, 'BUSINESS PARTNER', 0, 0, 0, 'CETTI CANARILE', 'concetta.canarile@hpe.com', '3481413453', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(60, 'HP INC', 'ESPRINET', NULL, 'SINERGY', 0, 0, 0, 'STEFANIA MASINA', 'stefania.masina@hp.com', '3483572189', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(61, 'HPE', 'V VALLEY - INGRAM', NULL, 'GOLD', 0, 0, 0, 'MATTEO TONELLI', 'tonelli@hpe.com', '339312 627', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(62, 'LENOVO', 'ESPRINET - COMETA', NULL, 'AUTHORIZED / GOLD', 0, 0, 0, 'ALESSANDRO LEPORE', 'alepore@lenovo.com', '3455766528', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(63, 'MICROSOFT', 'V VALLEY', NULL, 'SOLUTION PARTNER', 0, 0, 0, NULL, NULL, NULL, NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(64, 'MSI', 'ESPRINET', NULL, 'GOLD', 0, 0, 0, 'LUIGI BRUNI', 'luigibruni@msi.com', '3495019950', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(65, 'NETAPP', 'ICOS', NULL, 'PREFERRED', 0, 0, 0, 'Gabriella Montecchi', 'gabriella.montecchi@netapp.com', NULL, NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(66, 'OMNISSA', 'TD SYNNEX', NULL, 'PLATINUM', 0, 0, 0, 'Giuseppe Miglietta', 'gmiglietta@omnissa.com', '+34 669 32 51 98', NULL, 'PRUNAI GUIA', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(67, 'PALO ALTO', 'WESTCON', NULL, '', 0, 0, 0, NULL, NULL, NULL, NULL, 'PRUNAI GUIA', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(68, 'PURE STORAGE', 'ESPRINET / ARROW', NULL, 'AUTHORIZED', 0, 0, 0, 'ROBERTA GIULIANO', 'rgiuliano@purestorage.com', '?3357431146?', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(69, 'QUMULO', 'ARROW', NULL, 'AUTHORIZED', 0, 0, 0, 'MARCO BERGOGLIO', 'mborgoglio@qumulo.com', '342 764 3329', NULL, 'GUERCINI ALBERTO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(70, 'RAKUTEN', NULL, NULL, 'REGISTERED', 0, 0, 0, 'RAUL CARUBELLI', 'raul.carubelli@rakuten.com', '+34 699 75 54 31', NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(71, 'SAMSUNG', 'ESPRINET', NULL, 'REGISTERED', 0, 0, 0, 'GIANMARCO TOTERA', 'g.totera@samsung.com', '3476131275', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(72, 'SCHNEIDER', 'ZELIATECH', NULL, 'PREMIER', 0, 0, 0, 'LUCA SCODELLINI', 'luca.scodellini@se.com', '117089100', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(73, 'SOPHOS', 'V VALLEY', NULL, 'SILVER', 0, 0, 0, 'Vladyslava Melenevska', 'vladyslava.melenevska@sophos.it', '3356206368', NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(74, 'SOTI', 'DIRETTO', NULL, 'GOLD', 0, 0, 0, 'FABIO CONSGLI', 'fabio.consigli@soti.net', '366 4576551', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(75, 'SPLUNK', 'ARROW', NULL, 'ASSOCIATE', 0, 0, 0, 'Marilena Grippo', 'mgrippo@splunk.com', '335 8478987', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(76, 'TOSHIBA', 'DIRETTO', NULL, 'PREMIUM', 0, 0, 0, 'EDOARDO GUERRIERO', 'edoardo.guerriero@toshibatec-tiis.com', '3285895919', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(77, 'TREND MICRO', 'V VALLEY', NULL, 'EXPERT', 0, 0, 0, 'BORIS ZAMBELLI', 'boris_zambelli@trendmicro.com', '3481516569', NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(78, 'VEEAM', 'V VALLEY', NULL, 'GOLD', 0, 0, 0, 'RICCARDO SIMIONI', 'riccardo.simioni@veeam.com', '3442323530', NULL, 'MOTTA JONATAN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(79, 'VERTIV', 'ZELIATECH - INGRAM', NULL, 'GOLD', 0, 0, 0, 'ELENA VERDERIO', 'elena.verderio@vertiv.com', '366 5618856', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(80, 'VMWARE BROADCOM', 'TD SYNNEX', NULL, 'PREMIER', 0, 0, 0, 'BARBARA SCALICH', 'barbara.scalich@broadcom.com', '3351797098', NULL, 'PRUNAI GUIA', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(81, 'VOIPVOICE', 'ALLNET', NULL, 'SUPREME', 0, 0, 0, 'UMBERTO SANTINI', 'umberto.santini@voipvoice.it', '550935400', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(82, 'ZEBRA', 'JARLTECH / BLUESTAR / DACOM', NULL, 'PREMIUM', 0, 0, 0, 'SIMONE CUMUZZO', 'simone.comuzzo@zebra.com', '3357320410', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6');

-- --------------------------------------------------------

--
-- Struttura della tabella `brand_contacts_history`
--

CREATE TABLE `brand_contacts_history` (
  `id` int(11) NOT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `archived_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`archived_data`)),
  `archived_by` int(11) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `brand_contacts_history`
--

INSERT INTO `brand_contacts_history` (`id`, `brand_id`, `archived_data`, `archived_by`, `archived_at`) VALUES
(1, NULL, '{\"type\":\"permission_change\",\"role_id\":2,\"previous\":[\"brand_referents.php\",\"brand.php\",\"config_notifiche.php\",\"gap_analysis.php\",\"manage_companies.php\",\"manage_employees.php\",\"manage_work_modes.php\",\"manager_users.php\",\"mass_upload.php\",\"programmazione.php\",\"recruiting_agenzie.php\",\"recruiting_candidati.php\",\"recruiting_contratti.php\",\"recruiting_posizioni.php\",\"report_certificazioni.php\",\"settings.php\",\"training_plans.php\",\"upload_certificato.php\",\"visualizza_storico.php\"]}', 1, '2026-03-29 20:21:51'),
(2, NULL, '{\"type\":\"permission_change\",\"role_id\":2,\"previous\":[\"programmazione.php\",\"recruiting_agenzie.php\",\"recruiting_candidati.php\",\"recruiting_contratti.php\",\"recruiting_posizioni.php\",\"training_plans.php\",\"upload_certificato.php\",\"visualizza_storico.php\"]}', 1, '2026-03-29 20:25:42'),
(3, NULL, '{\"type\":\"permission_change\",\"role_id\":6,\"previous\":[\"programmazione.php\",\"training_plans.php\",\"upload_certificato.php\"]}', 1, '2026-03-29 20:26:19'),
(4, NULL, '{\"type\":\"permission_change\",\"role_id\":2,\"previous\":[\"programmazione.php\",\"recruiting_agenzie.php\",\"recruiting_candidati.php\",\"recruiting_contratti.php\",\"recruiting_posizioni.php\",\"training_plans.php\",\"upload_certificato.php\",\"visualizza_storico.php\"]}', 1, '2026-03-29 21:24:36'),
(5, NULL, '{\"type\":\"permission_change\",\"role_id\":2,\"previous\":[\"manage_companies.php\",\"manage_work_modes.php\",\"programmazione.php\",\"recruiting_agenzie.php\",\"recruiting_candidati.php\",\"recruiting_contratti.php\",\"recruiting_posizioni.php\",\"settings.php\",\"training_plans.php\",\"upload_certificato.php\",\"visualizza_storico.php\"]}', 1, '2026-03-30 04:46:39');

-- --------------------------------------------------------

--
-- Struttura della tabella `brand_referents`
--

CREATE TABLE `brand_referents` (
  `id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'FK → employees.id (ex user_id)',
  `role_type` enum('brand_manager','account_commerciale','referente_formazione','tecnico') NOT NULL DEFAULT 'brand_manager',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `brand_referents`
--

INSERT INTO `brand_referents` (`id`, `brand_id`, `employee_id`, `role_type`, `start_date`, `end_date`, `notes`, `created_at`) VALUES
(1, 44, 2, 'referente_formazione', '2026-03-31', NULL, NULL, '2026-03-31 07:43:51'),
(2, 45, 2, 'referente_formazione', '2026-03-31', NULL, NULL, '2026-03-31 07:44:02'),
(3, 46, 2, 'referente_formazione', '2026-03-31', NULL, 'Tecnica', '2026-03-31 07:44:29'),
(4, 47, 2, 'referente_formazione', '2026-03-31', NULL, 'Tecnica', '2026-03-31 07:44:48');

-- --------------------------------------------------------

--
-- Struttura della tabella `brand_requirements_history`
--

CREATE TABLE `brand_requirements_history` (
  `id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `partnership_level` varchar(50) DEFAULT NULL,
  `req_company` int(11) DEFAULT 0,
  `req_commercial` int(11) DEFAULT 0,
  `req_technical` int(11) DEFAULT 0,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `brand_technologies`
--

CREATE TABLE `brand_technologies` (
  `id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL COMMENT 'FK → brands.id',
  `category` enum('Tecnologia','Servizio','Prodotto') NOT NULL DEFAULT 'Tecnologia' COMMENT 'Tipo: Tecnologia, Servizio o Prodotto',
  `name` varchar(150) NOT NULL COMMENT 'Nome tecnologia/servizio/prodotto',
  `description` text DEFAULT NULL COMMENT 'Descrizione dettagliata',
  `version` varchar(50) DEFAULT NULL COMMENT 'Versione corrente',
  `status` enum('active','deprecated','eol') NOT NULL DEFAULT 'active' COMMENT 'Stato: attiva, deprecata, end-of-life',
  `doc_url` varchar(500) DEFAULT NULL COMMENT 'Link documentazione ufficiale',
  `relevance` tinyint(1) NOT NULL DEFAULT 3 COMMENT 'Rilevanza per l''azienda 1 (alta) - 5 (bassa)',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tecnologie, servizi e prodotti associati a ciascun brand';

-- --------------------------------------------------------

--
-- Struttura della tabella `candidates`
--

CREATE TABLE `candidates` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `linkedin_url` varchar(255) DEFAULT NULL,
  `ral_requested` decimal(10,2) DEFAULT NULL,
  `notice_period` varchar(50) DEFAULT NULL,
  `cv_path` varchar(255) DEFAULT NULL,
  `skills_tags` text DEFAULT NULL,
  `source` enum('Agenzia','LinkedIn','Referral','Portale','Altro') DEFAULT 'Altro',
  `agency_id` int(11) DEFAULT NULL,
  `agency_contact_id` int(11) DEFAULT NULL,
  `gdpr_consent` tinyint(1) NOT NULL DEFAULT 0,
  `gdpr_date` date DEFAULT NULL,
  `gdpr_expiry` date DEFAULT NULL,
  `status` enum('new','in_pipeline','on_hold','hired','rejected','withdrawn') DEFAULT 'new',
  `notes` text DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `education_level` varchar(80) DEFAULT NULL COMMENT 'Titolo di studio',
  `education_field` varchar(150) DEFAULT NULL COMMENT 'Indirizzo / facoltà',
  `education_institute` varchar(200) DEFAULT NULL COMMENT 'Istituto / Università',
  `education_year` varchar(10) DEFAULT NULL COMMENT 'Anno conseguimento titolo',
  `external_certs` text DEFAULT NULL COMMENT 'JSON: certificazioni dichiarate dal candidato',
  `test_path` varchar(255) DEFAULT NULL COMMENT 'Test psicologico',
  `lettera_path` varchar(255) DEFAULT NULL COMMENT 'Lettera di presentazione',
  `doc_extra_path` varchar(255) DEFAULT NULL COMMENT 'Documento aggiuntivo',
  `soft_skills_notes` text DEFAULT NULL COMMENT 'Note su soft skills / carattere'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `candidates`
--

INSERT INTO `candidates` (`id`, `first_name`, `last_name`, `email`, `phone`, `linkedin_url`, `ral_requested`, `notice_period`, `cv_path`, `skills_tags`, `source`, `agency_id`, `agency_contact_id`, `gdpr_consent`, `gdpr_date`, `gdpr_expiry`, `status`, `notes`, `added_by`, `created_at`, `education_level`, `education_field`, `education_institute`, `education_year`, `external_certs`, `test_path`, `lettera_path`, `doc_extra_path`, `soft_skills_notes`) VALUES
(1, 'ANTONELLO', 'ORRU', 'antonello.orru@gmail.com', '3477365191', NULL, 100000.00, '90 giorni', NULL, NULL, 'LinkedIn', NULL, NULL, 1, '2026-03-30', '2027-03-30', 'new', NULL, 1, '2026-03-30 07:42:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'ANTONELLO', 'ORRU', 'antonello.orru@gmail.com', '3477365191', NULL, 100000.00, '90 giorni', NULL, 'tutto', 'LinkedIn', NULL, NULL, 1, '2026-03-30', '2027-03-30', 'new', NULL, 1, '2026-03-30 08:50:38', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `candidate_applications`
--

CREATE TABLE `candidate_applications` (
  `id` int(11) NOT NULL,
  `candidate_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `stage` enum('cv_received','screening','tech_test','hr_interview','tech_interview','offer_sent','hired','rejected') NOT NULL DEFAULT 'cv_received',
  `match_score` tinyint(3) UNSIGNED DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `stage_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `certifications`
--

CREATE TABLE `certifications` (
  `id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `technology_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `category` enum('aziendale','commerciale','tecnica') DEFAULT 'tecnica',
  `level` enum('Foundation','Associate','Professional','Expert','Specialty') DEFAULT NULL,
  `validity_months` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `exam_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `legal_representative` varchar(150) DEFAULT NULL,
  `vat_number` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `companies`
--

INSERT INTO `companies` (`id`, `name`, `legal_representative`, `vat_number`, `created_at`) VALUES
(1, 'WETECH\'S SPA SB', 'ALESSANDRO TURCHI', '05174160480', '2026-03-28 20:59:36'),
(2, 'Mips Informatica', 'Luca Marini', '03311300101', '2026-03-29 20:56:08'),
(3, 'Antea srl', NULL, '01222470427', '2026-03-29 20:57:57');

-- --------------------------------------------------------

--
-- Struttura della tabella `company_locations`
--

CREATE TABLE `company_locations` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `location_name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `lat` decimal(10,8) DEFAULT NULL,
  `lng` decimal(11,8) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `email_pec` varchar(150) DEFAULT NULL,
  `manager_site` varchar(150) DEFAULT NULL,
  `manager_it` varchar(150) DEFAULT NULL,
  `manager_service` varchar(150) DEFAULT NULL,
  `manager_admin` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `company_locations`
--

INSERT INTO `company_locations` (`id`, `company_id`, `location_name`, `address`, `lat`, `lng`, `phone`, `email`, `email_pec`, `manager_site`, `manager_it`, `manager_service`, `manager_admin`) VALUES
(1, 1, 'Sede Montevarchi (SEDE LEGALE)', 'Via Fratelli Alinari 76/82  Montevarchi (AR)', NULL, NULL, '055 9850197', 'info@wetechs.it', NULL, NULL, 'DANIELE CAPELLETTI', 'ANTONELLO ORRU\'', NULL),
(2, 1, 'Sede Milano', 'Strada 1 – Palazzo F1 Milanofiori – Assago (MI)', NULL, NULL, '02 89366777', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 1, 'Sede Scandicci', 'Via del Lavoro, 10/37 Scandicci (FI)', NULL, NULL, '0574 1747613', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 1, 'Sede Calenzano', 'Via S. Morese, 34 Calenzano (FI)', NULL, NULL, '055 8825207', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 1, 'Sede Roma', 'Viale Luigi Schiavonetti, 270 Roma', NULL, NULL, '06 83941147', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 1, 'Sede Falconara Marittima', 'Via dell’industria 16 Falconara Marittima (AN)', NULL, NULL, '0719188753', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 1, 'Sede Genova', 'Via Lanfranconi 33 (rosso) Genova', NULL, NULL, '010 290011', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 2, 'Sede Principale', 'Via Lanfranconi 33 (rosso) - 16121 Genova (adiacenze Piazza della Vittoria)', NULL, NULL, '010.2900.11', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 3, 'Sede Principale', 'VIA DELL\'INDUSTRIA 16 - 60015 - FALCONARA MARITTIMA (AN)', NULL, NULL, '071 918 8753', NULL, 'anteatcs@legalmail.it', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `email_log`
--

CREATE TABLE `email_log` (
  `id` int(11) NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(500) NOT NULL,
  `status` enum('sent','failed','queued') NOT NULL DEFAULT 'sent',
  `error_msg` text DEFAULT NULL,
  `smtp_response` text DEFAULT NULL,
  `module` varchar(50) DEFAULT 'system',
  `related_id` int(11) DEFAULT NULL COMMENT 'ID record collegato (opzionale)',
  `sent_by` int(11) DEFAULT NULL COMMENT 'FK → users.id (NULL = cron/system)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log di tutti gli invii email SMTP';

-- --------------------------------------------------------

--
-- Struttura della tabella `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT 1,
  `location_id` int(11) DEFAULT NULL,
  `work_mode_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `fiscal_code` varchar(20) DEFAULT NULL COMMENT 'Codice fiscale',
  `date_of_birth` date DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `personal_email` varchar(150) DEFAULT NULL COMMENT 'Email personale (non di sistema)',
  `employee_code` varchar(30) DEFAULT NULL COMMENT 'Matricola interna',
  `job_title` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `contract_type` enum('Indeterminato','Determinato','Somministrazione','Consulenza','Stage','Partita IVA') DEFAULT 'Indeterminato',
  `hire_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive','terminated') DEFAULT 'active',
  `bio` text DEFAULT NULL,
  `technical_skills` text DEFAULT NULL COMMENT 'Tag separati da virgola',
  `soft_skills` text DEFAULT NULL COMMENT 'Tag separati da virgola',
  `cv_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Note HR riservate — non visibili al dipendente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Anagrafica dipendenti — separata dalle utenze di accesso (v2.2)';

--
-- Dump dei dati per la tabella `employees`
--

INSERT INTO `employees` (`id`, `company_id`, `location_id`, `work_mode_id`, `first_name`, `last_name`, `fiscal_code`, `date_of_birth`, `phone`, `personal_email`, `employee_code`, `job_title`, `department`, `contract_type`, `hire_date`, `end_date`, `status`, `bio`, `technical_skills`, `soft_skills`, `cv_path`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, NULL, 'Super', 'Admin', NULL, NULL, NULL, NULL, NULL, 'System Administrator', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-03-28 20:59:36', '2026-03-28 20:59:36'),
(2, 1, 3, 3, 'ANTONELLO', 'ORRU\'', 'RRONNL75A22B354C', NULL, '3477465191', 'antonello.orru@gmail.com', '330', 'Quadro', 'IT', 'Indeterminato', '2025-08-01', NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-03-29 21:15:19', '2026-03-31 13:54:11'),
(3, 1, 1, 3, 'ALESSANDRO', 'MACINAI', NULL, NULL, NULL, 'alessandro.macinai@wetechs.it', NULL, 'Responsabile Coordinatore Commerciale', 'Commerciale', 'Partita IVA', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-03-31 07:46:44', '2026-03-31 07:47:23'),
(4, 1, 1, 1, 'DAMINANO', 'FOSSATI', NULL, NULL, NULL, 'damiano.fossati@wetechs.it', NULL, 'Responsabile Acquisti / Procurament', 'Acquisti / Procurament', 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-03-31 07:48:41', '2026-03-31 07:48:41');

-- --------------------------------------------------------

--
-- Struttura della tabella `employee_brands`
--

CREATE TABLE `employee_brands` (
  `employee_id` int(11) NOT NULL COMMENT 'FK → employees.id (ex user_brands)',
  `brand_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `interview_scorecards`
--

CREATE TABLE `interview_scorecards` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `interviewer_id` int(11) NOT NULL COMMENT 'FK → users.id (chi ha fatto il colloquio)',
  `interview_date` date NOT NULL,
  `type` enum('HR','Tecnico','Finale') NOT NULL DEFAULT 'Tecnico',
  `hs_label_1` varchar(100) DEFAULT 'Competenza 1',
  `hs_score_1` tinyint(1) DEFAULT 0,
  `hs_note_1` text DEFAULT NULL,
  `hs_label_2` varchar(100) DEFAULT 'Competenza 2',
  `hs_score_2` tinyint(1) DEFAULT 0,
  `hs_note_2` text DEFAULT NULL,
  `hs_label_3` varchar(100) DEFAULT 'Competenza 3',
  `hs_score_3` tinyint(1) DEFAULT 0,
  `hs_note_3` text DEFAULT NULL,
  `hs_label_4` varchar(100) DEFAULT 'Competenza 4',
  `hs_score_4` tinyint(1) DEFAULT 0,
  `hs_note_4` text DEFAULT NULL,
  `ss_score_problem_solving` tinyint(1) DEFAULT 0,
  `ss_score_communication` tinyint(1) DEFAULT 0,
  `ss_score_teamwork` tinyint(1) DEFAULT 0,
  `ss_score_learning_agility` tinyint(1) DEFAULT 0,
  `total_score` decimal(4,2) DEFAULT NULL,
  `hs_avg` decimal(4,2) DEFAULT NULL,
  `ss_avg` decimal(4,2) DEFAULT NULL,
  `recommendation` enum('proceed','hold','reject') DEFAULT NULL,
  `summary_note` text DEFAULT NULL,
  `sent_to_hr` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `job_positions`
--

CREATE TABLE `job_positions` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `approved_by` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `team_leader_id` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `status` enum('draft','open','paused','closed','cancelled') NOT NULL DEFAULT 'draft',
  `priority` enum('Bassa','Media','Alta','Urgente') NOT NULL DEFAULT 'Media',
  `description` text DEFAULT NULL,
  `required_skills` text DEFAULT NULL,
  `nice_to_have` text DEFAULT NULL,
  `ral_min` decimal(10,2) DEFAULT NULL,
  `ral_max` decimal(10,2) DEFAULT NULL,
  `contract_type` enum('Indeterminato','Determinato','Somministrazione','Consulenza','Stage') DEFAULT 'Indeterminato',
  `location` varchar(100) DEFAULT NULL,
  `remote_policy` enum('In sede','Ibrido','Full Remote') DEFAULT 'Ibrido',
  `target_date` date DEFAULT NULL,
  `opened_at` date DEFAULT NULL,
  `closed_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `job_positions`
--

INSERT INTO `job_positions` (`id`, `title`, `department`, `brand_id`, `requested_by`, `approved_by`, `team_leader_id`, `status`, `priority`, `description`, `required_skills`, `nice_to_have`, `ral_min`, `ral_max`, `contract_type`, `location`, `remote_policy`, `target_date`, `opened_at`, `closed_at`, `created_at`) VALUES
(1, 'Tecnico di presidio', 'it', NULL, 1, NULL, NULL, 'draft', 'Alta', 'bjsadhbvjsdhbkjdh', ',zjdhfkjashd.kayusygfa', NULL, NULL, NULL, 'Indeterminato', 'da cliente', 'In sede', '2026-04-01', '2026-03-30', NULL, '2026-03-30 07:44:20'),
(2, 'System Administrator', 'IT-autostrade', NULL, 1, NULL, NULL, 'draft', 'Alta', NULL, NULL, NULL, NULL, NULL, 'Indeterminato', 'Calenzano', 'Ibrido', '2026-04-01', '2026-03-30', NULL, '2026-03-30 08:46:47');

-- --------------------------------------------------------

--
-- Struttura della tabella `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'FK → users.id (destinatario account)',
  `role_id` int(11) DEFAULT NULL,
  `type` enum('info','warning','critical','success') NOT NULL DEFAULT 'info',
  `module` enum('brand','asset','recruiting','system') NOT NULL DEFAULT 'system',
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `escalation_level` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `planned_exams`
--

CREATE TABLE `planned_exams` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'FK → employees.id (ex user_id)',
  `certification_id` int(11) NOT NULL,
  `planned_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('planned','completed','cancelled') DEFAULT 'planned',
  `result` enum('passed','failed') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `position_publications`
--

CREATE TABLE `position_publications` (
  `id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL COMMENT 'FK → job_positions.id',
  `channel` enum('linkedin','indeed','infojobs','glassdoor','monster','jobrapido','custom') NOT NULL DEFAULT 'linkedin',
  `channel_url` varchar(500) DEFAULT NULL COMMENT 'URL del post/annuncio pubblicato',
  `status` enum('draft','published','expired','removed') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `api_post_id` varchar(255) DEFAULT NULL COMMENT 'ID restituito dall API (LinkedIn jobPostingId ecc.)',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Traccia le pubblicazioni delle posizioni sui portali esterni';

-- --------------------------------------------------------

--
-- Struttura della tabella `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Super Admin', 'Accesso totale al sistema'),
(2, 'HR Director', 'Supervisione ciclo talento, contratti, budget'),
(3, 'Brand Manager', 'Governance brand, requisiti vendor, gap analysis'),
(4, 'Team Leader', 'Gestione team, valutazione candidati, scorecard'),
(5, 'Recruiter', 'Operativo selezione, agenzie, candidature'),
(6, 'Dipendente', 'Self-service: profilo, piano formativo, upload attestati');

-- --------------------------------------------------------

--
-- Struttura della tabella `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `page_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `page_name`) VALUES
(1, 'manage_employees.php'),
(1, 'publish_posizione.php'),
(1, 'smtp_settings.php'),
(2, 'brand_technologies.php'),
(2, 'candidato_profilo.php'),
(2, 'manage_companies.php'),
(2, 'manage_employees.php'),
(2, 'manage_work_modes.php'),
(2, 'programmazione.php'),
(2, 'publish_posizione.php'),
(2, 'recruiting_agenzie.php'),
(2, 'recruiting_candidati.php'),
(2, 'recruiting_contratti.php'),
(2, 'recruiting_posizioni.php'),
(2, 'settings.php'),
(2, 'training_plans.php'),
(2, 'upload_certificato.php'),
(2, 'visualizza_storico.php'),
(3, 'brand_referents.php'),
(3, 'brand_technologies.php'),
(3, 'brand.php'),
(3, 'candidato_profilo.php'),
(3, 'gap_analysis.php'),
(3, 'programmazione.php'),
(3, 'recruiting_posizioni.php'),
(3, 'report_certificazioni.php'),
(3, 'training_plans.php'),
(3, 'upload_certificato.php'),
(3, 'visualizza_storico.php'),
(4, 'brand.php'),
(4, 'candidato_profilo.php'),
(4, 'gap_analysis.php'),
(4, 'programmazione.php'),
(4, 'recruiting_candidati.php'),
(4, 'recruiting_posizioni.php'),
(4, 'report_certificazioni.php'),
(4, 'training_plans.php'),
(4, 'upload_certificato.php'),
(4, 'visualizza_storico.php'),
(5, 'brand.php'),
(5, 'candidato_profilo.php'),
(5, 'publish_posizione.php'),
(5, 'recruiting_agenzie.php'),
(5, 'recruiting_candidati.php'),
(5, 'recruiting_posizioni.php'),
(6, 'programmazione.php'),
(6, 'report_certificazioni.php'),
(6, 'training_plans.php'),
(6, 'upload_certificato.php'),
(6, 'visualizza_storico.php'),
-- v2.4: permessi mancanti aggiunti
(2, 'brand.php'),
(2, 'brand_referents.php'),
(2, 'gap_analysis.php'),
(2, 'report_certificazioni.php'),
(2, 'manager_users.php'),
(2, 'mass_upload.php'),
(2, 'config_notifiche.php'),
(4, 'brand_technologies.php'),
(5, 'brand_technologies.php'),
(5, 'report_certificazioni.php'),
(5, 'training_plans.php'),
(5, 'visualizza_storico.php');

-- --------------------------------------------------------

--
-- Struttura della tabella `technologies`
--

CREATE TABLE `technologies` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `technologies`
--

INSERT INTO `technologies` (`id`, `name`, `description`) VALUES
(1, 'Cloud & Infrastructure', NULL),
(2, 'Networking', NULL),
(3, 'Security', NULL),
(4, 'Data & AI', NULL),
(5, 'DevOps', NULL),
(6, 'Soft Skills', NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `training_plans`
--

CREATE TABLE `training_plans` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'FK → employees.id (ex user_id)',
  `certification_id` int(11) NOT NULL,
  `target_date` date DEFAULT NULL,
  `planned_exam_date` date DEFAULT NULL,
  `status` enum('planned','in_progress','completed','cancelled') DEFAULT 'planned',
  `priority` enum('Bassa','Media','Alta') DEFAULT 'Media',
  `notes` text DEFAULT NULL,
  `budget` decimal(8,2) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `is_renewal` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL COMMENT 'FK → employees.id. NULL = account di servizio senza dipendente associato',
  `role_id` int(11) NOT NULL DEFAULT 6,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(150) DEFAULT NULL COMMENT 'Usato solo se employee_id è NULL (account di servizio)',
  `status` enum('active','inactive') DEFAULT 'active',
  `notifications_email` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Utenze di accesso al portale — separate dall''anagrafica (v2.2)';

--
-- Dump dei dati per la tabella `users`
--

INSERT INTO `users` (`id`, `employee_id`, `role_id`, `email`, `password_hash`, `display_name`, `status`, `notifications_email`, `created_at`) VALUES
(1, 1, 1, 'admin@certv.local', '$2y$12$TJA55kedgGR.P.qGN7PcZepkhc7sxKYgPklaj3DSeJmfSt9fE3niC', NULL, 'active', 1, '2026-03-28 20:59:37');

-- --------------------------------------------------------

--
-- Struttura della tabella `user_certifications`
--

CREATE TABLE `user_certifications` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'FK → employees.id (ex user_id in v2.1)',
  `certification_id` int(11) NOT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('active','expiring','expired','revoked') DEFAULT 'active',
  `score` int(11) DEFAULT NULL,
  `certificate_code` varchar(100) DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL COMMENT 'FK → users.id (chi ha caricato)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `work_modes`
--

CREATE TABLE `work_modes` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `color_hex` varchar(7) DEFAULT '#f1f5f9'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `work_modes`
--

INSERT INTO `work_modes` (`id`, `name`, `description`, `color_hex`) VALUES
(1, 'In sede', 'Presenza fisica in ufficio', '#d1fae5'),
(2, 'Smart Working', 'Lavoro da remoto', '#e0f2fe'),
(3, 'Ibrido', 'Mix sede e remoto', '#fef3c7'),
(4, 'Trasferta', 'In trasferta clienti', '#f3e8ff');

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `agencies`
--
ALTER TABLE `agencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indici per le tabelle `agency_contacts`
--
ALTER TABLE `agency_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agency_id` (`agency_id`);

--
-- Indici per le tabelle `agency_contracts`
--
ALTER TABLE `agency_contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agency_id` (`agency_id`);

--
-- Indici per le tabelle `app_logs`
--
ALTER TABLE `app_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `level` (`level`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indici per le tabelle `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indici per le tabelle `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_brands_priority` (`priority`);

--
-- Indici per le tabelle `brand_contacts_history`
--
ALTER TABLE `brand_contacts_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `brand_id` (`brand_id`),
  ADD KEY `archived_by` (`archived_by`);

--
-- Indici per le tabelle `brand_referents`
--
ALTER TABLE `brand_referents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `brand_id` (`brand_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indici per le tabelle `brand_requirements_history`
--
ALTER TABLE `brand_requirements_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `brand_id` (`brand_id`);

--
-- Indici per le tabelle `brand_technologies`
--
ALTER TABLE `brand_technologies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bt_brand` (`brand_id`),
  ADD KEY `idx_bt_category` (`category`);

--
-- Indici per le tabelle `candidates`
--
ALTER TABLE `candidates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agency_id` (`agency_id`);

--
-- Indici per le tabelle `candidate_applications`
--
ALTER TABLE `candidate_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_app` (`candidate_id`,`position_id`),
  ADD KEY `candidate_id` (`candidate_id`),
  ADD KEY `position_id` (`position_id`),
  ADD KEY `stage` (`stage`);

--
-- Indici per le tabelle `certifications`
--
ALTER TABLE `certifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `brand_id` (`brand_id`),
  ADD KEY `technology_id` (`technology_id`);

--
-- Indici per le tabelle `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indici per le tabelle `company_locations`
--
ALTER TABLE `company_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indici per le tabelle `email_log`
--
ALTER TABLE `email_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_log_status` (`status`),
  ADD KEY `idx_email_log_date` (`created_at`);

--
-- Indici per le tabelle `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_employee_code` (`employee_code`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `location_id` (`location_id`),
  ADD KEY `work_mode_id` (`work_mode_id`),
  ADD KEY `status` (`status`);

--
-- Indici per le tabelle `employee_brands`
--
ALTER TABLE `employee_brands`
  ADD PRIMARY KEY (`employee_id`,`brand_id`),
  ADD KEY `eb_fk2` (`brand_id`);

--
-- Indici per le tabelle `interview_scorecards`
--
ALTER TABLE `interview_scorecards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_scorecard` (`application_id`,`interviewer_id`),
  ADD KEY `interviewer_id` (`interviewer_id`);

--
-- Indici per le tabelle `job_positions`
--
ALTER TABLE `job_positions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `brand_id` (`brand_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `team_leader_id` (`team_leader_id`),
  ADD KEY `status` (`status`);

--
-- Indici per le tabelle `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `is_read` (`is_read`);

--
-- Indici per le tabelle `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `token` (`token`);

--
-- Indici per le tabelle `planned_exams`
--
ALTER TABLE `planned_exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `certification_id` (`certification_id`);

--
-- Indici per le tabelle `position_publications`
--
ALTER TABLE `position_publications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `position_id` (`position_id`),
  ADD KEY `channel` (`channel`),
  ADD KEY `status` (`status`),
  ADD KEY `pp_fk2` (`published_by`);

--
-- Indici per le tabelle `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indici per le tabelle `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`page_name`);

--
-- Indici per le tabelle `technologies`
--
ALTER TABLE `technologies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indici per le tabelle `training_plans`
--
ALTER TABLE `training_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `certification_id` (`certification_id`);

--
-- Indici per le tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `role_id` (`role_id`);

--
-- Indici per le tabelle `user_certifications`
--
ALTER TABLE `user_certifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `certification_id` (`certification_id`),
  ADD KEY `status` (`status`),
  ADD KEY `expiry_date` (`expiry_date`);

--
-- Indici per le tabelle `work_modes`
--
ALTER TABLE `work_modes`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `agencies`
--
ALTER TABLE `agencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT per la tabella `agency_contacts`
--
ALTER TABLE `agency_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `agency_contracts`
--
ALTER TABLE `agency_contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `app_logs`
--
ALTER TABLE `app_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT per la tabella `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT per la tabella `brand_contacts_history`
--
ALTER TABLE `brand_contacts_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT per la tabella `brand_referents`
--
ALTER TABLE `brand_referents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT per la tabella `brand_requirements_history`
--
ALTER TABLE `brand_requirements_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `brand_technologies`
--
ALTER TABLE `brand_technologies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT per la tabella `candidate_applications`
--
ALTER TABLE `candidate_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `certifications`
--
ALTER TABLE `certifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT per la tabella `company_locations`
--
ALTER TABLE `company_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT per la tabella `email_log`
--
ALTER TABLE `email_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT per la tabella `interview_scorecards`
--
ALTER TABLE `interview_scorecards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `job_positions`
--
ALTER TABLE `job_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT per la tabella `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `planned_exams`
--
ALTER TABLE `planned_exams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `position_publications`
--
ALTER TABLE `position_publications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT per la tabella `technologies`
--
ALTER TABLE `technologies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT per la tabella `training_plans`
--
ALTER TABLE `training_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT per la tabella `user_certifications`
--
ALTER TABLE `user_certifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `work_modes`
--
ALTER TABLE `work_modes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `agency_contacts`
--
ALTER TABLE `agency_contacts`
  ADD CONSTRAINT `ac_fk1` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `agency_contracts`
--
ALTER TABLE `agency_contracts`
  ADD CONSTRAINT `acn_fk1` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `app_logs`
--
ALTER TABLE `app_logs`
  ADD CONSTRAINT `log_fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `brand_contacts_history`
--
ALTER TABLE `brand_contacts_history`
  ADD CONSTRAINT `bch_fk_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bch_fk_user` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `brand_referents`
--
ALTER TABLE `brand_referents`
  ADD CONSTRAINT `bref_fk1` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bref_fk_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `brand_requirements_history`
--
ALTER TABLE `brand_requirements_history`
  ADD CONSTRAINT `brh_fk1` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `brand_technologies`
--
ALTER TABLE `brand_technologies`
  ADD CONSTRAINT `fk_bt_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `candidates`
--
ALTER TABLE `candidates`
  ADD CONSTRAINT `cand_fk1` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `candidate_applications`
--
ALTER TABLE `candidate_applications`
  ADD CONSTRAINT `app_fk1` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `app_fk2` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `certifications`
--
ALTER TABLE `certifications`
  ADD CONSTRAINT `c_fk1` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `c_fk2` FOREIGN KEY (`technology_id`) REFERENCES `technologies` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `company_locations`
--
ALTER TABLE `company_locations`
  ADD CONSTRAINT `cl_fk1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `emp_fk_co` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `emp_fk_loc` FOREIGN KEY (`location_id`) REFERENCES `company_locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `emp_fk_wm` FOREIGN KEY (`work_mode_id`) REFERENCES `work_modes` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `employee_brands`
--
ALTER TABLE `employee_brands`
  ADD CONSTRAINT `eb_fk_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `eb_fk_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `interview_scorecards`
--
ALTER TABLE `interview_scorecards`
  ADD CONSTRAINT `sc_fk1` FOREIGN KEY (`application_id`) REFERENCES `candidate_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sc_fk2` FOREIGN KEY (`interviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `job_positions`
--
ALTER TABLE `job_positions`
  ADD CONSTRAINT `jp_fk1` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `jp_fk2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `jp_fk3` FOREIGN KEY (`team_leader_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notif_fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `planned_exams`
--
ALTER TABLE `planned_exams`
  ADD CONSTRAINT `pe_fk2` FOREIGN KEY (`certification_id`) REFERENCES `certifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pe_fk_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `position_publications`
--
ALTER TABLE `position_publications`
  ADD CONSTRAINT `pp_fk1` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pp_fk2` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `rp_fk1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `training_plans`
--
ALTER TABLE `training_plans`
  ADD CONSTRAINT `tp_fk2` FOREIGN KEY (`certification_id`) REFERENCES `certifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tp_fk_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `u_fk_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `u_fk_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Limiti per la tabella `user_certifications`
--
ALTER TABLE `user_certifications`
  ADD CONSTRAINT `uc_fk2` FOREIGN KEY (`certification_id`) REFERENCES `certifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `uc_fk_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
