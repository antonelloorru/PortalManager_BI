-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Creato il: Mag 15, 2026 alle 17:24
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
-- Database: `portal_manager`
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
(5, 'MAW', 'Misto', NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, '2026-03-30 08:49:38'),
(6, 'Randstad Digital', 'Misto', 'www.randstad.it', 'andrea.morandini@randstad.it', '3240021804', NULL, NULL, 'active', NULL, NULL, '2026-04-30 08:38:32');

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
(43, 'Auth', 'success', 'Login', 1, NULL, '::1', '2026-04-02 10:30:46'),
(44, 'System', 'success', 'Installazione completata tramite wizard', 1, NULL, '::1', '2026-04-07 07:26:23'),
(45, 'Auth', 'success', 'Login', 1, NULL, '::1', '2026-04-07 07:42:56'),
(46, 'SMTP', 'warning', 'Test connessione SMTP: Connessione a :587 fallita: [10061] Impossibile stabilire la connessione. Rifiuto persistente del computer di destinazione', 1, NULL, '::1', '2026-04-07 07:51:00'),
(47, 'SMTP', 'success', 'Configurazione SMTP aggiornata', 1, NULL, '::1', '2026-04-07 07:51:00'),
(48, 'SMTP', 'warning', 'Test connessione SMTP: Comando \'X0NAbCF3M3IwIzE5NzVf\' fallito. Risposta: 534-5.7.9 Application-specific password required. For more information, go to\r\n534 5.7.9  https://support.google.com/mail/?p=InvalidSecondFactor 5b1f17b1804b1-488bfc31418sm45922305e9.3 - gsmtp', 1, NULL, '::1', '2026-04-07 07:51:02'),
(49, 'SMTP', 'success', 'Configurazione SMTP aggiornata', 1, NULL, '::1', '2026-04-07 07:52:59'),
(50, 'SMTP', 'success', 'Test connessione SMTP: OK', 1, NULL, '::1', '2026-04-07 07:53:01'),
(51, 'Brand', 'success', 'Nuovo distributore: TD Synnex', 1, NULL, '::1', '2026-04-07 08:01:05'),
(52, 'Brand', 'success', 'Associazione brand #47 ↔ distributore #1 (primary)', 1, NULL, '::1', '2026-04-07 08:01:22'),
(53, 'Auth', 'success', 'Login', 1, NULL, '::1', '2026-04-07 08:27:01'),
(54, 'Brand', 'success', 'Associazione brand #47 ↔ distributore #1 (primary)', 1, NULL, '::1', '2026-04-07 08:37:35'),
(55, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-07 08:49:02'),
(56, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-07 09:16:25'),
(57, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-07 09:16:54'),
(58, 'Permissions', 'success', 'Permessi ruolo #5 aggiornati', 1, NULL, '192.168.230.1', '2026-04-07 09:19:50'),
(59, 'Permissions', 'success', 'Permessi ruolo #2 aggiornati', 1, NULL, '192.168.230.1', '2026-04-07 09:20:32'),
(60, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-07 09:21:22'),
(61, 'Settings', 'success', 'Impostazioni aggiornate', 1, NULL, '192.168.230.1', '2026-04-07 09:23:36'),
(62, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-07 12:44:50'),
(63, 'Import', 'success', 'Import dipendenti (sep=\',\'): ins=77 upd=1', 1, NULL, '192.168.230.1', '2026-04-07 14:36:21'),
(64, 'Employees', 'success', 'Modifica dipendente #66', 1, NULL, '192.168.230.1', '2026-04-07 15:00:20'),
(65, 'Employees', 'info', 'Stato dipendente #5 → terminated', 1, NULL, '192.168.230.1', '2026-04-07 15:01:05'),
(66, 'Employees', 'success', 'Modifica dipendente #2', 1, NULL, '192.168.230.1', '2026-04-07 15:02:55'),
(67, 'Employees', 'success', 'Nuovo dipendente #82', 1, NULL, '192.168.230.1', '2026-04-07 15:06:11'),
(68, 'Employees', 'success', 'Nuovo dipendente #83', 1, NULL, '192.168.230.1', '2026-04-07 15:07:01'),
(69, 'Employees', 'success', 'Nuovo dipendente #84', 1, NULL, '192.168.230.1', '2026-04-07 15:08:17'),
(70, 'Companies', 'success', 'Nuova azienda: Nis Group srl', 1, NULL, '192.168.230.1', '2026-04-08 07:10:28'),
(71, 'Employees', 'success', 'Nuovo dipendente #85', 1, NULL, '192.168.230.1', '2026-04-08 07:12:40'),
(72, 'Employees', 'success', 'Nuovo dipendente #86', 1, NULL, '192.168.230.1', '2026-04-08 07:13:49'),
(73, 'Brand', 'success', 'Nuovo brand creato', 1, NULL, '192.168.230.1', '2026-04-08 10:26:10'),
(74, 'Brand', 'success', 'Tecnologia brand: nuova', 1, NULL, '192.168.230.1', '2026-04-08 10:31:02'),
(75, 'Brand', 'success', 'Sincronizzazione referenti brand #83: 0 ruoli aggiornati', 1, NULL, '192.168.230.1', '2026-04-08 10:31:32'),
(76, 'Brand', 'success', 'Brand #83 aggiornato', 1, NULL, '192.168.230.1', '2026-04-09 09:29:47'),
(77, 'Brand', 'success', 'Brand #83 aggiornato', 1, NULL, '192.168.230.1', '2026-04-09 09:30:48'),
(78, 'Brand', 'success', 'Sincronizzazione referenti brand #83: 0 ruoli aggiornati', 1, NULL, '192.168.230.1', '2026-04-09 09:33:40'),
(79, 'Brand', 'success', 'Brand #83 aggiornato', 1, NULL, '192.168.230.1', '2026-04-09 09:33:47'),
(80, 'Brand', 'success', 'Brand #83 aggiornato', 1, NULL, '192.168.230.1', '2026-04-09 09:52:21'),
(81, 'Brand', 'success', 'Brand #83 aggiornato', 1, NULL, '192.168.230.1', '2026-04-09 10:12:57'),
(82, 'Permissions', 'success', 'Permessi ruolo #6 aggiornati', 1, NULL, '192.168.230.1', '2026-04-09 10:48:07'),
(83, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-09 11:04:22'),
(84, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-09 22:29:51'),
(85, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-09 22:30:02'),
(86, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-10 06:59:50'),
(87, 'Recruiting', 'success', 'Candidato aggiunto id=3', 1, NULL, '192.168.230.1', '2026-04-10 10:46:53'),
(88, 'Recruiting', 'success', 'Candidato aggiunto id=4', 1, NULL, '192.168.230.1', '2026-04-10 11:02:57'),
(89, 'Recruiting', 'success', 'Candidato aggiunto id=5', 1, NULL, '192.168.230.1', '2026-04-10 11:05:10'),
(90, 'Recruiting', 'success', 'Posizione #2 aggiornata', 1, NULL, '192.168.230.1', '2026-04-10 11:15:38'),
(91, 'Recruiting', 'success', 'Posizione #2 aggiornata', 1, NULL, '192.168.230.1', '2026-04-10 11:17:38'),
(92, 'Recruiting', 'success', 'Upload cv candidato #2', 1, NULL, '192.168.230.1', '2026-04-10 11:19:25'),
(93, 'Recruiting', 'success', 'Aggiornato candidato #2', 1, NULL, '192.168.230.1', '2026-04-10 11:20:38'),
(94, 'Recruiting', 'success', 'Aggiornato candidato #2', 1, NULL, '192.168.230.1', '2026-04-10 11:20:48'),
(95, 'Recruiting', 'success', 'Posizione #1 eliminata', 1, NULL, '192.168.230.1', '2026-04-10 14:49:31'),
(96, 'Recruiting', 'success', 'Posizione #2 aggiornata', 1, NULL, '192.168.230.1', '2026-04-10 16:13:59'),
(97, 'Recruiting', 'success', 'Posizione #2 aggiornata', 1, NULL, '192.168.230.1', '2026-04-10 16:14:46'),
(98, 'Recruiting', 'warning', 'Candidato #5 soft-deleted', 1, NULL, '192.168.230.1', '2026-04-10 20:54:46'),
(99, 'Recruiting', 'warning', 'Candidato #4 soft-deleted', 1, NULL, '192.168.230.1', '2026-04-10 20:54:50'),
(100, 'Recruiting', 'warning', 'Candidato #3 soft-deleted', 1, NULL, '192.168.230.1', '2026-04-10 20:54:53'),
(101, 'Recruiting', 'warning', 'Candidato #2 soft-deleted', 1, NULL, '192.168.230.1', '2026-04-10 20:54:57'),
(102, 'Recruiting', 'warning', 'Candidato #1 soft-deleted', 1, NULL, '192.168.230.1', '2026-04-10 20:55:01'),
(103, 'Recruiting', 'success', 'Candidato aggiunto id=6 con candidatura su pos #2', 1, NULL, '192.168.230.1', '2026-04-10 20:55:46'),
(104, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-13 12:17:12'),
(105, 'Brand', 'success', 'Nuovo brand creato', 1, NULL, '192.168.230.1', '2026-04-13 12:39:38'),
(106, 'Import', 'success', 'Import catalogo: ins=31 upd=3 err=0 auto=2', 1, NULL, '192.168.230.1', '2026-04-13 13:56:48'),
(107, 'Certifications', 'success', 'Certificazione #32 creata', 1, NULL, '192.168.230.1', '2026-04-14 07:50:36'),
(108, 'Exams', 'success', 'Esame pianificato cert=32 emp=19', 1, NULL, '192.168.230.1', '2026-04-14 07:52:24'),
(109, 'Exams', 'success', 'Esame pianificato cert=17 emp=41', 1, NULL, '192.168.230.1', '2026-04-14 07:57:53'),
(110, 'Exams', 'success', 'Esame pianificato cert=17 emp=41', 1, NULL, '192.168.230.1', '2026-04-14 07:58:50'),
(111, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:06:08'),
(112, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:06:22'),
(113, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:06:26'),
(114, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:09:35'),
(115, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:10:04'),
(116, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:10:06'),
(117, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:15:59'),
(118, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:18:29'),
(119, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 08:20:17'),
(120, 'Exams', 'success', 'Modificato evento #3 tipo=formazione', 1, NULL, '192.168.230.1', '2026-04-14 08:29:56'),
(121, 'Certifications', 'success', 'Certificazione #33 creata', 1, NULL, '192.168.230.1', '2026-04-14 08:34:44'),
(122, 'Exams', 'success', 'Pianificato esame_certificazione cert=33 emp=49', 1, NULL, '192.168.230.1', '2026-04-14 08:35:21'),
(123, 'Exams', 'success', 'Pianificato esame_certificazione cert=33 emp=46', 1, NULL, '192.168.230.1', '2026-04-14 08:35:44'),
(124, 'Exams', 'success', 'Pianificato esame_certificazione cert=33 emp=69', 1, NULL, '192.168.230.1', '2026-04-14 08:36:04'),
(125, 'Users', 'success', 'Creazione account: damiano.fossati@wetechs.it', 1, NULL, '192.168.230.1', '2026-04-14 08:39:21'),
(126, 'Roles', 'success', 'Nuovo ruolo: Responsabile Brand (Pianificazione)', 1, NULL, '192.168.230.1', '2026-04-14 08:40:41'),
(127, 'Permissions', 'success', 'Permessi ruolo #7 aggiornati', 1, NULL, '192.168.230.1', '2026-04-14 08:41:29'),
(128, 'Users', 'success', 'Modifica account #2', 1, NULL, '192.168.230.1', '2026-04-14 08:41:56'),
(129, 'Users', 'success', 'Creazione account: antonello.orru@wetechs.it', 1, NULL, '192.168.230.1', '2026-04-14 08:42:39'),
(130, 'Auth', 'success', 'Login', 3, NULL, '192.168.230.1', '2026-04-14 10:18:17'),
(131, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-14 18:35:18'),
(132, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-18 13:03:45'),
(133, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-18 13:06:40'),
(134, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-20 10:50:24'),
(135, 'SMTP', 'error', 'Invio a 1 fallito: Comando \'RCPT TO:<1>\' fallito. Risposta: 550 5.5.0 <1> invalid address \'1\'', NULL, NULL, '192.168.230.1', '2026-04-20 10:51:56'),
(136, 'System', 'success', 'Update da v4.0 a v4.0: 3 aggiornati, 2 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-04-20 11:00:07'),
(137, 'Upgrade', 'success', 'Database aggiornato a v2.4', NULL, NULL, '192.168.230.1', '2026-04-20 11:01:00'),
(138, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-22 08:58:18'),
(139, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-23 08:02:36'),
(140, 'Auth', 'success', 'Login', 1, NULL, '192.168.230.1', '2026-04-23 15:45:04'),
(141, 'System', 'success', 'Update da v4.0 a v?: 6 aggiornati, 14 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-04-27 14:35:06'),
(142, 'Auth', 'success', 'Login', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-27 14:52:44'),
(143, 'Auth', 'success', 'Login', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-27 15:07:17'),
(144, 'Auth', 'success', 'Login', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-28 06:54:16'),
(145, 'Auth', 'success', 'Login (no 2FA)', 3, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-28 10:11:16'),
(146, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-28 18:50:59'),
(147, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-28 19:24:08'),
(148, '2FA-Admin', 'info', 'Email OTP autorizzato per utente 3', 1, NULL, '192.168.230.1', '2026-04-28 19:24:34'),
(149, 'Auth', 'info', 'Password OK, 2FA pending', 3, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-28 19:25:03'),
(150, 'Auth', 'info', '2FA email OTP inviato', 3, NULL, '192.168.230.1', '2026-04-28 19:25:07'),
(151, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-28 20:14:19'),
(152, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-28 20:50:53'),
(153, 'System', 'success', 'Update da v4.0 a v?: 1 aggiornati, 3 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-04-28 21:14:53'),
(154, 'System', 'success', 'Update da v4.0 a v?: 0 aggiornati, 1 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-04-28 21:20:47'),
(155, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-29 07:11:48'),
(156, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-29 07:11:48'),
(157, 'System', 'success', 'Update da v4.0 a v?: 0 aggiornati, 0 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-04-29 07:13:29'),
(158, 'Recruiting', 'info', 'Export XLSX posizioni: 1 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-29 07:17:27'),
(159, 'Recruiting', 'info', 'Export PDF posizioni: 1 record', 1, NULL, '192.168.230.1', '2026-04-29 07:18:53'),
(160, 'System', 'success', 'Update da v4.0 a v?: 6 aggiornati, 0 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-04-29 07:29:17'),
(161, 'Recruiting', 'success', 'Posizione #2 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 07:47:51'),
(162, 'Recruiting', 'success', 'Posizione #3 creata', 1, NULL, '192.168.230.1', '2026-04-29 07:55:42'),
(163, 'Recruiting', 'success', 'Posizione #3 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 07:56:48'),
(164, 'Recruiting', 'info', 'Export PDF posizioni: 2 record', 1, NULL, '192.168.230.1', '2026-04-29 07:57:06'),
(165, 'Recruiting', 'info', 'Export XLSX posizioni: 2 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-29 08:01:40'),
(166, 'Employees', 'success', 'Upload CV dipendente #2', 1, NULL, '192.168.230.1', '2026-04-29 08:22:44'),
(167, 'Recruiting', 'success', 'Posizione #4 creata', 1, NULL, '192.168.230.1', '2026-04-29 08:41:41'),
(168, 'Recruiting', 'success', 'Posizione #5 creata', 1, NULL, '192.168.230.1', '2026-04-29 08:54:13'),
(169, 'Recruiting', 'info', 'Export XLSX posizioni: 4 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-29 08:56:26'),
(170, 'SMTP', 'success', 'Test connessione SMTP: OK', 1, NULL, '192.168.230.1', '2026-04-29 08:58:34'),
(171, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-29 10:42:07'),
(172, 'SMTP', 'success', 'Configurazione SMTP aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 10:42:34'),
(173, 'SMTP', 'success', 'Test connessione SMTP: OK', 1, NULL, '192.168.230.1', '2026-04-29 10:42:39'),
(174, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-29 12:36:00'),
(175, 'System', 'success', 'Update da v4.0 a v?: 3 aggiornati, 5 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-04-29 12:36:31'),
(176, 'Recruiting', 'info', 'Export XLSX posizioni: 5 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-29 13:13:13'),
(177, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-29 14:01:04'),
(178, 'Auth', 'info', 'Logout', 1, NULL, '192.168.230.1', '2026-04-29 14:02:23'),
(179, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-29 14:02:55'),
(180, 'Recruiting', 'info', 'Cambio compenso posizione #4', 1, NULL, '192.168.230.1', '2026-04-29 14:11:45'),
(181, 'Recruiting', 'success', 'Posizione #4 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 14:11:45'),
(182, 'Recruiting', 'info', 'Export XLSX posizioni: 5 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-29 14:11:49'),
(183, 'Recruiting', 'info', 'Cambio compenso posizione #4', 1, NULL, '192.168.230.1', '2026-04-29 14:12:57'),
(184, 'Recruiting', 'success', 'Posizione #4 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 14:12:57'),
(185, 'Recruiting', 'info', 'Cambio status posizione #7:  → draft', 1, NULL, '192.168.230.1', '2026-04-29 14:35:53'),
(186, 'Recruiting', 'info', 'Cambio compenso posizione #7', 1, NULL, '192.168.230.1', '2026-04-29 14:35:53'),
(187, 'Recruiting', 'success', 'Posizione #7 creata', 1, NULL, '192.168.230.1', '2026-04-29 14:35:53'),
(188, 'Recruiting', 'info', 'Cambio compenso posizione #3', 1, NULL, '192.168.230.1', '2026-04-29 14:37:40'),
(189, 'Recruiting', 'success', 'Posizione #3 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 14:37:40'),
(190, 'Recruiting', 'info', 'Cambio compenso posizione #4', 1, NULL, '192.168.230.1', '2026-04-29 14:37:59'),
(191, 'Recruiting', 'success', 'Posizione #4 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 14:37:59'),
(192, 'Recruiting', 'info', 'Cambio compenso posizione #5', 1, NULL, '192.168.230.1', '2026-04-29 14:38:15'),
(193, 'Recruiting', 'success', 'Posizione #5 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 14:38:15'),
(194, 'Recruiting', 'info', 'Cambio compenso posizione #6', 1, NULL, '192.168.230.1', '2026-04-29 14:38:31'),
(195, 'Recruiting', 'success', 'Posizione #6 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 14:38:31'),
(196, 'Recruiting', 'info', 'Cambio compenso posizione #6', 1, NULL, '192.168.230.1', '2026-04-29 14:38:41'),
(197, 'Recruiting', 'success', 'Posizione #6 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 14:38:41'),
(198, 'Recruiting', 'info', 'Cambio compenso posizione #7', 1, NULL, '192.168.230.1', '2026-04-29 14:38:46'),
(199, 'Recruiting', 'success', 'Posizione #7 aggiornata', 1, NULL, '192.168.230.1', '2026-04-29 14:38:46'),
(200, 'Employees', 'success', 'Nuovo dipendente #87', 1, NULL, '192.168.230.1', '2026-04-29 14:54:09'),
(201, 'Import', 'success', 'Import certificati: ins=0 upd=0 err=21 auto=1', 1, NULL, '192.168.230.1', '2026-04-29 15:31:26'),
(202, 'Employees', 'success', 'Nuovo dipendente #88', 1, NULL, '192.168.230.1', '2026-04-29 15:35:28'),
(203, 'Employees', 'success', 'Nuovo dipendente #89', 1, NULL, '192.168.230.1', '2026-04-29 15:36:15'),
(204, 'Employees', 'success', 'Nuovo dipendente #90', 1, NULL, '192.168.230.1', '2026-04-29 15:36:49'),
(205, 'Employees', 'success', 'Nuovo dipendente #91', 1, NULL, '192.168.230.1', '2026-04-29 15:37:24'),
(206, 'Import', 'success', 'Import certificati: ins=0 upd=0 err=21 auto=1', 1, NULL, '192.168.230.1', '2026-04-29 15:37:49'),
(207, 'Import', 'success', 'Import certificati: ins=0 upd=0 err=21 auto=1', 1, NULL, '192.168.230.1', '2026-04-29 15:46:03'),
(208, 'Import', 'success', 'Import certificati: ins=0 upd=0 err=21 auto=1', 1, NULL, '192.168.230.1', '2026-04-29 15:50:03'),
(209, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-29 21:24:10'),
(210, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-30 05:15:33'),
(211, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-30 07:39:22'),
(212, 'Recruiting', 'info', 'Export XLSX posizioni: 6 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-30 07:57:53'),
(213, 'Recruiting', 'info', 'Cambio status posizione #8:  → draft', 1, NULL, '192.168.230.1', '2026-04-30 08:06:49'),
(214, 'Recruiting', 'info', 'Cambio compenso posizione #8', 1, NULL, '192.168.230.1', '2026-04-30 08:06:49'),
(215, 'Recruiting', 'success', 'Posizione #8 creata', 1, NULL, '192.168.230.1', '2026-04-30 08:06:49'),
(216, 'Recruiting', 'info', 'Cambio compenso posizione #3', 1, NULL, '192.168.230.1', '2026-04-30 08:07:15'),
(217, 'Recruiting', 'success', 'Posizione #3 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:07:15'),
(218, 'Recruiting', 'info', 'Cambio compenso posizione #8', 1, NULL, '192.168.230.1', '2026-04-30 08:07:54'),
(219, 'Recruiting', 'success', 'Posizione #8 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:07:54'),
(220, 'Recruiting', 'info', 'Export XLSX posizioni: 7 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-30 08:10:14'),
(221, 'Recruiting', 'info', 'Cambio compenso posizione #4', 1, NULL, '192.168.230.1', '2026-04-30 08:22:54'),
(222, 'Recruiting', 'success', 'Posizione #4 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:22:54'),
(223, 'Recruiting', 'info', 'Export XLSX posizioni: 7 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-30 08:33:04'),
(224, 'Recruiting', 'info', 'Cambio compenso posizione #4', 1, NULL, '192.168.230.1', '2026-04-30 08:35:31'),
(225, 'Recruiting', 'success', 'Posizione #4 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:35:31'),
(226, 'Recruiting', 'info', 'Export XLSX posizioni: 7 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-30 08:35:48'),
(227, 'Agencies', 'success', 'Agenzia salvata id=0', 1, NULL, '192.168.230.1', '2026-04-30 08:38:32'),
(228, 'Recruiting', 'info', 'Cambio compenso posizione #4', 1, NULL, '192.168.230.1', '2026-04-30 08:40:31'),
(229, 'Recruiting', 'success', 'Posizione #4 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:40:31'),
(230, 'Recruiting', 'info', 'Cambio compenso posizione #5', 1, NULL, '192.168.230.1', '2026-04-30 08:41:02'),
(231, 'Recruiting', 'success', 'Posizione #5 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:41:02'),
(232, 'Recruiting', 'info', 'Cambio status posizione #5: draft → open', 1, NULL, '192.168.230.1', '2026-04-30 08:41:14'),
(233, 'Recruiting', 'info', 'Cambio compenso posizione #5', 1, NULL, '192.168.230.1', '2026-04-30 08:41:14'),
(234, 'Recruiting', 'success', 'Posizione #5 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:41:14'),
(235, 'Recruiting', 'info', 'Cambio status posizione #3: draft → open', 1, NULL, '192.168.230.1', '2026-04-30 08:41:33'),
(236, 'Recruiting', 'info', 'Cambio compenso posizione #3', 1, NULL, '192.168.230.1', '2026-04-30 08:41:33'),
(237, 'Recruiting', 'success', 'Posizione #3 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:41:33'),
(238, 'Recruiting', 'info', 'Cambio status posizione #6: draft → open', 1, NULL, '192.168.230.1', '2026-04-30 08:41:47'),
(239, 'Recruiting', 'info', 'Cambio compenso posizione #6', 1, NULL, '192.168.230.1', '2026-04-30 08:41:47'),
(240, 'Recruiting', 'success', 'Posizione #6 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:41:47'),
(241, 'Recruiting', 'info', 'Cambio status posizione #4: draft → open', 1, NULL, '192.168.230.1', '2026-04-30 08:42:09'),
(242, 'Recruiting', 'info', 'Cambio compenso posizione #4', 1, NULL, '192.168.230.1', '2026-04-30 08:42:09'),
(243, 'Recruiting', 'success', 'Posizione #4 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:42:09'),
(244, 'Recruiting', 'info', 'Cambio status posizione #8: draft → open', 1, NULL, '192.168.230.1', '2026-04-30 08:42:21'),
(245, 'Recruiting', 'info', 'Cambio compenso posizione #8', 1, NULL, '192.168.230.1', '2026-04-30 08:42:21'),
(246, 'Recruiting', 'success', 'Posizione #8 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:42:21'),
(247, 'Recruiting', 'info', 'Cambio status posizione #7: draft → open', 1, NULL, '192.168.230.1', '2026-04-30 08:42:38'),
(248, 'Recruiting', 'info', 'Cambio compenso posizione #7', 1, NULL, '192.168.230.1', '2026-04-30 08:42:38'),
(249, 'Recruiting', 'success', 'Posizione #7 aggiornata', 1, NULL, '192.168.230.1', '2026-04-30 08:42:38'),
(250, 'Recruiting', 'success', 'Candidato aggiunto id=7 con candidatura su pos #5', 1, NULL, '192.168.230.1', '2026-04-30 08:44:45'),
(251, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-30 10:30:43'),
(252, 'Recruiting', 'success', 'Candidato aggiunto id=8 con candidatura su pos #7', 1, NULL, '192.168.230.1', '2026-04-30 10:37:09'),
(253, 'Recruiting', 'success', 'Aggiornato candidato #8', 1, NULL, '192.168.230.1', '2026-04-30 10:38:34'),
(254, 'Recruiting', 'success', 'Upload cv candidato #8', 1, NULL, '192.168.230.1', '2026-04-30 10:40:09'),
(255, 'Recruiting', 'success', 'Upload test_psicologico candidato #8', 1, NULL, '192.168.230.1', '2026-04-30 10:40:19'),
(256, 'Recruiting', 'success', 'Upload test_psicologico candidato #8', 1, NULL, '192.168.230.1', '2026-04-30 10:40:24'),
(257, 'Recruiting', 'success', 'Upload test_psicologico candidato #8', 1, NULL, '192.168.230.1', '2026-04-30 10:41:26'),
(258, 'Documenti', 'success', 'Upload cv per candidato #8', 1, NULL, '192.168.230.1', '2026-04-30 10:41:58'),
(259, 'Documenti', 'success', 'Upload test_psicologico per candidato #8', 1, NULL, '192.168.230.1', '2026-04-30 10:42:31'),
(260, 'Documenti', 'success', 'Upload test_psicologico per candidato #8', 1, NULL, '192.168.230.1', '2026-04-30 10:42:55'),
(261, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-30 13:02:39'),
(262, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-30 13:57:18'),
(263, 'Recruiting', 'info', 'Cambio status posizione #9:  → open', 1, NULL, '192.168.230.1', '2026-04-30 13:59:58'),
(264, 'Recruiting', 'info', 'Cambio compenso posizione #9', 1, NULL, '192.168.230.1', '2026-04-30 13:59:58'),
(265, 'Recruiting', 'success', 'Posizione #9 creata', 1, NULL, '192.168.230.1', '2026-04-30 13:59:58'),
(266, 'Recruiting', 'info', 'Export XLSX posizioni: 8 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-30 14:00:08'),
(267, 'Recruiting', 'info', 'Export XLSX posizioni: 8 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-30 14:01:52'),
(268, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-30 15:07:50'),
(269, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-04-30 21:11:17'),
(270, 'Recruiting', 'info', 'Export XLSX posizioni: 8 record', 1, '{\"filters\":{\"f_st\":\"all\",\"f_br\":0,\"f_pr\":\"\"}}', '192.168.230.1', '2026-04-30 21:11:47'),
(271, 'Branding', 'success', 'Aggiornato upload_logo', 1, NULL, '192.168.230.1', '2026-04-30 22:08:42'),
(272, 'Auth', 'info', 'Logout', 1, NULL, '192.168.230.1', '2026-04-30 22:10:36'),
(273, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-01 06:09:48'),
(274, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"::1\"}', '::1', '2026-05-01 07:04:00'),
(275, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-01 13:24:25'),
(276, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-04 10:07:50'),
(277, 'Import', 'info', 'Creato job #1 (certificati): 43 righe', 1, NULL, '192.168.230.1', '2026-05-04 10:23:08'),
(278, 'Import', 'info', 'Validato job #1: 0 valide, 43 invalide', 1, NULL, '192.168.230.1', '2026-05-04 10:23:08'),
(279, 'Import', 'info', 'Validato job #1: 0 valide, 43 invalide', 1, NULL, '192.168.230.1', '2026-05-04 10:24:22'),
(280, 'Import', 'info', 'Validato job #1: 0 valide, 43 invalide', 1, NULL, '192.168.230.1', '2026-05-04 10:24:24'),
(281, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-05 08:04:28'),
(282, 'Import', 'info', 'Creato job #2 (catalogo): 38 righe', 1, NULL, '192.168.230.1', '2026-05-05 08:21:32'),
(283, 'Import', 'info', 'Validato job #2: 0 valide, 38 invalide', 1, NULL, '192.168.230.1', '2026-05-05 08:21:33'),
(284, 'Auth', 'info', 'Logout', 1, NULL, '192.168.230.1', '2026-05-05 08:50:56'),
(285, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-05 08:50:59'),
(286, 'Import', 'info', 'Creato job #3 (catalogo): 38 righe', 1, NULL, '192.168.230.1', '2026-05-05 09:21:37'),
(287, 'Import', 'info', 'Validato job #3: 0 valide, 0 parziali (LDB), 38 invalide', 1, NULL, '192.168.230.1', '2026-05-05 09:21:37'),
(288, 'Auth', 'info', 'Logout', 1, NULL, '192.168.230.1', '2026-05-05 09:26:22'),
(289, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-05 09:26:25'),
(290, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-05 15:19:10'),
(291, 'Settings', 'success', 'Impostazioni aggiornate', 1, NULL, '192.168.230.1', '2026-05-05 15:19:56'),
(292, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-05 23:24:02'),
(293, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-06 07:55:47'),
(294, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-06 07:55:48'),
(295, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-06 09:57:13'),
(296, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-06 13:48:18'),
(297, 'Import', 'info', 'Creato job #4 (catalogo): 38 righe', 1, NULL, '192.168.230.1', '2026-05-06 14:07:00'),
(298, 'Import', 'info', 'Validato job #4: 0 valide, 0 parziali (LDB), 38 invalide', 1, NULL, '192.168.230.1', '2026-05-06 14:07:00'),
(299, 'Import', 'info', 'Riga staging #120 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:38'),
(300, 'Import', 'info', 'Riga staging #121 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:40'),
(301, 'Import', 'info', 'Riga staging #122 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:42'),
(302, 'Import', 'info', 'Riga staging #123 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:44'),
(303, 'Import', 'info', 'Riga staging #124 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:46'),
(304, 'Import', 'info', 'Riga staging #125 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:47'),
(305, 'Import', 'info', 'Riga staging #126 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:49'),
(306, 'Import', 'info', 'Riga staging #127 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:51'),
(307, 'Import', 'info', 'Riga staging #128 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:53'),
(308, 'Import', 'info', 'Riga staging #129 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:55'),
(309, 'Import', 'info', 'Riga staging #130 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:29:58'),
(310, 'Import', 'info', 'Riga staging #131 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:30:01'),
(311, 'Import', 'info', 'Riga staging #132 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:30:04'),
(312, 'Import', 'info', 'Riga staging #133 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:30:06'),
(313, 'Import', 'info', 'Riga staging #134 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-06 14:30:09'),
(314, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-06 15:52:01'),
(315, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-07 08:51:28'),
(316, 'Import', 'info', 'Riga staging #135 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:54:25'),
(317, 'Import', 'info', 'Riga staging #136 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:54:28'),
(318, 'Import', 'info', 'Riga staging #137 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:58:50'),
(319, 'Import', 'info', 'Riga staging #138 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:58:53'),
(320, 'Import', 'info', 'Riga staging #139 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:58:55'),
(321, 'Import', 'info', 'Riga staging #140 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:58:57'),
(322, 'Import', 'info', 'Riga staging #141 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:59:00'),
(323, 'Import', 'info', 'Riga staging #142 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:59:02'),
(324, 'Import', 'info', 'Riga staging #143 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:59:05'),
(325, 'Import', 'info', 'Riga staging #144 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:59:08'),
(326, 'Import', 'info', 'Riga staging #145 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 08:59:11'),
(327, 'Import', 'info', 'Riga staging #146 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:07'),
(328, 'Import', 'info', 'Riga staging #147 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:09'),
(329, 'Import', 'info', 'Riga staging #148 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:12'),
(330, 'Import', 'info', 'Riga staging #149 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:14'),
(331, 'Import', 'info', 'Riga staging #150 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:17'),
(332, 'Import', 'info', 'Riga staging #151 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:20'),
(333, 'Import', 'info', 'Riga staging #152 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:23'),
(334, 'Import', 'info', 'Riga staging #153 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:25'),
(335, 'Import', 'info', 'Riga staging #154 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:29'),
(336, 'Import', 'info', 'Riga staging #155 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:31'),
(337, 'Import', 'info', 'Riga staging #156 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:36'),
(338, 'Import', 'info', 'Riga staging #157 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:03:40'),
(339, 'Import', 'success', 'Commit job #4: 0 importate, 38 fallite, 0 saltate', 1, NULL, '192.168.230.1', '2026-05-07 09:03:45'),
(340, 'Import', 'info', 'Validato job #4: 34 valide, 4 parziali (LDB), 0 invalide', 1, NULL, '192.168.230.1', '2026-05-07 09:04:11'),
(341, 'Import', 'info', 'Riga staging #120 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(342, 'Import', 'info', 'Riga staging #121 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(343, 'Import', 'info', 'Riga staging #122 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(344, 'Import', 'info', 'Riga staging #123 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(345, 'Import', 'info', 'Riga staging #124 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(346, 'Import', 'info', 'Riga staging #125 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(347, 'Import', 'info', 'Riga staging #126 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(348, 'Import', 'info', 'Riga staging #127 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(349, 'Import', 'info', 'Riga staging #128 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(350, 'Import', 'info', 'Riga staging #129 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(351, 'Import', 'info', 'Riga staging #130 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(352, 'Import', 'info', 'Riga staging #135 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(353, 'Import', 'info', 'Riga staging #136 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(354, 'Import', 'info', 'Riga staging #137 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(355, 'Import', 'info', 'Riga staging #138 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(356, 'Import', 'info', 'Riga staging #139 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(357, 'Import', 'info', 'Riga staging #140 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:20'),
(358, 'Import', 'info', 'Riga staging #141 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(359, 'Import', 'info', 'Riga staging #142 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(360, 'Import', 'info', 'Riga staging #143 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(361, 'Import', 'info', 'Riga staging #144 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(362, 'Import', 'info', 'Riga staging #145 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(363, 'Import', 'info', 'Riga staging #146 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(364, 'Import', 'info', 'Riga staging #147 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(365, 'Import', 'info', 'Riga staging #148 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(366, 'Import', 'info', 'Riga staging #149 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(367, 'Import', 'info', 'Riga staging #150 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(368, 'Import', 'info', 'Riga staging #151 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(369, 'Import', 'info', 'Riga staging #152 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(370, 'Import', 'info', 'Riga staging #153 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(371, 'Import', 'info', 'Riga staging #154 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(372, 'Import', 'info', 'Riga staging #155 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(373, 'Import', 'info', 'Riga staging #156 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(374, 'Import', 'info', 'Riga staging #157 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(375, 'Import', 'info', 'Riga staging #131 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(376, 'Import', 'info', 'Riga staging #132 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(377, 'Import', 'info', 'Riga staging #133 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(378, 'Import', 'info', 'Riga staging #134 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(379, 'Import', 'info', 'Bulk approve job #4 scope=valid: 38 strict + 0 LDB, 0 skip', 1, NULL, '192.168.230.1', '2026-05-07 09:06:21'),
(380, 'Import', 'info', 'Annullata approvazione riga staging #132', 1, NULL, '192.168.230.1', '2026-05-07 09:06:31'),
(381, 'Import', 'info', 'Riga staging #132 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:44'),
(382, 'Import', 'info', 'Annullata approvazione riga staging #133', 1, NULL, '192.168.230.1', '2026-05-07 09:06:48'),
(383, 'Import', 'info', 'Riga staging #133 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:06:57'),
(384, 'Import', 'info', 'Annullata approvazione riga staging #134', 1, NULL, '192.168.230.1', '2026-05-07 09:07:02'),
(385, 'Import', 'info', 'Riga staging #134 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:07:15'),
(386, 'Import', 'success', 'Commit job #4: 0 importate, 38 fallite, 0 saltate', 1, NULL, '192.168.230.1', '2026-05-07 09:07:19'),
(387, 'Import', 'info', 'Validato job #4: 38 valide, 0 parziali (LDB), 0 invalide', 1, NULL, '192.168.230.1', '2026-05-07 09:07:53'),
(388, 'Import', 'info', 'Riga staging #120 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(389, 'Import', 'info', 'Riga staging #121 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(390, 'Import', 'info', 'Riga staging #122 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(391, 'Import', 'info', 'Riga staging #123 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(392, 'Import', 'info', 'Riga staging #124 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(393, 'Import', 'info', 'Riga staging #125 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(394, 'Import', 'info', 'Riga staging #126 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(395, 'Import', 'info', 'Riga staging #127 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(396, 'Import', 'info', 'Riga staging #128 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(397, 'Import', 'info', 'Riga staging #129 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(398, 'Import', 'info', 'Riga staging #130 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(399, 'Import', 'info', 'Riga staging #131 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(400, 'Import', 'info', 'Riga staging #132 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(401, 'Import', 'info', 'Riga staging #133 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(402, 'Import', 'info', 'Riga staging #134 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(403, 'Import', 'info', 'Riga staging #135 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(404, 'Import', 'info', 'Riga staging #136 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(405, 'Import', 'info', 'Riga staging #137 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(406, 'Import', 'info', 'Riga staging #138 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(407, 'Import', 'info', 'Riga staging #139 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(408, 'Import', 'info', 'Riga staging #140 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(409, 'Import', 'info', 'Riga staging #141 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(410, 'Import', 'info', 'Riga staging #142 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(411, 'Import', 'info', 'Riga staging #143 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(412, 'Import', 'info', 'Riga staging #144 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(413, 'Import', 'info', 'Riga staging #145 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(414, 'Import', 'info', 'Riga staging #146 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(415, 'Import', 'info', 'Riga staging #147 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(416, 'Import', 'info', 'Riga staging #148 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(417, 'Import', 'info', 'Riga staging #149 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(418, 'Import', 'info', 'Riga staging #150 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(419, 'Import', 'info', 'Riga staging #151 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(420, 'Import', 'info', 'Riga staging #152 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(421, 'Import', 'info', 'Riga staging #153 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(422, 'Import', 'info', 'Riga staging #154 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(423, 'Import', 'info', 'Riga staging #155 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(424, 'Import', 'info', 'Riga staging #156 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(425, 'Import', 'info', 'Riga staging #157 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(426, 'Import', 'info', 'Bulk approve job #4 scope=valid: 38 strict + 0 LDB, 0 skip', 1, NULL, '192.168.230.1', '2026-05-07 09:08:02'),
(427, 'Import', 'info', 'Commit job #4 start (type=catalogo)', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(428, 'Import', 'error', 'Commit job #4 staging #120: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(429, 'Import', 'error', 'Commit job #4 staging #121: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(430, 'Import', 'error', 'Commit job #4 staging #122: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(431, 'Import', 'error', 'Commit job #4 staging #123: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38');
INSERT INTO `app_logs` (`id`, `category`, `level`, `message`, `user_id`, `context`, `ip_address`, `created_at`) VALUES
(432, 'Import', 'error', 'Commit job #4 staging #124: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(433, 'Import', 'error', 'Commit job #4 staging #125: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(434, 'Import', 'error', 'Commit job #4 staging #126: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(435, 'Import', 'error', 'Commit job #4 staging #127: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(436, 'Import', 'error', 'Commit job #4 staging #128: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(437, 'Import', 'error', 'Commit job #4 staging #129: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(438, 'Import', 'error', 'Commit job #4 staging #130: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(439, 'Import', 'error', 'Commit job #4 staging #131: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(440, 'Import', 'error', 'Commit job #4 staging #132: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(441, 'Import', 'error', 'Commit job #4 staging #133: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(442, 'Import', 'error', 'Commit job #4 staging #134: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(443, 'Import', 'error', 'Commit job #4 staging #135: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(444, 'Import', 'error', 'Commit job #4 staging #136: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(445, 'Import', 'error', 'Commit job #4 staging #137: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(446, 'Import', 'error', 'Commit job #4 staging #138: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(447, 'Import', 'error', 'Commit job #4 staging #139: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(448, 'Import', 'error', 'Commit job #4 staging #140: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(449, 'Import', 'error', 'Commit job #4 staging #141: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(450, 'Import', 'error', 'Commit job #4 staging #142: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(451, 'Import', 'error', 'Commit job #4 staging #143: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(452, 'Import', 'error', 'Commit job #4 staging #144: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(453, 'Import', 'error', 'Commit job #4 staging #145: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(454, 'Import', 'error', 'Commit job #4 staging #146: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(455, 'Import', 'error', 'Commit job #4 staging #147: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(456, 'Import', 'error', 'Commit job #4 staging #148: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(457, 'Import', 'error', 'Commit job #4 staging #149: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(458, 'Import', 'error', 'Commit job #4 staging #150: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(459, 'Import', 'error', 'Commit job #4 staging #151: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(460, 'Import', 'error', 'Commit job #4 staging #152: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(461, 'Import', 'error', 'Commit job #4 staging #153: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(462, 'Import', 'error', 'Commit job #4 staging #154: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(463, 'Import', 'error', 'Commit job #4 staging #155: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(464, 'Import', 'error', 'Commit job #4 staging #156: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(465, 'Import', 'error', 'Commit job #4 staging #157: Error: Class \"EntityChangeLog\" not found @ ImportProcessor.php:374', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(466, 'Import', 'warning', 'Commit job #4 end: 0 importate, 38 fallite, 0 saltate, 0 partial → status=partial', 1, NULL, '192.168.230.1', '2026-05-07 09:23:38'),
(467, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-07 12:23:44'),
(468, 'EnumExtender', 'success', 'ENUM esteso: certifications.level += \'Specialist\'', 1, NULL, '192.168.230.1', '2026-05-07 12:25:18'),
(469, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-07 14:00:26'),
(470, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-07 16:11:41'),
(471, 'Import', 'info', 'Riga staging #120 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:12:01'),
(472, 'Import', 'info', 'Riga staging #121 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:12:04'),
(473, 'Import', 'info', 'Riga staging #122 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:12:07'),
(474, 'Import', 'info', 'Commit job #4 start (type=catalogo)', 1, NULL, '192.168.230.1', '2026-05-07 16:12:09'),
(475, 'Import', 'success', 'Commit job #4 end: 3 importate, 0 fallite, 0 saltate, 0 partial → status=partial', 1, NULL, '192.168.230.1', '2026-05-07 16:12:09'),
(476, 'Import', 'info', 'Riga staging #124 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:13:47'),
(477, 'Import', 'info', 'Riga staging #125 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:13:48'),
(478, 'Import', 'info', 'Riga staging #126 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:13:49'),
(479, 'Import', 'info', 'Riga staging #127 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:13:51'),
(480, 'Import', 'info', 'Riga staging #157 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:14:27'),
(481, 'Import', 'info', 'Riga staging #128 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:14:29'),
(482, 'Import', 'info', 'Riga staging #129 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:14:30'),
(483, 'Import', 'info', 'Riga staging #130 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:14:34'),
(484, 'Import', 'info', 'Riga staging #131 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:14:36'),
(485, 'Import', 'info', 'Riga staging #132 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:14:38'),
(486, 'Import', 'info', 'Riga staging #133 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:14:39'),
(487, 'Import', 'info', 'Riga staging #143 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:14'),
(488, 'Import', 'info', 'Riga staging #134 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:16'),
(489, 'Import', 'info', 'Riga staging #135 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:17'),
(490, 'Import', 'info', 'Riga staging #136 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:18'),
(491, 'Import', 'info', 'Riga staging #137 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:20'),
(492, 'Import', 'info', 'Riga staging #138 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:22'),
(493, 'Import', 'info', 'Riga staging #139 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:23'),
(494, 'Import', 'info', 'Riga staging #142 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:26'),
(495, 'Import', 'info', 'Riga staging #140 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:27'),
(496, 'Import', 'info', 'Riga staging #141 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:29'),
(497, 'Import', 'info', 'Riga staging #144 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:31'),
(498, 'Import', 'info', 'Riga staging #148 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:33'),
(499, 'Import', 'info', 'Riga staging #156 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:36'),
(500, 'Import', 'info', 'Riga staging #147 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:37'),
(501, 'Import', 'info', 'Riga staging #145 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:40'),
(502, 'Import', 'info', 'Riga staging #146 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:42'),
(503, 'Import', 'info', 'Riga staging #149 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:44'),
(504, 'Import', 'info', 'Annullata approvazione riga staging #157', 1, NULL, '192.168.230.1', '2026-05-07 16:15:46'),
(505, 'Import', 'info', 'Riga staging #150 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:49'),
(506, 'Import', 'info', 'Riga staging #151 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:51'),
(507, 'Import', 'info', 'Riga staging #157 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:55'),
(508, 'Import', 'info', 'Riga staging #155 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:15:58'),
(509, 'Import', 'info', 'Riga staging #154 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:16:01'),
(510, 'Import', 'info', 'Riga staging #152 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:16:03'),
(511, 'Import', 'info', 'Riga staging #153 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:16:05'),
(512, 'Import', 'info', 'Riga staging #123 approvata in modalità strict', 1, NULL, '192.168.230.1', '2026-05-07 16:16:14'),
(513, 'Import', 'info', 'Bulk approve job #4 scope=valid: 1 strict + 0 LDB, 0 skip', 1, NULL, '192.168.230.1', '2026-05-07 16:16:14'),
(514, 'Import', 'info', 'Commit job #4 start (type=catalogo)', 1, NULL, '192.168.230.1', '2026-05-07 16:16:18'),
(515, 'Import', 'success', 'Commit job #4 end: 35 importate, 0 fallite, 0 saltate, 0 partial → status=imported', 1, NULL, '192.168.230.1', '2026-05-07 16:16:18'),
(516, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-08 12:38:08'),
(517, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-08 13:53:57'),
(518, 'Backup', 'success', 'Backup generato (24.29 MB) — 234 file, 60 tabelle, .env.php incluso', 1, NULL, '192.168.230.1', '2026-05-08 13:56:28'),
(519, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-08 15:13:10'),
(520, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-12 10:17:49'),
(521, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-12 10:49:11'),
(522, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-13 08:15:15'),
(523, 'Certifications', 'success', 'Certificazione #125 creata', 1, NULL, '192.168.230.1', '2026-05-13 08:16:52'),
(524, 'Certifications', 'success', 'Certificazione #126 creata', 1, NULL, '192.168.230.1', '2026-05-13 08:19:26'),
(525, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-13 09:12:24'),
(526, 'Certifications', 'success', 'Certificazione #125 aggiornata', 1, NULL, '192.168.230.1', '2026-05-13 09:14:46'),
(527, 'Certifications', 'success', 'Upload cert. id=125 per emp=13', 1, NULL, '192.168.230.1', '2026-05-13 09:15:24'),
(528, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-13 21:52:26'),
(529, 'CredlyImport', 'error', 'Dipendente e username Credly richiesti.', 1, NULL, '192.168.230.1', '2026-05-13 21:55:44'),
(530, 'CredlyImport', 'success', 'Linkato Credly username=lorenzo-buschi a employee_id=12', 1, NULL, '192.168.230.1', '2026-05-13 22:00:28'),
(531, 'CredlyImport', 'error', 'Badge \"Cisco AI Technical Practitioner\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:41'),
(532, 'CredlyImport', 'error', 'Badge \"Cisco AI Business Practitioner\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:41'),
(533, 'CredlyImport', 'error', 'Badge \"Introduction to Network Simulations with Cisco Modeling Labs\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:41'),
(534, 'CredlyImport', 'error', 'Badge \"Understanding Cisco Network Automation Essentials\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:41'),
(535, 'CredlyImport', 'error', 'Badge \"AI Solutions on Cisco Infrastructure Essentials\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(536, 'CredlyImport', 'error', 'Badge \"Cisco Certificate in Ethical Hacking\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(537, 'CredlyImport', 'error', 'Badge \"Offensive Security Capture the Flag - DNS Event\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(538, 'CredlyImport', 'error', 'Badge \"Ethical Hacker\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(539, 'CredlyImport', 'error', 'Badge \"Fortinet Certified Professional Secure Networking\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(540, 'CredlyImport', 'error', 'Badge \"Fortinet FortiAnalyzer 7.2 Administrator\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(541, 'CredlyImport', 'error', 'Badge \"Fortinet Certified Associate Cybersecurity\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(542, 'CredlyImport', 'error', 'Badge \"Fortinet FortiGate 7.4 Operator\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(543, 'CredlyImport', 'error', 'Badge \"Fortinet FortiGate 7.2 Administrator\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(544, 'CredlyImport', 'error', 'Badge \"VMware Certified Professional - Network Virtualization 2023\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(545, 'CredlyImport', 'error', 'Badge \"Fortinet Certified Fundamentals Cybersecurity\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(546, 'CredlyImport', 'error', 'Badge \"Getting Started in Cybersecurity 1.0\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(547, 'CredlyImport', 'error', 'Badge \"Introduction to the Threat Landscape 1.0\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(548, 'CredlyImport', 'error', 'Badge \"Cato Certified Associate\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(549, 'CredlyImport', 'error', 'Badge \"Environmental Sustainability Practice-Building\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(550, 'CredlyImport', 'error', 'Badge \"Cisco Environmental Sustainability Overview\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(551, 'CredlyImport', 'error', 'Badge \"SASE Expert Level 2\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(552, 'CredlyImport', 'error', 'Badge \"SASE Expert Level 1\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(553, 'CredlyImport', 'error', 'Badge \"Small Business Technical Overview\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(554, 'CredlyImport', 'error', 'Badge \"Understanding Design for Cisco Internetworking Solutions\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(555, 'CredlyImport', 'error', 'Badge \"Understanding of Cisco Network Devices\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(556, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Enterprise Wireless Design\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(557, 'CredlyImport', 'error', 'Badge \"Cisco Certified Network Professional Security (CCNP Security)\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(558, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Network Security Firewalls\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(559, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Network Security VPN\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(560, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Security Core\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(561, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Security Identity Management\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(562, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Web Content Security\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(563, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Enterprise Design\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(564, 'CredlyImport', 'error', 'Badge \"Cisco Certified Network Professional Enterprise (CCNP Enterprise)\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(565, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Enterprise Advanced Infrastructure\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(566, 'CredlyImport', 'error', 'Badge \"Cisco Certified Specialist - Enterprise Core\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(567, 'CredlyImport', 'error', 'Badge \"Broadcom Partner Certification - Certified Expert - VCF Networking - Pre-Sales\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(568, 'CredlyImport', 'error', 'Badge \"Broadcom Partner Certification - Proven Professional - VCF Networking - Architecture\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(569, 'CredlyImport', 'error', 'Badge \"Broadcom Partner Certification - Proven Professional - VCF Networking - Implementation\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(570, 'CredlyImport', 'error', 'Badge \"Broadcom Partner Certification - Proven Professional - VCF Networking - Support\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(571, 'CredlyImport', 'error', 'Badge \"Cisco Certified Design Professional (CCDP)\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(572, 'CredlyImport', 'error', 'Badge \"Cisco Certified Network Professional Routing and Switching (CCNP Routing and Switching)\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(573, 'CredlyImport', 'error', 'Badge \"Cisco Certified Design Associate (CCDA)\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(574, 'CredlyImport', 'error', 'Badge \"Cisco Certified Network Associate Routing and Switching (CCNA Routing and Switching)\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(575, 'CredlyImport', 'error', 'Badge \"Webex Calling Administration Professional\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(576, 'CredlyImport', 'error', 'Badge \"Cisco Certified Design Professional (CCDP)\": SQLSTATE[42S22]: Column not found: 1054 Unknown column \'source_file\' in \'where clause\'', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(577, 'CredlyImport', 'success', 'Sync emp=12: 1 imp, 0 upd, 0 unmatch, 46 err', 1, NULL, '192.168.230.1', '2026-05-13 22:00:42'),
(578, 'CredlyImport', 'success', 'Sync emp=12: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-13 22:07:11'),
(579, 'Backup', 'success', 'Backup generato (5.58 MB) — 226 file, 61 tabelle', 1, NULL, '192.168.230.1', '2026-05-13 22:10:10'),
(580, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-15 10:05:46'),
(581, 'LinkedInImport', 'success', 'Linkato LinkedIn vanity=a-orru750122 a employee_id=2', 1, NULL, '192.168.230.1', '2026-05-15 10:08:34'),
(582, 'LinkedInImport', 'success', 'Import emp=2 da zip: 0 imp, 10 auto-cat, 0 upd, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:34:48'),
(583, 'System', 'success', 'Update da v4.0 a v?: 1 aggiornati, 0 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-05-15 10:38:39'),
(584, 'CredlyImport', 'success', 'Sync emp=12: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:40:43'),
(585, 'CredlyImport', 'success', 'Linkato Credly username=lorenzo.buschi a employee_id=87', 1, NULL, '192.168.230.1', '2026-05-15 10:43:03'),
(586, 'CredlyImport', 'error', 'Username Credly non valido: lorenzo.buschi', 1, NULL, '192.168.230.1', '2026-05-15 10:43:06'),
(587, 'CredlyImport', 'success', 'Linkato Credly username=lorenzo-buschi a employee_id=87', 1, NULL, '192.168.230.1', '2026-05-15 10:44:20'),
(588, 'CredlyImport', 'success', 'Sync emp=87: 47 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:44:29'),
(589, 'CredlyImport', 'success', 'Sync emp=87: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:44:35'),
(590, 'CredlyImport', 'success', 'Linkato Credly username=david-sozzi a employee_id=76', 1, NULL, '192.168.230.1', '2026-05-15 10:45:38'),
(591, 'CredlyImport', 'success', 'Sync emp=76: 1 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:45:42'),
(592, 'CredlyImport', 'success', 'Linkato Credly username=marco-ayed a employee_id=7', 1, NULL, '192.168.230.1', '2026-05-15 10:46:24'),
(593, 'CredlyImport', 'success', 'Linkato Credly username=mirko-vadi a employee_id=78', 1, NULL, '192.168.230.1', '2026-05-15 10:47:22'),
(594, 'CredlyImport', 'success', 'Sync emp=78: 12 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:47:28'),
(595, 'CredlyImport', 'success', 'Linkato Credly username=paolo-baruchello a employee_id=11', 1, NULL, '192.168.230.1', '2026-05-15 10:48:21'),
(596, 'CredlyImport', 'success', 'Sync emp=11: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:48:28'),
(597, 'CredlyImport', 'success', 'Sync emp=7: 1 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:48:30'),
(598, 'CredlyImport', 'success', 'Linkato Credly username=ciaran-conway a employee_id=23', 1, NULL, '192.168.230.1', '2026-05-15 10:51:09'),
(599, 'CredlyImport', 'success', 'Sync emp=23: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:51:14'),
(600, 'CredlyImport', 'success', 'Linkato Credly username=fabrizio-meli a employee_id=53', 1, NULL, '192.168.230.1', '2026-05-15 10:53:28'),
(601, 'CredlyImport', 'success', 'Sync emp=53: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 10:53:33'),
(602, 'System', 'success', 'Update da v4.0 a v?: 1 aggiornati, 1 nuovi, 0 SQL', 1, NULL, '192.168.230.1', '2026-05-15 10:57:38'),
(603, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-15 12:01:50'),
(604, 'CredlyImport', 'success', 'Linkato Credly username=alessandro-imbrosciano a employee_id=43', 1, NULL, '192.168.230.1', '2026-05-15 12:03:01'),
(605, 'CredlyImport', 'success', 'Sync emp=43: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:03:25'),
(606, 'CredlyImport', 'success', 'Sync emp=43: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:03:26'),
(607, 'CredlyImport', 'success', 'Linkato Credly username=duccio-bordoni a employee_id=14', 1, NULL, '192.168.230.1', '2026-05-15 12:04:05'),
(608, 'CredlyImport', 'success', 'Sync emp=14: 1 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:04:13'),
(609, 'CredlyImport', 'success', 'Linkato Credly username=leonardo-baggiani a employee_id=8', 1, NULL, '192.168.230.1', '2026-05-15 12:04:52'),
(610, 'CredlyImport', 'success', 'Sync emp=8: 2 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:04:57'),
(611, 'CredlyImport', 'success', 'Linkato Credly username=emanuele-bressi a employee_id=16', 1, NULL, '192.168.230.1', '2026-05-15 12:06:24'),
(612, 'CredlyImport', 'success', 'Sync emp=16: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:06:27'),
(613, 'CredlyImport', 'success', 'Linkato Credly username=francesco-brandi a employee_id=15', 1, NULL, '192.168.230.1', '2026-05-15 12:07:30'),
(614, 'CredlyImport', 'success', 'Sync emp=15: 1 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:07:34'),
(615, 'CredlyImport', 'success', 'Linkato Credly username=michele-brunelli a employee_id=81', 1, NULL, '192.168.230.1', '2026-05-15 12:08:17'),
(616, 'CredlyImport', 'success', 'Sync emp=81: 1 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:08:28'),
(617, 'CredlyImport', 'success', 'Linkato Credly username=sebastiano-chiarini a employee_id=19', 1, NULL, '192.168.230.1', '2026-05-15 12:12:39'),
(618, 'CredlyImport', 'success', 'Sync emp=19: 5 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:12:44'),
(619, 'CredlyImport', 'success', 'Linkato Credly username=giorgio-bruschi a employee_id=17', 1, NULL, '192.168.230.1', '2026-05-15 12:14:39'),
(620, 'CredlyImport', 'success', 'Linkato Credly username=edoardo-colonna a employee_id=22', 1, NULL, '192.168.230.1', '2026-05-15 12:15:41'),
(621, 'CredlyImport', 'success', 'Linkato Credly username=leonardo-corsi a employee_id=24', 1, NULL, '192.168.230.1', '2026-05-15 12:16:17'),
(622, 'CredlyImport', 'success', 'Linkato Credly username=paolo-failla a employee_id=31', 1, NULL, '192.168.230.1', '2026-05-15 12:18:08'),
(623, 'CredlyImport', 'success', 'Linkato Credly username=alberto-ruffo a employee_id=69', 1, NULL, '192.168.230.1', '2026-05-15 12:19:18'),
(624, 'CredlyImport', 'success', 'Sync emp=22: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:19:23'),
(625, 'CredlyImport', 'success', 'Sync emp=17: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:19:26'),
(626, 'CredlyImport', 'success', 'Sync emp=24: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:19:29'),
(627, 'CredlyImport', 'success', 'Sync emp=31: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:19:31'),
(628, 'CredlyImport', 'success', 'Sync emp=69: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:19:39'),
(629, 'CredlyImport', 'success', 'Linkato Credly username=andrea-cristofano a employee_id=25', 1, NULL, '192.168.230.1', '2026-05-15 12:20:21'),
(630, 'CredlyImport', 'success', 'Sync emp=25: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:20:25'),
(631, 'CredlyImport', 'success', 'Linkato Credly username=tiziana-verdina a employee_id=79', 1, NULL, '192.168.230.1', '2026-05-15 12:21:00'),
(632, 'CredlyImport', 'success', 'Linkato Credly username=andrea-sestini a employee_id=74', 1, NULL, '192.168.230.1', '2026-05-15 12:25:29'),
(633, 'CredlyImport', 'success', 'Sync emp=79: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:25:39'),
(634, 'CredlyImport', 'success', 'Sync emp=74: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:25:47'),
(635, 'CredlyImport', 'success', 'Linkato Credly username=michele-fionga a employee_id=33', 1, NULL, '192.168.230.1', '2026-05-15 12:36:42'),
(636, 'CredlyImport', 'success', 'Sync emp=33: 3 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:36:48'),
(637, 'CredlyImport', 'success', 'Linkato Credly username=marco-marziali a employee_id=50', 1, NULL, '192.168.230.1', '2026-05-15 12:38:14'),
(638, 'CredlyImport', 'success', 'Sync emp=50: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 12:38:23'),
(639, 'CredlyImport', 'success', 'Linkato Credly username=ergis-kocumi a employee_id=29', 1, NULL, '192.168.230.1', '2026-05-15 12:39:12'),
(640, 'CredlyImport', 'success', 'Linkato Credly username=joele-milazzo a employee_id=85', 1, NULL, '192.168.230.1', '2026-05-15 12:41:09'),
(641, 'CredlyImport', 'success', 'Linkato Credly username=alberto-guercini a employee_id=88', 1, NULL, '192.168.230.1', '2026-05-15 12:42:26'),
(642, 'CredlyImport', 'success', 'Linkato Credly username=enrico-mancini a employee_id=49', 1, NULL, '192.168.230.1', '2026-05-15 12:43:29'),
(643, 'Employees', 'success', 'Nuovo dipendente #92', 1, NULL, '192.168.230.1', '2026-05-15 12:45:49'),
(644, 'CredlyImport', 'success', 'Linkato Credly username=biagio-monello a employee_id=92', 1, NULL, '192.168.230.1', '2026-05-15 12:49:11'),
(645, 'CredlyImport', 'success', 'Linkato Credly username=irni-nushi a employee_id=86', 1, NULL, '192.168.230.1', '2026-05-15 13:01:21'),
(646, 'CredlyImport', 'success', 'Sync emp=86: 0 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 13:02:21'),
(647, 'CredlyImport', 'success', 'Linkato Credly username=giacomo-guerrini a employee_id=40', 1, NULL, '192.168.230.1', '2026-05-15 13:03:54'),
(648, 'Employees', 'success', 'Nuovo dipendente #93', 1, NULL, '192.168.230.1', '2026-05-15 13:08:06'),
(649, 'CredlyImport', 'success', 'Linkato Credly username=giacomo-pierotti a employee_id=64', 1, NULL, '192.168.230.1', '2026-05-15 13:10:50'),
(650, 'CredlyImport', 'success', 'Sync emp=64: 6 imp, 0 upd, 0 unmatch, 0 err', 1, NULL, '192.168.230.1', '2026-05-15 13:10:57'),
(651, 'Employees', 'success', 'Nuovo dipendente #94', 1, NULL, '192.168.230.1', '2026-05-15 13:27:37'),
(652, 'Employees', 'info', 'Stato dipendente #94 → terminated', 1, NULL, '192.168.230.1', '2026-05-15 13:28:26'),
(653, 'Users', 'success', 'Creazione account: erika.franceschini@wetechs.it', 1, NULL, '192.168.230.1', '2026-05-15 13:29:37'),
(654, 'Users', 'success', 'Modifica account #4', 1, NULL, '192.168.230.1', '2026-05-15 13:29:57'),
(655, 'Permissions', 'success', 'Permessi ruolo #2 aggiornati', 1, NULL, '192.168.230.1', '2026-05-15 13:32:20'),
(656, 'Permissions', 'success', 'Permessi ruolo #3 aggiornati', 1, NULL, '192.168.230.1', '2026-05-15 13:32:54'),
(657, 'Permissions', 'success', 'Permessi ruolo #4 aggiornati', 1, NULL, '192.168.230.1', '2026-05-15 13:33:29'),
(658, 'Permissions', 'success', 'Permessi ruolo #4 aggiornati', 1, NULL, '192.168.230.1', '2026-05-15 13:33:47'),
(659, 'Permissions', 'success', 'Permessi ruolo #5 aggiornati', 1, NULL, '192.168.230.1', '2026-05-15 13:34:25'),
(660, 'Auth', 'info', 'Logout', 1, NULL, '192.168.230.1', '2026-05-15 13:34:37'),
(661, 'Auth', 'success', 'Login (no 2FA)', 4, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-15 13:34:58'),
(662, 'Auth', 'success', 'Login (no 2FA)', 1, '{\"ip\":\"192.168.230.1\"}', '192.168.230.1', '2026-05-15 14:17:03');

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
('accent_color', '#5b21b6', NULL),
('agency_contract_alert_days', '60', 'Alert scadenza contratti agenzie'),
('app_name', 'PortalManager', 'Nome applicazione'),
('app_tagline', 'Portale gestione certificazioni e talent governance', NULL),
('app_version', '1.0.0', 'Versione build'),
('company_apply_url', '', 'URL candidatura esterna (es. https://careers.azienda.it)'),
('company_website', '', 'URL sito web azienda (usato nei post)'),
('compliance_critical_pct', '60', 'Soglia compliance rossa (%)'),
('compliance_warning_pct', '80', 'Soglia compliance gialla (%)'),
('copyright_text', '© 2026 Wetech\'s S.p.A. SB · Tutti i diritti riservati', NULL),
('credly_auto_create_catalog', '1', 'Se 1, durante import Credly crea automaticamente brand+certificazione nel catalogo per badge sconosciuti. Se 0, registra in staging per intervento manuale.'),
('credly_auto_sync_cron', '0', 'Auto-sync giornaliero via cron (1/0). Richiede script worker schedulato.'),
('credly_enabled', '1', 'Abilita integrazione Credly (1/0)'),
('credly_match_fuzzy', '1', 'Abilita matching fuzzy per nome certificazione quando manca credly_template_id'),
('danger_color', '#ef4444', NULL),
('employee_code_prefix', 'EMP-', 'Prefisso matricola dipendenti'),
('favicon_path', 'uploads/branding/favicon_1777586922.png', NULL),
('font_family', 'system', NULL),
('layout_template', 'modern', NULL),
('legacy_codename', 'certV', 'Codename precedente (storico)'),
('linkedin_access_token', '', 'OAuth2 Access Token (generato via publish_posizione.php)'),
('linkedin_auto_create_catalog', '1', 'Se 1, import LinkedIn crea automaticamente brand+certificazione a catalogo per cert sconosciute. Se 0, le ignora.'),
('linkedin_client_id', '', 'LinkedIn App Client ID (da developer.linkedin.com)'),
('linkedin_client_secret', '', 'LinkedIn App Client Secret'),
('linkedin_company_id', '', 'LinkedIn Company ID (numerico, da URL pagina azienda)'),
('linkedin_enabled', '1', 'Abilita integrazione LinkedIn (1/0)'),
('linkedin_update_cv', '1', 'Se 1, import LinkedIn aggiorna bio/skills/CV del dipendente (merge non distruttivo).'),
('logo_path', 'uploads/branding/logo_1777586922.png', NULL),
('mail_from', 'antonello.orru@aoss.eu', 'Email mittente notifiche'),
('mail_from_name', 'PortalManager System', 'Nome mittente notifiche'),
('mfa_enforced', '0', 'Forza 2FA per tutti gli utenti (1/0)'),
('notify_days_1', '90', '1° alert scadenza cert'),
('notify_days_2', '60', '2° alert scadenza cert'),
('notify_days_3', '30', '3° alert scadenza cert'),
('notify_days_4', '7', 'Alert critico scadenza cert'),
('notify_exam_days_1', '7', 'Primo avviso esame pianificato (giorni prima)'),
('notify_exam_days_2', '1', 'Promemoria esame (giorno prima)'),
('notify_logistics_cc', '', 'CC aggiuntivo per notifiche segreteria/logistica'),
('notify_logistics_email', '', 'Email destinatario notifiche segreteria/logistica (vuoto = tutti i manager)'),
('notify_renewal_days', '30', 'Avviso finestra rinnovo certificazione (giorni dopo scadenza)'),
('primary_color', '#3db0e6', 'Colore primario UI'),
('release_label', 'v5.03.00', NULL),
('release_show_footer', '1', NULL),
('schema_version', '1.0.0', NULL),
('sidebar_bg', '#0f172a', NULL),
('sidebar_hover', '#1e293b', NULL),
('sidebar_text', '#cbd5e1', NULL),
('smtp_auth', '1', 'Richiede autenticazione (1=si, 0=no)'),
('smtp_debug', '1', 'Log debug SMTP nel log di sistema (1=si)'),
('smtp_enabled', '1', 'Abilita invio email via SMTP (1=si, 0=no/fallback mail())'),
('smtp_encryption', 'ssl', 'Crittografia: tls (STARTTLS), ssl (implicita), none'),
('smtp_host', 'smtps.aruba.it', 'Server SMTP (es. smtp.gmail.com, smtp.office365.com)'),
('smtp_pass', '_Ant0n3ll0#', 'Password SMTP o App Password'),
('smtp_port', '465', 'Porta SMTP (25=plain, 465=SSL, 587=STARTTLS)'),
('smtp_test_email', 'antonello.orru@wetechs.it', 'Indirizzo per il test di invio'),
('smtp_timeout', '15', 'Timeout connessione in secondi'),
('smtp_user', 'test@aoss.eu', 'Username SMTP (solitamente l\'email completa)'),
('smtp_verified', '1777459359', 'Ultimo test SMTP riuscito (timestamp o 0)'),
('success_color', '#10b981', NULL),
('warning_color', '#f59e0b', NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `branding_settings`
--

CREATE TABLE `branding_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(82, 'ZEBRA', 'JARLTECH / BLUESTAR / DACOM', NULL, 'PREMIUM', 0, 0, 0, 'SIMONE CUMUZZO', 'simone.comuzzo@zebra.com', '3357320410', NULL, 'MACINAI ALESSANDRO', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 15:27:12', 3, '#3b82f6'),
(83, '2N by Axis', 'societa\' controllata da Axis ', NULL, 'Basic', 0, 0, 0, 'Claudio Esposito', '', '', '', '', '', '', '', '', NULL, '', '', NULL, 'https://academy.2n.com/it', '', '', '2026-04-08 10:26:10', 3, '#3b82f6'),
(84, 'Google', '', NULL, '', 0, 0, 0, '', '', '', '', '', '', '', '', '', NULL, '', '', NULL, '', '', '', '2026-04-13 12:39:38', 3, '#3b82f6'),
(89, 'Broadcom', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-13 22:07:04', 3, '#3b82f6'),
(90, 'Cato Networks', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-13 22:07:04', 3, '#3b82f6'),
(91, 'LinkedIn', 'Brand creato automaticamente da import LinkedIn', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 10:34:48', 3, '#3b82f6'),
(92, 'Disney Programs', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 10:51:14', 3, '#3b82f6'),
(93, 'Oracle', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 10:53:33', 3, '#3b82f6'),
(94, 'Red Hat', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:06:27', 3, '#3b82f6'),
(95, 'Palo Alto Networks', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:08:27', 3, '#3b82f6'),
(96, 'Dell Technologies', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:08:27', 3, '#3b82f6'),
(97, 'Amazon Web Services Training and Certification', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:12:44', 3, '#3b82f6'),
(98, 'IBM', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:12:44', 3, '#3b82f6'),
(99, 'The Linux Foundation', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:19:23', 3, '#3b82f6'),
(100, 'NI (National Instruments)', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:19:29', 3, '#3b82f6'),
(101, 'Hewlett Packard Enterprise', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:19:31', 3, '#3b82f6'),
(102, 'SORINT.lab S.p.A.', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:20:25', 3, '#3b82f6'),
(103, 'IBM SkillsBuild', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:41:38', 3, '#3b82f6'),
(104, 'IBM Professional Certification', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:49:44', 3, '#3b82f6'),
(105, 'Acronis', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:49:54', 3, '#3b82f6'),
(106, 'WatchGuard Technologies', 'Brand creato automaticamente da import Credly', NULL, 'Registered', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-15 13:10:17', 3, '#3b82f6');

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
(5, NULL, '{\"type\":\"permission_change\",\"role_id\":2,\"previous\":[\"manage_companies.php\",\"manage_work_modes.php\",\"programmazione.php\",\"recruiting_agenzie.php\",\"recruiting_candidati.php\",\"recruiting_contratti.php\",\"recruiting_posizioni.php\",\"settings.php\",\"training_plans.php\",\"upload_certificato.php\",\"visualizza_storico.php\"]}', 1, '2026-03-30 04:46:39'),
(6, NULL, '{\"type\":\"permission_change\",\"role_id\":5,\"previous\":[\"brand_technologies.php\",\"brand.php\",\"candidato_profilo.php\",\"publish_posizione.php\",\"recruiting_agenzie.php\",\"recruiting_candidati.php\",\"recruiting_posizioni.php\",\"report_certificazioni.php\",\"training_plans.php\",\"visualizza_storico.php\"]}', 1, '2026-04-07 09:19:50'),
(7, NULL, '{\"type\":\"permission_change\",\"role_id\":2,\"previous\":[\"brand_distributors.php\",\"brand_referents.php\",\"brand_technologies.php\",\"brand.php\",\"candidato_profilo.php\",\"config_notifiche.php\",\"gap_analysis.php\",\"manage_companies.php\",\"manage_employees.php\",\"manage_work_modes.php\",\"manager_users.php\",\"mass_upload.php\",\"programmazione.php\",\"publish_posizione.php\",\"recruiting_agenzie.php\",\"recruiting_candidati.php\",\"recruiting_contratti.php\",\"recruiting_posizioni.php\",\"report_certificazioni.php\",\"settings.php\",\"training_plans.php\",\"upload_certificato.php\",\"visualizza_storico.php\"]}', 1, '2026-04-07 09:20:32'),
(8, 83, '{\"id\":83,\"name\":\"2N by Axis\",\"description\":\"societa\' controllata da Axis \",\"logo_path\":null,\"partnership_level\":\"Basic\",\"req_company\":0,\"req_commercial\":0,\"req_technical\":0,\"pam_name\":\"Claudio Esposito\",\"pam_email\":\"\",\"pam_phone\":\"\",\"pam_phone2\":\"\",\"internal_bm_name\":\"\",\"internal_bm_email\":\"\",\"internal_bm_phone\":\"\",\"brand_sl_name\":\"\",\"brand_sl_email\":\"\",\"brand_sl_phone\":null,\"internal_sl_name\":\"\",\"internal_sl_email\":\"\",\"internal_sl_phone\":null,\"learning_link\":\"https:\\/\\/academy.2n.com\\/it\",\"tech_doc_link\":\"\",\"partner_portal_link\":\"\",\"created_at\":\"2026-04-08 12:26:10\",\"priority\":3,\"priority_color\":\"#3b82f6\"}', 1, '2026-04-09 09:30:48'),
(9, NULL, '{\"type\":\"permission_change\",\"role_id\":6,\"previous\":[\"programmazione.php\",\"report_certificazioni.php\",\"segreteria.php\",\"training_plans.php\",\"upload_certificato.php\",\"visualizza_storico.php\"]}', 1, '2026-04-09 10:48:07'),
(10, NULL, '{\"type\":\"permission_change\",\"role_id\":7,\"previous\":[]}', 1, '2026-04-14 08:41:29');

-- --------------------------------------------------------

--
-- Struttura della tabella `brand_distributors`
--

CREATE TABLE `brand_distributors` (
  `id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `distributor_id` int(11) NOT NULL,
  `ranking` enum('primary','secondary') NOT NULL DEFAULT 'primary',
  `priority_order` tinyint(4) NOT NULL DEFAULT 1,
  `is_volume` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Acquisto a Volume',
  `is_value` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Acquisto a Valore',
  `is_academy` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Academy/Formazione',
  `commercial_ref` varchar(150) DEFAULT NULL,
  `commercial_email` varchar(150) DEFAULT NULL,
  `commercial_phone` varchar(30) DEFAULT NULL,
  `academy_ref` varchar(150) DEFAULT NULL,
  `academy_email` varchar(150) DEFAULT NULL,
  `academy_phone` varchar(30) DEFAULT NULL,
  `contract_ref` varchar(100) DEFAULT NULL,
  `discount_pct` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `brand_distributors`
--

INSERT INTO `brand_distributors` (`id`, `brand_id`, `distributor_id`, `ranking`, `priority_order`, `is_volume`, `is_value`, `is_academy`, `commercial_ref`, `commercial_email`, `commercial_phone`, `academy_ref`, `academy_email`, `academy_phone`, `contract_ref`, `discount_pct`, `notes`, `created_at`) VALUES
(1, 47, 1, 'primary', 1, 1, 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 08:01:22'),
(2, 80, 1, 'primary', 1, 0, 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 08:53:47'),
(3, 53, 2, 'primary', 1, 1, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 08:57:46'),
(4, 47, 2, 'primary', 2, 0, 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 08:58:16'),
(5, 80, 2, 'primary', 2, 0, 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 08:58:30'),
(6, 56, 4, 'primary', 1, 0, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:53:56'),
(7, 56, 3, 'secondary', 2, 0, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:54:23'),
(8, 61, 3, 'secondary', 2, 0, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:55:03'),
(9, 60, 3, 'secondary', 2, 1, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:55:56'),
(10, 59, 3, 'secondary', 2, 0, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:56:20'),
(11, 60, 5, 'primary', 1, 1, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:57:18'),
(12, 61, 5, 'primary', 1, 0, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:57:31'),
(13, 59, 5, 'primary', 1, 0, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:57:43'),
(14, 63, 5, 'primary', 1, 0, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:57:59');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `synced_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `brand_referents`
--

INSERT INTO `brand_referents` (`id`, `brand_id`, `employee_id`, `role_type`, `start_date`, `end_date`, `notes`, `created_at`, `synced_at`) VALUES
(1, 44, 2, 'referente_formazione', '2026-03-31', NULL, NULL, '2026-03-31 07:43:51', NULL),
(2, 45, 2, 'referente_formazione', '2026-03-31', NULL, NULL, '2026-03-31 07:44:02', NULL),
(3, 46, 2, 'referente_formazione', '2026-03-31', NULL, 'Tecnica', '2026-03-31 07:44:29', NULL),
(4, 47, 2, 'referente_formazione', '2026-03-31', NULL, 'Tecnica', '2026-03-31 07:44:48', NULL);

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

--
-- Dump dei dati per la tabella `brand_technologies`
--

INSERT INTO `brand_technologies` (`id`, `brand_id`, `category`, `name`, `description`, `version`, `status`, `doc_url`, `relevance`, `notes`, `created_at`, `updated_at`) VALUES
(1, 83, 'Prodotto', 'Video citofoni', 'Sistemi di comunicazione video citofoni ip Voip', NULL, 'active', NULL, 4, 'venduti per autostrade', '2026-04-08 10:31:02', '2026-04-08 10:31:02');

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
  `soft_skills_notes` text DEFAULT NULL COMMENT 'Note su soft skills / carattere',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `candidates`
--

INSERT INTO `candidates` (`id`, `first_name`, `last_name`, `email`, `phone`, `linkedin_url`, `ral_requested`, `notice_period`, `cv_path`, `skills_tags`, `source`, `agency_id`, `agency_contact_id`, `gdpr_consent`, `gdpr_date`, `gdpr_expiry`, `status`, `notes`, `added_by`, `created_at`, `education_level`, `education_field`, `education_institute`, `education_year`, `external_certs`, `test_path`, `lettera_path`, `doc_extra_path`, `soft_skills_notes`, `deleted_at`, `deleted_by`) VALUES
(1, 'ANTONELLO', 'ORRU', 'antonello.orru@gmail.com', '3477365191', NULL, 100000.00, '90 giorni', NULL, NULL, 'LinkedIn', NULL, NULL, 1, '2026-03-30', '2027-03-30', 'new', NULL, 1, '2026-03-30 07:42:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-10 22:55:01', 1),
(2, 'ANTONELLO', 'ORRU', 'antonello.orru@gmail.com', '3477365191', NULL, 100000.00, '90 giorni', 'cand_2_cv_1775819965.docx', 'tante e diverse', 'LinkedIn', NULL, NULL, 1, '2026-03-30', '2027-03-30', 'new', NULL, 1, '2026-03-30 08:50:38', 'Laurea triennale', 'Ingegneria della pazienza', 'Università del Caos', '2012', NULL, NULL, NULL, NULL, 'tante', '2026-04-10 22:54:57', 1),
(3, 'ANTONELLO', 'ORRU TEST', 'TES@GMAIL.COM', '92836427834', NULL, 100000.00, '3', NULL, NULL, 'LinkedIn', NULL, NULL, 1, '2026-04-10', '2027-04-10', 'new', NULL, 1, '2026-04-10 10:46:53', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-10 22:54:53', 1),
(4, 'ANTONELLO', 'ORRU TEST', 'TES@GMAIL.COM', '92836427834', NULL, 100000.00, '3', NULL, NULL, 'LinkedIn', NULL, NULL, 1, '2026-04-10', '2027-04-10', 'new', NULL, 1, '2026-04-10 11:02:57', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-10 22:54:50', 1),
(5, 'Antonello Test', 'Test Orru', 'gfhfhalksc@gfgf.com', '2873468234', NULL, 100000.00, NULL, NULL, NULL, 'LinkedIn', NULL, NULL, 1, '2026-04-10', '2027-04-10', 'new', NULL, 1, '2026-04-10 11:05:10', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-10 22:54:46', 1),
(6, 'ANTONELLO', 'ORRUTEST', 'antonello.orru@gmail.com', '3333222233', NULL, 100000.00, NULL, NULL, NULL, 'LinkedIn', NULL, NULL, 1, '2026-04-10', '2027-04-10', 'new', NULL, 1, '2026-04-10 20:55:46', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(7, 'Nicola', 'Dell\'Artino', NULL, NULL, NULL, 39000.00, NULL, NULL, NULL, 'Agenzia', 6, NULL, 1, '2026-04-30', '2027-04-30', 'new', 'attuale 39k + buoni pasto di 8€ welfare e bonus 1k, desiderata 45000 € lavoro dal lun-ven full presenza', 1, '2026-04-30 08:44:45', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'NICO', 'BORGOGNI', 'nikochan81@gmail.com', '3404851421', NULL, NULL, NULL, 'cand_8_cv_1777545609.pdf', NULL, 'Agenzia', 5, NULL, 1, '2026-04-30', '2027-04-30', 'new', NULL, 1, '2026-04-30 10:37:09', 'Diploma', 'PERITO ELETTRONICO', 'I.T.I.S. GALILEO GALILEI AREZZO', '2002', NULL, 'cand_8_test_psicologico_1777545686.pdf', NULL, NULL, NULL, NULL, NULL);

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

--
-- Dump dei dati per la tabella `candidate_applications`
--

INSERT INTO `candidate_applications` (`id`, `candidate_id`, `position_id`, `stage`, `match_score`, `rejection_reason`, `stage_updated_at`, `created_at`) VALUES
(1, 6, 2, 'cv_received', 50, NULL, '2026-04-10 20:55:46', '2026-04-10 20:55:46'),
(2, 7, 5, 'cv_received', NULL, NULL, '2026-04-30 08:44:45', '2026-04-30 08:44:45'),
(3, 8, 7, 'cv_received', NULL, NULL, '2026-04-30 10:37:09', '2026-04-30 10:37:09');

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
  `level` enum('Foundation','Associate','Professional','Expert','Specialty','Specialist') DEFAULT NULL,
  `validity_months` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Note interne libere',
  `exam_url` varchar(255) DEFAULT NULL,
  `credly_template_id` varchar(64) DEFAULT NULL COMMENT 'UUID badge_template Credly per mapping diretto',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `renewal_policy` varchar(200) DEFAULT NULL,
  `exam_cost` decimal(8,2) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `certifications`
--

INSERT INTO `certifications` (`id`, `brand_id`, `technology_id`, `name`, `code`, `category`, `level`, `validity_months`, `description`, `notes`, `exam_url`, `credly_template_id`, `is_active`, `created_at`, `renewal_policy`, `exam_cost`, `updated_at`, `updated_by`) VALUES
(1, 84, 7, 'Cloud Digital Leader (Livello base)', 'GCP-CDL', 'tecnica', '', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:47', NULL, NULL, '2026-04-14 07:47:34', NULL),
(2, 84, 7, 'Associate Cloud Engineer', 'GCP-ACE', 'tecnica', 'Professional', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:47', NULL, NULL, '2026-04-14 07:47:34', NULL),
(3, 84, 7, 'Professional Cloud Architect', 'GCP-PCA', 'tecnica', 'Professional', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(4, 84, 7, 'Professional Data Engineer', 'GCP-PDE', 'tecnica', 'Professional', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(5, 84, 7, 'Professional Cloud Developer', 'GCP-PCD', 'tecnica', 'Professional', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(6, 84, 7, 'Professional Cloud Security Engineer', 'GCP-PCSE', 'tecnica', 'Professional', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(7, 84, 7, 'Generative AI Leader [1, 2, 3, 4, 5]', NULL, 'tecnica', 'Professional', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(8, 47, 8, 'CCT Collaboration', 'Exam 100-890 CLTECH', 'tecnica', '', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(9, 47, 8, 'CCT Data Center', 'Exam 010-151 DCTECH', 'tecnica', '', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(10, 47, 8, 'CCT Routing & Switching', 'Exam 100-490 RSTECH', 'tecnica', '', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(11, 47, 8, 'CCNP Enterprise', 'Core 350-401 ENCOR', 'tecnica', 'Professional', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(12, 47, 8, 'CCNP Security', 'Core 350-701 SCOR', 'tecnica', 'Professional', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(13, 47, 8, 'CCNP Data Center', 'Core 350-601 DCCOR', 'tecnica', 'Professional', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(14, 47, 8, 'CCNP Collaboration', 'Core 350-801 CLCOR', 'tecnica', 'Professional', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(15, 47, 8, 'CCNP Service Provider', 'Core 350-501 SPCOR', 'tecnica', 'Professional', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(16, 47, 8, 'Cisco Certified DevNet Professional', 'Core 350-901 DEVCOR', 'tecnica', 'Professional', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(17, 47, 8, 'CCNA', 'Exam 200-301', 'tecnica', 'Associate', 36, NULL, NULL, NULL, '59876d7c-8286-4186-b234-5afc23d51b06', 1, '2026-04-13 13:56:48', NULL, NULL, '2026-05-13 22:00:42', NULL),
(18, 47, 8, 'CyberOps Associate', 'Exam 200-201 CBROPS', 'tecnica', 'Associate', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(19, 47, 8, 'DevNet Associate', 'Exam 200-901 DEVASC', 'tecnica', 'Associate', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(20, 47, 8, 'CCIE Enterprise Infrastructure', NULL, 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(21, 47, 8, 'CCIE Enterprise Wireless', NULL, 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(22, 47, 8, 'CCIE Security', NULL, 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(23, 47, 8, 'CCIE Data Center', NULL, 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(24, 47, 8, 'CCIE Collaboration', NULL, 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(25, 47, 8, 'CCIE Service Provider', NULL, 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(26, 47, 8, 'Cisco Certified DevNet Expert', NULL, 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(27, 56, 8, 'FCF Fundamentals (FCF)', 'NSE2', 'tecnica', NULL, 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(28, 56, 8, 'FCA Associate (FCA)', 'NSE4', 'tecnica', NULL, 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(29, 56, 8, 'FCP Professional (FCP)', 'NSE6', 'tecnica', 'Professional', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(30, 56, 8, 'FCSS Solution Specialist (FCSS)', 'NSE7', 'tecnica', '', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(31, 56, 8, 'FCX Expert (FCX)', 'NSE8', 'tecnica', 'Expert', 24, NULL, NULL, NULL, NULL, 1, '2026-04-13 13:56:48', NULL, NULL, '2026-04-14 07:47:34', NULL),
(32, 84, 1, 'Google Cloud Fundamentals: Core Infrastructure', 'GCF-CI', 'tecnica', 'Foundation', 24, NULL, NULL, NULL, NULL, 1, '2026-04-14 07:50:36', NULL, NULL, '2026-04-14 07:50:36', NULL),
(33, 80, 1, 'VMware Cloud Foundation Administrator (2V0-17.25)', '2V0-17.25', 'tecnica', NULL, 24, 'Certificazione VCF9 Implemetantion  a seguire deve avvenire il colloquio expert specifico', NULL, NULL, NULL, 1, '2026-04-14 08:34:44', 'Ricertificazione obbligatoria ogni 24 mesi', 250.00, '2026-04-14 08:34:44', NULL),
(90, 61, 9, 'HPE Master Accredited Solutions Expert', 'HPE Master ASE', 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:12:09', NULL, NULL, '2026-05-07 16:12:09', NULL),
(91, 61, 9, 'HPE Accredited Solutions Expert', 'HPE ASE', 'tecnica', 'Expert', 36, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:12:09', NULL, NULL, '2026-05-07 16:12:09', NULL),
(92, 61, 9, 'HPE Accredited Technical Professional', 'HPE ATP', 'tecnica', 'Professional', 36, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:12:09', NULL, NULL, '2026-05-07 16:12:09', NULL),
(93, 56, 3, 'Fortinet Certified Fundamentals', 'FCF', 'tecnica', 'Foundation', 24, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(94, 56, 3, 'Fortinet Certified Associate', 'FCA', 'tecnica', 'Expert', 24, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(95, 56, 3, 'Fortinet Certified Professional - Security Operations', 'FCP', 'tecnica', 'Professional', 24, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(96, 56, 9, 'Fortinet Certified Solution Specialist', 'FCSS', 'tecnica', 'Expert', 24, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(97, 56, 10, 'Fortinet Certified Expert', 'FCX', 'tecnica', 'Expert', 24, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(98, 63, 11, 'Microsoft Azure Fundamentals', 'AZ-900', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(99, 63, 11, 'Microsoft Azure Administrator', 'AZ-104', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(100, 63, 11, 'Azure Solutions Architect Expert', 'AZ-305', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(101, 63, 11, 'Designing/Implementing DevOps', 'AZ-400', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(102, 63, 11, 'Azure Security Engineer Associate', 'AZ-500', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(103, 63, 11, 'Azure AI Engineer Associate', 'AI-102', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(104, 63, 12, 'Power BI Data Analyst Associate', 'PL-300', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(105, 63, 11, 'Microsoft 365 Administrator Expert', 'MS-102', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(106, 63, 11, 'Endpoint Administrator Associate', 'MD-102', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(107, 63, 3, 'Cybersecurity Architect Expert', 'SC-100', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(108, 63, 11, 'Microsoft Technology Associate', 'MTA', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(109, 63, 11, 'Microsoft Certified Solutions Associate', 'MCSA', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(110, 59, 8, 'Aruba Certified Switching Associate', 'ACSA', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(111, 59, 8, 'Aruba Certified Mobility Associate', 'ACMA', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(112, 59, 8, 'Aruba Certified ClearPass Associate', 'ACCA', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(113, 59, 8, 'Aruba Certified ClearPass Professional', 'ACCP', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(114, 59, 8, 'Aruba Certified Switching Professional', 'ACSP', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(115, 59, 8, 'Aruba Certified Mobility Professional', 'ACMP', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(116, 59, 8, 'Aruba Certified Design Professional', 'ACDP', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(117, 59, 8, 'Aruba Certified Mobility Expert', 'ACMX', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(118, 59, 8, 'Aruba Certified Switching Expert', 'ACSE', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(119, 59, 8, 'Aruba Certified Edge Expert', 'ACEX', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(120, 59, 8, 'HPE Aruba Networking Certified Professional - Campus Access', 'ACP-CA', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(121, 59, 8, 'HPE Aruba Networking Certified Professional - Data Center', 'ACP-DC', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(122, 59, 8, 'Aruba Certified Switching Professional', 'ACSP (Legacy)', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(123, 59, 8, 'Aruba Certified Mobility Associate', 'ACMA (Legacy)', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(124, 59, 8, 'Aruba Mobility and Fabric Expert', 'AMFX', 'tecnica', 'Professional', NULL, NULL, NULL, NULL, NULL, 1, '2026-05-07 16:16:18', NULL, NULL, '2026-05-07 16:16:18', NULL),
(125, 61, 9, 'GreenLake fundamentals', 'SaleGreenLake', 'commerciale', 'Foundation', NULL, 'CL Badge', NULL, NULL, NULL, 1, '2026-05-13 08:16:52', NULL, NULL, '2026-05-13 09:14:46', 1),
(126, 61, 4, 'HPE Sales Certified - Compute Solutions [2026]', 'HPE S-CCS', 'commerciale', 'Associate', NULL, 'GreenLake fundamentals - Sales Compute Certified Individuals', NULL, NULL, NULL, 1, '2026-05-13 08:19:26', NULL, NULL, '2026-05-13 08:19:26', NULL),
(127, 47, 13, 'Cisco AI Technical Practitioner', 'cisco-ai-technical-practitioner', 'tecnica', NULL, NULL, 'This badge earner demonstrates foundational to intermediate AI skills in effectively designing technical solutions, automating tasks, and leading technical teams using AI tools and methodologies. They can assess and apply AI use cases, monitor and optimize AI-powered workflows, evaluate AI infrastructure, and apply responsible AI principles. They understand AI ethics, data pipelines, and the architectural components that support scalable, enterprise-grade AI deployments.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-ai-technical-practitioner', NULL, 'https://www.credly.com/org/cisco/badge/cisco-ai-technical-practitioner', 'c444f85f-025f-4408-9015-cdda40bcae60', 1, '2026-05-13 22:07:03', NULL, NULL, '2026-05-13 22:07:03', 1),
(128, 47, 13, 'Cisco AI Business Practitioner', 'cisco-ai-business-practitioner', 'tecnica', NULL, NULL, 'This badge earner empowers business innovation by strategically applying generative AI to enhance communication, ideation, and analysis. They have demonstrated practical skills in evaluating AI platforms, precise prompt engineering, AI-assisted research, ideation & content creation, integrating AI tools into workflows, and applying responsible and ethical AI practices. By effectively applying generative AI in business contexts, they are positioned for long term success in an AI-enabled world.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-ai-business-practitioner', NULL, 'https://www.credly.com/org/cisco/badge/cisco-ai-business-practitioner', '2703e463-b0d8-4ce5-a941-bbd12d40aa1c', 1, '2026-05-13 22:07:03', NULL, NULL, '2026-05-13 22:07:03', 1),
(129, 47, 13, 'Introduction to Network Simulations with Cisco Modeling Labs', 'introduction-to-network-simulations-with-cisco-mod', 'tecnica', NULL, NULL, 'This badge earner possesses foundational and practical skills in network simulation, proficiently using Cisco Modeling Labs to design, build, and manage virtual network topologies. They can configure virtual network devices, connect simulations to external networks, capture traffic, and leverage CML for effective network design, testing, and hands-on learning of complex networking concepts.\n\nFonte: https://www.credly.com/org/cisco/badge/introduction-to-network-simulations-with-cisco-mode', NULL, 'https://www.credly.com/org/cisco/badge/introduction-to-network-simulations-with-cisco-mode', '190bfe58-43c0-4217-a0b2-79e289655e10', 1, '2026-05-13 22:07:03', NULL, NULL, '2026-05-13 22:07:03', 1),
(130, 47, 13, 'Understanding Cisco Network Automation Essentials', 'understanding-cisco-network-automation-essentials', 'tecnica', 'Foundation', NULL, 'This badge earner has core knowledge of network automation fundamentals, such as the NetDevOps environment, data encoding formats (XML, JSON, and YAML), and scripting with Python. They have demonstrated practical skills in applying automation tooling (Ansible) and model-driven programmability using protocols (NETCONF, RESTCONF, gNMI) and data models (YANG) to scale and streamline network operations. This individual is ready to support the demands of modern network environments.\n\nFonte: https://www.credly.com/org/cisco/badge/understanding-cisco-network-automation-essentials', NULL, 'https://www.credly.com/org/cisco/badge/understanding-cisco-network-automation-essentials', '6018ef0b-d33e-403d-9b9f-b516747e5c50', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(131, 47, 13, 'AI Solutions on Cisco Infrastructure Essentials', 'ai-solutions-on-cisco-infrastructure-essentials.1', 'tecnica', 'Foundation', NULL, 'This badge earner has demonstrated core technical knowledge in AI fundamentals, infrastructure, and network architectures, enabling them to design and optimize AI-ready environments. They have essential skills architecting infrastructure for AI workloads, managing data, selecting hardware for AI clusters, and ensuring compliant, high-performing AI solutions.\n\nFonte: https://www.credly.com/org/cisco/badge/ai-solutions-on-cisco-infrastructure-essentials.1', NULL, 'https://www.credly.com/org/cisco/badge/ai-solutions-on-cisco-infrastructure-essentials.1', 'c82146dd-fb76-415b-9f15-ea4047daba01', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(132, 47, 13, 'Cisco Certificate in Ethical Hacking', 'cisco-certificate-in-ethical-hacking', 'tecnica', NULL, NULL, 'The Cisco Certificate in Ethical Hacking badge recognizes individuals who have successfully completed the Ethical Hacker course and a rigorous Capture the Flag challenge, demonstrating proficiency in identifying and addressing security vulnerabilities using ethical hacking techniques.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certificate-in-ethical-hacking', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certificate-in-ethical-hacking', '28bbd21e-dc21-4ac9-9b8b-0e2e60c4675e', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(133, 47, 13, 'Offensive Security Capture the Flag - DNS Event', 'offensive-security-capture-the-flag-dns-event.1', 'tecnica', NULL, NULL, 'The Offensive Security Capture the Flag (Series 1) DNS Event badge is awarded to participants who successfully complete an exclusive quarterly-themed Capture the Flag challenge between Oct 1-Dec 31, 2024. Holders of this badge are recognized for demonstrating advanced skills in ethical hacking, problem-solving, and cybersecurity tactics, showcasing their ability to identify and exploit security vulnerabilities in a controlled environment.\n\nFonte: https://www.credly.com/org/cisco/badge/offensive-security-capture-the-flag-dns-event.1', NULL, 'https://www.credly.com/org/cisco/badge/offensive-security-capture-the-flag-dns-event.1', 'a81a772e-d138-4d42-8d5b-ce5120c0eac1', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(134, 47, 13, 'Ethical Hacker', 'ethical-hacker', 'tecnica', NULL, NULL, 'Cisco verifies the earner of this badge successfully completed the Ethical Hacker course. The holder of this student level credential has a broad understanding of the legal and compliance requirements and is proficient in the art of scoping, executing, reporting vulnerability assessments, and recommending mitigation strategies. The holder has completed up to 34 hands-on activities using Kali Linux, WebSploit, and other tools.\n\nFonte: https://www.credly.com/org/cisco/badge/ethical-hacker', NULL, 'https://www.credly.com/org/cisco/badge/ethical-hacker', '2aff6d71-ae6f-44cb-a8ec-5349f807d49b', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(135, 56, 13, 'Fortinet Certified Professional Secure Networking', 'fortinet-certified-professional-secure-networking', 'tecnica', 'Professional', NULL, 'The Fortinet Certified Professional in Secure Networking certification validates the earner\'s ability to secure networks and applications by deploying, managing, and monitoring Fortinet network security products.\n\nFonte: https://www.credly.com/org/fortinet/badge/fortinet-certified-professional-secure-networking', NULL, 'https://www.credly.com/org/fortinet/badge/fortinet-certified-professional-secure-networking', 'b5fbfd37-39c9-4800-a348-75400f199f8f', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(136, 56, 13, 'Fortinet FortiAnalyzer 7.2 Administrator', 'fortinet-fortianalyzer-7-2-administrator', 'tecnica', NULL, NULL, 'The FortiAnalyzer 7.2 Administrator exam badge recognizes expertise in network security management using FortiAnalyzer. The badge earner has demonstrated knowledge of FortiAnalyzer configuration, operation, and day-to-day administration.\n\nFonte: https://www.credly.com/org/fortinet/badge/fortinet-fortianalyzer-7-2-administrator', NULL, 'https://www.credly.com/org/fortinet/badge/fortinet-fortianalyzer-7-2-administrator', 'b1be0714-51d3-4b2d-bf29-06f88bd66d17', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(137, 56, 13, 'Fortinet Certified Associate Cybersecurity', 'fortinet-certified-associate-cybersecurity.1', 'tecnica', 'Associate', NULL, 'The Fortinet Certified Associate in Cybersecurity certification validates that the earner can run high-level operations on a FortiGate device. The curriculum covers the fundamentals of operating the most common FortiGate features.\n\nFonte: https://www.credly.com/org/fortinet/badge/fortinet-certified-associate-cybersecurity.1', NULL, 'https://www.credly.com/org/fortinet/badge/fortinet-certified-associate-cybersecurity.1', '16abd220-eac0-469f-b15f-300c9d97a343', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(138, 56, 13, 'Fortinet FortiGate 7.4 Operator', 'fortinet-fortigate-7-4-operator', 'tecnica', NULL, NULL, 'The FortiGate 7.4 Operator exam badge recognizes expertise in the common features of implementing and operating a FortiGate device. The badge earner has demonstrated knowledge of basic configuration, operation, and day-to-day administration.\n\nFonte: https://www.credly.com/org/fortinet/badge/fortinet-fortigate-7-4-operator', NULL, 'https://www.credly.com/org/fortinet/badge/fortinet-fortigate-7-4-operator', 'a988c07d-dd6c-4249-8f01-2fa8f9f3acdb', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(139, 56, 13, 'Fortinet FortiGate 7.2 Administrator', 'fortinet-fortigate-7-2-administrator', 'tecnica', NULL, NULL, 'The FortiGate 7.2 Administrator exam badge recognizes expertise in FortiGate administration. The badge earner has demonstrated knowledge of FortiGate configuration, operation, and day-to-day administration.\n\nFonte: https://www.credly.com/org/fortinet/badge/fortinet-fortigate-7-2-administrator', NULL, 'https://www.credly.com/org/fortinet/badge/fortinet-fortigate-7-2-administrator', '20576877-1003-444c-91c8-b7aba603253e', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(140, 89, 13, 'VMware Certified Professional - Network Virtualization 2023', 'vmware-certified-professional-network-virtualizati', 'tecnica', 'Professional', NULL, 'The VMware Certified Professional - Network Virtualization certification proves you can transform the economics of network and security operations for your company. VMware Certified Professional - Network Virtualization certification validates your ability to install, configure, and administer NSX virtual networking implementations, regardless of the underlying physical architecture.\n\nFonte: https://www.credly.com/org/broadcom/badge/vmware-certified-professional-network-virtualization-2023', NULL, 'https://www.credly.com/org/broadcom/badge/vmware-certified-professional-network-virtualization-2023', 'a78ce6a7-521d-4e2f-9408-386d3701bd6e', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(141, 56, 13, 'Fortinet Certified Fundamentals Cybersecurity', 'fortinet-certified-fundamentals-cybersecurity', 'tecnica', NULL, NULL, 'The Fortinet Certified Fundamentals in Cybersecurity certification validates that the earner has mastered the technical skills and knowledge that are required for any entry-level job role in cybersecurity. The curriculum covers today’s threat landscape and the fundamentals of cybersecurity.\n\nFonte: https://www.credly.com/org/fortinet/badge/fortinet-certified-fundamentals-cybersecurity', NULL, 'https://www.credly.com/org/fortinet/badge/fortinet-certified-fundamentals-cybersecurity', 'f6159ec0-43b2-444b-a1e0-18a323f4833f', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(142, 56, 13, 'Getting Started in Cybersecurity 1.0', 'getting-started-in-cybersecurity-1-0', 'tecnica', NULL, NULL, 'The Getting Started in Cybersecurity 1.0 badge recognizes a basic understanding of cybersecurity. The badge earner has demonstrated knowledge of cybersecurity terminology and concepts, and has a fundamental understanding of key cybersecurity topics, technologies, and products.\n\nFonte: https://www.credly.com/org/fortinet/badge/getting-started-in-cybersecurity-1-0', NULL, 'https://www.credly.com/org/fortinet/badge/getting-started-in-cybersecurity-1-0', '5bdbc502-ac97-451f-8b91-75bff4049cb9', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(143, 56, 13, 'Introduction to the Threat Landscape 1.0', 'introduction-to-the-threat-landscape-1-0', 'tecnica', NULL, NULL, 'The Introduction to the Threat Landscape 1.0 badge recognizes a fundamental understanding of the cyber threat landscape. The badge earner has demonstrated essential knowledge of the threats that endanger computer networks, the cast of bad actors who are behind cyber threats, and the cyber security principles that can keep users and computer networks safe.\n\nFonte: https://www.credly.com/org/fortinet/badge/introduction-to-the-threat-landscape-1-0', NULL, 'https://www.credly.com/org/fortinet/badge/introduction-to-the-threat-landscape-1-0', '227d4f08-6330-4d6b-8782-6bfb11159d51', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(144, 90, 13, 'Cato Certified Associate', 'cato-certified-associate', 'tecnica', 'Associate', NULL, 'Completing the Cato Certified Associate course validates your technical knowledge. This badge represents that you have completed the Associate level course, and that you have completed the certification exams. You understand the concepts and theory of items such as SD-WAN, ZTNA and SDP, and have demonstrated proficiency in using the Cato Management Application. You know how to deploy sites, connect your remote users. and how to protect your network from attackers and malicious threats.\n\nFonte: https://www.credly.com/org/cato-networks/badge/cato-certified-associate', NULL, 'https://www.credly.com/org/cato-networks/badge/cato-certified-associate', '691140ff-b1ad-421c-856d-b78d9deb7b69', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(145, 47, 13, 'Environmental Sustainability Practice-Building', 'environmental-sustainability-practice-building', 'tecnica', NULL, NULL, 'The 700-245 (ESPB) exam tests a candidate\'s knowledge of building an environmental sustainability practice.\n\nFonte: https://www.credly.com/org/cisco/badge/environmental-sustainability-practice-building', NULL, 'https://www.credly.com/org/cisco/badge/environmental-sustainability-practice-building', '1901be49-c67b-48e4-9840-aeb98e433d1f', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(146, 47, 13, 'Cisco Environmental Sustainability Overview', 'cisco-environmental-sustainability-overview', 'tecnica', NULL, NULL, 'The 700-240 CESO exam tests a candidate\'s knowledge of general environmental sustainability principles and the specific efforts being made by Cisco to support them.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-environmental-sustainability-overview', NULL, 'https://www.credly.com/org/cisco/badge/cisco-environmental-sustainability-overview', '6b89ff53-217f-4471-8c01-11eef9a687fc', 1, '2026-05-13 22:07:04', NULL, NULL, '2026-05-13 22:07:04', 1),
(147, 90, 13, 'SASE Expert Level 2', 'sase-expert-level-2', 'tecnica', 'Expert', NULL, 'Earning the SASE Expert Level 2 certification further validates your SASE know-how. This is an advanced badge that proves you have successfully concluded the second SASE certification course and passed the certification exam. You understand the unique capabilities of the SASE architecture and are particularly familiar with single-pass processing, global cloud and cloud network, the importance of native SD-WAN, security processing in the cloud vs. edge, and IPS-as-a-Service.\n\nFonte: https://www.credly.com/org/cato-networks/badge/sase-expert-level-2', NULL, 'https://www.credly.com/org/cato-networks/badge/sase-expert-level-2', 'f64706e8-611b-4182-872c-128a63a59ec1', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(148, 90, 13, 'SASE Expert Level 1', 'sase-expert-level-1', 'tecnica', 'Expert', NULL, 'Earning the SASE Expert, Level 1 certification validates your fundamental understanding of Gartner’s Secure Access Service Edge (SASE) framework. This is an entry-level badge that proves you have concluded the first SASE course successfully and passed the certification exam. You are familiar with the drivers, value, architecture, use cases, and network and security functions of SASE; and can demonstrate your knowledge to help drive the adoption of SASE within an enterprise.\n\nFonte: https://www.credly.com/org/cato-networks/badge/sase-expert-level-1', NULL, 'https://www.credly.com/org/cato-networks/badge/sase-expert-level-1', '5b18393f-de23-4972-8de7-046df682ff5b', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(149, 47, 13, 'Small Business Technical Overview', 'small-business-technical-overview', 'tecnica', NULL, NULL, 'The 700-755 SBTO exam tests a candidate\'s knowledge and skills to educate, deploy and activate small business solutions with particular emphasis on the Cisco Designed small business portfolio.\n\nFonte: https://www.credly.com/org/cisco/badge/small-business-technical-overview', NULL, 'https://www.credly.com/org/cisco/badge/small-business-technical-overview', '475d6541-8f29-49b6-b81d-b55c2415e26d', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(150, 47, 13, 'Understanding Design for Cisco Internetworking Solutions', 'understanding-design-for-cisco-internetworking-sol', 'tecnica', NULL, NULL, 'The holder of this credential has a strong foundation in the design of switched and routed network infrastructures and services involving LAN/WAN technologies for SMB or basic enterprise campus and branch networks.\n\nFonte: https://www.credly.com/org/cisco/badge/understanding-design-for-cisco-internetworking-solutions', NULL, 'https://www.credly.com/org/cisco/badge/understanding-design-for-cisco-internetworking-solutions', '50bf2406-4f9a-4d8c-8268-30a40afe44d1', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(151, 47, 13, 'Understanding of Cisco Network Devices', 'understanding-of-cisco-network-devices', 'tecnica', NULL, NULL, 'The holder of this credential has a strong foundation in network fundamentals, LAN switching technologies, IPv4 and IPv6 routing technologies, WAN technologies, infrastructure services, security, and management.\n\nFonte: https://www.credly.com/org/cisco/badge/understanding-of-cisco-network-devices', NULL, 'https://www.credly.com/org/cisco/badge/understanding-of-cisco-network-devices', '54033bcb-637a-4cc4-900f-8db194071ae2', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(152, 47, 13, 'Cisco Certified Specialist - Enterprise Wireless Design', 'cisco-certified-specialist-enterprise-wireless-des', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Enterprise Wireless Design have demonstrated knowledge of wireless network design including site surveys, wired and wireless infrastructure, mobility, and WLAN high availability.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-enterprise-wireless-design', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-enterprise-wireless-design', '7dd2f32e-d5d8-478b-b999-12243404494c', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(153, 47, 13, 'Cisco Certified Network Professional Security (CCNP Security)', 'cisco-certified-network-professional-security-ccnp', 'tecnica', 'Professional', NULL, 'This certification validates the skills required of professional-level network security engineers to choose, deploy, support and troubleshoot firewalls, VPNs, and IPS solutions for networking environments.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-network-professional-security-ccnp-security', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-network-professional-security-ccnp-security', 'ac579428-dda0-4491-86b9-e519f1ce7d24', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(154, 47, 13, 'Cisco Certified Specialist - Network Security Firewalls', 'cisco-certified-specialist-network-security-firewa', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Network Security Firewalls have demonstrated a candidate\'s knowledge of Cisco Firepower® Threat Defense and Firepower®, including policy configurations, integrations, deployments, management, and troubleshooting.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-network-security-firewal', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-network-security-firewal', 'b9154be1-ee3a-46a2-a387-6d0b48162754', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(155, 47, 13, 'Cisco Certified Specialist - Network Security VPN', 'cisco-certified-specialist-network-security-vpn', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Network Security VPN have demonstrated knowledge of implementing secure remote communications with Virtual Private Network (VPN) solutions including secure communications, architectures, and troubleshooting.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-network-security-vpn', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-network-security-vpn', 'a817cea3-fa81-49e4-ae64-28685a8092a2', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(156, 47, 13, 'Cisco Certified Specialist - Security Core', 'cisco-certified-specialist-security-core', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist -Security Core have demonstrated knowlege of implementing and operating core security technologies including network security, cloud security, content security, endpoint protection and detection, secure network access, visibility and enforcements.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-security-core', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-security-core', '2aba1ffb-e5cb-4951-bed5-2e5f4daff7b5', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(157, 47, 13, 'Cisco Certified Specialist - Security Identity Management', 'cisco-certified-specialist-security-identity-manag', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Security Identity Management have demonstrated knowledge of Cisco Identify Services Engine, including architecture and deployment, policy enforcement, Web Auth and guest services, profiler, BYOD, endpoint compliance, and network access device administration.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-security-identity-manage', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-security-identity-manage', '7bbc0730-ab31-4a7a-8698-7a156a463a13', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(158, 47, 13, 'Cisco Certified Specialist - Web Content Security', 'cisco-certified-specialist-web-content-security', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Security Identity Management have demonstrated knowledge of Cisco Web Security Appliance, including proxy services, authentication, decryption policies differentiated traffic access policies and identification policies, acceptable use control settings, malware defense, and data security and data loss prevention.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-web-content-security', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-web-content-security', 'b11c8a08-9204-45af-94ba-c634091dd710', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(159, 47, 13, 'Cisco Certified Specialist - Enterprise Design', 'cisco-certified-specialist-enterprise-design', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Enterprise Design have demonstrated knowledge of enterprise design including advanced addressing and routing solutions, advanced enterprise campus networks, WAN, security services, network services, and SDA.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-enterprise-design', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-enterprise-design', '463a9839-c97e-42c2-987d-a52a815ba540', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(160, 47, 13, 'Cisco Certified Network Professional Enterprise (CCNP Enterprise)', 'cisco-certified-network-professional-enterprise-cc', 'tecnica', 'Professional', NULL, 'Holders of CCNP Enterprise certification have demonstrated skills with enterprise networking solutions.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-network-professional-enterprise-ccnp-enterprise', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-network-professional-enterprise-ccnp-enterprise', '138e7e97-b2fc-417d-a69b-182fda54ec4c', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(161, 47, 13, 'Cisco Certified Specialist - Enterprise Advanced Infrastructure', 'cisco-certified-specialist-enterprise-advanced-inf', 'tecnica', 'Professional', NULL, 'Earners of Cisco Certified Specialist - Enterprise Advanced Infrastructure have demonstrated knowledge for implementation and troubleshooting of advanced routing technologies and services including Layer 3, VPN services, infrastructure security, infrastructure services, and infrastructure automation.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-enterprise-advanced-infr', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-enterprise-advanced-infr', '84ab53c1-08f9-464f-be6c-5d98a72e3fa9', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(162, 47, 13, 'Cisco Certified Specialist - Enterprise Core', 'cisco-certified-specialist-enterprise-core', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Enterprise Core have demonstrated knowledge of implementing core enterprise network technologies including dual stack (IPv4 and IPv6) architecture, virtualization, infrastructure, network assurance, security and automation.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-enterprise-core', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-enterprise-core', 'e6c7fce6-bd0c-438d-96bd-034a06f8ea97', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(163, 89, 13, 'Broadcom Partner Certification - Certified Expert - VCF Networking - Pre-Sales', 'broadcom-partner-certification-certified-expert-vc', 'tecnica', 'Expert', NULL, 'Earners of this certification have demonstrated their capability and experience in performing a Proof of Concept (POC) or Proof of Value (POV) engagement at a Customer site with the Broadcom product indicated. This certification is earned through the successful completion of a third-party proctored exam (70% or higher), OR through the submission of an application form that contains proof of at least two prior Customer engagements providing POC/POV services with the Broadcom product indicated.\n\nFonte: https://www.credly.com/org/broadcom/badge/broadcom-partner-certification-certified-expert-vcf.10', NULL, 'https://www.credly.com/org/broadcom/badge/broadcom-partner-certification-certified-expert-vcf.10', 'b442b730-e180-41c8-b72c-d450d5187b0b', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(164, 89, 13, 'Broadcom Partner Certification - Proven Professional - VCF Networking - Architecture', 'broadcom-partner-certification-proven-professional', 'tecnica', 'Professional', NULL, 'Earners of this Broadcom Partner certification have validated their understanding of foundational technical areas of study, including basic solution design concepts, for the Broadcom product indicated. This certification is earned through the successful completion of a third-party proctored exam (70% or higher).\n\nFonte: https://www.credly.com/org/broadcom/badge/broadcom-partner-certification-proven-professional-.150', NULL, 'https://www.credly.com/org/broadcom/badge/broadcom-partner-certification-proven-professional-.150', 'c45aa744-2f02-432b-a9d8-6859169e5d27', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(165, 89, 13, 'Broadcom Partner Certification - Proven Professional - VCF Networking - Implementation', 'broadcom-partner-certification-proven-professional', 'tecnica', 'Professional', NULL, 'Earners of this Broadcom Partner certification have validated their understanding of foundational technical areas of study, including basic installation and configuration concepts, for the Broadcom product indicated. This certification is earned through the successful completion of a third-party proctored exam (70% or higher).\n\nFonte: https://www.credly.com/org/broadcom/badge/broadcom-partner-certification-proven-professional-.126', NULL, 'https://www.credly.com/org/broadcom/badge/broadcom-partner-certification-proven-professional-.126', '4666d775-ed93-427d-ae42-f69c3c6d9892', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(166, 89, 13, 'Broadcom Partner Certification - Proven Professional - VCF Networking - Support', 'broadcom-partner-certification-proven-professional', 'tecnica', 'Professional', NULL, 'Earners of this Broadcom Partner certification have validated their understanding of foundational technical areas of study, including basic installation, configuration, and product support concepts, for the Broadcom product indicated. This certification is earned through the successful completion of a third-party proctored exam (70% or higher).\n\nFonte: https://www.credly.com/org/broadcom/badge/broadcom-partner-certification-proven-professional-.140', NULL, 'https://www.credly.com/org/broadcom/badge/broadcom-partner-certification-proven-professional-.140', 'a01b8067-3941-452f-a2a1-15a65d74ef03', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(167, 47, 13, 'Cisco Certified Design Professional (CCDP)', 'cisco-certified-design-professional-ccdp', 'tecnica', 'Professional', NULL, 'This certification validates the skills required of professional-level Network Engineers and Architects to design networks using advanced addressing and routing protocols, virtualization and integration strategies for multi-layered Enterprise architectures.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-design-professional-ccdp', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-design-professional-ccdp', '0cf2e977-6246-4d48-be9a-53e69255c110', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(168, 47, 13, 'Cisco Certified Network Professional Routing and Switching (CCNP Routing and Switching)', 'cisco-certified-network-professional-routing-and-s', 'tecnica', 'Professional', NULL, 'This certification validates the skills required of professional-level network engineers, support engineers, systems engineers or network technicians to plan, implement, verify and troubleshoot local and wide-area enterprise networks and work collaboratively with specialists on advanced security, voice, wireless and video solutions.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-network-professional-routing-and-switching-ccnp-routing-and-switching', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-network-professional-routing-and-switching-ccnp-routing-and-switching', '0542b7e2-bbfe-4ab4-8817-ec3db986b5bf', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(169, 47, 13, 'Cisco Certified Design Associate (CCDA)', 'cisco-certified-design-associate-ccda', 'tecnica', 'Associate', NULL, 'This certification validates the skills required of associate-level network engineers to design routed and switched network infrastructures and services involving LAN/WAN technologies for SMB or basic enterprise campus and branch networks.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-design-associate-ccda', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-design-associate-ccda', 'f13fa86c-a3d8-43fa-ab18-f6be9925699b', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(170, 47, 13, 'Cisco Certified Network Associate Routing and Switching (CCNA Routing and Switching)', 'cisco-certified-network-associate-routing-and-swit', 'tecnica', 'Associate', NULL, 'This certification validates the skills required of associate-level network professionals to understand network fundamentals, LAN switching technologies, IPv4 and IPv6 routing technologies, WAN technologies, infrastructure services, security, and management.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-network-associate-routing-and-switching-ccna-routing-and-switching', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-network-associate-routing-and-switching-ccna-routing-and-switching', '1e75a61c-8885-4f68-8ffe-2e19b77b527a', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(171, 47, 13, 'Webex Calling Administration Professional', 'webex-calling-administration-professional', 'tecnica', 'Professional', NULL, 'Earners of this badge have demonstrated advanced skills in configuring, managing, and troubleshooting Webex Calling environments. They are proficient in user and device management, call routing, security settings, analytics, integrations, and resolving complex issues. This badge is exclusive to Cisco partners and is required for those supporting Webex Calling solutions for customers.\n\nFonte: https://www.credly.com/org/cisco/badge/webex-calling-administration-professional', NULL, 'https://www.credly.com/org/cisco/badge/webex-calling-administration-professional', '3891195b-6b08-401a-804f-fe995629c10e', 1, '2026-05-13 22:07:05', NULL, NULL, '2026-05-13 22:07:05', 1),
(172, 91, 13, 'Migliorare l\'innovazione del team', 'migliorare-l-innovazione-del-team', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/d53cc93986c0c98c0ec066ff68c104d159ad03da58732dc89f3a22f487023515', NULL, 'https://www.linkedin.com/learning/certificates/d53cc93986c0c98c0ec066ff68c104d159ad03da58732dc89f3a22f487023515', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(173, 91, 13, 'Cambiare leadership', 'cambiare-leadership', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/332402522264fa700e7c79800665d3048c51aefae2f41114fca2266cfa7f577c', NULL, 'https://www.linkedin.com/learning/certificates/332402522264fa700e7c79800665d3048c51aefae2f41114fca2266cfa7f577c', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(174, 91, 13, 'En bref : Gérer les risques cybersécurité avec l’ISO 27001', 'en-bref-g-rer-les-risques-cybers-curit-avec-l-iso-', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/9767cb983739c489012db4d6a19d6b7bb5837fd1048ce7b4fac9670c8365cb51', NULL, 'https://www.linkedin.com/learning/certificates/9767cb983739c489012db4d6a19d6b7bb5837fd1048ce7b4fac9670c8365cb51', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(175, 91, 13, 'La gestion du temps : Série', 'la-gestion-du-temps-s-rie', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/4cfd4cb03a5253beaeb4ab6c2a200966d52e3a1f844fcafd14d6ea7430f7d5ed', NULL, 'https://www.linkedin.com/learning/certificates/4cfd4cb03a5253beaeb4ab6c2a200966d52e3a1f844fcafd14d6ea7430f7d5ed', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(176, 91, 13, 'La minute de formation : L\'amélioration des processus', 'la-minute-de-formation-l-am-lioration-des-processu', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/9caa1acb98b41e9fa045d9d2fe42415c9f10e071f51ddf265588a4a904685684', NULL, 'https://www.linkedin.com/learning/certificates/9caa1acb98b41e9fa045d9d2fe42415c9f10e071f51ddf265588a4a904685684', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(177, 91, 13, 'Gestione dei progetti semplificata', 'gestione-dei-progetti-semplificata', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/01ca8d8d52d77aa2d6d411a3e46f74dccfe7937e143da847828a18829b524afc', NULL, 'https://www.linkedin.com/learning/certificates/01ca8d8d52d77aa2d6d411a3e46f74dccfe7937e143da847828a18829b524afc', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(178, 91, 13, 'Leadership dell’assistenza clienti', 'leadership-dell-assistenza-clienti', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/9bf88b6420d6651b22d321870f66ea7e77a85de6bc0890ec5493b5e0d75c1bd8', NULL, 'https://www.linkedin.com/learning/certificates/9bf88b6420d6651b22d321870f66ea7e77a85de6bc0890ec5493b5e0d75c1bd8', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(179, 91, 13, 'Supporto IT per una forza lavoro ibrida', 'supporto-it-per-una-forza-lavoro-ibrida', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/a6683f4b539a1615c61baea19df17d4da8614a46916a6eb7ab1ae68b3b7d4932', NULL, 'https://www.linkedin.com/learning/certificates/a6683f4b539a1615c61baea19df17d4da8614a46916a6eb7ab1ae68b3b7d4932', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1);
INSERT INTO `certifications` (`id`, `brand_id`, `technology_id`, `name`, `code`, `category`, `level`, `validity_months`, `description`, `notes`, `exam_url`, `credly_template_id`, `is_active`, `created_at`, `renewal_policy`, `exam_cost`, `updated_at`, `updated_by`) VALUES
(180, 91, 13, 'Leader di progetti remoti e team virtuali', 'leader-di-progetti-remoti-e-team-virtuali', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/080e173b246ca5ffce93b156dc1df1f9815891406e12a9fdcdf0382a61746b0c', NULL, 'https://www.linkedin.com/learning/certificates/080e173b246ca5ffce93b156dc1df1f9815891406e12a9fdcdf0382a61746b0c', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(181, 91, 13, 'Definire e raggiungere gli obiettivi professionali', 'definire-e-raggiungere-gli-obiettivi-professionali', 'tecnica', NULL, NULL, 'Verifica: https://www.linkedin.com/learning/certificates/d184fc2d14c3a81826d175aec3186c3561384c7bad04ecb6a253698b9af608f4', NULL, 'https://www.linkedin.com/learning/certificates/d184fc2d14c3a81826d175aec3186c3561384c7bad04ecb6a253698b9af608f4', NULL, 1, '2026-05-15 10:34:48', NULL, NULL, '2026-05-15 10:34:48', 1),
(182, 47, 13, 'Implementing and Operating Cisco Security Core Technologies', 'implementing-and-operating-cisco-security-core-tec', 'tecnica', NULL, NULL, 'Earners of this credential have a strong foundation in implementing and operating core security technologies including network security, cloud security, content security, endpoint protection and detection, secure network access, visibility and enforcements. Earners are able to use technology to design deploy, automate, and optimize advanced security solutions.\n\nFonte: https://www.credly.com/org/cisco/badge/implementing-and-operating-cisco-security-core-technologies', NULL, 'https://www.credly.com/org/cisco/badge/implementing-and-operating-cisco-security-core-technologies', '2ed4d42a-860d-4995-ac84-928701ac11ce', 1, '2026-05-15 10:47:28', NULL, NULL, '2026-05-15 10:47:28', 1),
(183, 47, 13, 'Cisco Certified Network Professional Collaboration (CCNP Collaboration)', 'cisco-certified-network-professional-collaboration', 'tecnica', 'Professional', NULL, 'This certification validates the skills required of professional-level network engineers to design, deploy, configure, and troubleshoot collaboration and unified communications applications, devices, and networks.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-network-professional-collaboration-ccnp-collaboration', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-network-professional-collaboration-ccnp-collaboration', '82c3f356-9b45-4bc5-a3c2-1a3e84c0376b', 1, '2026-05-15 10:47:28', NULL, NULL, '2026-05-15 10:47:28', 1),
(184, 47, 13, 'Cisco Certified Specialist - Collaboration Call Control On-Premises', 'cisco-certified-specialist-collaboration-call-cont', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Collaboration Call Control On-Premises have demonstrated knowledge of advanced call control and mobility services, including signaling and media protocols, CME/SRST gateway technologies, Cisco Unified Board Element, call control and dial planning, Cisco Unified CM Call Control, and mobility.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-collaboration-call-contr', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-collaboration-call-contr', '023e83ef-88b2-4178-bf06-906df7486c40', 1, '2026-05-15 10:47:28', NULL, NULL, '2026-05-15 10:47:28', 1),
(185, 47, 13, 'Cisco Certified Specialist - Collaboration Core', 'cisco-certified-specialist-collaboration-core', 'tecnica', 'Specialist', NULL, 'Earners of Cisco Certified Specialist - Collaboration Core have demonstrated knowledge of implementing core collaboration technologies including infrastructure and design, protocols, codecs, and endpoints, Cisco IOS XE gateway and media resources, Call Control, QoS, and collaboration applications.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-certified-specialist-collaboration-core', NULL, 'https://www.credly.com/org/cisco/badge/cisco-certified-specialist-collaboration-core', 'd1283a16-f009-4b9a-8458-0704efb41a54', 1, '2026-05-15 10:47:28', NULL, NULL, '2026-05-15 10:47:28', 1),
(186, 63, 13, 'Exam 412: Configuring Advanced Windows Server 2012 Services', 'exam-412-configuring-advanced-windows-server-2012-', 'tecnica', 'Professional', NULL, 'Passing Exam 412: Configuring Advanced Windows Server 2012 Services validates the skills and knowledge necessary to administer a Windows Server 2012 infrastructure in an enterprise environment. Candidates demonstrate the ability to perform the advanced configuring tasks required to deploy, manage, and maintain a Windows Server 2012 infrastructure.\n\nFonte: https://www.credly.com/org/microsoft-certification/badge/exam-412-configuring-advanced-windows-server-2012-services', NULL, 'https://www.credly.com/org/microsoft-certification/badge/exam-412-configuring-advanced-windows-server-2012-services', '1ba69201-d873-4406-9339-d066b878b133', 1, '2026-05-15 10:48:28', NULL, NULL, '2026-05-15 10:48:28', 1),
(187, 63, 13, 'MCSA: Windows Server 2012 - Certified 2016', 'mcsa-windows-server-2012-certified-2016', 'tecnica', NULL, NULL, 'Earners of the MCSA: Windows Server 2012 certification have demonstrated the skills required to reduce IT costs and deliver more business. They are qualified for a position as a network or computer systems administrator or as a computer network specialist.\n\nFonte: https://www.credly.com/org/microsoft-certification/badge/mcsa-windows-server-2012-certified-2016', NULL, 'https://www.credly.com/org/microsoft-certification/badge/mcsa-windows-server-2012-certified-2016', 'b5a782e0-d45f-4cc5-83d7-592fb6744dff', 1, '2026-05-15 10:48:28', NULL, NULL, '2026-05-15 10:48:28', 1),
(188, 63, 13, 'Exam 411: Administering Windows Server 2012', 'exam-411-administering-windows-server-2012', 'tecnica', NULL, NULL, 'Passing Exam 411: Administering Windows Server 2012 validates the skills and knowledge necessary to administer a Windows Server 2012 infrastructure in an enterprise environment. Candidates demonstrate the ability to maintain a Windows Server 2012 infrastructure, such as user and group management, network access and data security.\n\nFonte: https://www.credly.com/org/microsoft-certification/badge/exam-411-administering-windows-server-2012', NULL, 'https://www.credly.com/org/microsoft-certification/badge/exam-411-administering-windows-server-2012', '4859d3a9-988f-4492-84cd-abc930555db6', 1, '2026-05-15 10:48:28', NULL, NULL, '2026-05-15 10:48:28', 1),
(189, 63, 13, 'Exam 410: Installing and Configuring Windows Server 2012', 'exam-410-installing-and-configuring-windows-server', 'tecnica', NULL, NULL, 'Passing Exam 410: Installing and Configuring Windows Server 2012 validates the skills and knowledge necessary to implement a core Windows Server 2012 infrastructure in an existing enterprise environment. Candidates demonstrate the ability to implement and configure Windows Server 2012 core services, such as Active Directory and the networking services.\n\nFonte: https://www.credly.com/org/microsoft-certification/badge/exam-410-installing-and-configuring-windows-server-2012', NULL, 'https://www.credly.com/org/microsoft-certification/badge/exam-410-installing-and-configuring-windows-server-2012', 'a8c28d1c-e6d2-4ee9-8293-57c3c2e30a4b', 1, '2026-05-15 10:48:28', NULL, NULL, '2026-05-15 10:48:28', 1),
(190, 92, 13, 'Disney College & International Program Internship', 'disney-college-international-program-internship.2', 'tecnica', NULL, NULL, 'This unique internship is a integrated experience that combines working in a fast-pased environment, living within a multicultural community on-site, and participating in personal and professional development offerings. Earners of this badge appreciate company culture, professionalism, critical thinking, diversity, inclusion, and community.\n\nFonte: https://www.credly.com/org/disney-college/badge/disney-college-international-program-internship.2', NULL, 'https://www.credly.com/org/disney-college/badge/disney-college-international-program-internship.2', '8625249d-1a6b-40ea-9b61-5495769c8e5f', 1, '2026-05-15 10:51:14', NULL, NULL, '2026-05-15 10:51:14', 1),
(191, 93, 13, 'Oracle Database Administration 2019 Certified Professional', 'oracle-database-administration-2019-certified-prof', 'tecnica', 'Professional', NULL, 'An Oracle Database Administration 2019 Certified Professional has proven theoretical understanding of and the practical skills required to configure and manage Oracle Databases up to and including Oracle 19c. This includes: installation, upgrades, patching, SQL programming skills, database and network administration and backup and recovery. This person also demonstrates fluency with some advanced skills such as multi-tenant, SQL performance monitoring and problem determination.\n\nFonte: https://www.credly.com/org/oracle/badge/oracle-database-administration-2019-certified-professional', NULL, 'https://www.credly.com/org/oracle/badge/oracle-database-administration-2019-certified-professional', '3129500d-fc23-4c6c-9648-428c4f7269c6', 1, '2026-05-15 10:53:33', NULL, NULL, '2026-05-15 10:53:33', 1),
(192, 93, 13, 'Oracle Database SQL Certified Associate', 'oracle-database-sql-certified-associate', 'tecnica', 'Associate', NULL, 'An Oracle Database SQL Certified Associate demonstrates understanding of fundamental SQL concepts needed to undertake any database project. Candidates have illustrated a depth of knowledge of SQL and its use when working with the Oracle Database server, and a working knowledge of queries, insert, update and delete SQL statements as well as some Data Definition language and Data Control Language, the optimizer, tables and indexes, data modeling and normalization.\n\nFonte: https://www.credly.com/org/oracle/badge/oracle-database-sql-certified-associate', NULL, 'https://www.credly.com/org/oracle/badge/oracle-database-sql-certified-associate', 'c2a705e4-fe2d-45cc-a8c9-9e3aa8df05e9', 1, '2026-05-15 10:53:33', NULL, NULL, '2026-05-15 10:53:33', 1),
(193, 93, 13, 'Oracle Database 12c Administrator Certified Professional', 'oracle-database-12c-administrator-certified-profes', 'tecnica', 'Professional', NULL, 'An Oracle Database 12c Administrator Certified Professional has demonstrated the abilities of top-performing database admins, including, but not limited to, backup, recovery and performance management.\n\nFonte: https://www.credly.com/org/oracle/badge/oracle-database-12c-administrator-certified-professional', NULL, 'https://www.credly.com/org/oracle/badge/oracle-database-12c-administrator-certified-professional', 'ca4b83f3-bb37-4983-9abf-8fd814a5f184', 1, '2026-05-15 10:53:33', NULL, NULL, '2026-05-15 10:53:33', 1),
(194, 93, 13, 'Oracle Certified Expert, Oracle Real Application Clusters 11g and Grid Infrastructure Administrator', 'oracle-certified-expert-oracle-real-application-cl', 'tecnica', 'Expert', NULL, 'An Oracle Certified Expert, Oracle Real Application Clusters 11gR2 and Grid Infrastructure Administrator has demonstrated skills in Oracle Grid Infrastructure and RAC Administration, installation, configuration and performance management. He or she can also install, maintain and configure Grid Infrastructure, and has proven skills administering Oracle Clusterware.\n\nFonte: https://www.credly.com/org/oracle/badge/oracle-certified-expert-oracle-real-application-clusters-11g-and-grid-infrastructure-administrator', NULL, 'https://www.credly.com/org/oracle/badge/oracle-certified-expert-oracle-real-application-clusters-11g-and-grid-infrastructure-administrator', '7b35ca52-1eaf-4aa9-a819-68fe8fa50aac', 1, '2026-05-15 10:53:33', NULL, NULL, '2026-05-15 10:53:33', 1),
(195, 93, 13, 'Oracle Database 11g Administrator Certified Professional', 'oracle-database-11g-administrator-certified-profes', 'tecnica', 'Professional', NULL, 'An Oracle Database 11g Administrator Certified Professional has a deep understanding of a wide array of database features, functions and tasks, and has passed a rigorous exam featuring real-world, scenario-based questions that challenge and measure their ability to think and perform.\n\nFonte: https://www.credly.com/org/oracle/badge/oracle-database-11g-administrator-certified-professional', NULL, 'https://www.credly.com/org/oracle/badge/oracle-database-11g-administrator-certified-professional', 'b477cf0c-b4d5-4e9c-a0ef-0fc32c5bbaaa', 1, '2026-05-15 10:53:33', NULL, NULL, '2026-05-15 10:53:33', 1),
(196, 93, 13, 'Oracle Certified Associate, Oracle WebLogic Server 11g System Administrator', 'oracle-certified-associate-oracle-weblogic-server-', 'tecnica', 'Associate', NULL, 'An Oracle Certified Associate, Oracle WebLogic Server 11g System Administrator has demonstrated the ability to install and configure WebLogic Server 11g, deploy Java EE applications to Oracle WebLogic Server 11g and configure Oracle HTTP Server as a Web proxy.\n\nFonte: https://www.credly.com/org/oracle/badge/oracle-certified-associate-oracle-weblogic-server-11g-system-administrator', NULL, 'https://www.credly.com/org/oracle/badge/oracle-certified-associate-oracle-weblogic-server-11g-system-administrator', '08e84eba-b7f2-4851-8d43-c2dc6166bb99', 1, '2026-05-15 10:53:33', NULL, NULL, '2026-05-15 10:53:33', 1),
(197, 93, 13, 'Oracle Database 11g Administrator Certified Associate', 'oracle-database-11g-administrator-certified-associ', 'tecnica', 'Associate', NULL, 'An Oracle Database 11g Administrator Certified Associate has demonstrated knowledge working with a wide array of database features, functions and tasks by mastering real-world, scenario-based questions which assess their ability to think and perform.\n\nFonte: https://www.credly.com/org/oracle/badge/oracle-database-11g-administrator-certified-associate', NULL, 'https://www.credly.com/org/oracle/badge/oracle-database-11g-administrator-certified-associate', 'e1389b75-56ee-4562-9efe-93d42afd6ecf', 1, '2026-05-15 10:53:33', NULL, NULL, '2026-05-15 10:53:33', 1),
(198, 56, 13, 'Fortinet FortiGate 7.6 Operator', 'fortinet-fortigate-7-6-operator', 'tecnica', NULL, NULL, 'The FortiGate 7.6 Operator exam badge recognizes expertise in the common features of implementing and operating a FortiGate device. The badge earner has demonstrated knowledge of basic configuration, operation, and day-to-day administration.\n\nFonte: https://www.credly.com/org/fortinet/badge/fortinet-fortigate-7-6-operator', NULL, 'https://www.credly.com/org/fortinet/badge/fortinet-fortigate-7-6-operator', '7d917668-4eb6-4459-824f-a9faacac3b12', 1, '2026-05-15 12:04:57', NULL, NULL, '2026-05-15 12:04:57', 1),
(199, 56, 13, 'Getting Started in Cybersecurity 3.0', 'getting-started-in-cybersecurity-3-0', 'tecnica', NULL, NULL, 'The Getting Started in Cybersecurity 3.0 badge recognizes a basic understanding of cybersecurity. The badge earner has demonstrated knowledge of cybersecurity terminology and concepts, and has a fundamental understanding of key cybersecurity topics, technologies, and products.\n\nFonte: https://www.credly.com/org/fortinet/badge/getting-started-in-cybersecurity-3-0', NULL, 'https://www.credly.com/org/fortinet/badge/getting-started-in-cybersecurity-3-0', '13b8699a-d998-4674-8509-5dcaee16807b', 1, '2026-05-15 12:04:57', NULL, NULL, '2026-05-15 12:04:57', 1),
(200, 56, 13, 'Introduction to the Threat Landscape 3.0', 'introduction-to-the-threat-landscape-3-0', 'tecnica', NULL, NULL, 'The Introduction to the Threat Landscape 3.0 badge recognizes a fundamental understanding of the cyber threat landscape. The badge earner has demonstrated essential knowledge of the threats that endanger computer networks, the cast of bad actors who are behind cyber threats, and the cyber security principles that can keep users and computer networks safe.\n\nFonte: https://www.credly.com/org/fortinet/badge/introduction-to-the-threat-landscape-3-0', NULL, 'https://www.credly.com/org/fortinet/badge/introduction-to-the-threat-landscape-3-0', '9b3a946f-be9f-457e-b9e9-6f2eec0f60e8', 1, '2026-05-15 12:04:57', NULL, NULL, '2026-05-15 12:04:57', 1),
(201, 56, 13, 'Technical Introduction to Cybersecurity 3.0', 'technical-introduction-to-cybersecurity-3-0', 'tecnica', NULL, NULL, 'The Technical Introduction to Cybersecurity 3.0 badge recognizes expertise in the technical basics of cybersecurity. The badge earner has demonstrated knowledge of essential cybersecurity terminology and concepts, and of the technical foundations for network and endpoint security.\n\nFonte: https://www.credly.com/org/fortinet/badge/technical-introduction-to-cybersecurity-3-0', NULL, 'https://www.credly.com/org/fortinet/badge/technical-introduction-to-cybersecurity-3-0', 'a2969d0f-bc02-44fb-8c16-5c3e2fe0180e', 1, '2026-05-15 12:04:57', NULL, NULL, '2026-05-15 12:04:57', 1),
(202, 94, 13, 'Red Hat System Administration I (RH124) - Ver. 9.3', 'red-hat-system-administration-i-rh124-ver-9-3', 'tecnica', NULL, NULL, 'This credential verifies the attendance of the Red Hat System Administration I (RH124) course. After attending the course, students are recommended to take the Red Hat System Administration II (RH134) course.\n\nFonte: https://www.credly.com/org/red-hat-inc/badge/red-hat-system-administration-i-rh124-ver-9-3', NULL, 'https://www.credly.com/org/red-hat-inc/badge/red-hat-system-administration-i-rh124-ver-9-3', '1bf2a935-1b83-4877-8069-e7d9c44012c9', 1, '2026-05-15 12:06:27', NULL, NULL, '2026-05-15 12:06:27', 1),
(203, 94, 13, 'Red Hat System Administration II (RH134) - Ver. 9.3', 'red-hat-system-administration-ii-rh134-ver-9-3', 'tecnica', NULL, NULL, 'This credential verifies the attendance of the Red Hat System Administration II (RH134) course. After attending the course, students are encouraged to test their skills and knowledge by taking the Red Hat Certified System Administrator (RHCSA) exam.\n\nFonte: https://www.credly.com/org/red-hat-inc/badge/red-hat-system-administration-ii-rh134-ver-9-3', NULL, 'https://www.credly.com/org/red-hat-inc/badge/red-hat-system-administration-ii-rh134-ver-9-3', 'e15a9c04-779d-4c69-81ab-aa8e8155a940', 1, '2026-05-15 12:06:27', NULL, NULL, '2026-05-15 12:06:27', 1),
(204, 89, 13, 'VMware Certified Professional - Digital Workspace 2023', 'vmware-certified-professional-digital-workspace-20', 'tecnica', 'Professional', NULL, 'The VMware Certified Professional - Digital Workspace certification validates that a badge earner can configure, deploy, manage, maintain, optimize, and perform basic troubleshooting of VMware Workspace ONE and related solutions, as well as properly identify and differentiate any needed supporting products and components.\n\nFonte: https://www.credly.com/org/broadcom/badge/vmware-certified-professional-digital-workspace-2023', NULL, 'https://www.credly.com/org/broadcom/badge/vmware-certified-professional-digital-workspace-2023', 'ae392266-61c9-4fc4-9373-bcb8506f40ac', 1, '2026-05-15 12:06:27', NULL, NULL, '2026-05-15 12:06:27', 1),
(205, 94, 13, 'Red Hat OpenShift Administration I: Operating a Production Cluster (DO180) - Ver. 4.14', 'red-hat-openshift-administration-i-operating-a-pro', 'tecnica', NULL, NULL, 'This credential verifies the attendance of the Red Hat OpenShift Administration I: Operating a Production Cluster (DO180) course. After attending the course, students are recommended to take the Red Hat OpenShift Administration II: Configuring a Production Cluster (DO280) course.\n\nFonte: https://www.credly.com/org/red-hat-inc/badge/red-hat-openshift-administration-i-operating-a-prod.1', NULL, 'https://www.credly.com/org/red-hat-inc/badge/red-hat-openshift-administration-i-operating-a-prod.1', 'b06d5c75-e8a6-4572-8047-d487ba5aa240', 1, '2026-05-15 12:06:27', NULL, NULL, '2026-05-15 12:06:27', 1),
(206, 94, 13, 'Red Hat OpenShift: Seller', 'red-hat-openshift-seller.2', 'tecnica', NULL, NULL, 'A sales professional who has earned the Red Hat® OpenShift®: Seller credential has demonstrated knowledge of how OpenShift addresses customer challenges, provides value, and addresses pain points. They are able to drive strategic intent as well as qualify and consult on the needs around the industry\'s leading hybrid cloud application platform powered by Kubernetes.\n\nFonte: https://www.credly.com/org/red-hat-inc/badge/red-hat-openshift-seller.2', NULL, 'https://www.credly.com/org/red-hat-inc/badge/red-hat-openshift-seller.2', 'b867d500-dff9-42b8-8852-24d9a5a9993e', 1, '2026-05-15 12:06:27', NULL, NULL, '2026-05-15 12:06:27', 1),
(207, 94, 13, 'Red Hat OpenShift: Technical Seller', 'red-hat-openshift-technical-seller.1', 'tecnica', NULL, NULL, 'A technical sales professional who has earned the Red Hat® OpenShift®: Technical Seller credential has demonstrated knowledge of how OpenShift addresses customer technical challenges and pain points. They can explain as well as differentiate the technical capabilities for standard use cases and both qualify opportunities and conduct discovery from a technical perspective to drive Red Hat solutions with customers.\n\nFonte: https://www.credly.com/org/red-hat-inc/badge/red-hat-openshift-technical-seller.1', NULL, 'https://www.credly.com/org/red-hat-inc/badge/red-hat-openshift-technical-seller.1', 'afb010a7-dd6f-4618-858d-49ea08eb776b', 1, '2026-05-15 12:06:27', NULL, NULL, '2026-05-15 12:06:27', 1),
(208, 94, 13, 'Red Hat Portfolio: Foundational', 'red-hat-portfolio-foundational.1', 'tecnica', NULL, NULL, 'A sales professional who has earned the Red Hat® Portfolio: Foundational credential has demonstrated foundational knowledge of the three Red Hat core technologies (Red Hat Enterprise Linux®, Red Hat OpenShift®, and Red Hat Ansible® Automation Platform), their business value to customers, and how they drive Red Hat’s open hybrid cloud strategy.\n\nFonte: https://www.credly.com/org/red-hat-inc/badge/red-hat-portfolio-foundational.1', NULL, 'https://www.credly.com/org/red-hat-inc/badge/red-hat-portfolio-foundational.1', 'a40e7ad7-f9dc-48f4-9ecc-0550c80e6ec5', 1, '2026-05-15 12:06:27', NULL, NULL, '2026-05-15 12:06:27', 1),
(209, 95, 13, 'Palo Alto Networks Certified Network Security Professional', 'palo-alto-networks-certified-network-security-prof', 'tecnica', 'Professional', NULL, 'The Palo Alto Networks Certified Network Security Professional certification is designed to validate a candidate’s understanding of all products and services included in the Palo Alto Networks Network Security solution, their use cases, and how they are applicable to an organization. This exam validates a candidate’s ability to use, maintain, and configure Network Security products at an entry level, and to perform basic Network Security product installation and deployment.\n\nFonte: https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-certified-network-security-profe', NULL, 'https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-certified-network-security-profe', '0a37cc61-7358-4038-b626-9e4f2bf1f404', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(210, 95, 13, 'CYBERFORCE: Defender', 'cyberforce-defender', 'tecnica', NULL, NULL, 'CYBERFORCE represents the top 1% of partner engineers that spans over 90 countries. They are trusted for their security expertise, always putting the customer first, and focused on preventing successful cyberattacks. CYBERFORCE Defenders understand Palo Alto Networks technical fundamentals, and are mastering pre-sales tools to engage with customers.\n\nFonte: https://www.credly.com/org/palo-alto-networks/badge/cyberforce-defender', NULL, 'https://www.credly.com/org/palo-alto-networks/badge/cyberforce-defender', 'edeed863-3565-4dc9-a7af-cbf96337e074', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(211, 96, 13, 'Dell PowerFlex Design 2023', 'dell-powerflex-design-2023', 'tecnica', NULL, NULL, 'This badge recognizes the successful completion of Dell PowerFlex Design 2023. Successfully obtaining this certification enables and validates the candidate’s ability to apply the knowledge and skills required to effectively design a PowerFlex solution in order to identify and document specific requirements in preparation for installation and implementation of the solution.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/dell-powerflex-design-2023', NULL, 'https://www.credly.com/org/delltechnologies/badge/dell-powerflex-design-2023', '7894deba-9fb4-4ef6-abbc-41a1d77e0ba9', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(212, 96, 13, 'Dell PowerProtect Data Domain Operate 2023', 'dell-powerprotect-data-domain-operate-2023', 'tecnica', NULL, NULL, 'This Proven Certification benefits any professional who needs to demonstrate their ability to operate Dell PowerProtect DD systems.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/dell-powerprotect-data-domain-operate-2023', NULL, 'https://www.credly.com/org/delltechnologies/badge/dell-powerprotect-data-domain-operate-2023', '88833881-a296-44b5-a149-77ad24d6a5f3', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(213, 96, 13, 'Dell PowerProtect DD Deploy Exam', 'dell-powerprotect-dd-deploy-exam', 'tecnica', NULL, NULL, 'This Proven Certification benefits any professional who needs to demonstrate their ability to deploy Dell PowerProtect DD systems.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/dell-powerprotect-dd-deploy-exam', NULL, 'https://www.credly.com/org/delltechnologies/badge/dell-powerprotect-dd-deploy-exam', '6b4a4114-b06c-4a5f-80ae-af05282cb2db', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(214, 96, 13, 'Exam Developer - 2023', 'exam-developer-2023', 'tecnica', NULL, NULL, 'This badge recognizes the earner\'s participation in a Dell Technologies Proven Professional Exam Development Workshop. Earner\'s of this badge have fully participated in an Exam Workshop and assisted in writing a Dell Technologies Proven Professional Exam.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/exam-developer-2023', NULL, 'https://www.credly.com/org/delltechnologies/badge/exam-developer-2023', 'b7567001-fc13-4732-8118-9a720b80a261', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(215, 96, 13, 'Specialist – Implementation Engineer, PowerProtect DD Version 3.0', 'specialist-implementation-engineer-powerprotect-dd', 'tecnica', 'Specialist', NULL, 'This badge recognizes the achievement of Dell Technologies Proven Professional Specialist – Implementation Engineer, PowerProtect DD, version 3.0. This certification will benefit any Implementation Engineer or PowerProtect Data Manager professional implementing or managing the PowerProtect Data Manager product. This certification formally validates the skills such as deploy, configure, troubleshoot, integrate and upgrade a PowerProtect Data Manager solution in various environments.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-powerprotect-dd-version-3-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-powerprotect-dd-version-3-0', '361e4d2b-6f09-4841-a4f9-e435d9203558', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(216, 96, 13, 'Associate - Information Storage and Management Version 4.0', 'associate-information-storage-and-management-versi', 'tecnica', 'Associate', NULL, 'This badge recognizes the achievement of Dell Technologies Proven Professional Associate - Information Storage and Management Version 4.0 certification. Earners of this badge will have knowledge on data center infrastructure including but not limited to storage and business continuity including software-defined systems and environments for big data, IoT, and mobile technologies.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/associate-information-storage-and-management-version-4-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/associate-information-storage-and-management-version-4-0', 'd54afa85-4ce3-4af2-8cdd-8020670eb210', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(217, 96, 13, 'Specialist - Technology Architect, PowerScale Solutions Version 4.0', 'specialist-technology-architect-powerscale-solutio', 'tecnica', 'Expert', NULL, 'This badge recognizes the achievement of the Dell Technologies Proven Professional Specialist - Technology Architect, PowerScale Solutions Version 4.0 certification. Earners of this badge understand the PowerScale technical architecture, requirements gathering, implementation considerations, sizing guidelines, role-based administration and operation of the PowerScale data storage product.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/specialist-technology-architect-powerscale-solutions-version-4-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/specialist-technology-architect-powerscale-solutions-version-4-0', '90582ecb-48a6-4e41-bada-acfcf9389252', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(218, 96, 13, 'Specialist – Implementation Engineer, PowerStore Solutions Version 1.0', 'specialist-implementation-engineer-powerstore-solu', 'tecnica', 'Specialist', NULL, 'This badge recognizes the achievement of earning the Specialist – Implementation Engineer, PowerStore Solutions Version 1.0 certification. Earners of this badge are able to implement and administer PowerStore storage arrays in open systems environments. The certification focuses on configuration, administration, migration, upgrades and basic troubleshooting.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-powerstore-solutions-version-1-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-powerstore-solutions-version-1-0', 'f27178d2-347e-43a7-9905-8a0b3bd9f530', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(219, 96, 13, 'Associate - Converged Systems and Hybrid Cloud Version 2.0', 'associate-converged-systems-and-hybrid-cloud-versi', 'tecnica', 'Associate', NULL, 'The badge recognizes the achievement of earning the Proven Professional Associate - Converged Systems and Hybrid Cloud Version 2.0 certification. Earners of this badge have proven their understanding of Digital and IT Transformation, and how Dell Technologies Converged Systems can be used to accelerate the transformation process.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/associate-converged-systems-and-hybrid-cloud-version-2-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/associate-converged-systems-and-hybrid-cloud-version-2-0', '2577c4d9-0a20-4909-8a23-4f1df4922f1d', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(220, 96, 13, 'Specialist – Implementation Engineer, VxRail Appliance Version 1.0', 'specialist-implementation-engineer-vxrail-applianc', 'tecnica', 'Specialist', NULL, 'This badge recognizes the achievement of the Dell Technologies Proven Professional Specialist – Implementation Engineer, VxRail Appliance Version 1.0 certification. Earners of this badge are able to understand and follow the implementation services in addition to understanding the extended VxRail environment.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-vxrail-appliance-version-1-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-vxrail-appliance-version-1-0', 'e3e3f899-eefc-40ef-adc3-978016253c76', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(221, 96, 13, 'Specialist - Implementation Engineer, Dell EMC Unity Solutions Version 2.0', 'specialist-implementation-engineer-dell-emc-unity-', 'tecnica', 'Specialist', NULL, 'This badge recognizes the achievement of Dell Technologies Proven Professional Specialist - Implementation Engineer, Dell EMC Unity Solutions Version 2.0 certification. Earners of this badge have the knowledge of key product related activities including installation, configuration and management of Dell EMC Unity Systems. The UnityVSA and product features such as Snapshots and Replication are also covered.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-dell-emc-unity-solutions-version-2-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-dell-emc-unity-solutions-version-2-0', '7b3eee82-79fd-41f6-9652-f124aaf50a16', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(222, 96, 13, 'Specialist – Implementation Engineer, SC Series Version 1.0', 'specialist-implementation-engineer-sc-series-versi', 'tecnica', 'Specialist', NULL, 'This badge recognizes the achievement of the Dell Technologies Proven Professional Specialist – Implementation Engineer, SC Series Version 1.0 certification. Earners of this badge are able to perform basic to intermediate skill level tasks in deploying and managing the Dell Technologies SC Series storage products.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-sc-series-version-1-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/specialist-implementation-engineer-sc-series-version-1-0', 'c0fcae97-cbe5-4bfd-8fdb-1558596b33fd', 1, '2026-05-15 12:08:27', NULL, NULL, '2026-05-15 12:08:27', 1),
(223, 96, 13, 'Specialist - Technology Architect, Midrange Storage Solutions Version 1.0', 'specialist-technology-architect-midrange-storage-s', 'tecnica', 'Expert', NULL, 'This badge recognizes the achievement of the Dell Technologies Proven Professional Specialist - Technology Architect, Midrange Storage Solutions Version 1.0 certification. Earners of this badge have the knowledge and skills to architect and size solutions for Dell Technologies Unity and SC Series systems.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/specialist-technology-architect-midrange-storage-solutions-version-1-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/specialist-technology-architect-midrange-storage-solutions-version-1-0', '45151853-e04b-42f2-9d31-4af639ec3139', 1, '2026-05-15 12:08:28', NULL, NULL, '2026-05-15 12:08:28', 1),
(224, 96, 13, 'Associate - Information Storage and Management Version 3.0', 'associate-information-storage-and-management-versi', 'tecnica', 'Associate', NULL, 'This badge recognizes the achievement of Dell Technologies Proven Professional Associate - Information Storage and Management Version 3.0 certification. Earners of this badge will have knowledge on data center infrastructure including but not limited to storage and business continuity including software-defined systems and environments for big data, IoT, and mobile technologies.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/associate-information-storage-and-management-version-3-0', NULL, 'https://www.credly.com/org/delltechnologies/badge/associate-information-storage-and-management-version-3-0', '4f40c9f4-84bf-470d-9b2f-4d50e4d57083', 1, '2026-05-15 12:08:28', NULL, NULL, '2026-05-15 12:08:28', 1),
(225, 96, 13, 'VxRail Appliance 4.x Deployment and Implementation', 'vxrail-appliance-4-x-deployment-and-implementation', 'tecnica', NULL, NULL, 'This badge recognizes the achievement of the Dell Technologies Proven Professional VxRail Appliance 4.x Deployment and Implementation certification. Earners of this badge have proven their ability to deploy VxRail Appliance clusters. They are able to follow the hardware and software activities to be successful during the implementation of the product. They understand the benefits of converged and hyperconverged solutions, evergreen scale up and scale out, and common troubleshooting activities.\n\nFonte: https://www.credly.com/org/delltechnologies/badge/vxrail-appliance-4-x-deployment-and-implementation', NULL, 'https://www.credly.com/org/delltechnologies/badge/vxrail-appliance-4-x-deployment-and-implementation', '2f64b362-9528-46f2-9c6b-ae5af7b37afb', 1, '2026-05-15 12:08:28', NULL, NULL, '2026-05-15 12:08:28', 1),
(226, 95, 13, 'Palo Alto Networks Certified Network Security Engineer', 'palo-alto-networks-certified-network-security-engi', 'tecnica', NULL, NULL, 'The PCNSE certification validates the knowledge and skills required for network security engineers who design, deploy, operate, manage, and troubleshoot Palo Alto Networks firewalls. PCNSE certified individuals have demonstrated in-depth knowledge of the Palo Alto Networks product portfolio and can make full use of it in the vast majority of implementations. The PCNSE validates that engineers can correctly deploy firewalls while leveraging the rest of the platform.\n\nFonte: https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-certified-network-security-engineer', NULL, 'https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-certified-network-security-engineer', 'e1343edd-ce8e-464c-9ede-457eea76081f', 1, '2026-05-15 12:08:28', NULL, NULL, '2026-05-15 12:08:28', 1),
(227, 95, 13, 'Palo Alto Networks Certified Network Security Administrator', 'palo-alto-networks-certified-network-security-admi', 'tecnica', NULL, NULL, 'The PCNSA certification validates the knowledge and skills required for network security administrators responsible for deploying and operating Palo Alto Networks firewalls. PCNSA certified individuals have demonstrated knowledge of the Palo Alto Networks NGFW feature set and in the Palo Alto Networks product portfolio core components. The PCNSA seeks to identify people who can operate Palo Alto Networks firewalls to protect networks from cutting-edge cyber threats.\n\nFonte: https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-certified-network-security-administrator', NULL, 'https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-certified-network-security-administrator', '6d9bd115-1a77-4f53-8e37-91d158c342e9', 1, '2026-05-15 12:08:28', NULL, NULL, '2026-05-15 12:08:28', 1),
(228, 95, 13, 'Palo Alto Networks System Engineer (PSE) - Hardware Firewall Professional', 'palo-alto-networks-system-engineer-pse-hardware-fi', 'tecnica', 'Professional', NULL, 'The PSE: Hardware Firewall Professional certification validates the knowledge, skills, and abilities required to be successful in the technical sales of the Strata products and services. PSE: Hardware Firewall Professional certified individuals have demonstrated in-depth knowledge of how to present, demonstrate, evaluate, and defend the value of Palo Alto Networks Strata technology.\n\nFonte: https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-system-engineer-pse-hardware-fir', NULL, 'https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-system-engineer-pse-hardware-fir', 'fbe78d66-32f2-47c7-93ea-5cad5304a6b6', 1, '2026-05-15 12:08:28', NULL, NULL, '2026-05-15 12:08:28', 1),
(229, 95, 13, 'Palo Alto Networks Systems Engineer (PSE)- Foundation', 'palo-alto-networks-systems-engineer-pse-foundation', 'tecnica', 'Foundation', NULL, 'A Palo Alto Networks Systems Engineer (PSE) is knowledgeable in the core value propositions of the Palo Alto Networks Next-Generation platform and can pitch and demonstrate Palo Alto Networks products using PAN-OS demo environments. The individual has successfully demonstrated the foundational knowledge and skills to be of Palo Alto Networks platform.\n\nFonte: https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-systems-engineer-pse-foundation', NULL, 'https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-systems-engineer-pse-foundation', '0cf7144f-ee4a-4336-94f2-17953aa52fba', 1, '2026-05-15 12:08:28', NULL, NULL, '2026-05-15 12:08:28', 1),
(230, 95, 13, 'Palo Alto Networks Systems Engineer (PSE) - Strata Associate', 'palo-alto-networks-systems-engineer-pse-strata-ass', 'tecnica', 'Associate', NULL, 'The PSE: Strata Associate certified individual has successfully completed the specialized learning path and passed the written exam to verify they possess the necessary knowledge, skills, and abilities required to demonstrate knowledge in the competitive features and functions of Palo Alto Networks Next-Generation firewalls, execute an evaluation of Palo Alto Networks firewalls, and present a Security Lifecycle Review (SLR) report.\n\nFonte: https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-systems-engineer-pse-strata-associate', NULL, 'https://www.credly.com/org/palo-alto-networks/badge/palo-alto-networks-systems-engineer-pse-strata-associate', '5de0ccf2-88da-488c-b26f-4e47429d3c18', 1, '2026-05-15 12:08:28', NULL, NULL, '2026-05-15 12:08:28', 1),
(231, 47, 13, 'CCNA: Introduction to Networks', 'ccna-introduction-to-networks', 'tecnica', 'Associate', NULL, 'Cisco verifies the earner of this badge successfully completed the Introduction to Networks course and achieved this student level credential. Earner has knowledge of networking including IP addressing, how physical, data link protocols support Ethernet, can configure connectivity between switches, routers and end devices to provide access to local and remote resources. Earner participated in up to 54 labs and accumulated up to 14 hours of hands-on labs using Cisco hardware or Packet Tracer tool\n\nFonte: https://www.credly.com/org/cisco/badge/ccna-introduction-to-networks', NULL, 'https://www.credly.com/org/cisco/badge/ccna-introduction-to-networks', '227dafde-5f07-4968-ae2f-75e8246f85b3', 1, '2026-05-15 12:12:44', NULL, NULL, '2026-05-15 12:12:44', 1),
(232, 97, 13, 'AWS Certified Cloud Practitioner', 'aws-certified-cloud-practitioner', 'tecnica', NULL, NULL, 'Earners of this certification have a fundamental understanding of IT services and their uses in the AWS Cloud. They demonstrated cloud fluency and foundational AWS knowledge. Badge owners are able to identify essential AWS services necessary to set up AWS-focused projects.\n\nFonte: https://www.credly.com/org/amazon-web-services/badge/aws-certified-cloud-practitioner', NULL, 'https://www.credly.com/org/amazon-web-services/badge/aws-certified-cloud-practitioner', '6069fb52-0c27-42d7-852b-6aa86ea45e81', 1, '2026-05-15 12:12:44', NULL, NULL, '2026-05-15 12:12:44', 1),
(233, 97, 13, 'AWS Partner: Sales Accreditation - Training Badge', 'aws-partner-sales-accreditation-training-badge', 'tecnica', NULL, NULL, 'Earners of this new badge are AWS Partners who have demonstrated a foundational knowledge of the business value of cloud, how to overcome common customer objections, and how to co-sell with AWS.\n\nFonte: https://www.credly.com/org/amazon-web-services/badge/aws-partner-sales-accreditation-training-badge', NULL, 'https://www.credly.com/org/amazon-web-services/badge/aws-partner-sales-accreditation-training-badge', '17f867df-3e05-42c9-8f4d-0e8a920b27d1', 1, '2026-05-15 12:12:44', NULL, NULL, '2026-05-15 12:12:44', 1),
(234, 98, 13, 'watsonx.ai Generative AI Tools Technical Sales Intermediate', 'watsonx-ai-generative-ai-tools-technical-sales-int', 'tecnica', 'Associate', NULL, 'This badge earner has gained hands-on experience with IBM watsonx.ai and the tools available to work with generative AI and large language models. They understand how watsonx.ai works and how it delivers generative AI and foundation model capabilities, as well as how it fits into the overall watsonx strategy. By leveraging the resources included, they can discuss the watsonx.ai value proposition with clients and demonstrate the key features and capabilities of various generative AI tools.\n\nFonte: https://www.credly.com/org/ibm/badge/watsonx-ai-generative-ai-tools-technical-sales-inte', NULL, 'https://www.credly.com/org/ibm/badge/watsonx-ai-generative-ai-tools-technical-sales-inte', '13f8f7d6-1aa4-4584-8d65-eb1d3a6a85de', 1, '2026-05-15 12:12:44', NULL, NULL, '2026-05-15 12:12:44', 1),
(235, 99, 13, 'LFS101: Introduction to Linux', 'lfs101-introduction-to-linux', 'tecnica', NULL, NULL, 'Earners of the LFS101: Introduction to Linux badge have gained the skills to effectively navigate and manage configurations across major Linux distributions. They utilize both the graphical interface and command line proficiently. Badge holders are skilled in system startup, file operations, network management, and basic scripting. They have also developed a strong understanding of local security principles, including system updates, root privileges, and password management.\n\nFonte: https://www.credly.com/org/the-linux-foundation/badge/lfs101-introduction-to-linux', NULL, 'https://www.credly.com/org/the-linux-foundation/badge/lfs101-introduction-to-linux', 'adefb0c2-1909-4acb-9433-c601be85b25e', 1, '2026-05-15 12:19:23', NULL, NULL, '2026-05-15 12:19:23', 1),
(236, 63, 13, 'Microsoft 365 Certified: Fundamentals', 'microsoft-365-certified-fundamentals', 'tecnica', NULL, NULL, 'Earning the Microsoft 365 Fundamentals certification demonstrates an understanding of the options available in Microsoft 365 and the benefits of adopting cloud services, the Software as a Service (SaaS) cloud model, and implementing Microsoft 365 cloud service.\n\nFonte: https://www.credly.com/org/microsoft-certification/badge/microsoft-365-certified-fundamentals', NULL, 'https://www.credly.com/org/microsoft-certification/badge/microsoft-365-certified-fundamentals', 'eb178496-4bcf-45d5-8a35-858a1da0f081', 1, '2026-05-15 12:19:26', NULL, NULL, '2026-05-15 12:19:26', 1),
(237, 63, 13, 'Microsoft Certified: Security, Compliance, and Identity Fundamentals', 'microsoft-certified-security-compliance-and-identi', 'tecnica', NULL, NULL, 'Earners of the Security, Compliance, and Identity Fundamentals demonstrate a functional understanding of security, compliance, and identity (SCI) across cloud-based and related Microsoft services.\n\nFonte: https://www.credly.com/org/microsoft-certification/badge/microsoft-certified-security-compliance-and-identity-fundamentals', NULL, 'https://www.credly.com/org/microsoft-certification/badge/microsoft-certified-security-compliance-and-identity-fundamentals', '778db280-4976-41e4-9eaa-f8834a0a8d46', 1, '2026-05-15 12:19:26', NULL, NULL, '2026-05-15 12:19:26', 1),
(238, 63, 13, 'Microsoft Certified: Azure Fundamentals', 'microsoft-certified-azure-fundamentals', 'tecnica', NULL, NULL, 'Earners of the Azure Fundamentals certification have demonstrated foundational level knowledge of cloud services and how those services are provided with Microsoft Azure.\n\nFonte: https://www.credly.com/org/microsoft-certification/badge/microsoft-certified-azure-fundamentals', NULL, 'https://www.credly.com/org/microsoft-certification/badge/microsoft-certified-azure-fundamentals', 'e0997fd0-d532-4ba0-a8a7-ee6cc6c7b8e9', 1, '2026-05-15 12:19:26', NULL, NULL, '2026-05-15 12:19:26', 1),
(239, 100, 13, 'Certified LabVIEW Associate Developer', 'certified-labview-associate-developer', 'tecnica', 'Associate', NULL, 'Certified LabVIEW Associate Developers (CLAD) demonstrate familiarity with the LabVIEW programming environment and basic programming concepts like data acquisition and manipulation. CLADs possess a working knowledge of the LabVIEW environment, a basic understanding of coding and documentation best practices, and the ability to read and interpret existing code.\n\nFonte: https://www.credly.com/org/ni/badge/certified-labview-associate-developer', NULL, 'https://www.credly.com/org/ni/badge/certified-labview-associate-developer', '3e296525-6133-41b7-b546-f734ddaa3d26', 1, '2026-05-15 12:19:29', NULL, NULL, '2026-05-15 12:19:29', 1),
(240, 101, 13, 'HPE Sales Certified - Hybrid Cloud Solutions [2022]', 'hpe-sales-certified-hybrid-cloud-solutions-2022.1', 'tecnica', NULL, NULL, 'HPE Sales Certified - Hybrid Cloud Solutions [2022] will teach you how to identify sales opportunities in HPE solutions—storage, compute, HPE Ezmeral, and HPE GreenLake—and drive outcome-based customer conversations to increase your traditional infrastructure sales and add new revenue streams to your as-a-service-sales.\n\nFonte: https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-sales-certified-hybrid-cloud-solutions-2022.1', NULL, 'https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-sales-certified-hybrid-cloud-solutions-2022.1', 'ae21d663-f8a1-4b19-b633-d4a19c3f91f9', 1, '2026-05-15 12:19:31', NULL, NULL, '2026-05-15 12:19:31', 1),
(241, 101, 13, 'HPE Sales Certified Edge-to-Cloud [2021]', 'hpe-sales-certified-edge-to-cloud-2021', 'tecnica', NULL, NULL, 'The HPE Sales Certified Edge-to-Cloud provides focuses on the technology landscape on which your customers run their businesses is shifting at lightening speed. This certification identifies major industry trends in areas of technology, procurement and consumption and how HPE is uniquely positioned to address each.\n\nFonte: https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-sales-certified-edge-to-cloud-2021', NULL, 'https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-sales-certified-edge-to-cloud-2021', '0897f225-a159-4372-9024-d9796fdd1da1', 1, '2026-05-15 12:19:31', NULL, NULL, '2026-05-15 12:19:31', 1);
INSERT INTO `certifications` (`id`, `brand_id`, `technology_id`, `name`, `code`, `category`, `level`, `validity_months`, `description`, `notes`, `exam_url`, `credly_template_id`, `is_active`, `created_at`, `renewal_policy`, `exam_cost`, `updated_at`, `updated_by`) VALUES
(242, 101, 13, 'HPE Sales Certified - Hybrid Cloud Solutions [2020]', 'hpe-sales-certified-hybrid-cloud-solutions-2020', 'tecnica', NULL, NULL, 'This certification verifies that you can identify HPE sales opportunities and build sales pipeline.By learning to engage customers in strategic IT conversations, you will be able to uncover business needs and qualify customers for HPE hybrid cloud solutions. The course content includes•Why digital transformation impacts businesses today•The importance/benefits of consultative selling•How customers benefit from everything-as-a-service• The need for Cloud experience on-premises and more.\n\nFonte: https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-sales-certified-hybrid-cloud-solutions-2020', NULL, 'https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-sales-certified-hybrid-cloud-solutions-2020', '0d26071d-038c-4435-a70a-38f081e727b7', 1, '2026-05-15 12:19:31', NULL, NULL, '2026-05-15 12:19:31', 1),
(243, 101, 13, 'HPE Sales Certified - Hybrid IT Solutions [2019]', 'hpe-sales-certified-hybrid-it-solutions-2019.1', 'tecnica', NULL, NULL, 'This badge validates your understanding of industry and technology trends and helps you transition to a more strategic value-based selling approach. You will have the tools to be more conversant in the language of HPE Everything-as-a-Service, Software-defined, and Intelligent storage solutions. This selling approach will help you uncover opportunities and thrive in today\'s changing IT environment.\n\nFonte: https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-sales-certified-hybrid-it-solutions-2019.1', NULL, 'https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-sales-certified-hybrid-it-solutions-2019.1', 'fdfcf5b2-0883-470e-a70f-5b7643e1f213', 1, '2026-05-15 12:19:31', NULL, NULL, '2026-05-15 12:19:31', 1),
(244, 89, 13, 'VMware Certified Professional - VMware Cloud Foundation Administrator', 'vmware-certified-professional-vmware-cloud-foundat', 'tecnica', 'Professional', NULL, 'The VMware Certified Professional - VMware Cloud Foundation Administrator certification validates your ability to install, configure, manage, and perform basic troubleshooting of the VMware Cloud Foundation (VCF) solution. It proves you are knowledgeable of the features, functions, and architectures of all VCF components.\n\nFonte: https://www.credly.com/org/broadcom/badge/vmware-certified-professional-vmware-cloud-foundati.4', NULL, 'https://www.credly.com/org/broadcom/badge/vmware-certified-professional-vmware-cloud-foundati.4', 'b7b7ba84-9e5e-4e9a-90b6-f0210bfee533', 1, '2026-05-15 12:19:39', NULL, NULL, '2026-05-15 12:19:39', 1),
(245, 101, 13, 'HPE ASE - Storage integrator solutions', 'hpe-ase-storage-integrator-solutions', 'tecnica', NULL, NULL, 'This certification validates your knowledge and experience as an integrator for HPE Storage platforms and products, including the ability to size, design, install, configure, optimize, upgrade, manage, monitor, troubleshoot, and maintain HPE Storage solutions. It demonstrates your ability to assess business requirements, and develop and implement a solution that meets customers\' needs.\n\nFonte: https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-ase-storage-integrator-solutions', NULL, 'https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-ase-storage-integrator-solutions', '816460a4-0469-4983-9e3b-71e63a73bdbe', 1, '2026-05-15 12:19:39', NULL, NULL, '2026-05-15 12:19:39', 1),
(246, 101, 13, 'HPE ASE - Storage solutions', 'hpe-ase-storage-solutions', 'tecnica', NULL, NULL, 'This certification validates that you can identify, recommend, and explain HPE Enterprise Storage Solutions architectures and technologies, and translate business requirements into storage solution designs that support applications and data across physical, virtual and cloud environments with a common architecture and converged management. This certification also shows that you can design HPE Backup Solutions including the right Backup, Recovery, and Archive (BURA) strategies for customers.\n\nFonte: https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-ase-storage-solutions', NULL, 'https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-ase-storage-solutions', 'f3ec489b-6ab3-48d9-b0ea-4c2a7dfbe997', 1, '2026-05-15 12:19:39', NULL, NULL, '2026-05-15 12:19:39', 1),
(247, 101, 13, 'HPE ATP - Hybrid cloud', 'hpe-atp-hybrid-cloud', 'tecnica', NULL, NULL, 'This certification validates that you have foundational knowledge and skills of the HPE edge-to-cloud strategy, encompassing server, storage, networking, HPE GreenLake, management tools and their underlying architectures, technologies, and consumption strategies. Additionally this certification validates that you can evaluate customer requirements and recommend the best HPE offering (solutions, products, and services) for current and anticipated business needs.\n\nFonte: https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-atp-hybrid-cloud', NULL, 'https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-atp-hybrid-cloud', '7b732551-76c2-499c-889a-472bc3327309', 1, '2026-05-15 12:19:39', NULL, NULL, '2026-05-15 12:19:39', 1),
(248, 101, 13, 'HPE Product Certified - HPE OneView', 'hpe-product-certified-hpe-oneview', 'tecnica', NULL, NULL, 'This certification validates that you can describe, recommend, demonstrate, and configure HPE OneView converged infrastructure management solutions. 1) Explain the HPE OneView 6.x architectural model, features and functions. 2) Explain the benefits and strengths of integrated, converged management. 3) Explain the benefits and strengths of HPE OneView security, automation, and proactive monitoring capabilities. 4) Perform installation and setup processes 5) Configure various environments\n\nFonte: https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-product-certified-hpe-oneview', NULL, 'https://www.credly.com/org/hewlett-packard-enterprise/badge/hpe-product-certified-hpe-oneview', '942cbe0a-7c6f-4a3b-8871-ff883f1f0dda', 1, '2026-05-15 12:19:39', NULL, NULL, '2026-05-15 12:19:39', 1),
(249, 102, 13, 'Sorint Summer Campus 2022', 'sorint-summer-campus-2022', 'tecnica', NULL, NULL, 'Earners of this badge have demonstrated their skills in different IT technology areas through successfully completing the Summer Campus 2022 delivered by Sorint organization.This year the Summer Campus consists of 2 weeks of training on various technological areas with hints of DB, Linux, Virtualization, Storage, Cloud, Fullstack Development, Backup, Network and Security explained by professionals Sorint teachers\n\nFonte: https://www.credly.com/org/sorint-lab-s-p-a/badge/sorint-summer-campus-2022', NULL, 'https://www.credly.com/org/sorint-lab-s-p-a/badge/sorint-summer-campus-2022', '25b0c8e3-23e9-4689-858b-8013db7b9347', 1, '2026-05-15 12:20:25', NULL, NULL, '2026-05-15 12:20:25', 1),
(250, 97, 13, 'AWS Partner: Accreditation (Business)', 'aws-partner-accreditation-business', 'tecnica', NULL, NULL, 'This course is retired, however the owners of this badge demonstrated a foundational knowledge of key AWS products and services and understand effective client engagement strategies. AWS Partner learners should take the updated AWS Partner: Sales Accreditation (Business) course and assessment to earn the associated badge..\n\nFonte: https://www.credly.com/org/amazon-web-services/badge/aws-partner-accreditation-business', NULL, 'https://www.credly.com/org/amazon-web-services/badge/aws-partner-accreditation-business', 'cc2d0b5f-df9b-4396-b075-33526668a304', 1, '2026-05-15 12:25:39', NULL, NULL, '2026-05-15 12:25:39', 1),
(251, 47, 13, 'Operating Systems Basics', 'operating-systems-basics', 'tecnica', NULL, NULL, 'Cisco verifies the earner of this badge successfully completed the Operating Systems Basics course and achieved this student level credential. Earner has fundamental knowledge of operating systems by covering the basic concepts and skills needed to explain the purpose and characteristics of operating systems, implement basic operating system security, and explain how to configure mobile device network connectivity and email.\n\nFonte: https://www.credly.com/org/cisco/badge/operating-systems-basics', NULL, 'https://www.credly.com/org/cisco/badge/operating-systems-basics', '39708bbd-f5cc-4062-8023-74fc2d97ada4', 1, '2026-05-15 12:25:47', NULL, NULL, '2026-05-15 12:25:47', 1),
(252, 47, 13, 'Introduction to Cybersecurity', 'introduction-to-cybersecurity', 'tecnica', NULL, NULL, 'Cisco verifies the earner of this badge successfully completed the Introduction to Cybersecurity course. The holder of this student-level credential has introductory knowledge of cybersecurity, including the global implications of cyber threats on industries, and why cybersecurity is a growing profession. They understand vulnerabilities and threat detection and defense. They also have insight into opportunities available with pursuing cybersecurity certifications.\n\nFonte: https://www.credly.com/org/cisco/badge/introduction-to-cybersecurity', NULL, 'https://www.credly.com/org/cisco/badge/introduction-to-cybersecurity', '10b1a2de-f36b-4730-b1ca-8505e19f4390', 1, '2026-05-15 12:25:47', NULL, NULL, '2026-05-15 12:25:47', 1),
(253, 47, 13, 'Computer Hardware Basics', 'computer-hardware-basics', 'tecnica', NULL, NULL, 'Cisco verifies the earner of this badge successfully completed the Computer Hardware Basics course and achieved this student level credential. Earner has fundamentals knowledge of computers and mobile devices, how they work, as well as the basic concepts and skills needed to install components to build, repair, upgrade personal computers and and basic troubleshooting tools and techniques.\n\nFonte: https://www.credly.com/org/cisco/badge/computer-hardware-basics', NULL, 'https://www.credly.com/org/cisco/badge/computer-hardware-basics', 'c3631bef-53d0-450e-aa8b-95e9ad624af4', 1, '2026-05-15 12:25:47', NULL, NULL, '2026-05-15 12:25:47', 1),
(254, 89, 13, 'VMware Certified Professional 6.5 – Data Center Virtualization', 'vmware-certified-professional-6-5-data-center-virt', 'tecnica', 'Professional', NULL, 'The VCP6.5-DCV certification validates that a badge earner can implement, manage, and troubleshoot a vSphere V6.5 infrastructure, using best practices to provide a powerful, flexible, and secure foundation for business agility that can accelerate the transformation to cloud computing.\n\nFonte: https://www.credly.com/org/broadcom/badge/vmware-certified-professional-6-5-data-center-virtualization.1', NULL, 'https://www.credly.com/org/broadcom/badge/vmware-certified-professional-6-5-data-center-virtualization.1', 'f5d8419e-f846-4ace-a1cd-6bed2fb6c88c', 1, '2026-05-15 12:38:23', NULL, NULL, '2026-05-15 12:38:23', 1),
(255, 89, 13, 'VMware Certified Professional 6 – Data Center Virtualization', 'vmware-certified-professional-6-data-center-virtua', 'tecnica', 'Professional', NULL, 'The VCP6-DCV certification validates that a badge earner can administer and troubleshoot vSphere V6 compute infrastructures. This certification also proves that a badge earner can leverage best practices - providing a scalable and reliable virtualization platform for his/her company.\n\nFonte: https://www.credly.com/org/broadcom/badge/vmware-certified-professional-6-data-center-virtualization', NULL, 'https://www.credly.com/org/broadcom/badge/vmware-certified-professional-6-data-center-virtualization', 'ee8c840a-1f8d-45e9-96ef-243018794258', 1, '2026-05-15 12:38:23', NULL, NULL, '2026-05-15 12:38:23', 1),
(256, 103, 13, 'Getting Started with Artificial Intelligence', 'getting-started-with-artificial-intelligence', 'tecnica', NULL, NULL, 'This credential earner demonstrates a foundational understanding of Artificial Intelligence concepts and processes, including common applications of AI, and generative AI. The individual has worked with generative AI tools to refine and create prompts.\n\nFonte: https://www.credly.com/org/ibm-skillsbuild/badge/getting-started-with-artificial-intelligence', NULL, 'https://www.credly.com/org/ibm-skillsbuild/badge/getting-started-with-artificial-intelligence', '16a73b3d-bf87-48a3-bcca-54dbb07d348e', 1, '2026-05-15 12:41:38', NULL, NULL, '2026-05-15 12:41:38', 1),
(257, 56, 13, 'Technical Introduction to Cybersecurity 1.0', 'technical-introduction-to-cybersecurity-1-0', 'tecnica', NULL, NULL, 'The Technical Introduction to Cybersecurity 1.0 badge recognizes expertise in the technical basics of cybersecurity. The badge earner has demonstrated knowledge of essential cybersecurity terminology and concepts, and of the technical foundations for network and endpoint security.\n\nFonte: https://www.credly.com/org/fortinet/badge/technical-introduction-to-cybersecurity-1-0', NULL, 'https://www.credly.com/org/fortinet/badge/technical-introduction-to-cybersecurity-1-0', 'a9bf1cf9-1a8b-4e12-bbd5-65f0e76df4d1', 1, '2026-05-15 12:41:43', NULL, NULL, '2026-05-15 12:41:43', 1),
(258, 56, 13, 'Introduction to the Threat Landscape 2.0', 'introduction-to-the-threat-landscape-2-0', 'tecnica', NULL, NULL, 'The Introduction to the Threat Landscape 2.0 badge recognizes a fundamental understanding of the cyber threat landscape. The badge earner has demonstrated essential knowledge of the threats that endanger computer networks, the cast of bad actors who are behind cyber threats, and the cyber security principles that can keep users and computer networks safe.\n\nFonte: https://www.credly.com/org/fortinet/badge/introduction-to-the-threat-landscape-2-0', NULL, 'https://www.credly.com/org/fortinet/badge/introduction-to-the-threat-landscape-2-0', 'fe3f3966-94f6-47ac-97a7-e07adb7c4673', 1, '2026-05-15 12:41:43', NULL, NULL, '2026-05-15 12:41:43', 1),
(259, 47, 13, 'Introduction to Cisco Sales', 'introduction-to-cisco-sales', 'tecnica', NULL, NULL, 'The 700-150 ICS exam tests a candidate\'s knowledge and skills needed by an account manager to successfully sell Cisco technology services and solutions.\n\nFonte: https://www.credly.com/org/cisco/badge/introduction-to-cisco-sales', NULL, 'https://www.credly.com/org/cisco/badge/introduction-to-cisco-sales', 'a8c38833-5337-4054-a7e6-5cb96a5b72b4', 1, '2026-05-15 12:49:44', NULL, NULL, '2026-05-15 12:49:44', 1),
(260, 47, 13, 'Cisco Collaboration SaaS', 'cisco-collaboration-saas', 'tecnica', NULL, NULL, 'The 700-680 CSaaS exam will test the knowledge of Account Manager/Presales engineers on the foundations of Cisco’s Collaboration SaaS solutions in order for them to effectively sell these cloud-based services. This exam is a requirement for the Cisco Collaboration SaaS Authorization Program.\n\nFonte: https://www.credly.com/org/cisco/badge/cisco-collaboration-saas', NULL, 'https://www.credly.com/org/cisco/badge/cisco-collaboration-saas', '52348cb1-f040-4f19-8173-0ac0c20aa7e8', 1, '2026-05-15 12:49:44', NULL, NULL, '2026-05-15 12:49:44', 1),
(261, 104, 13, 'IBM Cloud Solutions - MobileFirst Sales Mastery v2', 'ibm-cloud-solutions-mobilefirst-sales-mastery-v2', 'tecnica', NULL, NULL, 'The badge holder can effectively identify a client\'s business needs within a defined solution area and position the appropriate IBM Hybrid Cloud solution(s) to meet those needs. (This badge is only available to IBM Business Partner employees.)\n\nFonte: https://www.credly.com/org/ibm-professional-certification-program/badge/ibm-cloud-solutions-mobilefirst-sales-mastery-v2', NULL, 'https://www.credly.com/org/ibm-professional-certification-program/badge/ibm-cloud-solutions-mobilefirst-sales-mastery-v2', 'd2d051fb-5e50-44d9-9b81-a1eb592fdf5b', 1, '2026-05-15 12:49:44', NULL, NULL, '2026-05-15 12:49:44', 1),
(262, 105, 13, 'Acronis Cyber Frame Basic Course', 'acronis-cyber-frame-basic-course', 'tecnica', 'Foundation', NULL, 'Acronis Cyber Frame Basic Course\n\nFonte: https://www.credly.com/org/acronis/badge/acronis-cyber-frame-basic-course', NULL, 'https://www.credly.com/org/acronis/badge/acronis-cyber-frame-basic-course', 'da7432e1-bd5d-45aa-90da-d3e60c5530a2', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(263, 105, 13, 'Cloud Tech Associate Advanced Automation', 'cloud-tech-associate-advanced-automation', 'tecnica', 'Professional', NULL, 'Intermediate-level technical course focused on Acronis Advanced Automation. Designed for IT professionals, MSPs and cloud architects. Covers strategies for automating deployment, scaling, and management of cloud resources using industry-standard tools and frameworks.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-automation', NULL, 'https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-automation', 'ee08e4af-3017-4dd1-975e-b1ee1124467d', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(264, 105, 13, 'Cloud Sales Associate Security + XDR', 'cloud-sales-associate-security-xdr', 'tecnica', 'Associate', NULL, 'Designed for sales, marketing, and account managers at service providers and distributors to recertify on Acronis Cloud Sales Advanced Security + XDR. Throughout the course we will cover a case study, certain areas to be re-emphasized from last year, and new features within the last year. Additionally, we will cover sales qualifying questions and some objection handling.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-sales-associate-security-xdr', NULL, 'https://www.credly.com/org/acronis/badge/cloud-sales-associate-security-xdr', '3f3e3497-c230-4609-96c5-075ccf03b6c1', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(265, 105, 13, 'Intro to Acronis', 'intro-to-acronis', 'tecnica', NULL, NULL, 'Intro to Acronis\n\nFonte: https://www.credly.com/org/acronis/badge/intro-to-acronis', NULL, 'https://www.credly.com/org/acronis/badge/intro-to-acronis', 'c925916c-0270-4f22-ada3-b49003c04419', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(266, 105, 13, 'M365 Basic Course', 'm365-basic-course', 'tecnica', 'Foundation', NULL, 'M365 Basic Course\n\nFonte: https://www.credly.com/org/acronis/badge/m365-basic-course', NULL, 'https://www.credly.com/org/acronis/badge/m365-basic-course', '4c7993db-95d0-4830-be44-aceb090dc601', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(267, 105, 13, 'Solution Basic Training – Ultimate 365', 'solution-basic-training-ultimate-365.1', 'tecnica', 'Foundation', NULL, 'Solution Basic Training – Ultimate 365\n\nFonte: https://www.credly.com/org/acronis/badge/solution-basic-training-ultimate-365.1', NULL, 'https://www.credly.com/org/acronis/badge/solution-basic-training-ultimate-365.1', 'f2055410-b1fa-4b3e-8661-c88db2153a76', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(268, 105, 13, 'EDR Basic Course', 'edr-basic-course', 'tecnica', 'Foundation', NULL, 'EDR Basic Course\n\nFonte: https://www.credly.com/org/acronis/badge/edr-basic-course', NULL, 'https://www.credly.com/org/acronis/badge/edr-basic-course', '210d7c1d-fbf8-46a6-b080-921dde2d4850', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(269, 105, 13, 'RMM & PSA Basic Course', 'rmm-psa-basic-course', 'tecnica', 'Foundation', NULL, 'RMM & PSA Basic Course\n\nFonte: https://www.credly.com/org/acronis/badge/rmm-psa-basic-course', NULL, 'https://www.credly.com/org/acronis/badge/rmm-psa-basic-course', 'a837e1a0-7dca-4c43-be3b-88d38cf98bf6', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(270, 105, 13, 'Solution Basic Training – Protected Workspace', 'solution-basic-training-protected-workspace.1', 'tecnica', 'Foundation', NULL, 'Solution Basic Training – Protected Workspace\n\nFonte: https://www.credly.com/org/acronis/badge/solution-basic-training-protected-workspace.1', NULL, 'https://www.credly.com/org/acronis/badge/solution-basic-training-protected-workspace.1', 'ed5bd09f-4fd1-4318-bffa-76854b0fca2d', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(271, 105, 13, 'BDR Basic Course', 'bdr-basic-course', 'tecnica', 'Foundation', NULL, 'BDR Basic Course\n\nFonte: https://www.credly.com/org/acronis/badge/bdr-basic-course', NULL, 'https://www.credly.com/org/acronis/badge/bdr-basic-course', '0e8dfe3e-a5f7-4fd0-b167-7d10bb691650', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(272, 105, 13, 'Solution Basic Training – Cyber Resilience', 'solution-basic-training-cyber-resilience', 'tecnica', 'Foundation', NULL, 'Solution Basic Training – Cyber Resilience\n\nFonte: https://www.credly.com/org/acronis/badge/solution-basic-training-cyber-resilience', NULL, 'https://www.credly.com/org/acronis/badge/solution-basic-training-cyber-resilience', '22044390-a3b7-4a6c-9069-9a89f0d97201', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(273, 105, 13, 'Tech Fundamentals', 'tech-fundamentals.1', 'tecnica', NULL, NULL, 'The Acronis Tech Fundamentals course is designed for technical representatives working at a reseller or system integrator looking to gain basic knowledge about the product and how to deploy it. This course covers target customers and their traits, key capabilities and benefits, and licensing. It also includes information on the different software components and deployment types, supported environments, and how to start installing agents and management servers.\n\nFonte: https://www.credly.com/org/acronis/badge/tech-fundamentals.1', NULL, 'https://www.credly.com/org/acronis/badge/tech-fundamentals.1', '360c8b24-ff8f-4983-bd6d-e0674752a420', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(274, 105, 13, 'Cloud Sales Associate Advanced Security with EDR', 'cloud-sales-associate-advanced-security-with-edr', 'tecnica', 'Professional', NULL, 'Intermediate-level technical course focused on advanced security services with EDR (endpoint detection and response) found in Acronis Cyber Protect Cloud. Designed for MSP Systems Engineers, Systems Administrators, and IT Professionals. Covers how to plan and perform advanced security and EDR operations with Acronis Cyber Protect Cloud.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-security-with-edr', NULL, 'https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-security-with-edr', '57ac3de2-4780-4c40-97fe-b223299f58bc', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(275, 105, 13, 'Cloud Tech Associate Advanced Disaster Recovery', 'cloud-tech-associate-advanced-disaster-recovery', 'tecnica', 'Professional', NULL, 'Intermediate level technical course focused on Disaster Recovery as a Service found in Acronis Cyber Protect Cloud. Designed for MSP Systems Engineers, Systems Administrators, and IT Professionals. Covers how to plan and perform cloud-based disaster recovery failover and failback with Acronis Cyber Protect Cloud.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-disaster-recovery', NULL, 'https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-disaster-recovery', '2ebd334c-2c9b-4fb7-b1cd-2811186cde13', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(276, 105, 13, 'Cloud Tech Associate Advanced Backup', 'cloud-tech-associate-advanced-backup', 'tecnica', 'Professional', NULL, 'Intermediate level technical course focused on backup operations and concepts found in Acronis Cyber Protect Cloud. Designed for MSP Systems Engineers, Systems Administrators, and IT Professionals. Covers how to plan and perform backup, recovery, and related operations with Acronis Cyber Protect Cloud.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-backup', NULL, 'https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-backup', 'e0841303-a781-4eeb-a21c-2ce20c98e7f8', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(277, 105, 13, 'Cloud Tech Fundamentals', 'cloud-tech-fundamentals', 'tecnica', NULL, NULL, 'Entry-level technical course on Acronis Cyber Protect Cloud release for MSP Owners, Helpdesk, Administrators, Technicians, and IT Generalist. Covers overview, licensing, agent installation, operating concepts, and managing tenants and users with Acronis Cyber Protect Cloud services.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-tech-fundamentals', NULL, 'https://www.credly.com/org/acronis/badge/cloud-tech-fundamentals', 'c508e487-e9a2-454f-be3c-5304c9a7d171', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(278, 105, 13, 'MSP Academy: XDR Basics', 'msp-academy-xdr-basics', 'tecnica', NULL, NULL, 'This quiz tests your knowledge on Acronis XDR, focusing on its features and benefits. Achieve a 70% score to pass and prove your understanding of effective cybersecurity management.\n\nFonte: https://www.credly.com/org/acronis/badge/msp-academy-xdr-basics', NULL, 'https://www.credly.com/org/acronis/badge/msp-academy-xdr-basics', 'ab67db11-9d94-45a4-a26d-0b7cddf34187', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(279, 105, 13, 'Cloud Tech Associate Advanced Security', 'cloud-tech-associate-advanced-security', 'tecnica', 'Professional', NULL, 'Intermediate level technical course focused on security services found in Acronis Cyber Protect Cloud. Designed for MSP Systems Engineers, Systems Administrators, and IT Professionals. Covers how to plan and perform cyber protection operations with Acronis Cyber Protect Cloud.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-security', NULL, 'https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-security', 'aa0d88cd-4b76-4e59-964b-8f8599577359', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(280, 105, 13, 'Cloud Tech Associate Advanced Management', 'cloud-tech-associate-advanced-management', 'tecnica', 'Professional', NULL, 'Intermediate-level course that covers how to use Management capabilities, including group management, remote desktop, remote assistance, hardware inventory that\'s available as standard in Acronis Cyber Protect Cloud and patch management, disk health monitoring, software inventory, and fail-safe patching available in the Advanced Management Pack. It is meant for Managed Service Provider (MSP), Hoster, Cloud Aggregator, and Cloud Distributor representatives in a technical or support role.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-management', NULL, 'https://www.credly.com/org/acronis/badge/cloud-tech-associate-advanced-management', '82084790-1ec0-48d5-948a-7f64a81943d1', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(281, 105, 13, 'Cloud Sales Associate Advanced Disaster Recovery', 'cloud-sales-associate-advanced-disaster-recovery', 'tecnica', 'Professional', NULL, 'Intermediate-level sales course focused on Acronis Cyber Disaster Recovery as a Service. Designed for MSP Sales, Marketing, and Account Managers. Covers Acronis value proposition, sales tactics, and go-to-market tactics for the Disaster Recovery services that are part of Acronis Cyber Protect Cloud.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-disaster-recovery', NULL, 'https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-disaster-recovery', '094adc8f-98e9-41c8-a7b2-9a361a63353c', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(282, 105, 13, 'Cloud Sales Associate Advanced Security', 'cloud-sales-associate-advanced-security', 'tecnica', 'Professional', NULL, 'Intermediate-level sales course focused on selling the security services found in Acronis Cyber Protect Cloud. Designed for MSP Sales, Marketing, and Account Managers.Covers Acronis value proposition, sales tactics, and go-to-market tactics for the security options provided by Acronis Cyber Protect Cloud.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-security', NULL, 'https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-security', '0e569599-5910-428b-af5e-47d71a21c203', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(283, 105, 13, 'Cloud Sales Professional', 'cloud-sales-professional', 'tecnica', 'Professional', NULL, 'Designed to strengthen core sales skills that are required to be a successful sales professional. After taking this course, you will have a better understanding of the tools and techniques required to position and sell Acronis Cyber Protection.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-sales-professional', NULL, 'https://www.credly.com/org/acronis/badge/cloud-sales-professional', '0821db45-a88c-4ee7-b428-be4a5a0fff65', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(284, 105, 13, 'Cloud Sales Associate Advanced Backup', 'cloud-sales-associate-advanced-backup', 'tecnica', 'Professional', NULL, 'Intermediate-level sales course focused on best practices for selling and going to to market with the backup features of Acronis Cyber Protect Cloud. Designed for MSP Sales, Marketing, and Account Managers. Covers Acronis value proposition, sales tactics, and go-to-market tactics for the backup services provided by Acronis Cyber Protect Cloud.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-backup', NULL, 'https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-backup', '3691f1c4-7bef-4de3-b6c4-6ef2e1bc7442', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(285, 105, 13, 'Cloud Sales Associate Advanced Management', 'cloud-sales-associate-advanced-management', 'tecnica', 'Professional', NULL, 'Intermediate-level sales course focused on selling the endpoint management provided by Acronis Cyber Protect Cloud. Designed for MSP Sales, Marketing, and Account Managers. Covers Acronis value proposition, sales tactics, and go-to-market tactics for endpoint management services provided by Acronis Cyber Protect Cloud.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-management', NULL, 'https://www.credly.com/org/acronis/badge/cloud-sales-associate-advanced-management', 'e2903708-937e-443c-95b1-38000621f0d1', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(286, 105, 13, 'Cloud Sales Fundamentals', 'cloud-sales-fundamentals', 'tecnica', NULL, NULL, 'Entry-level sales course on Acronis Cyber Protect Cloud. Targeted for MSP Sales, Marketing, and Account Managers. Covers licensing and product overview for Acronis Cyber Protect Cloud services.\n\nFonte: https://www.credly.com/org/acronis/badge/cloud-sales-fundamentals', NULL, 'https://www.credly.com/org/acronis/badge/cloud-sales-fundamentals', '5556c2e7-57f5-47ed-bf6c-7c830dc72f09', 1, '2026-05-15 12:49:54', NULL, NULL, '2026-05-15 12:49:54', 1),
(287, 47, 13, 'Understanding Cisco Cybersecurity Operations Fundamentals', 'understanding-cisco-cybersecurity-operations-funda', 'tecnica', NULL, NULL, 'This badge earner has essential technical skills in monitoring, detecting, and analyzing cybersecurity threats using industry-standard tools and methodologies. They can interpret logs from multiple security technologies, correlate alerts across network and endpoint systems, and follow structured SOC workflows. Skilled in basic threat analysis, incident triage, and escalation, they are equipped to operate effectively in fast paced, real world cybersecurity operations environments.\n\nFonte: https://www.credly.com/org/cisco/badge/understanding-cisco-cybersecurity-operations-fundam', NULL, 'https://www.credly.com/org/cisco/badge/understanding-cisco-cybersecurity-operations-fundam', '7241b00e-c96b-4ad1-a97a-3a07c66ddfc0', 1, '2026-05-15 13:02:21', NULL, NULL, '2026-05-15 13:02:21', 1),
(288, 106, 13, 'Identity Security Technical Certification', 'identity-security-technical-certification', 'tecnica', NULL, NULL, 'The earner of this certification has demonstrated understanding of basic authentication technology concepts and is proficient in setting up, configuring, and managing WatchGuard AuthPoint. This credential validates the ability to effectively implement and manage authentication solutions using WatchGuard\'s technology.\n\nFonte: https://www.credly.com/org/watchguard-technologies/badge/identity-security-technical-certification', NULL, 'https://www.credly.com/org/watchguard-technologies/badge/identity-security-technical-certification', 'ef6da94f-f491-444a-9472-3c3f64240b31', 1, '2026-05-15 13:10:17', NULL, NULL, '2026-05-15 13:10:17', 1);

-- --------------------------------------------------------

--
-- Struttura della tabella `certification_versions`
--

CREATE TABLE `certification_versions` (
  `id` int(11) NOT NULL,
  `certification_id` int(11) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `field_changed` varchar(50) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `certification_versions`
--

INSERT INTO `certification_versions` (`id`, `certification_id`, `version`, `field_changed`, `old_value`, `new_value`, `changed_by`, `changed_at`) VALUES
(1, 125, 1, 'code', NULL, 'SaleGreenLake', 1, '2026-05-13 09:14:46'),
(2, 125, 1, 'category', 'tecnica', 'commerciale', 1, '2026-05-13 09:14:46');

-- --------------------------------------------------------

--
-- Struttura della tabella `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL COMMENT 'Ragione sociale',
  `vat_number` varchar(30) DEFAULT NULL COMMENT 'Partita IVA',
  `fiscal_code` varchar(30) DEFAULT NULL COMMENT 'Codice fiscale',
  `is_internal_company` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = è una companies del gruppo',
  `internal_company_id` int(11) DEFAULT NULL COMMENT 'FK companies.id se is_internal_company=1',
  `sector` varchar(100) DEFAULT NULL COMMENT 'Settore (IT, finance, ecc)',
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(50) DEFAULT NULL,
  `country` varchar(50) DEFAULT 'Italia',
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `email_pec` varchar(150) DEFAULT NULL,
  `website` varchar(200) DEFAULT NULL,
  `contact_person` varchar(150) DEFAULT NULL COMMENT 'Referente principale',
  `contact_role` varchar(100) DEFAULT NULL COMMENT 'Ruolo del referente',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Anagrafica clienti per i quali apriamo posizioni di ricerca';

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
(3, 'Antea srl', NULL, '01222470427', '2026-03-29 20:57:57'),
(4, 'Nis Group srl', 'Joele Milazzo', 'IT02568000976', '2026-04-08 07:10:28');

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
(9, 3, 'Sede Principale', 'VIA DELL\'INDUSTRIA 16 - 60015 - FALCONARA MARITTIMA (AN)', NULL, NULL, '071 918 8753', NULL, 'anteatcs@legalmail.it', NULL, NULL, NULL, NULL),
(10, 4, 'Sede Scandicci', 'VIA DEL LAVORO 10/37', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `contract_documents`
--

CREATE TABLE `contract_documents` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `status` enum('current','archived','superseded') NOT NULL DEFAULT 'current',
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `signed_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `distributors`
--

CREATE TABLE `distributors` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `type` enum('Distributore','VAD','Rivenditore','Aggregatore') NOT NULL DEFAULT 'Distributore',
  `website` varchar(255) DEFAULT NULL,
  `address` varchar(300) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(5) DEFAULT NULL,
  `vat_number` varchar(30) DEFAULT NULL,
  `status` enum('active','paused','inactive') NOT NULL DEFAULT 'active',
  `commercial_name` varchar(150) DEFAULT NULL,
  `commercial_email` varchar(150) DEFAULT NULL,
  `commercial_phone` varchar(30) DEFAULT NULL,
  `academy_name` varchar(150) DEFAULT NULL,
  `academy_email` varchar(150) DEFAULT NULL,
  `academy_phone` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `distributors`
--

INSERT INTO `distributors` (`id`, `name`, `type`, `website`, `address`, `city`, `province`, `vat_number`, `status`, `commercial_name`, `commercial_email`, `commercial_phone`, `academy_name`, `academy_email`, `academy_phone`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'TD Synnex', 'Distributore', 'https://it.tdsynnex.com/', NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 08:01:05', '2026-04-07 08:01:05'),
(2, 'ComputerGross', 'Distributore', 'https://www.computergross.it/', NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 08:56:41', '2026-04-07 08:56:41'),
(3, 'Ingram Micro', 'Distributore', 'https://www.ingrammicro.com/', NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:50:29', '2026-04-07 12:50:29'),
(4, 'Exclusive-Networks', 'Distributore', 'https://www.exclusive-networks.com/it', NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:51:51', '2026-04-07 12:51:51'),
(5, 'Esprinet', 'Distributore', 'https://www.esprinet.com/it/', NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-07 12:53:03', '2026-04-07 12:53:03');

-- --------------------------------------------------------

--
-- Struttura della tabella `document_access_rules`
--

CREATE TABLE `document_access_rules` (
  `id` int(11) NOT NULL,
  `doc_type` varchar(50) NOT NULL,
  `role_id` int(11) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_download` tinyint(1) NOT NULL DEFAULT 0,
  `can_upload` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `document_access_rules`
--

INSERT INTO `document_access_rules` (`id`, `doc_type`, `role_id`, `can_view`, `can_download`, `can_upload`, `can_delete`) VALUES
(1, 'cv', 1, 1, 1, 1, 1),
(2, 'lettera_presentazione', 1, 1, 1, 1, 1),
(3, 'note_selezione', 1, 1, 1, 1, 1),
(4, 'test_tecnico', 1, 1, 1, 1, 1),
(5, 'test_psicologico', 1, 1, 1, 1, 1),
(6, 'valutazione', 1, 1, 1, 1, 1),
(7, 'contratto', 1, 1, 1, 1, 1),
(8, 'certificato_formazione', 1, 1, 1, 1, 1),
(9, 'documento_identita', 1, 1, 1, 1, 1),
(10, 'altro', 1, 1, 1, 1, 1),
(11, 'cv', 2, 1, 1, 1, 1),
(12, 'lettera_presentazione', 2, 1, 1, 1, 1),
(13, 'note_selezione', 2, 1, 1, 1, 1),
(14, 'test_tecnico', 2, 1, 1, 1, 1),
(15, 'test_psicologico', 2, 1, 1, 1, 1),
(16, 'valutazione', 2, 1, 1, 1, 1),
(17, 'contratto', 2, 1, 1, 1, 1),
(18, 'certificato_formazione', 2, 1, 1, 1, 1),
(19, 'documento_identita', 2, 1, 1, 1, 1),
(20, 'altro', 2, 1, 1, 1, 1),
(21, 'cv', 3, 1, 1, 0, 0),
(22, 'test_tecnico', 3, 1, 1, 0, 0),
(23, 'certificato_formazione', 3, 1, 1, 0, 0),
(24, 'valutazione', 3, 1, 0, 0, 0),
(25, 'cv', 4, 1, 1, 0, 0),
(26, 'test_tecnico', 4, 1, 1, 0, 0),
(27, 'certificato_formazione', 4, 1, 1, 0, 0),
(28, 'cv', 5, 1, 1, 1, 0),
(29, 'lettera_presentazione', 5, 1, 1, 1, 0),
(30, 'note_selezione', 5, 1, 1, 1, 0),
(31, 'test_tecnico', 5, 1, 1, 1, 0),
(32, 'test_psicologico', 5, 1, 1, 1, 0),
(33, 'cv', 6, 1, 1, 0, 0),
(34, 'certificato_formazione', 6, 1, 1, 0, 0);

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

--
-- Dump dei dati per la tabella `email_log`
--

INSERT INTO `email_log` (`id`, `recipient`, `subject`, `status`, `error_msg`, `smtp_response`, `module`, `related_id`, `sent_by`, `created_at`) VALUES
(1, 'antonello.orru@wetechs.it', '[certV] Email di test SMTP', 'sent', NULL, '09:53:03 [CONNECT] ssl://smtps.aruba.it:465\n09:53:04 [RECV] 220 pepi1sm-sfepd05.ad.aruba.it Aruba SMTP ESMTP server ready\n09:53:04 [SEND] EHLO DESKTOP-RSB0KTR\n09:53:04 [RECV] 250-pepi1sm-sfepd05.ad.aruba.it hello [45.137.237.134], pleased to meet you\r\n250-HELP\r\n250-AUTH LOGIN PLAIN\r\n250-SIZE 524288000\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250 OK\n09:53:04 [SEND] AUTH LOGIN\n09:53:04 [RECV] 334 VXNlcm5hbWU6\n09:53:04 [SEND] YW50b25lbGxvLm9ycnVAYW9zcy5ldQ==\n09:53:04 [RECV] 334 UGFzc3dvcmQ6\n09:53:04 [SEND] I0FsMyRzNG5kcjAjT3JydSMxOTc1\n09:53:04 [RECV] 235 2.7.0 ... authentication succeeded\n09:53:04 [AUTH] LOGIN riuscita\n09:53:04 [SEND] MAIL FROM:<antonello.orru@aoss.eu>\n09:53:04 [RECV] 250 2.1.0 <antonello.orru@aoss.eu> sender ok\n09:53:04 [SEND] RCPT TO:<antonello.orru@wetechs.it>\n09:53:04 [RECV] 250 2.1.5 <antonello.orru@wetechs.it> recipient ok\n09:53:04 [SEND] DATA\n09:53:04 [RECV] 354 OK\n09:53:04 [DATA] (message body)\n09:53:04 [RECV] 250 2.0.0 A1F9wbYHgrbJKA1F9w7ULA mail accepted for delivery\n09:53:04 [SEND] QUIT\n09:53:04 [RECV] 221 2.0.0 pepi1sm-sfepd05.ad.aruba.it Aruba SMTP closing connection', 'test', NULL, 1, '2026-04-07 07:53:04'),
(2, '1', '[Portale Integrato Governance, Competenze & Recruiting] Richiesta \"Evento Fortinet\" approvata', 'failed', 'Comando \'RCPT TO:<1>\' fallito. Risposta: 550 5.5.0 <1> invalid address \'1\'', NULL, 'segreteria', 2, 1, '2026-04-20 10:51:56'),
(3, 'antonello.orru@wetechs.it', '[certV] Email di test SMTP', 'sent', NULL, '10:58:38 [CONNECT] ssl://smtps.aruba.it:465\n10:58:38 [RECV] 220 pepi1sm-sfepd20.ad.aruba.it Aruba SMTP ESMTP server ready\n10:58:38 [SEND] EHLO DESKTOP-RSB0KTR\n10:58:38 [RECV] 250-pepi1sm-sfepd20.ad.aruba.it hello [45.137.237.134], pleased to meet you\r\n250-HELP\r\n250-AUTH LOGIN PLAIN\r\n250-SIZE 524288000\r\n250-ENHANCEDSTATUSCODES\r\n250-8BITMIME\r\n250 OK\n10:58:38 [SEND] AUTH LOGIN\n10:58:38 [RECV] 334 VXNlcm5hbWU6\n10:58:38 [SEND] YW50b25lbGxvLm9ycnVAYW9zcy5ldQ==\n10:58:38 [RECV] 334 UGFzc3dvcmQ6\n10:58:38 [SEND] I0FsMyRzNG5kcjAjT3JydSMxOTc1\n10:58:38 [RECV] 235 2.7.0 ... authentication succeeded\n10:58:38 [AUTH] LOGIN riuscita\n10:58:38 [SEND] MAIL FROM:<antonello.orru@aoss.eu>\n10:58:38 [RECV] 250 2.1.0 <antonello.orru@aoss.eu> sender ok\n10:58:38 [SEND] RCPT TO:<antonello.orru@wetechs.it>\n10:58:38 [RECV] 250 2.1.5 <antonello.orru@wetechs.it> recipient ok\n10:58:38 [SEND] DATA\n10:58:38 [RECV] 354 OK\n10:58:38 [DATA] (message body)\n10:58:38 [RECV] 250 2.0.0 I0kdw1UNlEZ9tI0kdwRIwb mail accepted for delivery\n10:58:38 [SEND] QUIT\n10:58:38 [RECV] 221 2.0.0 pepi1sm-sfepd20.ad.aruba.it Aruba SMTP closing connection', 'test', NULL, 1, '2026-04-29 08:58:38');

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
(2, 1, 3, 3, 'Antonello', 'Orru\'', 'RRONNL75A22B354C', NULL, '+393316216747', 'antonello.orru@wetechs.it', '330', 'SERVICE MANAGER', 'IT', 'Indeterminato', '2025-08-01', NULL, 'active', 'Service Manager e Senior IT System Engineer\n\nSono un Professionista IT con un background completo che spazia dall\'amministrazione di sistema alla gestione di team e strategie IT. Attualmente ricopro il ruolo di Service Manager e ho maturato significative esperienze in ruoli chiave come CTO e IT Manager. Il mio percorso, iniziato come System Administrator, mi ha fornito una profonda conoscenza delle infrastrutture e dei processi IT. La mia passione per l\'innovazione si traduce in un approccio concreto alla risoluzione dei problemi, con l\'obiettivo di migliorare i processi e ottimizzare gli investimenti per il raggiungimento degli obiettivi aziendali. Grazie all\'esperienza maturata in contesti eterogenei, dalla Pubblica Amministrazione a realtà internazionali, possiedo una visione strategica che mi permette di anticipare le sfide tecnologiche e di guidare l\'innovazione, proponendo soluzioni mirate, pragmatiche e sostenibili.\n\n— Esperienze (LinkedIn) —\n• Service manager @ WeTech\'s S.p.A SB (2025-08-01 → in corso)\n  Service Manager Gestionale con focus Analisi Performances Servizi a Valore, Strumenti di gestione e governo dei Servizi, Organizzazione e processi dei Servizi Gestiti, Programmazione e Sviluppo Serviz\n• Service manager @ Go2Tec (2023-10-01 → 2025-07-01)\n• IT Manager @ Go2Tec (2021-10-01 → 2023-09-01)\n  IT Manager presso la Go2Tec srl, Azienda specializzata in IT Solution & Consulting Security, e a dare la giusta soluzione ad ogni esigenza che richieda l’ottimizzazione dei processi di IT. https://w\n• System Administrator Microsoft - VMware @ Pieralisi ❘ Separation Technology Equipment (2020-12-01 → 2021-10-01)\n• Consulente Business Energia @ Sorgenia (2015-01-01 → 2020-12-01)\n  Sviluppo e Consulenza Commerciale\n• Consulente ICT e Tributi Locali. @ AO System & Software (2012-04-01 → 2020-12-01)\n  Consulenza ICT, Consulenza DPS Privacy, DPO, Security Consultant Studi e analisi di fattibilità Amministrazione sistemi P.A. Censimenti Territoriali e di sistema riordino dati settore tributi, settor\n• Consulente Energetico Business @ Repower Italia (2014-05-01 → 2014-12-01)\n  Sviluppo e Consulenza commerciale\n• Key Account Manager @ Vodafone Italy (2014-04-01 → 2014-12-01)\n  Sviluppo e Commercializzazione settore Business\n• CONSULENTE ENERGIA - SMART AGENT @ ENEL ENERGIA SPA (2012-09-01 → 2013-12-01)\n  CONSULENZA E SVILUPPO COMMERCIALE PRESSO P.A. E P.M.I.\n• CONSULENTE IT,TRIBUTI LOCALI, AMMINISTRATORE DI SISTEMA, @ NICOLA ZUDDAS SRL (2004-09-01 → 2012-03-01)\n  CONSULENTE ITC,TRIBUTI LOCALI, AMMINISTRATORE DI SISTEMA', 'Troubleshooting, Controllo dei costi, Team di progetto, Soft skill, Competenze analitiche, Lavoro di squadra, ERP, Flusso di dati, Processi aziendali, Computer Dell, Retrospettive, Infrastructure Projects, Piani di progetto, Informatica, Comunicazione, Office 365, Gestione degli incidenti, Lingua italiana, Computer Hardware, Comunicazione interpersonale, Business Systems Analysis, Business analitics, Test di penetrazione, Console, Gestione dei dati master, Infrastruttura di rete, Gestione dei dispositivi mobili, Vulnerability Assessment, Amministrazione delle reti, Analisi funzionale, Competenze interpersonali, Design di processi, Analisi dei dati, Stesura di budget, Gestione vendor, Gestione fornitori, Pianificazione strategica, Coordinamento progetti, Gestione dei rapporti commerciali, Analisi dei requisiti, Analisi aziendale, Redazione di offerte, Coordinamento del team, ITIL, Capacità di ragionamento, Principi contabili, Gestione dei cambiamenti, Gestione servizi IT, Soddisfazione del cliente, Service delivery, Gestione IT, Linux, Database, Cloud computing, Sicurezza, Integrazione, Telecomunicazioni, Project management, Business plan, Gestione vendite, Strategia di marketing, Pre-sales, Security, Consulenza, project managment, Firewall, Information Technology, Legge sulla privacy, Amministrazione sistemi, Assistenza tecnica, Microsoft Office, SQL, MySQL, Java, PHP, XML, Microsoft Windows, Windows Server, Microsoft Hyper-V, Citrix, Windows PowerShell, Router, Switch, IIS, Active Directory, VMware, Management, Trattative, Assistenza clienti, Gestione team, Formazione, Gestione dello stress, Problem solving, Formazione del cliente, Oracle, GDPR DATA PROTECTION OFFICER, Reti, Leadership, Consulenza informatica, Trattamento dei dati personali', NULL, 'cv_emp2_1777450964.pdf', NULL, '2026-03-29 21:15:19', '2026-05-15 10:34:48'),
(3, 1, 1, 3, 'ALESSANDRO', 'MACINAI', NULL, NULL, NULL, 'alessandro.macinai@wetechs.it', NULL, 'Responsabile Coordinatore Commerciale', 'Commerciale', 'Partita IVA', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-03-31 07:46:44', '2026-03-31 07:47:23'),
(4, 1, 1, 1, 'DAMINANO', 'FOSSATI', NULL, NULL, NULL, 'damiano.fossati@wetechs.it', NULL, 'Responsabile Acquisti / Procurament', 'Acquisti / Procurament', 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-03-31 07:48:41', '2026-03-31 07:48:41'),
(5, 1, 1, 1, 'Alessandro', 'macinai', NULL, NULL, NULL, 'alessandro.macinai@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'terminated', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 15:01:05'),
(6, 1, 4, 1, 'Massimo', 'Anzidei', NULL, NULL, NULL, 'massimo.anzidei@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(7, 1, 4, 1, 'Marco', 'Ayed', NULL, NULL, NULL, 'marco.ayed@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(8, 1, 4, 1, 'Leonardo', 'Baggiani', NULL, NULL, NULL, 'leonardo.baggiani@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(9, 1, 1, 1, 'Marco', 'Baldinelli', NULL, NULL, NULL, 'marco.baldinelli@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(10, 1, 4, 1, 'Paolo', 'Balestrieri', NULL, NULL, NULL, 'paolo.balestrieri@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(11, 1, 4, 1, 'Paolo', 'Baruchello', NULL, NULL, NULL, 'paolo.baruchello@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(12, 1, 4, 1, 'Lorenzo', 'Bessi', NULL, NULL, NULL, 'lorenzo.bessi@wetechs.it', NULL, 'Tecnico Hardware Assist. Clienti', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(13, 1, 2, 1, 'Roberto', 'Bollani', NULL, NULL, NULL, 'roberto.bollani@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(14, 1, 4, 1, 'Duccio', 'Bordoni', NULL, NULL, NULL, 'duccio.bordoni@wetechs.it', NULL, 'Tec. Inst. Hard/Soft/Reti', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(15, 1, 4, 1, 'Francesco', 'Brandi', NULL, NULL, NULL, 'francesco.brandi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(16, 1, 4, 1, 'Emanuele', 'Bressi', NULL, NULL, NULL, 'emanuele.bressi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(17, 1, 6, 1, 'Giorgio', 'Bruschi', NULL, NULL, NULL, 'giorgio.bruschi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(18, 1, 1, 1, 'Daniele', 'Capelletti', NULL, NULL, NULL, 'daniele.cappelletti@wetechs.it', NULL, 'Direttore IT', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(19, 1, 3, 1, 'Sebastiano', 'Chiarini', NULL, NULL, NULL, 'sebastiano.chiarini@wetechs.it', NULL, 'IT Support area Service Desk', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(20, 1, 1, 1, 'Enrico', 'Ciorciolini', NULL, NULL, NULL, 'enrico.ciorciolini@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(21, 1, 4, 1, 'Mirko', 'Civaro', NULL, NULL, NULL, 'mirko.civaro@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(22, 1, 1, 1, 'Edoardo', 'Colonna', NULL, NULL, NULL, 'edoardo.colonna@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(23, 1, 4, 1, 'Ciaran', 'Conway', NULL, NULL, NULL, 'ciaran.conway@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(24, 1, 1, 1, 'Leonardo', 'Corsi', NULL, NULL, NULL, 'leonardo.corsi@wetechs.it', NULL, 'Add. Back office Vendite', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(25, 1, 1, 1, 'Andrea', 'Cristofano', NULL, NULL, NULL, 'andrea.cristofano@wetechs.it', NULL, 'Costumare Service', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(26, 1, 1, 1, 'Ennio', 'De Mitri', NULL, NULL, NULL, 'ennio.demitri@wetechs.it', NULL, 'Tec. Macc.', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(27, 1, 3, 1, 'Paolo', 'Di Pirro', NULL, NULL, NULL, 'paolo.dipirro@wetechs.it', NULL, 'Resp. Tec. Impiant', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(28, 1, 5, 1, 'Andrea', 'D\'Innocenzo', NULL, NULL, NULL, 'andrea.dinnocenzo@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(29, 1, 4, 1, 'Ergis', 'Kocumi', NULL, NULL, NULL, 'ergis.kocumi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(30, 1, 1, 1, 'Antonello', 'Esposito', NULL, NULL, NULL, 'antonello.esposito@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(31, 1, 1, 1, 'Paolo', 'Failla', NULL, NULL, NULL, 'paolo.failla@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(32, 1, 1, 1, 'Greta', 'Ferrante', NULL, NULL, NULL, 'greta.ferrante@wetechs.it', NULL, 'Service Desk', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(33, 1, 6, 1, 'Michele', 'Fionga', NULL, NULL, NULL, 'michele.fionga@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(34, 1, 1, 1, 'Damiano', 'Fossati', NULL, NULL, NULL, 'damiano.fossati@wetechs.it', NULL, 'Resp. Acquisti', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(35, 1, 1, 1, 'Fabio', 'Franci', NULL, NULL, NULL, 'fabio.franci@wetechs.it', NULL, 'Programmatore Man', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(36, 1, 2, 1, 'Luca', 'Garavaglia', NULL, NULL, NULL, 'luca.garavaglia@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(37, 1, 1, 1, 'Giulia', 'Giustelli', NULL, NULL, NULL, 'giulia.giustelli@wetechs.it', NULL, 'Imp. Back Office Comm.', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(38, 1, 3, 1, 'Chiara', 'Gori', NULL, NULL, NULL, 'chiara.gori@wetechs.it', NULL, 'Tec. Cablatore', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(39, 1, 6, 1, 'Cristiano', 'Griffoni', NULL, NULL, NULL, 'cristiano.griffoni@wetechs.it', NULL, 'Back Office', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(40, 1, 4, 1, 'Giacomo', 'Guerrini', NULL, NULL, NULL, 'giacomo.guerrini@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(41, 1, 6, 1, 'Giacomo', 'Guidarelli', NULL, NULL, NULL, 'giacomo.guidarelli@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(42, 1, 6, 1, 'Paolo', 'Iacone', NULL, NULL, NULL, 'paolo.iacone@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(43, 1, 4, 1, 'Alessandro', 'Imbrosciano', NULL, NULL, NULL, 'alessandro.imbrosciano@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(44, 1, 3, 1, 'Mirko', 'Iovenitti', NULL, NULL, NULL, 'mirko.iovenitti@wetechs.it', NULL, 'Tec. Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(45, 1, 5, 1, 'Rocca Mario', 'La', NULL, NULL, NULL, 'roccamario.la@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(46, 1, 1, 1, 'Filippo', 'Lucherini', NULL, NULL, NULL, 'filippo.lucherini@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(47, 1, 4, 1, 'Alessandro', 'Magi', NULL, NULL, NULL, 'alessandro.magi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(48, 1, 5, 1, 'Cristiano', 'Mancinelli', NULL, NULL, NULL, 'cristiano.mancinelli@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(49, 1, 3, 1, 'Enrico', 'Mancini', NULL, NULL, NULL, 'enrico.mancini@wetechs.it', NULL, 'Service Desk', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(50, 1, 4, 1, 'Marco', 'Marziali', NULL, NULL, NULL, 'marco.marziali@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(51, 1, 4, 1, 'Matteo', 'Marziali', NULL, NULL, NULL, 'matteo.marziali@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(52, 1, 4, 1, 'Simone', 'Masi', NULL, NULL, NULL, 'simone.masi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(53, 1, 4, 1, 'Fabrizio', 'Meli', NULL, NULL, NULL, 'fabrizio.meli@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(54, 1, 4, 1, 'Davide', 'Minneci', NULL, NULL, NULL, 'davide.minneci@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(55, 1, 4, 1, 'Fabio', 'Montingelli', NULL, NULL, NULL, 'fabio.montingelli@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(56, 1, 4, 1, 'Nicola', 'Morandini', NULL, NULL, NULL, 'nicola.morandini@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(57, 1, 4, 1, 'Riccardo', 'Niccoli', NULL, NULL, NULL, 'riccardo.niccoli@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(58, 1, 5, 1, 'Roberto', 'Ortolani', NULL, NULL, NULL, 'roberto.ortolani@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(59, 1, 4, 1, 'Matteo', 'Pacini', NULL, NULL, NULL, 'matteo.pacini@wetechs.it', NULL, 'Tecnico Hardware Assist. Clienti', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(60, 1, 4, 1, 'Gabriel', 'Parisi', NULL, NULL, NULL, 'gabriel.parisi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(61, 1, 1, 1, 'Daniele', 'Pasquini', NULL, NULL, NULL, 'daniele.pasquini@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(62, 1, 1, 1, 'Gabriele', 'Passeri', NULL, NULL, NULL, 'gabriele.passeri@wetechs.it', NULL, 'Op. Tec. Informati', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(63, 1, 4, 1, 'Michele', 'Passiatore', NULL, NULL, NULL, 'michele.passiatore@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(64, 1, 4, 1, 'Giacomo', 'Pierotti', NULL, NULL, NULL, 'giacomo.pierotti@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(65, 1, 1, 1, 'Guia', 'Prunai', NULL, NULL, NULL, 'guia.prunai@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(66, 1, 1, 1, 'Sergio', 'Puggelli', NULL, NULL, NULL, 'sergio.puggelli@wetechs.it', NULL, 'Responsabile Area NO', 'Commerciale Mips', 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 15:00:20'),
(67, 1, 4, 1, 'Giuseppe', 'Quercia', NULL, NULL, NULL, 'giuseppe.quercia@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(68, 1, 1, 1, 'Massimiliano', 'Ripari', NULL, NULL, NULL, 'massimiliano.ripari@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(69, 1, 1, 1, 'Alberto', 'Ruffo', NULL, NULL, NULL, 'alberto.ruffo@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(70, 1, 1, 1, 'Claudio', 'Sandroni', NULL, NULL, NULL, 'claudio.sandroni@wetechs.it', NULL, 'Programmatore', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(71, 1, 1, 1, 'Walter', 'Scotto', NULL, NULL, NULL, 'walter.scotto@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(72, 1, 1, 1, 'Maurizio', 'Secchi', NULL, NULL, NULL, 'maurizio.secchi@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(73, 1, 4, 1, 'Alessio', 'Senesi', NULL, NULL, NULL, 'alessio.senesi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(74, 1, 4, 1, 'Andrea', 'Sestini', NULL, NULL, NULL, 'andrea.sestini@wetechs.it', NULL, 'Addetto a sistemi e reti infor.', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(75, 1, 6, 1, 'Fabrizio', 'Simeone', NULL, NULL, NULL, 'fabrizio.simeone@wetechs.it', NULL, 'Man. di reti e sistemi infor.', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(76, 1, 4, 1, 'David', 'Sozzi', NULL, NULL, NULL, 'david.sozzi@wetechs.it', NULL, 'Tec. Computer', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(77, 1, 4, 1, 'Xavien', 'Tremayne', NULL, NULL, NULL, 'xavien.tremayne@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(78, 1, 4, 1, 'Mirko', 'Vadi', NULL, NULL, NULL, 'mirko.vadi@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(79, 1, 1, 1, 'Tiziana', 'Verdina', NULL, NULL, NULL, 'tiziana.verdina@wetechs.it', NULL, 'Commerciale', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(80, 1, 1, 1, 'Massimo', 'Zangheri', NULL, NULL, NULL, 'massimo.zangheri@wetechs.it', NULL, 'Op. Tec. Informati', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(81, 1, 3, 1, 'Michele TRII', 'Brunelli', NULL, NULL, NULL, 'michele.brunelli@wetechs.it', NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 14:36:21', '2026-04-07 14:36:21'),
(82, 1, 4, 1, 'Erika', 'Franceschini', NULL, NULL, '+39 05588786199', 'erika.franceschini@wetechs.it', NULL, 'Impiegato', 'HR', 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 15:06:11', '2026-04-07 15:06:11'),
(83, 1, 4, 3, 'Monica', 'Fanciulli', NULL, NULL, NULL, 'monica.fanciulli@wetechs.it', NULL, 'Responsabile HR', 'HR', 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 15:07:01', '2026-04-07 15:07:01'),
(84, 1, 4, 1, 'Rebecca', 'Spagnuolo', NULL, NULL, NULL, 'rebecca.spagnuolo@wetechs.it', NULL, 'Impiegato', 'HR', 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-07 15:08:17', '2026-04-07 15:08:17'),
(85, 4, 3, 3, 'Joele', 'Milazzo', NULL, NULL, NULL, 'joele.milazzo@nisgroup.it', NULL, 'Amministratore Delegato', 'Cyber', 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-08 07:12:40', '2026-04-08 07:12:40'),
(86, 4, 10, 3, 'Irni', 'Nushi', NULL, NULL, NULL, 'irni.nushi@nisgroup.it', NULL, 'Coordinatore SOC', 'Cyber', 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-08 07:13:49', '2026-04-08 07:13:49'),
(87, 3, NULL, 1, 'lorenzo', 'Buschi', NULL, NULL, NULL, 'lorenzo.buschi@wetechs.it', NULL, NULL, 'IT', 'Indeterminato', '1990-01-01', NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-29 14:54:09', '2026-04-29 14:54:09'),
(88, 1, NULL, 3, 'ALBERTO', 'GUERCINI', NULL, NULL, NULL, 'alberto.guercini@wetechs.it', NULL, 'Commerciale', 'Supporto alla Direzione Commerciale', 'Partita IVA', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-29 15:35:28', '2026-04-29 15:35:28'),
(89, 1, NULL, 3, 'Jonatan', 'Motta', NULL, NULL, NULL, 'jonatan.motta@wetechs.it', NULL, 'Commerciale', 'Coordinatore Commerciale', 'Partita IVA', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-04-29 15:36:15', '2026-04-29 15:36:15'),
(90, NULL, NULL, NULL, 'Andrea', 'Terreni', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Indeterminato', NULL, NULL, 'terminated', NULL, NULL, NULL, NULL, NULL, '2026-04-29 15:36:49', '2026-04-29 15:36:49'),
(91, NULL, NULL, NULL, 'Emanuele', 'Ambrosio', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Indeterminato', NULL, NULL, 'terminated', NULL, NULL, NULL, NULL, NULL, '2026-04-29 15:37:24', '2026-04-29 15:37:24'),
(92, 2, NULL, 3, 'Biagio', 'Monello', NULL, NULL, NULL, NULL, NULL, 'Sistemista', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-05-15 12:45:49', '2026-05-15 12:45:49'),
(93, 1, NULL, 3, 'Mirko', 'Imbrogno', NULL, NULL, NULL, 'mirko.imbrogno@wetechs.it', NULL, 'sisteista junior', NULL, 'Indeterminato', NULL, NULL, 'active', NULL, NULL, NULL, NULL, NULL, '2026-05-15 13:08:06', '2026-05-15 13:08:06'),
(94, 1, NULL, 1, 'Erika', 'Franceschini', NULL, NULL, NULL, 'erika.franceschini@wetechs.it', NULL, 'Apprendista', 'HR', 'Indeterminato', NULL, NULL, 'terminated', NULL, NULL, NULL, NULL, NULL, '2026-05-15 13:27:37', '2026-05-15 13:28:26');

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
-- Struttura della tabella `employee_credly_link`
--

CREATE TABLE `employee_credly_link` (
  `employee_id` int(11) NOT NULL,
  `credly_username` varchar(150) NOT NULL COMMENT 'Slug Credly (es. lorenzo-buschi) o UUID',
  `last_sync_at` datetime DEFAULT NULL,
  `last_sync_imported` int(11) NOT NULL DEFAULT 0,
  `last_sync_updated` int(11) NOT NULL DEFAULT 0,
  `last_sync_unmatched` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Collegamento dipendente ↔ profilo pubblico Credly';

--
-- Dump dei dati per la tabella `employee_credly_link`
--

INSERT INTO `employee_credly_link` (`employee_id`, `credly_username`, `last_sync_at`, `last_sync_imported`, `last_sync_updated`, `last_sync_unmatched`, `created_by`, `created_at`, `updated_at`) VALUES
(7, 'marco-ayed', '2026-05-15 15:09:52', 0, 0, 0, 1, '2026-05-15 10:46:24', '2026-05-15 13:09:52'),
(8, 'leonardo-baggiani', '2026-05-15 15:09:54', 0, 0, 0, 1, '2026-05-15 12:04:52', '2026-05-15 13:09:54'),
(11, 'paolo-baruchello', '2026-05-15 15:09:55', 0, 0, 0, 1, '2026-05-15 10:48:21', '2026-05-15 13:09:55'),
(14, 'duccio-bordoni', '2026-05-15 15:09:56', 0, 0, 0, 1, '2026-05-15 12:04:05', '2026-05-15 13:09:56'),
(15, 'francesco-brandi', '2026-05-15 15:09:57', 0, 0, 0, 1, '2026-05-15 12:07:30', '2026-05-15 13:09:57'),
(16, 'emanuele-bressi', '2026-05-15 15:09:58', 0, 0, 0, 1, '2026-05-15 12:06:24', '2026-05-15 13:09:58'),
(17, 'giorgio-bruschi', '2026-05-15 15:10:02', 0, 0, 0, 1, '2026-05-15 12:14:39', '2026-05-15 13:10:02'),
(19, 'sebastiano-chiarini', '2026-05-15 15:10:09', 0, 0, 0, 1, '2026-05-15 12:12:39', '2026-05-15 13:10:09'),
(22, 'edoardo-colonna', '2026-05-15 15:10:10', 0, 0, 0, 1, '2026-05-15 12:15:41', '2026-05-15 13:10:10'),
(23, 'ciaran-conway', '2026-05-15 15:10:11', 0, 0, 0, 1, '2026-05-15 10:51:09', '2026-05-15 13:10:11'),
(24, 'leonardo-corsi', '2026-05-15 15:10:12', 0, 0, 0, 1, '2026-05-15 12:16:17', '2026-05-15 13:10:12'),
(25, 'andrea-cristofano', '2026-05-15 15:10:13', 0, 0, 0, 1, '2026-05-15 12:20:21', '2026-05-15 13:10:13'),
(29, 'ergis-kocumi', '2026-05-15 15:10:19', 0, 0, 0, 1, '2026-05-15 12:39:12', '2026-05-15 13:10:19'),
(31, 'paolo-failla', '2026-05-15 15:10:13', 0, 0, 0, 1, '2026-05-15 12:18:08', '2026-05-15 13:10:13'),
(33, 'michele-fionga', '2026-05-15 15:10:14', 0, 0, 0, 1, '2026-05-15 12:36:42', '2026-05-15 13:10:14'),
(40, 'giacomo-guerrini', '2026-05-15 15:10:17', 1, 0, 0, 1, '2026-05-15 13:03:54', '2026-05-15 13:10:17'),
(43, 'alessandro-imbrosciano', '2026-05-15 15:10:18', 0, 0, 0, 1, '2026-05-15 12:03:01', '2026-05-15 13:10:18'),
(49, 'enrico-mancini', '2026-05-15 15:10:20', 0, 0, 0, 1, '2026-05-15 12:43:29', '2026-05-15 13:10:20'),
(50, 'marco-marziali', '2026-05-15 15:10:21', 0, 0, 0, 1, '2026-05-15 12:38:14', '2026-05-15 13:10:21'),
(53, 'fabrizio-meli', '2026-05-15 15:10:22', 0, 0, 0, 1, '2026-05-15 10:53:28', '2026-05-15 13:10:22'),
(64, 'giacomo-pierotti', '2026-05-15 15:10:57', 6, 0, 0, 1, '2026-05-15 13:10:50', '2026-05-15 13:10:57'),
(69, 'alberto-ruffo', '2026-05-15 15:10:28', 0, 0, 0, 1, '2026-05-15 12:19:18', '2026-05-15 13:10:28'),
(74, 'andrea-sestini', '2026-05-15 15:10:30', 0, 0, 0, 1, '2026-05-15 12:25:29', '2026-05-15 13:10:30'),
(76, 'david-sozzi', '2026-05-15 15:10:30', 0, 0, 0, 1, '2026-05-15 10:45:38', '2026-05-15 13:10:30'),
(78, 'mirko-vadi', '2026-05-15 15:10:33', 0, 0, 0, 1, '2026-05-15 10:47:22', '2026-05-15 13:10:33'),
(79, 'tiziana-verdina', '2026-05-15 15:10:34', 0, 0, 0, 1, '2026-05-15 12:21:00', '2026-05-15 13:10:34'),
(81, 'michele-brunelli', '2026-05-15 15:10:01', 0, 0, 0, 1, '2026-05-15 12:08:17', '2026-05-15 13:10:01'),
(85, 'joele-milazzo', '2026-05-15 15:10:23', 0, 0, 0, 1, '2026-05-15 12:41:09', '2026-05-15 13:10:23'),
(86, 'irni-nushi', '2026-05-15 15:10:27', 0, 0, 0, 1, '2026-05-15 13:01:21', '2026-05-15 13:10:27'),
(87, 'lorenzo-buschi', '2026-05-15 15:10:07', 0, 0, 0, 1, '2026-05-15 10:44:20', '2026-05-15 13:10:07'),
(88, 'alberto-guercini', '2026-05-15 15:10:16', 0, 0, 0, 1, '2026-05-15 12:42:26', '2026-05-15 13:10:16'),
(92, 'biagio-monello', '2026-05-15 15:10:26', 0, 0, 0, 1, '2026-05-15 12:49:11', '2026-05-15 13:10:26');

-- --------------------------------------------------------

--
-- Struttura della tabella `employee_linkedin_link`
--

CREATE TABLE `employee_linkedin_link` (
  `employee_id` int(11) NOT NULL,
  `linkedin_vanity` varchar(150) NOT NULL COMMENT 'Vanity LinkedIn (es. a-orru750122)',
  `last_sync_at` datetime DEFAULT NULL,
  `last_sync_imported` int(11) NOT NULL DEFAULT 0,
  `last_sync_updated` int(11) NOT NULL DEFAULT 0,
  `last_sync_unmatched` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Collegamento dipendente ↔ profilo LinkedIn';

--
-- Dump dei dati per la tabella `employee_linkedin_link`
--

INSERT INTO `employee_linkedin_link` (`employee_id`, `linkedin_vanity`, `last_sync_at`, `last_sync_imported`, `last_sync_updated`, `last_sync_unmatched`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 'a-orru750122', '2026-05-15 12:34:48', 10, 0, 0, 1, '2026-05-15 10:08:34', '2026-05-15 10:34:48');

-- --------------------------------------------------------

--
-- Struttura della tabella `employee_skills`
--

CREATE TABLE `employee_skills` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `skill_name` varchar(150) NOT NULL,
  `level` enum('beginner','intermediate','advanced','expert') NOT NULL DEFAULT 'intermediate',
  `years` decimal(4,1) DEFAULT NULL COMMENT 'Anni di esperienza',
  `last_used` date DEFAULT NULL,
  `self_assessed` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = autovalutata, 0 = validata da team leader',
  `validated_by` int(11) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Skill matrix delle risorse (autovalutate o validate)';

-- --------------------------------------------------------

--
-- Struttura della tabella `entity_change_log`
--

CREATE TABLE `entity_change_log` (
  `id` bigint(20) NOT NULL,
  `entity_table` varchar(60) NOT NULL COMMENT 'Tabella interessata (employees, certifications, ...)',
  `entity_id` int(11) NOT NULL,
  `field_name` varchar(80) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `change_action` enum('insert','update','approve','reject','delete') NOT NULL DEFAULT 'update',
  `change_source` enum('import','ui','api','migration','system') NOT NULL DEFAULT 'ui',
  `source_ref_id` int(11) DEFAULT NULL COMMENT 'FK polimorfico: import_jobs.id, ecc.',
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit cross-tabella: storicizzazione di TUTTI i campi modificati';

--
-- Dump dei dati per la tabella `entity_change_log`
--

INSERT INTO `entity_change_log` (`id`, `entity_table`, `entity_id`, `field_name`, `old_value`, `new_value`, `change_action`, `change_source`, `source_ref_id`, `changed_by`, `changed_at`) VALUES
(1, 'technologies', 2, '__delete__', '1', NULL, 'update', 'ui', NULL, 1, '2026-05-06 09:57:23'),
(2, 'technologies', 11, 'name', 'Microsoft', 'Sistemi', 'update', 'ui', NULL, 1, '2026-05-06 16:16:07'),
(3, 'certifications', 90, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:12:09'),
(4, 'certifications', 91, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:12:09'),
(5, 'certifications', 92, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:12:09'),
(6, 'certifications', 93, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(7, 'certifications', 93, 'level', 'Professional', 'Foundation', 'update', 'import', 4, 1, '2026-05-07 18:16:18'),
(8, 'certifications', 94, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(9, 'certifications', 95, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(10, 'certifications', 95, 'technology_id', '10', '3', 'update', 'import', 4, 1, '2026-05-07 18:16:18'),
(11, 'certifications', 95, 'name', 'Fortinet Certified Professional - Network Security', 'Fortinet Certified Professional - Security Operations', 'update', 'import', 4, 1, '2026-05-07 18:16:18'),
(12, 'certifications', 96, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(13, 'certifications', 96, 'level', 'Professional', 'Expert', 'update', 'import', 4, 1, '2026-05-07 18:16:18'),
(14, 'certifications', 97, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(15, 'certifications', 98, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(16, 'certifications', 99, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(17, 'certifications', 100, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(18, 'certifications', 101, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(19, 'certifications', 102, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(20, 'certifications', 103, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(21, 'certifications', 104, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(22, 'certifications', 105, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(23, 'certifications', 106, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(24, 'certifications', 107, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(25, 'certifications', 108, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(26, 'certifications', 109, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(27, 'certifications', 110, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(28, 'certifications', 111, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(29, 'certifications', 112, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(30, 'certifications', 113, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(31, 'certifications', 114, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(32, 'certifications', 115, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(33, 'certifications', 116, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(34, 'certifications', 117, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(35, 'certifications', 118, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(36, 'certifications', 119, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(37, 'certifications', 120, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(38, 'certifications', 121, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(39, 'certifications', 122, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(40, 'certifications', 123, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18'),
(41, 'certifications', 124, '__create__', NULL, '1', 'insert', 'import', 4, 1, '2026-05-07 18:16:18');

-- --------------------------------------------------------

--
-- Struttura della tabella `enum_proposals`
--

CREATE TABLE `enum_proposals` (
  `id` int(11) NOT NULL,
  `target_table` varchar(60) NOT NULL COMMENT 'Tabella DB del campo enum (es. certifications)',
  `target_column` varchar(60) NOT NULL COMMENT 'Nome colonna enum (es. level, category)',
  `proposed_value` varchar(100) NOT NULL COMMENT 'Nuovo valore proposto (es. Senior)',
  `occurrences` int(11) NOT NULL DEFAULT 1 COMMENT 'Quante volte è apparso negli import',
  `status` enum('pending','approved','mapped','rejected') NOT NULL DEFAULT 'pending' COMMENT 'pending=in attesa, approved=esteso enum, mapped=convertito a valore esistente, rejected=ignorato',
  `mapped_to` varchar(100) DEFAULT NULL COMMENT 'Se status=mapped: valore canonico esistente',
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `first_source_ref` int(11) DEFAULT NULL COMMENT 'job_id del primo import che ha generato la proposta',
  `decided_at` datetime DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Proposte di estensione ENUM da import (catalogo.level, ecc.)';

--
-- Dump dei dati per la tabella `enum_proposals`
--

INSERT INTO `enum_proposals` (`id`, `target_table`, `target_column`, `proposed_value`, `occurrences`, `status`, `mapped_to`, `first_seen_at`, `last_seen_at`, `first_source_ref`, `decided_at`, `decided_by`, `notes`) VALUES
(1, 'certifications', 'level', 'Specialist', 4, 'approved', NULL, '2026-05-07 11:04:11', '2026-05-07 14:25:18', 4, '2026-05-07 14:25:18', 1, NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `import_jobs`
--

CREATE TABLE `import_jobs` (
  `id` int(11) NOT NULL,
  `import_type` varchar(40) NOT NULL COMMENT 'dipendenti, brand, candidati, ...',
  `original_name` varchar(255) NOT NULL COMMENT 'Nome del file CSV caricato',
  `file_size` int(11) DEFAULT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `valid_rows` int(11) NOT NULL DEFAULT 0 COMMENT 'Righe valide (passano validazione)',
  `approved_rows` int(11) NOT NULL DEFAULT 0 COMMENT 'Righe approvate manualmente (in attesa di commit)',
  `rejected_rows` int(11) NOT NULL DEFAULT 0 COMMENT 'Righe esplicitamente rifiutate dall''utente',
  `invalid_rows` int(11) NOT NULL DEFAULT 0 COMMENT 'Righe con errori',
  `imported_rows` int(11) NOT NULL DEFAULT 0 COMMENT 'Effettivamente importate (insert+update)',
  `partial_rows` int(11) NOT NULL DEFAULT 0 COMMENT 'Righe importate con LDB (campi mancanti)',
  `skipped_rows` int(11) NOT NULL DEFAULT 0,
  `status` enum('uploaded','queued','processing','validated','partial','partial_lds','imported','aborted','rolled_back') NOT NULL DEFAULT 'uploaded',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `queued_at` datetime DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL COMMENT 'User che ha approvato/avviato il commit',
  `imported_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `allow_partial` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Late Data Binding: 1 = consenti import di righe con campi mancanti'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Storico job di importazione massiva';

--
-- Dump dei dati per la tabella `import_jobs`
--

INSERT INTO `import_jobs` (`id`, `import_type`, `original_name`, `file_size`, `total_rows`, `valid_rows`, `approved_rows`, `rejected_rows`, `invalid_rows`, `imported_rows`, `partial_rows`, `skipped_rows`, `status`, `started_at`, `queued_at`, `validated_at`, `processed_at`, `processed_by`, `imported_at`, `created_by`, `notes`, `allow_partial`) VALUES
(1, 'certificati', 'template_certificati.CSV', 4027, 43, 0, 0, 0, 43, 0, 0, 0, 'aborted', '2026-05-04 12:23:08', NULL, '2026-05-04 12:24:24', NULL, NULL, NULL, 1, NULL, 0),
(2, 'catalogo', '2026.05.05_template_catalogo.csv', 4836, 38, 0, 0, 0, 38, 0, 0, 0, 'aborted', '2026-05-05 10:21:32', NULL, '2026-05-05 10:21:33', NULL, NULL, NULL, 1, NULL, 0),
(3, 'catalogo', '2026.05.05_template_catalogo.csv', 4836, 38, 0, 0, 0, 38, 0, 0, 0, 'aborted', '2026-05-05 11:21:37', NULL, '2026-05-05 11:21:37', NULL, NULL, NULL, 1, NULL, 0),
(4, 'catalogo', '2026.05.05_template_catalogo.csv', 4836, 38, 0, 35, 0, 0, 38, 0, 0, 'imported', '2026-05-06 16:07:00', NULL, '2026-05-07 11:07:53', '2026-05-07 18:16:18', 1, '2026-05-07 18:16:18', 1, NULL, 0);

-- --------------------------------------------------------

--
-- Struttura della tabella `import_partial_completions`
--

CREATE TABLE `import_partial_completions` (
  `id` int(11) NOT NULL,
  `staging_id` int(11) NOT NULL COMMENT 'FK → import_staging_rows',
  `target_table` varchar(60) NOT NULL COMMENT 'Tabella in cui è il record (es. employees)',
  `target_id` int(11) NOT NULL COMMENT 'ID del record importato in modo parziale',
  `field_name` varchar(80) NOT NULL,
  `old_value` text DEFAULT NULL COMMENT 'Valore precedente (NULL se prima compilazione)',
  `new_value` text DEFAULT NULL,
  `completed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit: campi completati manualmente dopo import LDB';

-- --------------------------------------------------------

--
-- Struttura della tabella `import_staging_rows`
--

CREATE TABLE `import_staging_rows` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `row_number` int(11) NOT NULL COMMENT 'Numero di riga nel CSV (1-based)',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Dati riga come oggetto chiave→valore' CHECK (json_valid(`payload`)),
  `status` enum('pending','valid','invalid','partial','approved','rejected','imported','skipped','corrected') NOT NULL DEFAULT 'pending',
  `approved_as` enum('strict','ldb') DEFAULT NULL COMMENT 'Modalità approvazione: strict=tutti i campi obbligatori; ldb=permetti campi mancanti',
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array di errori per campo' CHECK (json_valid(`errors`)),
  `missing_fields` longtext DEFAULT NULL COMMENT 'JSON array di campi originariamente mancanti, per UI di completamento' CHECK (json_valid(`missing_fields`)),
  `result_id` int(11) DEFAULT NULL COMMENT 'ID record creato/aggiornato in caso di successo',
  `result_action` enum('insert','update','skip') DEFAULT NULL,
  `is_partial` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = riga importata con LDB, attende integrazione manuale',
  `imported_at` datetime DEFAULT NULL,
  `last_edit_at` datetime DEFAULT NULL,
  `last_edit_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Righe in staging — non ancora importate o con errori';

--
-- Dump dei dati per la tabella `import_staging_rows`
--

INSERT INTO `import_staging_rows` (`id`, `job_id`, `row_number`, `payload`, `status`, `approved_as`, `approved_at`, `approved_by`, `errors`, `missing_fields`, `result_id`, `result_action`, `is_partial`, `imported_at`, `last_edit_at`, `last_edit_by`) VALUES
(1, 1, 1, '{\"employee_email\":\"emanuele.bressi@wetechs.it\",\"cert_code\":\"VCP\",\"issue_date\":\"2023-10-17\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-979025\",\"notes\":\"VCP Digital Workspace\",\"employee_id\":16}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VCP\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, '2026-05-05 11:06:56', 1),
(2, 1, 2, '{\"employee_email\":\"vmwareinternal1@webkorner.it\",\"cert_code\":\"Partner Integrity and Transparency Training\",\"issue_date\":\"2023-03-03\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-183151\",\"notes\":\"VOP-SE\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Riferimento \'vmwareinternal1@webkorner.it\' non trovato in employees.personal_email\",\"cert_code\":\"Riferimento \'Partner Integrity and Transparency Training\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(3, 1, 3, '{\"employee_email\":\"marco.marziali@wetechs.it\",\"cert_code\":\"VTSP - Mobility 2016\",\"issue_date\":\"2016-09-08\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-207326\",\"notes\":\"VOP - CP\",\"employee_id\":50}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - Mobility 2016\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(4, 1, 4, '{\"employee_email\":\"marco.marziali@wetechs.it\",\"cert_code\":\"VSP - Mobility 2016\",\"issue_date\":\"2016-08-03\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-207503\",\"notes\":null,\"employee_id\":50}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VSP - Mobility 2016\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(5, 1, 5, '{\"employee_email\":\"marco.marziali@wetechs.it\",\"cert_code\":\"VMware AirWatch Associate Accreditation: Enterprise Mobility\",\"issue_date\":\"2016-07-19\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-207750\",\"notes\":null,\"employee_id\":50}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VMware AirWatch Associate Accreditation: Enterprise Mobility\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(6, 1, 6, '{\"employee_email\":\"jonatan.motta@wetechs.it\",\"cert_code\":\"VOP-SE (Subscriptions Expert 2019)\",\"issue_date\":\"2019-09-18\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-220072\",\"notes\":null,\"employee_id\":89}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VOP-SE (Subscriptions Expert 2019)\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(7, 1, 7, '{\"employee_email\":\"alberto.guercini@wetechs.it\",\"cert_code\":\"VTSP Mobility Management 2020\",\"issue_date\":\"2020-09-17\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-228032\",\"notes\":null,\"employee_id\":88}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP Mobility Management 2020\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(8, 1, 8, '{\"employee_email\":\"marco.marziali@wetechs.it\",\"cert_code\":\"VOP - CP (Cloud Provider 2020)\",\"issue_date\":\"2023-07-11\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-235466\",\"notes\":null,\"employee_id\":50}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VOP - CP (Cloud Provider 2020)\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(9, 1, 9, '{\"employee_email\":\"alberto.guercini@wetechs.it\",\"cert_code\":\"VMware Ethics and Compliance Training for Partners 2021\",\"issue_date\":\"2021-01-18\",\"expiry_date\":\"2023-01-18\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-244465\",\"notes\":null,\"employee_id\":88}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VMware Ethics and Compliance Training for Partners 2021\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(10, 1, 10, '{\"employee_email\":\"marco.marziali@wetechs.it\",\"cert_code\":\"VMware Ethics and Compliance Training for Partners 2021\",\"issue_date\":\"2021-01-15\",\"expiry_date\":\"2023-01-15\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-245326\",\"notes\":null,\"employee_id\":50}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VMware Ethics and Compliance Training for Partners 2021\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(11, 1, 11, '{\"employee_email\":\"marco.marziali@wetechs.it\",\"cert_code\":\"VTSP - DAV (Desktop and App Virtualization 2021)\",\"issue_date\":\"2023-03-08\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-253116\",\"notes\":null,\"employee_id\":50}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - DAV (Desktop and App Virtualization 2021)\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(12, 1, 12, '{\"employee_email\":\"matteo.marziali@wetechs.it\",\"cert_code\":\"VTSP - DAV (Desktop and App Virtualization 2021)\",\"issue_date\":\"2023-03-08\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-253193\",\"notes\":\"VMware Ethics and Compliance Training for Partners\",\"employee_id\":51}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - DAV (Desktop and App Virtualization 2021)\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(13, 1, 13, '{\"employee_email\":\"massimo.ciancio@wetechs.it\",\"cert_code\":\"VMware Certified Professional - Desktop Management 2022\",\"issue_date\":\"2022-12-07\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-262178\",\"notes\":null}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Riferimento \'massimo.ciancio@wetechs.it\' non trovato in employees.personal_email\",\"cert_code\":\"Riferimento \'VMware Certified Professional - Desktop Management 2022\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(14, 1, 14, '{\"employee_email\":\"jonatan.motta@wetechs.it\",\"cert_code\":\"VOP - SE (Subscriptions Expert 2022)\",\"issue_date\":\"2023-04-26\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-269654\",\"notes\":null,\"employee_id\":89}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VOP - SE (Subscriptions Expert 2022)\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(15, 1, 15, '{\"employee_email\":\"alberto.guercini@wetechs.it\",\"cert_code\":\"VOP - SE (Subscriptions Expert 2022)\",\"issue_date\":\"2023-04-26\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-271359\",\"notes\":null,\"employee_id\":88}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VOP - SE (Subscriptions Expert 2022)\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(16, 1, 16, '{\"employee_email\":\"paolo.baruchello@wetechs.it\",\"cert_code\":\"VMware Certified Professional - Desktop Management 2023\",\"issue_date\":\"2023-10-31\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-278724\",\"notes\":\"VOP-SE\",\"employee_id\":11}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VMware Certified Professional - Desktop Management 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(17, 1, 17, '{\"employee_email\":\"massimo.ciancio@wetechs.it\",\"cert_code\":\"VMware Certified Professional - Digital Workspace 2023\",\"issue_date\":\"2023-10-17\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-279584\",\"notes\":\"VTSP\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Riferimento \'massimo.ciancio@wetechs.it\' non trovato in employees.personal_email\",\"cert_code\":\"Riferimento \'VMware Certified Professional - Digital Workspace 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(18, 1, 18, '{\"employee_email\":\"emanuele.bressi@wetechs.it\",\"cert_code\":\"VMware Certified Professional - Digital Workspace 2023\",\"issue_date\":\"2023-10-17\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-279603\",\"notes\":\"VOP - CP\",\"employee_id\":16}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VMware Certified Professional - Digital Workspace 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(19, 1, 19, '{\"employee_email\":\"massimo.ciancio@wetechs.it\",\"cert_code\":\"VMware Certified Advanced Professional - Desktop Management Design 2023\",\"issue_date\":\"2023-10-17\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-280391\",\"notes\":null}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Riferimento \'massimo.ciancio@wetechs.it\' non trovato in employees.personal_email\",\"cert_code\":\"Riferimento \'VMware Certified Advanced Professional - Desktop Management Design 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(20, 1, 20, '{\"employee_email\":\"alberto.guercini@wetechs.it\",\"cert_code\":\"VSP - Anywhere Workspace 2023\",\"issue_date\":\"2023-06-07\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-283570\",\"notes\":null,\"employee_id\":88}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VSP - Anywhere Workspace 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(21, 1, 21, '{\"employee_email\":\"edoardo.colonna@wetechs.it\",\"cert_code\":\"VSP - Anywhere Workspace 2023\",\"issue_date\":\"2023-09-25\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-283618\",\"notes\":\"VTSP\",\"employee_id\":22}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VSP - Anywhere Workspace 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(22, 1, 22, '{\"employee_email\":\"alberto.guercini@wetechs.it\",\"cert_code\":\"VSP - Mobility Management 2023\",\"issue_date\":\"2023-06-07\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-283951\",\"notes\":\"VTSP\",\"employee_id\":88}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VSP - Mobility Management 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(23, 1, 23, '{\"employee_email\":\"edoardo.colonna@wetechs.it\",\"cert_code\":\"VSP - Mobility Management 2023\",\"issue_date\":\"2023-09-25\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-284000\",\"notes\":\"VCP\",\"employee_id\":22}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VSP - Mobility Management 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(24, 1, 24, '{\"employee_email\":\"edoardo.colonna@wetechs.it\",\"cert_code\":\"VSP - Desktop Virtualization 2023\",\"issue_date\":\"2023-08-16\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-284164\",\"notes\":\"VOP-SE\",\"employee_id\":22}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VSP - Desktop Virtualization 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(25, 1, 25, '{\"employee_email\":\"alberto.guercini@wetechs.it\",\"cert_code\":\"VSP - Desktop Virtualization 2023\",\"issue_date\":\"2023-06-07\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-284178\",\"notes\":\"VOP-SE\",\"employee_id\":88}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VSP - Desktop Virtualization 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(26, 1, 26, '{\"employee_email\":\"emanuele.bressi@wetechs.it\",\"cert_code\":\"Mobility Management Post-Sales Accreditation Workspace ONE 2022\",\"issue_date\":\"2023-08-19\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-284521\",\"notes\":\"VCP\",\"employee_id\":16}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'Mobility Management Post-Sales Accreditation Workspace ONE 2022\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(27, 1, 27, '{\"employee_email\":\"massimo.ciancio@wetechs.it\",\"cert_code\":\"Mobility Management Post-Sales Accreditation Workspace ONE 2022\",\"issue_date\":\"2023-09-15\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-284539\",\"notes\":\"VCP\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Riferimento \'massimo.ciancio@wetechs.it\' non trovato in employees.personal_email\",\"cert_code\":\"Riferimento \'Mobility Management Post-Sales Accreditation Workspace ONE 2022\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(28, 1, 28, '{\"employee_email\":\"mirko.civaro@wetechs.it\",\"cert_code\":\"VTSP - Anywhere Workspace 2023\",\"issue_date\":\"2023-06-08\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-284606\",\"notes\":\"VCP\",\"employee_id\":21}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - Anywhere Workspace 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(29, 1, 29, '{\"employee_email\":\"paolo.baruchello@wetechs.it\",\"cert_code\":\"VTSP - Anywhere Workspace 2023\",\"issue_date\":\"2023-06-04\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-284792\",\"notes\":\"VCAP-Design\",\"employee_id\":11}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - Anywhere Workspace 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(30, 1, 30, '{\"employee_email\":\"mirko.civaro@wetechs.it\",\"cert_code\":\"VTSP - Desktop Virtualization 2023\",\"issue_date\":\"2023-06-08\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-285091\",\"notes\":\"VSP\",\"employee_id\":21}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - Desktop Virtualization 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(31, 1, 31, '{\"employee_email\":\"paolo.baruchello@wetechs.it\",\"cert_code\":\"VTSP - Desktop Virtualization 2023\",\"issue_date\":\"2023-06-03\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-285098\",\"notes\":\"VSP\",\"employee_id\":11}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - Desktop Virtualization 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(32, 1, 32, '{\"employee_email\":\"paolo.baruchello@wetechs.it\",\"cert_code\":\"VTSP - Mobility Management 2023\",\"issue_date\":\"2023-06-04\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-285353\",\"notes\":\"VSP\",\"employee_id\":11}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - Mobility Management 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(33, 1, 33, '{\"employee_email\":\"mirko.civaro@wetechs.it\",\"cert_code\":\"VTSP - Mobility Management 2023\",\"issue_date\":\"2023-06-08\",\"expiry_date\":\"2026-02-28\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-285485\",\"notes\":\"VSP\",\"employee_id\":21}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'VTSP - Mobility Management 2023\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(34, 1, 34, '{\"employee_email\":\"alberto.guercini@wetechs.it\",\"cert_code\":\"Partner Integrity and Transparency Training\",\"issue_date\":\"2023-04-21\",\"expiry_date\":\"2025-04-21\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-664856\",\"notes\":\"VSP\",\"employee_id\":88}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'Partner Integrity and Transparency Training\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(35, 1, 35, '{\"employee_email\":\"damiano.fossati@wetechs.it\",\"cert_code\":\"Partner Integrity and Transparency Training\",\"issue_date\":\"2025-06-03\",\"expiry_date\":\"2027-06-03\",\"status\":\"active\",\"score\":null,\"certificate_code\":\"CA-664866\",\"notes\":\"VSP\",\"employee_id\":4}', 'invalid', NULL, NULL, NULL, '{\"cert_code\":\"Riferimento \'Partner Integrity and Transparency Training\' non trovato in certifications.code\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(36, 1, 36, '{\"employee_email\":\"\",\"cert_code\":\"\",\"issue_date\":\"\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":null,\"notes\":\"VTSP\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Campo obbligatorio mancante\",\"cert_code\":\"Campo obbligatorio mancante\",\"issue_date\":\"Campo obbligatorio mancante\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(37, 1, 37, '{\"employee_email\":\"\",\"cert_code\":\"\",\"issue_date\":\"\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":null,\"notes\":\"VTSP\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Campo obbligatorio mancante\",\"cert_code\":\"Campo obbligatorio mancante\",\"issue_date\":\"Campo obbligatorio mancante\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(38, 1, 38, '{\"employee_email\":\"\",\"cert_code\":\"\",\"issue_date\":\"\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":null,\"notes\":\"VTSP\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Campo obbligatorio mancante\",\"cert_code\":\"Campo obbligatorio mancante\",\"issue_date\":\"Campo obbligatorio mancante\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(39, 1, 39, '{\"employee_email\":\"\",\"cert_code\":\"\",\"issue_date\":\"\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":null,\"notes\":\"VTSP\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Campo obbligatorio mancante\",\"cert_code\":\"Campo obbligatorio mancante\",\"issue_date\":\"Campo obbligatorio mancante\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(40, 1, 40, '{\"employee_email\":\"\",\"cert_code\":\"\",\"issue_date\":\"\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":null,\"notes\":\"VTSP\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Campo obbligatorio mancante\",\"cert_code\":\"Campo obbligatorio mancante\",\"issue_date\":\"Campo obbligatorio mancante\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(41, 1, 41, '{\"employee_email\":\"\",\"cert_code\":\"\",\"issue_date\":\"\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":null,\"notes\":\"VTSP\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Campo obbligatorio mancante\",\"cert_code\":\"Campo obbligatorio mancante\",\"issue_date\":\"Campo obbligatorio mancante\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(42, 1, 42, '{\"employee_email\":\"\",\"cert_code\":\"\",\"issue_date\":\"\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":null,\"notes\":\"VMware Ethics and Compliance Training for Partners\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Campo obbligatorio mancante\",\"cert_code\":\"Campo obbligatorio mancante\",\"issue_date\":\"Campo obbligatorio mancante\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(43, 1, 43, '{\"employee_email\":\"\",\"cert_code\":\"\",\"issue_date\":\"\",\"expiry_date\":null,\"status\":\"active\",\"score\":null,\"certificate_code\":null,\"notes\":\"VMware Ethics and Compliance Training for Partners\"}', 'invalid', NULL, NULL, NULL, '{\"employee_email\":\"Campo obbligatorio mancante\",\"cert_code\":\"Campo obbligatorio mancante\",\"issue_date\":\"Campo obbligatorio mancante\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(44, 2, 1, '{\"code\":\"HPE Master ASE\",\"name\":\"HPE Master Accredited Solutions Expert\",\"brand_name\":\"HPE\",\"tech_name\":\"Infrastruttura\",\"level\":\"Professional\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Infrastruttura\' non trovato in technologies.name\"}', NULL, NULL, NULL, 0, NULL, '2026-05-05 11:07:30', 1),
(45, 2, 2, '{\"code\":\"HPE ASE\",\"name\":\"HPE Accredited Solutions Expert\",\"brand_name\":\"HPE\",\"tech_name\":\"Solution Expert\",\"level\":\"ASE\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Solution Expert\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(46, 2, 3, '{\"code\":\"HPE ATP\",\"name\":\"HPE Accredited Technical Professional\",\"brand_name\":\"HPE\",\"tech_name\":\"Tecnical Professional\",\"level\":\"ATP\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Tecnical Professional\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(47, 2, 4, '{\"code\":\"FCF\",\"name\":\"Fortinet Certified Fundamentals\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Cybersecurity di base\",\"level\":\"NSE 1\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Cybersecurity di base\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(48, 2, 5, '{\"code\":\"FCF\",\"name\":\"Fortinet Certified Fundamentals\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Cybersecurity di base\",\"level\":\"NSE 2\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Cybersecurity di base\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(49, 2, 6, '{\"code\":\"FCA\",\"name\":\"Fortinet Certified Associate\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Operazioni FortiGate di alto livello\",\"level\":\"NSE 3\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Operazioni FortiGate di alto livello\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(50, 2, 7, '{\"code\":\"FCP\",\"name\":\"Fortinet Certified Professional - Network Security\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Amministrazione FortiGate\",\"level\":\"NSE 4\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Amministrazione FortiGate\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(51, 2, 8, '{\"code\":\"FCP\",\"name\":\"Fortinet Certified Professional - Security Operations\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Analisi operativa\",\"level\":\"NSE 5\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Analisi operativa\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(52, 2, 9, '{\"code\":\"FCSS\",\"name\":\"Fortinet Certified Solution Specialist\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Design e Troubleshooting complesso\",\"level\":\"NSE 6\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Design e Troubleshooting complesso\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(53, 2, 10, '{\"code\":\"FCSS\",\"name\":\"Fortinet Certified Solution Specialist\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Design e Troubleshooting complesso\",\"level\":\"NSE 7\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Design e Troubleshooting complesso\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(54, 2, 11, '{\"code\":\"FCX\",\"name\":\"Fortinet Certified Expert\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Architettura avanzata e sicurezza\",\"level\":\"NSE 8\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Architettura avanzata e sicurezza\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(55, 2, 12, '{\"code\":\"AZ-900\",\"name\":\"Microsoft Azure Fundamentals\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Concetti base di cloud computing e servizi Azure\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(56, 2, 13, '{\"code\":\"AZ-104\",\"name\":\"Microsoft Azure Administrator\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Gestione di identità storage calcolo e reti virtuali\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(57, 2, 14, '{\"code\":\"AZ-305\",\"name\":\"Azure Solutions Architect Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Progettazione di soluzioni cloud complesse\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(58, 2, 15, '{\"code\":\"AZ-400\",\"name\":\"Designing\\/Implementing DevOps\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Gestione di processi e strumenti DevOps\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(59, 2, 16, '{\"code\":\"AZ-500\",\"name\":\"Azure Security Engineer Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Implementazione di sicurezza per infrastrutture cloud\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(60, 2, 17, '{\"code\":\"AI-102\",\"name\":\"Azure AI Engineer Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Sviluppo di soluzioni AI e Bot Framework\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(61, 2, 18, '{\"code\":\"PL-300\",\"name\":\"Power BI Data Analyst Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Modellazione visualizzazione e analisi dati\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(62, 2, 19, '{\"code\":\"MS-102\",\"name\":\"Microsoft 365 Administrator Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Amministrazione del tenant Microsoft 365\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(63, 2, 20, '{\"code\":\"MD-102\",\"name\":\"Endpoint Administrator Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Distribuzione e protezione di dispositivi\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(64, 2, 21, '{\"code\":\"SC-100\",\"name\":\"Cybersecurity Architect Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Progettazione di strategie di sicurezza Zero Trust\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(65, 2, 22, '{\"code\":\"MTA\",\"name\":\"Microsoft Technology Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Ritirata - Fondamenta IT\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(66, 2, 23, '{\"code\":\"MCSA\",\"name\":\"Microsoft Certified Solutions Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Ritirata - Amministrazione sistemi\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(67, 2, 24, '{\"code\":\"ACSA\",\"name\":\"Aruba Certified Switching Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Fondamenta di Switching e Routing con ArubaOS-CX (Entry-level)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(68, 2, 25, '{\"code\":\"ACMA\",\"name\":\"Aruba Certified Mobility Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Fondamenta di Wireless LAN e ArubaOS (Entry-level)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(69, 2, 26, '{\"code\":\"ACCA\",\"name\":\"Aruba Certified ClearPass Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Fondamenta di Network Security e Access Control (ClearPass)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(70, 2, 27, '{\"code\":\"ACCP\",\"name\":\"Aruba Certified ClearPass Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Implementazione avanzata ClearPass NAC (Policy\\/AAA)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(71, 2, 28, '{\"code\":\"ACSP\",\"name\":\"Aruba Certified Switching Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Switching avanzato (Routing dinamico\\/Multicast\\/CX)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(72, 2, 29, '{\"code\":\"ACMP\",\"name\":\"Aruba Certified Mobility Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Mobility\\/Wireless avanzato (Controller\\/ArubaOS-10)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(73, 2, 30, '{\"code\":\"ACDP\",\"name\":\"Aruba Certified Design Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Progettazione di reti campus wired\\/wireless complesse\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(74, 2, 31, '{\"code\":\"ACMX\",\"name\":\"Aruba Certified Mobility Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazione Expert di massimo livello (Mobility)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(75, 2, 32, '{\"code\":\"ACSE\",\"name\":\"Aruba Certified Switching Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazione Expert di massimo livello (Switching)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(76, 2, 33, '{\"code\":\"ACEX\",\"name\":\"Aruba Certified Edge Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazione \\\"Expert\\\" unificata (Mobility+Switching+Security)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(77, 2, 34, '{\"code\":\"ACP-CA\",\"name\":\"HPE Aruba Networking Certified Professional - Campus Access\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Reti campus cablate e wireless integrate\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(78, 2, 35, '{\"code\":\"ACP-DC\",\"name\":\"HPE Aruba Networking Certified Professional - Data Center\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Architetture Data Center e Fabric\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(79, 2, 36, '{\"code\":\"ACSP (Legacy)\",\"name\":\"Aruba Certified Switching Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazioni storiche basate su versioni AOS-Switch\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(80, 2, 37, '{\"code\":\"ACMA (Legacy)\",\"name\":\"Aruba Certified Mobility Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazioni basate su AOS 6\\/8\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(81, 2, 38, '{\"code\":\"AMFX\",\"name\":\"Aruba Mobility and Fabric Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazione Expert orientata alla tecnologia Fabric\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(82, 3, 1, '{\"code\":\"HPE Master ASE\",\"name\":\"HPE Master Accredited Solutions Expert\",\"brand_name\":\"HPE\",\"tech_name\":\"Infrastruttura\",\"level\":\"Expert\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Infrastruttura\' non trovato in technologies.name\"}', NULL, NULL, NULL, 0, NULL, '2026-05-06 09:56:49', 1),
(83, 3, 2, '{\"code\":\"HPE ASE\",\"name\":\"HPE Accredited Solutions Expert\",\"brand_name\":\"HPE\",\"tech_name\":\"Solution Expert\",\"level\":\"ASE\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Solution Expert\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(84, 3, 3, '{\"code\":\"HPE ATP\",\"name\":\"HPE Accredited Technical Professional\",\"brand_name\":\"HPE\",\"tech_name\":\"Infrastruttura\",\"level\":\"Expert\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Infrastruttura\' non trovato in technologies.name\"}', NULL, NULL, NULL, 0, NULL, '2026-05-05 11:22:41', 1),
(85, 3, 4, '{\"code\":\"FCF\",\"name\":\"Fortinet Certified Fundamentals\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Cybersecurity di base\",\"level\":\"NSE 1\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Cybersecurity di base\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(86, 3, 5, '{\"code\":\"FCF\",\"name\":\"Fortinet Certified Fundamentals\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Cybersecurity di base\",\"level\":\"NSE 2\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Cybersecurity di base\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(87, 3, 6, '{\"code\":\"FCA\",\"name\":\"Fortinet Certified Associate\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Operazioni FortiGate di alto livello\",\"level\":\"NSE 3\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Operazioni FortiGate di alto livello\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(88, 3, 7, '{\"code\":\"FCP\",\"name\":\"Fortinet Certified Professional - Network Security\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Amministrazione FortiGate\",\"level\":\"NSE 4\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Amministrazione FortiGate\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(89, 3, 8, '{\"code\":\"FCP\",\"name\":\"Fortinet Certified Professional - Security Operations\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Analisi operativa\",\"level\":\"NSE 5\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Analisi operativa\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(90, 3, 9, '{\"code\":\"FCSS\",\"name\":\"Fortinet Certified Solution Specialist\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Design e Troubleshooting complesso\",\"level\":\"NSE 6\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Design e Troubleshooting complesso\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(91, 3, 10, '{\"code\":\"FCSS\",\"name\":\"Fortinet Certified Solution Specialist\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Design e Troubleshooting complesso\",\"level\":\"NSE 7\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Design e Troubleshooting complesso\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(92, 3, 11, '{\"code\":\"FCX\",\"name\":\"Fortinet Certified Expert\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Architettura avanzata e sicurezza\",\"level\":\"NSE 8\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Architettura avanzata e sicurezza\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(93, 3, 12, '{\"code\":\"AZ-900\",\"name\":\"Microsoft Azure Fundamentals\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Concetti base di cloud computing e servizi Azure\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(94, 3, 13, '{\"code\":\"AZ-104\",\"name\":\"Microsoft Azure Administrator\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Gestione di identità storage calcolo e reti virtuali\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(95, 3, 14, '{\"code\":\"AZ-305\",\"name\":\"Azure Solutions Architect Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Progettazione di soluzioni cloud complesse\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(96, 3, 15, '{\"code\":\"AZ-400\",\"name\":\"Designing\\/Implementing DevOps\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Gestione di processi e strumenti DevOps\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(97, 3, 16, '{\"code\":\"AZ-500\",\"name\":\"Azure Security Engineer Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Implementazione di sicurezza per infrastrutture cloud\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(98, 3, 17, '{\"code\":\"AI-102\",\"name\":\"Azure AI Engineer Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Sviluppo di soluzioni AI e Bot Framework\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(99, 3, 18, '{\"code\":\"PL-300\",\"name\":\"Power BI Data Analyst Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Modellazione visualizzazione e analisi dati\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL);
INSERT INTO `import_staging_rows` (`id`, `job_id`, `row_number`, `payload`, `status`, `approved_as`, `approved_at`, `approved_by`, `errors`, `missing_fields`, `result_id`, `result_action`, `is_partial`, `imported_at`, `last_edit_at`, `last_edit_by`) VALUES
(100, 3, 19, '{\"code\":\"MS-102\",\"name\":\"Microsoft 365 Administrator Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Amministrazione del tenant Microsoft 365\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(101, 3, 20, '{\"code\":\"MD-102\",\"name\":\"Endpoint Administrator Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Distribuzione e protezione di dispositivi\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(102, 3, 21, '{\"code\":\"SC-100\",\"name\":\"Cybersecurity Architect Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Progettazione di strategie di sicurezza Zero Trust\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(103, 3, 22, '{\"code\":\"MTA\",\"name\":\"Microsoft Technology Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Ritirata - Fondamenta IT\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(104, 3, 23, '{\"code\":\"MCSA\",\"name\":\"Microsoft Certified Solutions Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Admin\",\"level\":\"Ritirata - Amministrazione sistemi\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Admin\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(105, 3, 24, '{\"code\":\"ACSA\",\"name\":\"Aruba Certified Switching Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Fondamenta di Switching e Routing con ArubaOS-CX (Entry-level)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(106, 3, 25, '{\"code\":\"ACMA\",\"name\":\"Aruba Certified Mobility Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Fondamenta di Wireless LAN e ArubaOS (Entry-level)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(107, 3, 26, '{\"code\":\"ACCA\",\"name\":\"Aruba Certified ClearPass Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Fondamenta di Network Security e Access Control (ClearPass)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(108, 3, 27, '{\"code\":\"ACCP\",\"name\":\"Aruba Certified ClearPass Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Implementazione avanzata ClearPass NAC (Policy\\/AAA)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(109, 3, 28, '{\"code\":\"ACSP\",\"name\":\"Aruba Certified Switching Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Switching avanzato (Routing dinamico\\/Multicast\\/CX)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(110, 3, 29, '{\"code\":\"ACMP\",\"name\":\"Aruba Certified Mobility Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Mobility\\/Wireless avanzato (Controller\\/ArubaOS-10)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(111, 3, 30, '{\"code\":\"ACDP\",\"name\":\"Aruba Certified Design Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Progettazione di reti campus wired\\/wireless complesse\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(112, 3, 31, '{\"code\":\"ACMX\",\"name\":\"Aruba Certified Mobility Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazione Expert di massimo livello (Mobility)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(113, 3, 32, '{\"code\":\"ACSE\",\"name\":\"Aruba Certified Switching Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazione Expert di massimo livello (Switching)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(114, 3, 33, '{\"code\":\"ACEX\",\"name\":\"Aruba Certified Edge Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazione \\\"Expert\\\" unificata (Mobility+Switching+Security)\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(115, 3, 34, '{\"code\":\"ACP-CA\",\"name\":\"HPE Aruba Networking Certified Professional - Campus Access\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Reti campus cablate e wireless integrate\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(116, 3, 35, '{\"code\":\"ACP-DC\",\"name\":\"HPE Aruba Networking Certified Professional - Data Center\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Architetture Data Center e Fabric\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(117, 3, 36, '{\"code\":\"ACSP (Legacy)\",\"name\":\"Aruba Certified Switching Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazioni storiche basate su versioni AOS-Switch\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(118, 3, 37, '{\"code\":\"ACMA (Legacy)\",\"name\":\"Aruba Certified Mobility Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazioni basate su AOS 6\\/8\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(119, 3, 38, '{\"code\":\"AMFX\",\"name\":\"Aruba Mobility and Fabric Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Professional Networking\",\"level\":\"Certificazione Expert orientata alla tecnologia Fabric\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59}', 'invalid', NULL, NULL, NULL, '{\"tech_name\":\"Riferimento \'Professional Networking\' non trovato in technologies.name\",\"level\":\"Valore non ammesso. Valori validi: Foundation, Associate, Professional, Expert, Specialist, Other\"}', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(120, 4, 1, '{\"code\":\"HPE Master ASE\",\"name\":\"HPE Master Accredited Solutions Expert\",\"brand_name\":\"HPE\",\"tech_name\":\"Infrastruttura\",\"level\":\"Expert\",\"category\":\"tecnica\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61,\"technology_id\":9}', 'imported', 'strict', '2026-05-07 18:12:01', 1, NULL, NULL, 90, 'insert', 0, '2026-05-07 18:12:09', '2026-05-07 18:12:00', 1),
(121, 4, 2, '{\"code\":\"HPE ASE\",\"name\":\"HPE Accredited Solutions Expert\",\"brand_name\":\"HPE\",\"tech_name\":\"Infrastruttura\",\"level\":\"Expert\",\"category\":\"tecnica\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61,\"technology_id\":9}', 'imported', 'strict', '2026-05-07 18:12:04', 1, NULL, NULL, 91, 'insert', 0, '2026-05-07 18:12:09', '2026-05-07 18:12:03', 1),
(122, 4, 3, '{\"code\":\"HPE ATP\",\"name\":\"HPE Accredited Technical Professional\",\"brand_name\":\"HPE\",\"tech_name\":\"Infrastruttura\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":36,\"cost_estimate\":null,\"brand_id\":61,\"technology_id\":9}', 'imported', 'strict', '2026-05-07 18:12:07', 1, NULL, NULL, 92, 'insert', 0, '2026-05-07 18:12:09', '2026-05-07 18:12:05', 1),
(123, 4, 4, '{\"code\":\"FCF\",\"name\":\"Fortinet Certified Fundamentals\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Security\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56,\"technology_id\":3}', 'imported', 'strict', '2026-05-07 18:16:14', 1, NULL, NULL, 93, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:44', 1),
(124, 4, 5, '{\"code\":\"FCF\",\"name\":\"Fortinet Certified Fundamentals\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Security\",\"level\":\"Foundation\",\"category\":\"tecnica\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56,\"technology_id\":3}', 'imported', 'strict', '2026-05-07 18:13:47', 1, NULL, NULL, 93, 'update', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:47', 1),
(125, 4, 6, '{\"code\":\"FCA\",\"name\":\"Fortinet Certified Associate\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Security\",\"level\":\"Expert\",\"category\":\"tecnica\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56,\"technology_id\":3}', 'imported', 'strict', '2026-05-07 18:13:48', 1, NULL, NULL, 94, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:50', 1),
(126, 4, 7, '{\"code\":\"FCP\",\"name\":\"Fortinet Certified Professional - Network Security\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Firewall\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56,\"technology_id\":10}', 'imported', 'strict', '2026-05-07 18:13:49', 1, NULL, NULL, 95, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:51', 1),
(127, 4, 8, '{\"code\":\"FCP\",\"name\":\"Fortinet Certified Professional - Security Operations\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Security\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56,\"technology_id\":3}', 'imported', 'strict', '2026-05-07 18:13:51', 1, NULL, NULL, 95, 'update', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:53', 1),
(128, 4, 9, '{\"code\":\"FCSS\",\"name\":\"Fortinet Certified Solution Specialist\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Infrastruttura\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56,\"technology_id\":9}', 'imported', 'strict', '2026-05-07 18:14:29', 1, NULL, NULL, 96, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:54', 1),
(129, 4, 10, '{\"code\":\"FCSS\",\"name\":\"Fortinet Certified Solution Specialist\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Infrastruttura\",\"level\":\"Expert\",\"category\":\"tecnica\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56,\"technology_id\":9}', 'imported', 'strict', '2026-05-07 18:14:30', 1, NULL, NULL, 96, 'update', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:55', 1),
(130, 4, 11, '{\"code\":\"FCX\",\"name\":\"Fortinet Certified Expert\",\"brand_name\":\"Fortinet\",\"tech_name\":\"Firewall\",\"level\":\"Expert\",\"category\":\"tecnica\",\"validity_months\":24,\"cost_estimate\":null,\"brand_id\":56,\"technology_id\":10}', 'imported', 'strict', '2026-05-07 18:14:34', 1, NULL, NULL, 97, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:56', 1),
(131, 4, 12, '{\"code\":\"AZ-900\",\"name\":\"Microsoft Azure Fundamentals\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:14:36', 1, NULL, NULL, 98, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:12:58', 1),
(132, 4, 13, '{\"code\":\"AZ-104\",\"name\":\"Microsoft Azure Administrator\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:14:38', 1, NULL, NULL, 99, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:00', 1),
(133, 4, 14, '{\"code\":\"AZ-305\",\"name\":\"Azure Solutions Architect Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:14:39', 1, NULL, NULL, 100, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:04', 1),
(134, 4, 15, '{\"code\":\"AZ-400\",\"name\":\"Designing\\/Implementing DevOps\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:15:16', 1, NULL, NULL, 101, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:06', 1),
(135, 4, 16, '{\"code\":\"AZ-500\",\"name\":\"Azure Security Engineer Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:15:17', 1, NULL, NULL, 102, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:07', 1),
(136, 4, 17, '{\"code\":\"AI-102\",\"name\":\"Azure AI Engineer Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:15:18', 1, NULL, NULL, 103, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:09', 1),
(137, 4, 18, '{\"code\":\"PL-300\",\"name\":\"Power BI Data Analyst Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Data Analyst\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":12}', 'imported', 'strict', '2026-05-07 18:15:20', 1, NULL, NULL, 104, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:10', 1),
(138, 4, 19, '{\"code\":\"MS-102\",\"name\":\"Microsoft 365 Administrator Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:15:22', 1, NULL, NULL, 105, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:11', 1),
(139, 4, 20, '{\"code\":\"MD-102\",\"name\":\"Endpoint Administrator Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:15:23', 1, NULL, NULL, 106, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:16', 1),
(140, 4, 21, '{\"code\":\"SC-100\",\"name\":\"Cybersecurity Architect Expert\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Security\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":3}', 'imported', 'strict', '2026-05-07 18:15:27', 1, NULL, NULL, 107, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:17', 1),
(141, 4, 22, '{\"code\":\"MTA\",\"name\":\"Microsoft Technology Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:15:29', 1, NULL, NULL, 108, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:18', 1),
(142, 4, 23, '{\"code\":\"MCSA\",\"name\":\"Microsoft Certified Solutions Associate\",\"brand_name\":\"Microsoft\",\"tech_name\":\"Sistemi\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":63,\"technology_id\":11}', 'imported', 'strict', '2026-05-07 18:15:26', 1, NULL, NULL, 109, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:19', 1),
(143, 4, 24, '{\"code\":\"ACSA\",\"name\":\"Aruba Certified Switching Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:14', 1, NULL, NULL, 110, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:23', 1),
(144, 4, 25, '{\"code\":\"ACMA\",\"name\":\"Aruba Certified Mobility Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:31', 1, NULL, NULL, 111, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:25', 1),
(145, 4, 26, '{\"code\":\"ACCA\",\"name\":\"Aruba Certified ClearPass Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:40', 1, NULL, NULL, 112, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:28', 1),
(146, 4, 27, '{\"code\":\"ACCP\",\"name\":\"Aruba Certified ClearPass Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:42', 1, NULL, NULL, 113, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:30', 1),
(147, 4, 28, '{\"code\":\"ACSP\",\"name\":\"Aruba Certified Switching Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:37', 1, NULL, NULL, 114, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:34', 1),
(148, 4, 29, '{\"code\":\"ACMP\",\"name\":\"Aruba Certified Mobility Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:33', 1, NULL, NULL, 115, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:38', 1),
(149, 4, 30, '{\"code\":\"ACDP\",\"name\":\"Aruba Certified Design Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:44', 1, NULL, NULL, 116, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:40', 1),
(150, 4, 31, '{\"code\":\"ACMX\",\"name\":\"Aruba Certified Mobility Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:49', 1, NULL, NULL, 117, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:57', 1),
(151, 4, 32, '{\"code\":\"ACSE\",\"name\":\"Aruba Certified Switching Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:51', 1, NULL, NULL, 118, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:13:59', 1),
(152, 4, 33, '{\"code\":\"ACEX\",\"name\":\"Aruba Certified Edge Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:16:03', 1, NULL, NULL, 119, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:14:06', 1),
(153, 4, 34, '{\"code\":\"ACP-CA\",\"name\":\"HPE Aruba Networking Certified Professional - Campus Access\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:16:05', 1, NULL, NULL, 120, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:14:10', 1),
(154, 4, 35, '{\"code\":\"ACP-DC\",\"name\":\"HPE Aruba Networking Certified Professional - Data Center\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:16:01', 1, NULL, NULL, 121, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:14:13', 1),
(155, 4, 36, '{\"code\":\"ACSP (Legacy)\",\"name\":\"Aruba Certified Switching Professional\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:58', 1, NULL, NULL, 122, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:14:19', 1),
(156, 4, 37, '{\"code\":\"ACMA (Legacy)\",\"name\":\"Aruba Certified Mobility Associate\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:36', 1, NULL, NULL, 123, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:14:22', 1),
(157, 4, 38, '{\"code\":\"AMFX\",\"name\":\"Aruba Mobility and Fabric Expert\",\"brand_name\":\"HP Aruba\",\"tech_name\":\"Network\",\"level\":\"Professional\",\"category\":\"tecnica\",\"validity_months\":null,\"cost_estimate\":null,\"brand_id\":59,\"technology_id\":8}', 'imported', 'strict', '2026-05-07 18:15:55', 1, NULL, NULL, 124, 'insert', 0, '2026-05-07 18:16:18', '2026-05-07 18:14:25', 1);

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
  `positions_expected` int(11) NOT NULL DEFAULT 1 COMMENT 'Numero di figure da assumere per questa posizione (1 = singola, N = multipla)',
  `description` text DEFAULT NULL,
  `required_skills` text DEFAULT NULL,
  `nice_to_have` text DEFAULT NULL,
  `ral_min` decimal(10,2) DEFAULT NULL,
  `ral_max` decimal(10,2) DEFAULT NULL,
  `benefits` text DEFAULT NULL COMMENT 'Benefit testuali: auto aziendale, buoni pasto, formazione, ecc.',
  `contract_type` enum('Indeterminato','Determinato','Somministrazione','Consulenza','Stage') DEFAULT 'Indeterminato',
  `location` varchar(100) DEFAULT NULL,
  `remote_policy` enum('In sede','Ibrido','Full Remote') DEFAULT 'Ibrido',
  `target_date` date DEFAULT NULL,
  `opened_at` date DEFAULT NULL,
  `closed_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `presentation_text` text DEFAULT NULL,
  `gender_disclaimer` text DEFAULT NULL,
  `offer_info` text DEFAULT NULL,
  `hard_skills` text DEFAULT NULL,
  `soft_skills` text DEFAULT NULL,
  `we_offer` text DEFAULT NULL,
  `master_version_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `job_positions`
--

INSERT INTO `job_positions` (`id`, `title`, `department`, `brand_id`, `requested_by`, `approved_by`, `team_leader_id`, `status`, `priority`, `positions_expected`, `description`, `required_skills`, `nice_to_have`, `ral_min`, `ral_max`, `benefits`, `contract_type`, `location`, `remote_policy`, `target_date`, `opened_at`, `closed_at`, `created_at`, `presentation_text`, `gender_disclaimer`, `offer_info`, `hard_skills`, `soft_skills`, `we_offer`, `master_version_id`) VALUES
(2, 'Specialista ordini', 'Contract & Order', NULL, 1, 1, NULL, 'open', 'Alta', 1, NULL, NULL, ' Preferibile esperienza pregressa in aziende IT/ servizi tecnologici in ambito di Contract Management, Order Management, Back office Commerciale. Conoscenza della lingua inglese', NULL, NULL, NULL, 'Indeterminato', 'Scandicci - Montevarchi', 'In sede', '2026-04-01', '2026-04-10', NULL, '2026-03-30 08:46:47', 'Wetech’s S.p.A. SB, dinamica realtà di consulenza in forte crescita nel mercato dell’Information Technology, ricerca figure di Contract & Order Management Specialist da inserire nel team Commerciale, con responsabilità nella gestione operativa dei contratti e delle commesse (ordine clienti). La figura sarà un punto di raccordo tra le vendite, delivery, amministrazione e ufficio gare, assicurando la corretta gestione del ciclo ordine-contratto nel rispetto dei requisiti di compliance commerciale e delle policy aziendali.', 'WeTech’s non discrimina in base all\'età, alla razza, al colore, alla religione, al genere, all\'orientamento sessuale, all\'identità di genere, all\'espressione di genere, all\'origine nazionale, allo status di veterano protetto, alla disabilità o a qualsiasi altro stato legalmente protetto.', NULL, 'Ottima conoscenza del pacchetto Office con particolare focus su Excel\r\nFamiliarità con contratti commerciali e documentazione amministrativa\r\nConoscenza dei principali flussi order- to-cash', 'Precisione e attenzione al dettaglio\r\nCapacità organizzativa e gestione delle priorità\r\nProblem solving e orientamento al risultato\r\nOttime capacità relazionali e di coordinamento interfunzionale\r\nApproccio proattivo e orientato al miglioramento continuo\r\nCapacità di lavorare in team', 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job su contrattualistica, compliance e processi aziendali\r\nSedi di lavoro: Scandicci / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 2),
(3, 'System Administrator', 'IT', NULL, 1, NULL, NULL, 'open', 'Alta', 2, 'Per Ral Superiore contattare l\'AD o la Direzione Commerciale', NULL, 'Esperienza pregressa in aziende IT/ servizi tecnologici di almeno 3-5 anni in ruoli analoghi.\r\nCertificazioni rilevanti (es. Microsoft AZ-104, LPIC-1/2 o RHCSA).\r\nBuona conoscenza della lingua inglese.', NULL, 48000.00, NULL, 'Indeterminato', 'Calenzano', 'Ibrido', NULL, '2026-04-29', NULL, '2026-04-29 07:55:42', 'Wetech’s S.p.A. SB è un system integrator italiano in rapida ascesa, focalizzato sull’erogazione di servizi IT evoluti e consulenza tecnologica per grandi organizzazioni Enterprise. Come Società Benefit, coniughiamo la crescita del business con un impatto sociale positivo. Siamo un hub di innovazione in forte espansione nel mercato dell\'Information Technology, dove la gestione di infrastrutture critiche e servizi gestiti si fonde con una visione dinamica e orientata al futuro della tecnologia.', 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.', 'Siamo alla ricerca di un System Administrator esperto e proattivo per gestire, ottimizzare e rendere sicura la nostra infrastruttura IT multi-piattaforma. Il candidato ideale possiede una solida padronanza degli ambienti Microsoft e delle distribuzioni Linux, garantendo l\'alta affidabilità dei servizi e la continuità operativa.\r\nResponsabilità Principali\r\nGestione Infrastruttura: Installazione, configurazione e manutenzione di server fisici e virtuali (VMware/Hyper-V).\r\nAmministrazione Sistemi: Gestione completa di directory services (AD/Azure AD) e server Linux (Debian, Ubuntu, CentOS/RHEL).\r\nMonitoraggio e Sicurezza: Implementazione di policy di sicurezza, gestione firewall, backup e disaster recovery.\r\nAutomazione: Ottimizzazione dei processi tramite scripting per ridurre i task manuali e migliorare l\'efficienza.\r\nTroubleshooting: Risoluzione di problematiche complesse di secondo e terzo livello su sistemi e rete.', 'Ambiente Microsoft\r\nActive Directory, Group Policy (GPO), Office 365, PowerShell, SQL Server.\r\nAmbiente Linux\r\nAmministrazione shell (Bash), gestione pacchetti, Web Server (Apache/Nginx), SSH.\r\nVirtualizzazione\r\nVMware ESXi/vCenter o Microsoft Hyper-V.\r\nNetworking\r\nProtocolli TCP/IP, DNS, DHCP, VPN, gestione VLAN e routing base.\r\nCloud & Backup\r\nConoscenza Azure o AWS; gestione tool di backup (es. Veeam).\r\nAutomazione\r\nScripting in Python o Bash; familiarità con Ansible/Terraform è un plus.', 'Problem Solving: Capacità di analisi e risoluzione tempestiva di incidenti critici.\r\nResilienza: Gestione dello stress durante situazioni di emergenza o manutenzioni straordinarie.\r\nTeamwork: Attitudine alla collaborazione con i team di sviluppo (DevOps) e supporto tecnico.\r\nAutonomia: Capacità di organizzare le priorità e gestire progetti in modo indipendente.\r\nAggiornamento Continuo: Curiosità verso le nuove tecnologie e le best practice di cybersecurity.', 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job su contrattualistica, compliance e processi aziendali, e formazione su specifiche tecnologie del settore.\r\nSedi di lavoro: Scandicci / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 3),
(4, 'Personale di Presidio  \"Regione Toscana, Careggi, Apet, CRT\" ', 'IT Toscana', NULL, 1, NULL, NULL, 'open', 'Alta', 3, NULL, NULL, NULL, NULL, 30000.00, NULL, 'Indeterminato', 'Cliente', 'In sede', NULL, '2026-04-29', NULL, '2026-04-29 08:41:41', 'Siamo un\'azienda italiana specializzata nei servizi IT con presenza consolidata sul territorio nazionale. Ci occupiamo di consulenza tecnologica, sistemi informativi e servizi gestiti per clienti enterprise di vari settori.\r\nResponsabilità Principali:\r\nGestione CED Fisico: Monitoraggio delle condizioni ambientali del Data Center locale, gestione del cablaggio, installazione fisica di server, switch e apparati di storage.\r\nContinuità Operativa: Intervento immediato on-site in caso di guasti hardware o anomalie di rete per minimizzare i downtime.\r\nAmministrazione Ibrida: Gestione dei server Windows e Linux residenti nell\'infrastruttura locale e integrazione con eventuali servizi cloud.\r\nNetwork Administration: Configurazione e gestione di apparati di rete (Switch, Router, Firewall, AP Wi-Fi) presenti presso il cliente.\r\nAsset & Inventory: Gestione dell\'inventario hardware e software e supporto al cliente per l\'approvvigionamento di nuovi asset IT.\r\nRapporto con il Cliente: Raccolta delle esigenze tecniche del cliente e traduzione delle stesse in soluzioni operative o report di miglioramento.', 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.', 'Siamo alla ricerca di un System Administrator con 2-4 anni di esperienza per la gestione del presidio tecnico presso la sede del nostro cliente. La risorsa sarà responsabile dell\'integrità e dell\'efficienza dell\'infrastruttura locale (CED e Network), agendo come referente tecnico operativo. È richiesta autonomia nella gestione degli asset fisici e virtuali e una spiccata capacità di relazione con l\'utente finale e gli stakeholder.\r\nResponsabilità Principali:\r\nGestione CED Fisico: Monitoraggio delle condizioni ambientali del Data Center locale, gestione del cablaggio, installazione fisica di server, switch e apparati di storage.\r\nContinuità Operativa: Intervento immediato on-site in caso di guasti hardware o anomalie di rete per minimizzare i downtime.\r\nAmministrazione Ibrida: Gestione dei server Windows e Linux residenti nell\'infrastruttura locale e integrazione con eventuali servizi cloud.\r\nNetwork Administration: Configurazione e gestione di apparati di rete (Switch, Router, Firewall, AP Wi-Fi) presenti presso il cliente.\r\nAsset & Inventory: Gestione dell\'inventario hardware e software e supporto al cliente per l\'approvvigionamento di nuovi asset IT.\r\nRapporto con il Cliente: Raccolta delle esigenze tecniche del cliente e traduzione delle stesse in soluzioni operative o report di miglioramento.', 'Area Competenze Richieste\r\nMicrosoft & Linux:\r\nGestione Active Directory, GPO, DNS/DHCP e amministrazione base/intermedia di distribuzioni Linux (Ubuntu/CentOS).\r\nNetworking Locale:\r\nConoscenza pratica di networking (VLAN, Tagging, Troubleshooting fisico di rete, configurazione Firewall).\r\nHardware & CED:\r\nFamiliarità con architetture server (Rack, Blade) e sistemi di continuità (UPS). Capacità di intervento fisico sugli apparati.\r\nVirtualizzazione:\r\nGestione operativa di cluster VMware o Hyper-V on-premise.\r\nBackup & Recovery:\r\nPresidio dei processi di backup locali e gestione del disaster recovery fisico (es. rotazione dischi/tape o sincronizzazione off-site).', 'Professionalità e Presenza: Capacità di rappresentare l\'azienda presso la sede del cliente con un comportamento sempre consono e orientato al servizio.\r\nProblem Solving Pratico: Abilità nel diagnosticare rapidamente se un problema è di natura hardware, software o di connettività fisica.\r\nComunicazione Interpersonale: Capacità di interfacciarsi sia con referenti tecnici che con utenti non esperti.\r\nAffidabilità e Fiducia: Massima serietà nella gestione di dati sensibili e accessi fisici riservati (CED).\r\nGestione dell\'Urgenza: Capacità di mantenere la calma e agire metodicamente durante i fermi macchina critici.', 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job su contrattualistica, compliance e processi aziendali, e formazione su specifiche tecnologie del settore.\r\nSedi di lavoro: Scandicci / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 3),
(5, 'Autostrade SM di presidio e tecnico', 'IT Autostrade', NULL, 1, NULL, NULL, 'open', 'Media', 1, 'Da presentere a Scarselli, per Ral', NULL, 'Esperienza di 2-4 anni in ruoli di sistemista IT con compiti di presidio fisico e gestione servizi.\r\nConoscenza approfondita delle componenti hardware di un Data Center moderno.\r\nCapacità di configurazione di ambienti virtualizzati e apparati di rete enterprise.\r\nConoscenza lingua inglese.', NULL, 40000.00, NULL, 'Indeterminato', 'Cliente - Calenzano', 'In sede', '2026-06-30', '2026-04-29', NULL, '2026-04-29 08:54:13', 'Siamo un\'azienda italiana specializzata nei servizi IT con presenza consolidata sul territorio nazionale. Ci occupiamo di consulenza tecnologica, sistemi informativi e servizi gestiti per clienti enterprise di vari settori.', 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.', 'Ricerchiamo una figura ibrida con spiccate capacità operative e gestionali per il presidio dell\'infrastruttura IT presso un nostro cliente direzionale. Il candidato sarà il primo referente tecnico e gestionale: opererà direttamente sugli apparati (Network, Storage, Server) e coordinerà l\'erogazione del servizio, garantendo l\'efficienza del CED e il rispetto degli SLA.\r\nResponsabilità Principali\r\nOperatività On-site: Intervenire in prima battuta per la risoluzione di problemi tecnici su rete, storage e sistemi di virtualizzazione.\r\nGestione Fisica del CED: Responsabile dell\'installazione, cablaggio e manutenzione degli asset hardware (Server, Storage, UPS).\r\nService Management: Gestione del ciclo di vita dei ticket, coordinamento degli interventi e interfaccia diretta con il cliente per la qualità del servizio.\r\nOttimizzazione Infrastrutturale: Monitoraggio delle performance dei cluster virtuali e degli apparati di rete per garantire la business continuity.', 'Area Competenze Richieste (Operatività Diretta)\r\nHardware & CED Familiarità con architetture Server (Rack, Blade) e gestione dei sistemi di continuità (UPS). Capacità di intervento fisico sugli apparati.\r\nStorage & Backup Gestione operativa di sistemi SAN/NAS, configurazione LUN e monitoraggio dei sistemi di protezione dati.\r\nNetworking Troubleshooting e configurazione di Switch, Router, Firewall e VLAN. Gestione della connettività fisica.\r\nVirtualizzazione Gestione operativa e manutenzione di cluster VMware o Hyper-V on-premise.\r\nService Management Conoscenza dei processi ITIL (Incident, Change, Problem Management) e gestione della reportistica.', 'Multitasking Tecnico-Gestionale: Capacità di passare dall\'intervento fisico nel rack alla stesura di un report di servizio per il cliente.\r\nProblem Solving Pratico: Rapidità nel diagnosticare guasti hardware o logici e nel ripristinare i servizi critici.\r\nComunicazione e Presenza: Capacità di rappresentare l\'azienda presso il cliente, mantenendo un approccio professionale e risolutivo.\r\nAutonomia e Organizzazione: Gestione delle priorità in un contesto di presidio dove l\'imprevisto è all\'ordine del giorno.', 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job su contrattualistica, compliance e processi aziendali, e formazione su specifiche tecnologie del settore.\r\nSedi di lavoro: Scandicci / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 3),
(6, 'Service Manager Sistemistica Arezzo', 'it', NULL, 1, NULL, NULL, 'open', 'Media', 1, NULL, NULL, 'Certificazione ITIL Foundation o superiore (V3 o V4).\r\n\r\nCertificazioni in Project Management (PMP, Prince2).\r\n\r\nEsperienza pregressa nella gestione di contratti di outsourcing o servizi gestiti (Managed Services).', NULL, 48000.00, NULL, 'Indeterminato', 'Presso cliente', 'Ibrido', NULL, '2026-04-29', NULL, '2026-04-29 13:10:40', 'Wetech’s S.p.A. SB è un system integrator italiano in rapida ascesa, focalizzato sull’erogazione di servizi IT evoluti e consulenza tecnologica per grandi organizzazioni Enterprise. Come Società Benefit, coniughiamo la crescita del business con un impatto sociale positivo. Siamo un hub di innovazione in forte espansione nel mercato dell\'Information Technology, dove la gestione di infrastrutture critiche e servizi gestiti si fonde con una visione dinamica e orientata al futuro della tecnologia.', 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.', 'Wetech’s S.p.A. SB, dinamica realtà di consulenza in forte crescita nel mercato dell’Information Technology e Società Benefit, ricerca un Service Manager da inserire presso un nostro importante Cliente. La risorsa sarà il punto di contatto strategico tra l\'azienda, il team tecnico e il Cliente, garantendo che i servizi IT siano erogati con i massimi standard di qualità e in linea con le aspettative contrattuali.', 'Esperienza: Almeno 5-7 anni in ruoli di Service Management o Project Management in ambito IT.\r\n\r\nFramework ITIL: Conoscenza approfondita dei processi ITIL (Incident, Problem, Change, Configuration e Release Management).\r\n\r\nGovernance: Capacità di definire, monitorare e rendicontare i livelli di servizio (SLA/KPI).\r\n\r\nReporting: Padronanza di strumenti di Service Management (es. Jira, Service-Now) e tool di reportistica/analisi dati.\r\n\r\nBackground Tecnico: Buona comprensione generale delle infrastrutture IT (Sistemi, Network, Cloud) per interfacciarsi efficacemente con i team tecnici.', 'Autonomia e Leadership: Capacità di gestire il servizio in autonomia, prendendo decisioni strategiche per il mantenimento dei livelli di qualità.\r\n\r\nAttitudine al Coordinamento: Eccellenti doti organizzative per la gestione di team tecnici e il coordinamento di partner/fornitori esterni.\r\n\r\nGestione Proattiva: Capacità di anticipare i rischi, identificare aree di miglioramento del servizio (Service Improvement Plan) e gestire tempestivamente le escalation.\r\n\r\nComunicazione e Relazione: Spiccate doti relazionali per gestire il rapporto diretto con il Cliente e condurre i Service Review Meeting.\r\n\r\nNICE TO HAVE', 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job su contrattualistica, compliance e processi aziendali, e formazione su specifiche tecnologie del settore.\r\nSedi di lavoro: Arezzo / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 3),
(7, 'Help Desk', 'IT MONTEVARCHI', NULL, 1, NULL, NULL, 'open', 'Media', 2, NULL, NULL, NULL, NULL, 28000.00, NULL, 'Indeterminato', 'MONTEVARCHI', 'In sede', NULL, '2026-04-29', NULL, '2026-04-29 14:35:53', 'Wetech’s S.p.A. SB è un system integrator italiano in rapida ascesa, focalizzato sull’erogazione di servizi IT evoluti e consulenza tecnologica per grandi organizzazioni Enterprise. Come Società Benefit, coniughiamo la crescita del business con un impatto sociale positivo. Siamo un hub di innovazione in forte espansione nel mercato dell\'Information Technology, dove la gestione di infrastrutture critiche e servizi gestiti si fonde con una visione dinamica e orientata al futuro della tecnologia.', 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.', 'Siamo alla ricerca di Addetto/a Help Desk.\r\nChe avrà il compito di:\r\nGestione dei ticket, analisi e risoluzione problematiche;\r\nGestire le chiamate di assistenza su problematiche informatiche e applicative di primo livello;\r\nGestione delle esigenze e delle problematiche, sia hardware che software, legate alle postazioni di lavoro (PC, Stampanti, Periferiche, Telefoni VoIP, etc.);\r\nConfigurazione e eventuale installazione degli strumenti aziendali;\r\nOttima conoscenza del pacchetto Office e dimestichezza nell’utilizzo di software gestionali;\r\nCollaborazione con gli altri team presenti in azienda.', NULL, 'Diploma di scuola superiore in ambito Informatico;\r\nConoscenza di base dei sistemi operativi, hardware e software comuni;\r\nCapacità di comunicare in modo chiaro ed efficace con i clienti;\r\nOrientamento al cliente e capacità di risolvere i problemi in modo efficiente;\r\nOttima dimestichezza con l\'utilizzo del pc;\r\nRichiesta patente B;\r\nGradita conoscenza dell\'inglese (scritto e parlato).\r\nPassione, entusiasmo, buone capacità relazionali e doti comunicative;\r\nCapacità di lavorare in team;\r\nBuone capacità organizzative e di gestione del tempo.', 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job compliance e processi aziendali, e formazione su specifiche tecnologie del settore.\r\nSedi di lavoro: Scandicci / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 3),
(8, 'Storage Specialist – Reperibilità H24 (NetApp & Hitachi)', 'IT', NULL, 1, NULL, NULL, 'open', 'Media', 1, NULL, 'Certificazioni Netapp e hitachi', NULL, NULL, 45000.00, NULL, 'Indeterminato', 'Calenzano', 'Ibrido', NULL, '2026-04-30', NULL, '2026-04-30 08:06:49', 'Wetech’s S.p.A. SB è un system integrator italiano in rapida ascesa, focalizzato sull’erogazione di servizi IT evoluti e consulenza tecnologica per grandi organizzazioni Enterprise. Come Società Benefit, coniughiamo la crescita del business con un impatto sociale positivo. Siamo un hub di innovazione in forte espansione nel mercato dell\'Information Technology, dove la gestione di infrastrutture critiche e servizi gestiti si fonde con una visione dinamica e orientata al futuro della tecnologia.', 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.', 'Siamo alla ricerca di uno Specialista Storage con comprovata esperienza su sistemi NetApp e Hitachi per l\'affidamento di un servizio di reperibilità h24. La risorsa sarà responsabile della continuità operativa di infrastrutture critiche a livello nazionale, gestendo il troubleshooting di secondo livello, l\'apertura dei ticket verso i vendor e il coordinamento delle attività di ripristino in remoto.\r\n\r\nPerimetro Tecnologico\r\nIl candidato dovrà operare sulle seguenti tecnologie core:\r\n\r\nSistemi NetApp:\r\n\r\nGestione e troubleshooting di unità FAS2750HA distribuite sul territorio.\r\n\r\nAmministrazione di sistemi AFF300 in configurazione MetroCluster.\r\n\r\nSistemi Hitachi (VSP Series):\r\n\r\nStorage Core: VSP 5500.\r\n\r\nStorage Direzionale e Backup: VSP E590, G900.\r\n\r\nStorage Ambienti Virtualizzati: VSP 1090 (Ambienti Test e Sviluppo VMware).\r\n\r\nResponsabilità e Modalità del Servizio\r\nLa risorsa sarà inserita in un turno di reperibilità fuori orario lavorativo e dovrà gestire le seguenti attività:\r\n\r\nGestione Chiamate: Ricezione e presa in carico degli alert/chiamate dal Responsabile Tecnico del cliente.\r\n\r\nAnalisi Tecnica: Qualifica del guasto a livello Infrastruttura HW e OS (escluso livello applicativo).\r\n\r\nVendor Management: Apertura dei casi di assistenza presso i canali di supporto ufficiali (NetApp/Hitachi) e coordinamento delle attività di intervento remoto.\r\n\r\nMaintenance & Check: Partecipazione a sessioni mensili di verifica connettività/alerting e SAL trimestrali di allineamento tecnico.', 'Specializzazione Storage: Conoscenza approfondita dei sistemi operativi e delle architetture NetApp (ONTAP) e Hitachi VSP.\r\n\r\nMetroCluster & High Availability: Esperienza nella gestione di cluster geografici e configurazioni ad alta affidabilità.\r\n\r\nNetworking for Storage: Familiarità con protocolli FC (Fibre Channel), iSCSI e NFS.\r\n\r\nVirtualizzazione: Conoscenza delle interazioni tra storage e ambienti VMware vSphere.\r\n\r\nMonitoring & Tools: Capacità di utilizzo di tool di accesso remoto e piattaforme di monitoring/alerting.', 'Gestione dello Stress: Capacità di intervenire con lucidità su sistemi critici in orario notturno o festivo.\r\n\r\nPrecisione e Reporting: Rigore nel seguire le procedure di qualifica via mail e nella documentazione degli interventi.\r\n\r\nDisponibilità Oraria: Impegno formale alla copertura dei turni:\r\n\r\nFeriali: 18:00 – 09:00.\r\n\r\nWeekend e Festivi: H24.\r\n\r\nResidenza/Logistica: Possibilità di operare da remoto con connettività stabile e garantita durante i turni di guardia.', 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job su contrattualistica, compliance e processi aziendali, e formazione su specifiche tecnologie del settore.\r\nSedi di lavoro: Scandicci / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 3),
(9, 'Sistemista Junior (Marche)', 'IT ', NULL, 1, NULL, NULL, 'open', 'Media', 1, NULL, NULL, NULL, NULL, 30000.00, NULL, 'Indeterminato', 'Falconara Marittina', 'Ibrido', NULL, '2026-04-30', NULL, '2026-04-30 13:59:58', 'Wetech’s S.p.A. SB è un system integrator italiano in rapida ascesa, focalizzato sull’erogazione di servizi IT evoluti e consulenza tecnologica per grandi organizzazioni Enterprise. Come Società Benefit, coniughiamo la crescita del business con un impatto sociale positivo. Siamo un hub di innovazione in forte espansione nel mercato dell\'Information Technology, dove la gestione di infrastrutture critiche e servizi gestiti si fonde con una visione dinamica e orientata al futuro della tecnologia.', 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.', 'Siamo alla ricerca di  un Sistemista junior per alcuni importanti clienti sul territorio marchigiano, Il candidato ideale è una persona brillante, dinamica curiosa e propositiva con una forte passione per il mondo IT.\r\n\r\n\r\nSi richiede:\r\nEsperienza di almeno 2 anni sui sistemi operativi server: Microsoft Windows Server, Linux (RedHat e/o altre distribuzioni);\r\nEsperienza nella gestione di componenti quali: Active Directory, Group policy object oltre ai servizi di base quali DNS, DHCP;\r\nConoscenza di VMWare Vcenter e Vsphere;\r\nSarà considerato titolo preferenziale la conoscenza di infrastruttura hardware VMware Horizon e/o Workspace one;\r\nPrecisione;\r\nCapacità organizzativa;\r\nPatente B;\r\nLa persona selezionata lavorerà in un team già esistente composto da sistemisti con esperienza pluriennale negli ambienti richiesti.\r\n', NULL, NULL, 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job su contrattualistica, compliance e processi aziendali, e formazione tecnica certificata.\r\nSedi di lavoro: Scandicci / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 3);

-- --------------------------------------------------------

--
-- Struttura della tabella `logistics_requests`
--

CREATE TABLE `logistics_requests` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `planned_exam_id` int(11) DEFAULT NULL,
  `certification_id` int(11) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `request_type` enum('alloggio','mezzo','attrezzatura','aula','catering','altro') NOT NULL DEFAULT 'alloggio',
  `status` enum('draft','submitted','approved','booked','completed','cancelled') NOT NULL DEFAULT 'draft',
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `date_from` date NOT NULL,
  `date_to` date DEFAULT NULL,
  `time_from` time DEFAULT NULL,
  `time_to` time DEFAULT NULL,
  `location` varchar(300) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `num_people` tinyint(4) DEFAULT 1,
  `budget_estimated` decimal(10,2) DEFAULT NULL,
  `budget_actual` decimal(10,2) DEFAULT NULL,
  `supplier` varchar(200) DEFAULT NULL,
  `booking_ref` varchar(100) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `notes_internal` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `logistics_requests`
--

INSERT INTO `logistics_requests` (`id`, `employee_id`, `planned_exam_id`, `certification_id`, `brand_id`, `request_type`, `status`, `title`, `description`, `date_from`, `date_to`, `time_from`, `time_to`, `location`, `city`, `num_people`, `budget_estimated`, `budget_actual`, `supplier`, `booking_ref`, `requested_by`, `approved_by`, `approved_at`, `notes_internal`, `created_at`, `updated_at`) VALUES
(1, 49, 4, 33, 80, 'alloggio', 'cancelled', 'Esame VCF 9', 'prenotazione albergo per esame del 14.04.2026', '2026-04-13', '2026-04-14', '15:00:00', '18:00:00', 'Centro esami TD-Sinnex', NULL, 3, 300.00, NULL, NULL, NULL, 1, NULL, NULL, NULL, '2026-04-14 08:45:57', '2026-04-14 08:47:27'),
(2, 2, NULL, NULL, NULL, 'alloggio', 'approved', 'Evento Fortinet', 'evento promozionale', '2026-04-24', '2026-04-25', '10:00:00', '16:30:00', 'Pala Forum', 'Milano', 1, 500.00, NULL, NULL, 'Orru\'', 3, 1, '2026-04-20 12:51:56', NULL, '2026-04-14 22:22:45', '2026-04-20 10:51:56');

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

--
-- Dump dei dati per la tabella `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `role_id`, `type`, `module`, `title`, `message`, `link_url`, `is_read`, `escalation_level`, `expires_at`, `created_at`) VALUES
(1, 1, NULL, 'warning', 'system', 'Richiesta logistica annullata', 'La richiesta \"Esame VCF 9\" è stata annullata.', 'segreteria.php', 1, 1, '2026-05-14', '2026-04-14 08:47:27'),
(2, 3, NULL, 'success', 'system', 'Richiesta logistica approvata', 'La richiesta \"Evento Fortinet\" è stata approvata.', 'segreteria.php', 0, 1, '2026-05-20', '2026-04-20 10:51:56');

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
-- Struttura della tabella `person_documents`
--

CREATE TABLE `person_documents` (
  `id` int(11) NOT NULL,
  `candidate_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `doc_type` enum('cv','lettera_presentazione','note_selezione','test_tecnico','test_psicologico','valutazione','contratto','certificato_formazione','documento_identita','altro') NOT NULL DEFAULT 'altro',
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `compilation_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `visibility` enum('all','restricted') NOT NULL DEFAULT 'restricted',
  `min_role_view` tinyint(4) NOT NULL DEFAULT 2,
  `min_role_download` tinyint(4) NOT NULL DEFAULT 2,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `version` int(11) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `signed_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `person_documents`
--

INSERT INTO `person_documents` (`id`, `candidate_id`, `employee_id`, `doc_type`, `file_name`, `original_name`, `file_size`, `mime_type`, `title`, `compilation_date`, `notes`, `visibility`, `min_role_view`, `min_role_download`, `uploaded_by`, `created_at`, `updated_at`, `version`, `is_current`, `signed_date`) VALUES
(1, 8, NULL, 'cv', 'cand_8_cv_1777545718.pdf', 'Cv Nico Borgogni.pdf', 56120, 'application/pdf', 'Curriculum', NULL, NULL, 'restricted', 2, 2, 1, '2026-04-30 10:41:58', '2026-04-30 10:41:58', 1, 1, NULL),
(2, 8, NULL, 'test_psicologico', 'cand_8_test_psicologico_1777545751.pdf', 'Borgogni_Nico_Internet Reasoning Standard IT_MEAP4.pdf', 250607, 'application/pdf', 'Borgogni_Nico_Internet Reasoning Standard IT_MEAP4', NULL, NULL, 'restricted', 2, 2, 1, '2026-04-30 10:42:31', '2026-04-30 10:42:31', 1, 1, NULL),
(3, 8, NULL, 'test_psicologico', 'cand_8_test_psicologico_1777545775.pdf', 'Borgogni_Nico_OPPro Extended_FGXHM.pdf', 523089, 'application/pdf', 'Borgogni_Nico_OPPro Extended_FGXHM', NULL, NULL, 'restricted', 2, 2, 1, '2026-04-30 10:42:55', '2026-04-30 10:42:55', 1, 1, NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `planned_exams`
--

CREATE TABLE `planned_exams` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'FK → employees.id (ex user_id)',
  `certification_id` int(11) DEFAULT NULL,
  `planned_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('planned','completed','cancelled') DEFAULT 'planned',
  `result` enum('passed','failed') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `exam_location` varchar(300) DEFAULT NULL,
  `exam_center` varchar(200) DEFAULT NULL,
  `booking_code` varchar(100) DEFAULT NULL,
  `needs_logistics` tinyint(1) NOT NULL DEFAULT 0,
  `plan_type` enum('formazione','esame_certificazione','rinnovo','workshop_tecnico','workshop_commerciale','convegno') NOT NULL DEFAULT 'esame_certificazione',
  `notified_at` datetime DEFAULT NULL,
  `reminder_7d_sent` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_1d_sent` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `planned_exams`
--

INSERT INTO `planned_exams` (`id`, `employee_id`, `certification_id`, `planned_date`, `notes`, `status`, `result`, `created_at`, `exam_location`, `exam_center`, `booking_code`, `needs_logistics`, `plan_type`, `notified_at`, `reminder_7d_sent`, `reminder_1d_sent`) VALUES
(1, 19, 32, '2026-04-30', 'Percorso formativo obbligatorio per Google', 'planned', NULL, '2026-04-14 07:52:24', NULL, NULL, NULL, 0, 'esame_certificazione', NULL, 0, 0),
(2, 41, 17, '2026-05-08', 'Acquisizione certificazione', 'planned', NULL, '2026-04-14 07:57:53', NULL, NULL, NULL, 0, 'esame_certificazione', NULL, 0, 0),
(3, 41, 17, '2026-04-28', 'Preparazione all\'esame', 'planned', NULL, '2026-04-14 07:58:50', NULL, NULL, NULL, 0, 'formazione', NULL, 0, 0),
(4, 49, 33, '2026-04-14', NULL, 'planned', NULL, '2026-04-14 08:35:21', NULL, NULL, NULL, 0, 'esame_certificazione', NULL, 0, 0),
(5, 46, 33, '2026-04-14', NULL, 'planned', NULL, '2026-04-14 08:35:44', NULL, NULL, NULL, 0, 'esame_certificazione', NULL, 0, 0),
(6, 69, 33, '2026-04-14', NULL, 'planned', NULL, '2026-04-14 08:36:04', NULL, NULL, NULL, 0, 'esame_certificazione', NULL, 0, 0);

-- --------------------------------------------------------

--
-- Struttura della tabella `positions_expected`
--

CREATE TABLE `positions_expected` (
  `id` int(11) NOT NULL,
  `job_position_id` int(11) NOT NULL,
  `figure_label` varchar(150) NOT NULL,
  `qty_expected` int(11) NOT NULL DEFAULT 1,
  `qty_filled` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `position_clients`
--

CREATE TABLE `position_clients` (
  `position_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Posizioni aperte ↔ clienti (N:M)';

-- --------------------------------------------------------

--
-- Struttura della tabella `position_compensation_history`
--

CREATE TABLE `position_compensation_history` (
  `id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `ral_min` decimal(10,2) DEFAULT NULL,
  `ral_max` decimal(10,2) DEFAULT NULL,
  `benefits` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Storico modifiche compenso (RAL e benefits)';

--
-- Dump dei dati per la tabella `position_compensation_history`
--

INSERT INTO `position_compensation_history` (`id`, `position_id`, `ral_min`, `ral_max`, `benefits`, `changed_by`, `changed_at`, `notes`) VALUES
(1, 2, NULL, NULL, NULL, 1, '2026-03-30 10:46:47', 'Seed iniziale storico (migration v5)'),
(2, 3, NULL, NULL, NULL, 1, '2026-04-29 09:55:42', 'Seed iniziale storico (migration v5)'),
(3, 4, NULL, NULL, NULL, 1, '2026-04-29 10:41:41', 'Seed iniziale storico (migration v5)'),
(4, 5, NULL, NULL, NULL, 1, '2026-04-29 10:54:13', 'Seed iniziale storico (migration v5)'),
(8, 4, NULL, 30000.00, NULL, 1, '2026-04-29 16:11:45', NULL),
(9, 4, NULL, 30000.00, NULL, 1, '2026-04-29 16:12:57', NULL),
(10, 7, NULL, 28000.00, NULL, 1, '2026-04-29 16:35:53', 'Compenso iniziale'),
(11, 3, NULL, 48000.00, NULL, 1, '2026-04-29 16:37:40', NULL),
(12, 4, NULL, 30000.00, NULL, 1, '2026-04-29 16:37:59', NULL),
(13, 5, NULL, 40000.00, NULL, 1, '2026-04-29 16:38:15', NULL),
(14, 6, NULL, 48000.00, NULL, 1, '2026-04-29 16:38:31', NULL),
(15, 6, NULL, 48000.00, NULL, 1, '2026-04-29 16:38:41', NULL),
(16, 7, NULL, 28000.00, NULL, 1, '2026-04-29 16:38:46', NULL),
(17, 8, NULL, 45000.00, NULL, 1, '2026-04-30 10:06:49', 'Compenso iniziale'),
(18, 3, NULL, 48000.00, NULL, 1, '2026-04-30 10:07:15', NULL),
(19, 8, NULL, 45000.00, NULL, 1, '2026-04-30 10:07:54', NULL),
(20, 4, NULL, 30000.00, NULL, 1, '2026-04-30 10:22:54', NULL),
(21, 4, NULL, 30000.00, NULL, 1, '2026-04-30 10:35:31', NULL),
(22, 4, NULL, 30000.00, NULL, 1, '2026-04-30 10:40:31', NULL),
(23, 5, NULL, 40000.00, NULL, 1, '2026-04-30 10:41:02', NULL),
(24, 5, NULL, 40000.00, NULL, 1, '2026-04-30 10:41:14', NULL),
(25, 3, NULL, 48000.00, NULL, 1, '2026-04-30 10:41:33', NULL),
(26, 6, NULL, 48000.00, NULL, 1, '2026-04-30 10:41:47', NULL),
(27, 4, NULL, 30000.00, NULL, 1, '2026-04-30 10:42:09', NULL),
(28, 8, NULL, 45000.00, NULL, 1, '2026-04-30 10:42:21', NULL),
(29, 7, NULL, 28000.00, NULL, 1, '2026-04-30 10:42:38', NULL),
(30, 9, NULL, 30000.00, NULL, 1, '2026-04-30 15:59:58', 'Compenso iniziale');

-- --------------------------------------------------------

--
-- Struttura della tabella `position_master_texts`
--

CREATE TABLE `position_master_texts` (
  `id` int(11) NOT NULL,
  `text_type` enum('presentation','gender_disclaimer') NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `content` text NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `superseded_at` datetime DEFAULT NULL,
  `superseded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `position_master_texts`
--

INSERT INTO `position_master_texts` (`id`, `text_type`, `version`, `is_current`, `content`, `notes`, `created_by`, `created_at`, `superseded_at`, `superseded_by`) VALUES
(1, 'presentation', 1, 0, 'Siamo un\'azienda italiana specializzata nei servizi IT con presenza consolidata sul territorio nazionale. Ci occupiamo di consulenza tecnologica, sistemi informativi e servizi gestiti per clienti enterprise di vari settori.', 'Versione iniziale', 1, '2026-04-10 16:10:50', '2026-04-29 15:08:58', 1),
(2, 'gender_disclaimer', 1, 1, 'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.', 'Disclaimer GDPR iniziale', 1, '2026-04-10 16:10:50', NULL, NULL),
(3, 'presentation', 2, 1, 'Wetech’s S.p.A. SB è un system integrator italiano in rapida ascesa, focalizzato sull’erogazione di servizi IT evoluti e consulenza tecnologica per grandi organizzazioni Enterprise. Come Società Benefit, coniughiamo la crescita del business con un impatto sociale positivo. Siamo un hub di innovazione in forte espansione nel mercato dell\'Information Technology, dove la gestione di infrastrutture critiche e servizi gestiti si fonde con una visione dinamica e orientata al futuro della tecnologia.', NULL, 1, '2026-04-29 13:08:58', NULL, NULL);

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
-- Struttura della tabella `position_status_history`
--

CREATE TABLE `position_status_history` (
  `id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `old_status` enum('draft','open','paused','closed','cancelled') DEFAULT NULL,
  `new_status` enum('draft','open','paused','closed','cancelled') NOT NULL,
  `opened_at_snapshot` date DEFAULT NULL COMMENT 'Snapshot di opened_at al momento del cambio',
  `closed_at_snapshot` date DEFAULT NULL COMMENT 'Snapshot di closed_at al momento del cambio',
  `changed_by` int(11) DEFAULT NULL COMMENT 'FK users.id',
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(500) DEFAULT NULL COMMENT 'Motivo del cambio (opzionale)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Storico cambi di stato delle posizioni';

--
-- Dump dei dati per la tabella `position_status_history`
--

INSERT INTO `position_status_history` (`id`, `position_id`, `old_status`, `new_status`, `opened_at_snapshot`, `closed_at_snapshot`, `changed_by`, `changed_at`, `notes`) VALUES
(1, 2, NULL, 'open', '2026-04-10', NULL, 1, '2026-03-30 10:46:47', 'Seed iniziale storico (migration v5)'),
(2, 3, NULL, 'draft', '2026-04-29', NULL, 1, '2026-04-29 09:55:42', 'Seed iniziale storico (migration v5)'),
(3, 4, NULL, 'draft', '2026-04-29', NULL, 1, '2026-04-29 10:41:41', 'Seed iniziale storico (migration v5)'),
(4, 5, NULL, 'draft', '2026-04-29', NULL, 1, '2026-04-29 10:54:13', 'Seed iniziale storico (migration v5)'),
(8, 7, NULL, 'draft', '2026-04-29', NULL, 1, '2026-04-29 16:35:53', 'Posizione creata'),
(9, 8, NULL, 'draft', '2026-04-30', NULL, 1, '2026-04-30 10:06:49', 'Posizione creata'),
(10, 5, 'draft', 'open', '2026-04-29', NULL, 1, '2026-04-30 10:41:14', NULL),
(11, 3, 'draft', 'open', '2026-04-29', NULL, 1, '2026-04-30 10:41:33', NULL),
(12, 6, 'draft', 'open', '2026-04-29', NULL, 1, '2026-04-30 10:41:47', NULL),
(13, 4, 'draft', 'open', '2026-04-29', NULL, 1, '2026-04-30 10:42:09', NULL),
(14, 8, 'draft', 'open', '2026-04-30', NULL, 1, '2026-04-30 10:42:21', NULL),
(15, 7, 'draft', 'open', '2026-04-29', NULL, 1, '2026-04-30 10:42:38', NULL),
(16, 9, NULL, 'open', '2026-04-30', NULL, 1, '2026-04-30 15:59:58', 'Posizione creata');

-- --------------------------------------------------------

--
-- Struttura della tabella `position_templates`
--

CREATE TABLE `position_templates` (
  `id` int(11) NOT NULL,
  `template_type` enum('hard_skills','soft_skills','we_offer','offer_info','description','nice_to_have') NOT NULL,
  `name` varchar(150) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1 COMMENT 'Numero versione progressivo per (template_type, name)',
  `content` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Solo la versione corrente è 1; le precedenti vanno a 0',
  `usage_count` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `superseded_at` datetime DEFAULT NULL COMMENT 'Quando questa versione è stata superata',
  `superseded_by` int(11) DEFAULT NULL COMMENT 'ID della nuova versione che la sostituisce',
  `notes` varchar(255) DEFAULT NULL COMMENT 'Note alla versione (es. "rivisto da HR")'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `position_templates`
--

INSERT INTO `position_templates` (`id`, `template_type`, `name`, `version`, `content`, `is_active`, `is_current`, `usage_count`, `created_by`, `created_at`, `superseded_at`, `superseded_by`, `notes`) VALUES
(1, 'we_offer', 'OffertaStandard', 1, 'Inquadramento CCNL Commercio 14 mensilità e retribuzione commisurata alla seniority e profili professionali dei candidati\r\nInserimento in un team strutturato con possibilità di crescita professionale\r\nFormazione on-the-job su contrattualistica, compliance e processi aziendali\r\nSedi di lavoro: Scandicci / Montevarchi\r\nOrario full time (9:00-13:00 , 14:00-18:00)', 1, 1, 0, 1, '2026-04-29 07:35:33', NULL, NULL, NULL),
(2, 'hard_skills', 'System Administrator', 1, 'Ambiente Microsoft\r\nActive Directory, Group Policy (GPO), Office 365, PowerShell, SQL Server.\r\nAmbiente Linux\r\nAmministrazione shell (Bash), gestione pacchetti, Web Server (Apache/Nginx), SSH.\r\nVirtualizzazione\r\nVMware ESXi/vCenter o Microsoft Hyper-V.\r\nNetworking\r\nProtocolli TCP/IP, DNS, DHCP, VPN, gestione VLAN e routing base.\r\nCloud & Backup\r\nConoscenza Azure o AWS; gestione tool di backup (es. Veeam).\r\nAutomazione\r\nScripting in Python o Bash; familiarità con Ansible/Terraform è un plus.', 1, 1, 0, 1, '2026-04-29 07:43:40', NULL, NULL, NULL),
(3, 'soft_skills', 'System Administrator', 1, 'Problem Solving: Capacità di analisi e risoluzione tempestiva di incidenti critici.\r\nResilienza: Gestione dello stress durante situazioni di emergenza o manutenzioni straordinarie.\r\nTeamwork: Attitudine alla collaborazione con i team di sviluppo (DevOps) e supporto tecnico.\r\nAutonomia: Capacità di organizzare le priorità e gestire progetti in modo indipendente.\r\nAggiornamento Continuo: Curiosità verso le nuove tecnologie e le best practice di cybersecurity.', 1, 1, 0, 1, '2026-04-29 07:44:10', NULL, NULL, NULL),
(4, 'nice_to_have', 'System Administrator', 1, 'Esperienza pregressa in aziende IT/ servizi tecnologici di almeno 3-5 anni in ruoli analoghi.\r\nCertificazioni rilevanti (es. Microsoft AZ-104, LPIC-1/2 o RHCSA).\r\nBuona conoscenza della lingua inglese tecnica.', 1, 1, 0, 1, '2026-04-29 07:45:40', NULL, NULL, NULL),
(5, 'offer_info', 'System Administrator', 1, 'Responsabilità Principali\r\nGestione Infrastruttura: Installazione, configurazione e manutenzione di server fisici e virtuali (VMware/Hyper-V).\r\nAmministrazione Sistemi: Gestione completa di directory services (AD/Azure AD) e server Linux (Debian, Ubuntu, CentOS/RHEL).\r\nMonitoraggio e Sicurezza: Implementazione di policy di sicurezza, gestione firewall, backup e disaster recovery.\r\nAutomazione: Ottimizzazione dei processi tramite scripting per ridurre i task manuali e migliorare l\'efficienza.\r\nTroubleshooting: Risoluzione di problematiche complesse di secondo e terzo livello su sistemi e rete.', 1, 1, 0, 1, '2026-04-29 07:48:20', NULL, NULL, NULL),
(6, 'offer_info', 'System Administrator', 1, 'Siamo alla ricerca di un System Administrator esperto e proattivo per gestire, ottimizzare e rendere sicura la nostra infrastruttura IT multi-piattaforma. Il candidato ideale possiede una solida padronanza degli ambienti Microsoft e delle distribuzioni Linux, garantendo l\'alta affidabilità dei servizi e la continuità operativa.\r\nResponsabilità Principali\r\nGestione Infrastruttura: Installazione, configurazione e manutenzione di server fisici e virtuali (VMware/Hyper-V).\r\nAmministrazione Sistemi: Gestione completa di directory services (AD/Azure AD) e server Linux (Debian, Ubuntu, CentOS/RHEL).\r\nMonitoraggio e Sicurezza: Implementazione di policy di sicurezza, gestione firewall, backup e disaster recovery.\r\nAutomazione: Ottimizzazione dei processi tramite scripting per ridurre i task manuali e migliorare l\'efficienza.\r\nTroubleshooting: Risoluzione di problematiche complesse di secondo e terzo livello su sistemi e rete.', 1, 1, 0, 1, '2026-04-29 07:51:07', NULL, NULL, NULL),
(7, 'offer_info', 'System Administrator Junior', 1, 'Siamo alla ricerca di un Junior System Administrator motivato e appassionato per supportare la gestione della nostra infrastruttura IT. La risorsa lavorerà a stretto contatto con i Senior Administrator, acquisendo competenze su sistemi Microsoft e Linux, partecipando attivamente alla manutenzione dei server e all\'assistenza tecnica di secondo livello.\r\n\r\nResponsabilità Principali\r\nSupporto Operativo: Monitoraggio quotidiano dei sistemi, dei log e dello stato dei backup.\r\nGestione Utenze: Creazione e configurazione account in Active Directory e piattaforme Cloud (O365).\r\nManutenzione Base: Installazione di patch, aggiornamenti software e configurazione hardware server/client.\r\nDocumentazione: Redazione e aggiornamento della documentazione tecnica e delle procedure operative.\r\nAssistenza Tecnica: Risoluzione di ticket tecnici relativi a problematiche di rete e sistemi.', 1, 1, 0, 1, '2026-04-29 08:09:27', NULL, NULL, NULL),
(8, 'hard_skills', 'System Administrato Junior', 1, 'Area Competenze Richieste (Livello Base)\r\nSistemi Operativi: Installazione e configurazione base di Windows Server e Linux (Ubuntu/Debian).\r\nNetworking: Fondamenti di reti (modello OSI, indirizzamento IP, DNS, DHCP).\r\nCloud & Collaboration: Gestione base di Microsoft 365 (Email, OneDrive, Teams).\r\nHardware: Assemblaggio e troubleshooting base di componenti server e workstation.\r\nScripting: Familiarità con i comandi da terminale (Bash o PowerShell).\r\nVirtualizzazione: Concetti base di macchine virtuali (VMware, VirtualBox o Hyper-V).', 1, 1, 0, 1, '2026-04-29 08:11:32', NULL, NULL, NULL),
(9, 'soft_skills', 'System Administrator Junior', 1, 'Proattività: Voglia di imparare e di approfondire autonomamente nuove tecnologie.\r\nPrecisione: Attenzione ai dettagli, fondamentale nella gestione di configurazioni e backup.\r\nComunicazione: Capacità di spiegare problemi tecnici in modo chiaro a colleghi e utenti.\r\nOrientamento al Servizio: Attitudine positiva nel supporto agli utenti e nella risoluzione dei problemi.\r\nGestione del Tempo: Capacità di organizzare i propri task seguendo le priorità assegnate.', 1, 1, 0, 1, '2026-04-29 08:12:10', NULL, NULL, NULL),
(10, 'nice_to_have', 'System Administrator Junior', 1, 'Diploma o Laurea ad indirizzo informatico (o percorso di studi equivalente).\r\nBreve esperienza precedente (anche stage) in ruoli di helpdesk o supporto tecnico.\r\nConoscenza scolastica/tecnica della lingua inglese.\r\nCertificazioni, passione per l\'automazione e il mondo Open Source.', 1, 1, 0, 1, '2026-04-29 08:13:16', NULL, NULL, NULL),
(11, 'offer_info', 'Posizione di Presidio', 1, 'Siamo alla ricerca di un System Administrator con 2-4 anni di esperienza per la gestione del presidio tecnico presso la sede del nostro cliente. La risorsa sarà responsabile dell\'integrità e dell\'efficienza dell\'infrastruttura locale (CED e Network), agendo come referente tecnico operativo. È richiesta autonomia nella gestione degli asset fisici e virtuali e una spiccata capacità di relazione con l\'utente finale e gli stakeholder.\r\nResponsabilità Principali:\r\nGestione CED Fisico: Monitoraggio delle condizioni ambientali del Data Center locale, gestione del cablaggio, installazione fisica di server, switch e apparati di storage.\r\nContinuità Operativa: Intervento immediato on-site in caso di guasti hardware o anomalie di rete per minimizzare i downtime.\r\nAmministrazione Ibrida: Gestione dei server Windows e Linux residenti nell\'infrastruttura locale e integrazione con eventuali servizi cloud.\r\nNetwork Administration: Configurazione e gestione di apparati di rete (Switch, Router, Firewall, AP Wi-Fi) presenti presso il cliente.\r\nAsset & Inventory: Gestione dell\'inventario hardware e software e supporto al cliente per l\'approvvigionamento di nuovi asset IT.\r\nRapporto con il Cliente: Raccolta delle esigenze tecniche del cliente e traduzione delle stesse in soluzioni operative o report di miglioramento.', 1, 1, 0, 1, '2026-04-29 08:17:04', NULL, NULL, NULL),
(12, 'hard_skills', 'Posizione di Presidio', 1, 'Area Competenze Richieste\r\nMicrosoft & Linux:\r\nGestione Active Directory, GPO, DNS/DHCP e amministrazione base/intermedia di distribuzioni Linux (Ubuntu/CentOS).\r\nNetworking Locale:\r\nConoscenza pratica di networking (VLAN, Tagging, Troubleshooting fisico di rete, configurazione Firewall).\r\nHardware & CED:\r\nFamiliarità con architetture server (Rack, Blade) e sistemi di continuità (UPS). Capacità di intervento fisico sugli apparati.\r\nVirtualizzazione:\r\nGestione operativa di cluster VMware o Hyper-V on-premise.\r\nBackup & Recovery:\r\nPresidio dei processi di backup locali e gestione del disaster recovery fisico (es. rotazione dischi/tape o sincronizzazione off-site).', 1, 1, 0, 1, '2026-04-29 08:18:32', NULL, NULL, NULL),
(13, 'soft_skills', 'Posizione di Presidio', 1, 'Professionalità e Presenza: Capacità di rappresentare l\'azienda presso la sede del cliente con un comportamento sempre consono e orientato al servizio.\r\nProblem Solving Pratico: Abilità nel diagnosticare rapidamente se un problema è di natura hardware, software o di connettività fisica.\r\nComunicazione Interpersonale: Capacità di interfacciarsi sia con referenti tecnici che con utenti non esperti.\r\nAffidabilità e Fiducia: Massima serietà nella gestione di dati sensibili e accessi fisici riservati (CED).\r\nGestione dell\'Urgenza: Capacità di mantenere la calma e agire metodicamente durante i fermi macchina critici.', 1, 1, 0, 1, '2026-04-29 08:18:55', NULL, NULL, NULL),
(14, 'nice_to_have', 'Posizione di Presidio', 1, 'Esperienza di 2-4 anni in ruoli di sistemista o tecnico di rete, preferibilmente in contesti di outsourcing o consulenza on-site.\r\nPatente di guida (se richiesto per spostamenti tra diverse sedi del cliente).\r\nConoscenza dei protocolli di sicurezza fisica e logica degli ambienti CED.\r\nConoscenza Lingua inglese.', 1, 1, 0, 1, '2026-04-29 08:20:10', NULL, NULL, NULL),
(15, 'offer_info', 'Autostrade SM di presidio e tecnico', 1, 'Ricerchiamo una figura ibrida con spiccate capacità operative e gestionali per il presidio dell\'infrastruttura IT presso un nostro cliente direzionale. Il candidato sarà il primo referente tecnico e gestionale: opererà direttamente sugli apparati (Network, Storage, Server) e coordinerà l\'erogazione del servizio, garantendo l\'efficienza del CED e il rispetto degli SLA.\r\nResponsabilità Principali\r\nOperatività On-site: Intervenire in prima battuta per la risoluzione di problemi tecnici su rete, storage e sistemi di virtualizzazione.\r\nGestione Fisica del CED: Responsabile dell\'installazione, cablaggio e manutenzione degli asset hardware (Server, Storage, UPS).\r\nService Management: Gestione del ciclo di vita dei ticket, coordinamento degli interventi e interfaccia diretta con il cliente per la qualità del servizio.\r\nOttimizzazione Infrastrutturale: Monitoraggio delle performance dei cluster virtuali e degli apparati di rete per garantire la business continuity.', 1, 1, 0, 1, '2026-04-29 08:48:18', NULL, NULL, NULL),
(16, 'hard_skills', 'Autostrade SM di presidio e tecnico', 1, 'Area Competenze Richieste (Operatività Diretta)\r\nHardware & CED Familiarità con architetture Server (Rack, Blade) e gestione dei sistemi di continuità (UPS). Capacità di intervento fisico sugli apparati.\r\nStorage & Backup Gestione operativa di sistemi SAN/NAS, configurazione LUN e monitoraggio dei sistemi di protezione dati.\r\nNetworking Troubleshooting e configurazione di Switch, Router, Firewall e VLAN. Gestione della connettività fisica.\r\nVirtualizzazione Gestione operativa e manutenzione di cluster VMware o Hyper-V on-premise.\r\nService Management Conoscenza dei processi ITIL (Incident, Change, Problem Management) e gestione della reportistica.', 1, 1, 0, 1, '2026-04-29 08:50:33', NULL, NULL, NULL),
(17, 'soft_skills', 'Autostrade SM di presidio e tecnico', 1, 'Multitasking Tecnico-Gestionale: Capacità di passare dall\'intervento fisico nel rack alla stesura di un report di servizio per il cliente.\r\nProblem Solving Pratico: Rapidità nel diagnosticare guasti hardware o logici e nel ripristinare i servizi critici.\r\nComunicazione e Presenza: Capacità di rappresentare l\'azienda presso il cliente, mantenendo un approccio professionale e risolutivo.\r\nAutonomia e Organizzazione: Gestione delle priorità in un contesto di presidio dove l\'imprevisto è all\'ordine del giorno.', 1, 1, 0, 1, '2026-04-29 08:51:04', NULL, NULL, NULL),
(18, 'nice_to_have', 'Autostrade SM di presidio e tecnico', 1, 'Esperienza di 2-4 anni in ruoli di sistemista IT con compiti di presidio fisico e gestione servizi.\r\nConoscenza approfondita delle componenti hardware di un Data Center moderno.\r\nCapacità di configurazione di ambienti virtualizzati e apparati di rete enterprise.', 1, 1, 0, 1, '2026-04-29 08:51:59', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `position_templates_history`
--

CREATE TABLE `position_templates_history` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL COMMENT 'FK position_templates.id',
  `template_type` enum('hard_skills','soft_skills','we_offer','offer_info','description','nice_to_have') NOT NULL,
  `name` varchar(150) NOT NULL,
  `version` int(11) NOT NULL,
  `content` text NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `action` enum('create','update','delete','restore') NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Storico modifiche template (audit + restore)';

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
(6, 'Dipendente', 'Self-service: profilo, piano formativo, upload attestati'),
(7, 'Responsabile Brand (Pianificazione)', NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `page_name` varchar(100) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 1,
  `can_create` tinyint(1) NOT NULL DEFAULT 1,
  `can_edit` tinyint(1) NOT NULL DEFAULT 1,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `can_export` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `page_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`) VALUES
(1, '2fa_settings.php', 1, 1, 1, 1, 0),
(1, 'api_cert_codes.php', 1, 0, 0, 0, 0),
(1, 'branding.php', 1, 1, 1, 1, 0),
(1, 'catalogo_certificazioni.php', 1, 1, 1, 0, 1),
(1, 'credly_sync.php', 1, 1, 1, 1, 1),
(1, 'documenti.php', 1, 1, 1, 0, 1),
(1, 'entity_change_log.php', 1, 0, 0, 0, 1),
(1, 'linkedin_sync.php', 1, 1, 1, 1, 1),
(1, 'manage_clients.php', 1, 1, 1, 1, 1),
(1, 'manage_employees.php', 1, 1, 1, 0, 1),
(1, 'manage_enum_proposals.php', 1, 0, 1, 1, 1),
(1, 'manage_technologies.php', 1, 1, 1, 1, 1),
(1, 'manage_users_2fa.php', 1, 1, 1, 1, 0),
(1, 'mass_upload_jobs.php', 1, 0, 1, 1, 0),
(1, 'mass_upload_partials.php', 1, 0, 1, 0, 0),
(1, 'mass_upload.php', 1, 1, 1, 1, 1),
(1, 'position_history.php', 1, 0, 0, 0, 1),
(1, 'publish_posizione.php', 1, 1, 1, 0, 1),
(1, 'smtp_settings.php', 1, 1, 1, 0, 1),
(1, 'system_backup.php', 1, 1, 0, 0, 1),
(1, 'tech_skill_matrix.php', 1, 1, 1, 1, 1),
(2, 'brand_distributors.php', 0, 0, 0, 0, 0),
(2, 'brand_referents.php', 0, 0, 0, 0, 0),
(2, 'brand_technologies.php', 0, 0, 0, 0, 0),
(2, 'brand.php', 0, 0, 0, 0, 0),
(2, 'candidato_profilo.php', 1, 1, 1, 0, 1),
(2, 'catalogo_certificazioni.php', 1, 1, 1, 0, 1),
(2, 'config_notifiche.php', 0, 0, 0, 0, 0),
(2, 'db_upgrade.php', 0, 0, 0, 0, 0),
(2, 'documenti.php', 1, 1, 1, 0, 1),
(2, 'gap_analysis.php', 0, 0, 0, 0, 0),
(2, 'health_check.php', 0, 0, 0, 0, 0),
(2, 'manage_companies.php', 1, 1, 1, 0, 1),
(2, 'manage_employees.php', 1, 1, 1, 0, 1),
(2, 'manage_permissions.php', 0, 0, 0, 0, 0),
(2, 'manage_roles.php', 0, 0, 0, 0, 0),
(2, 'manage_work_modes.php', 1, 1, 1, 0, 1),
(2, 'manager_users.php', 0, 0, 0, 0, 0),
(2, 'mass_upload.php', 0, 0, 0, 0, 0),
(2, 'programmazione.php', 1, 1, 1, 0, 1),
(2, 'recruiting_agenzie.php', 1, 1, 1, 0, 1),
(2, 'recruiting_candidati.php', 1, 1, 1, 0, 1),
(2, 'recruiting_contratti.php', 1, 1, 1, 0, 1),
(2, 'recruiting_posizioni.php', 1, 1, 1, 0, 1),
(2, 'report_certificazioni.php', 1, 1, 1, 0, 1),
(2, 'segreteria.php', 1, 1, 1, 0, 1),
(2, 'settings.php', 0, 0, 0, 0, 0),
(2, 'smtp_settings.php', 0, 0, 0, 0, 0),
(2, 'system_update.php', 0, 0, 0, 0, 0),
(2, 'training_plans.php', 1, 1, 1, 0, 1),
(2, 'upload_certificato.php', 1, 1, 1, 0, 1),
(2, 'view_logs.php', 0, 0, 0, 0, 0),
(2, 'visualizza_storico.php', 1, 1, 1, 0, 1),
(3, 'brand_distributors.php', 1, 1, 1, 0, 1),
(3, 'brand_referents.php', 1, 1, 1, 0, 1),
(3, 'brand_technologies.php', 1, 1, 1, 0, 1),
(3, 'brand.php', 1, 1, 1, 0, 1),
(3, 'candidato_profilo.php', 1, 1, 1, 0, 1),
(3, 'catalogo_certificazioni.php', 1, 1, 1, 0, 1),
(3, 'config_notifiche.php', 0, 0, 0, 0, 0),
(3, 'db_upgrade.php', 0, 0, 0, 0, 0),
(3, 'documenti.php', 1, 1, 1, 0, 1),
(3, 'gap_analysis.php', 1, 1, 1, 0, 1),
(3, 'health_check.php', 0, 0, 0, 0, 0),
(3, 'manage_companies.php', 1, 0, 0, 0, 0),
(3, 'manage_employees.php', 1, 1, 1, 0, 1),
(3, 'manage_permissions.php', 0, 0, 0, 0, 0),
(3, 'manage_roles.php', 0, 0, 0, 0, 0),
(3, 'manage_work_modes.php', 0, 0, 0, 0, 0),
(3, 'manager_users.php', 0, 0, 0, 0, 0),
(3, 'mass_upload.php', 0, 0, 0, 0, 0),
(3, 'programmazione.php', 1, 1, 1, 0, 1),
(3, 'recruiting_agenzie.php', 0, 0, 0, 0, 0),
(3, 'recruiting_candidati.php', 0, 0, 0, 0, 0),
(3, 'recruiting_contratti.php', 0, 0, 0, 0, 0),
(3, 'recruiting_posizioni.php', 1, 1, 1, 0, 1),
(3, 'report_certificazioni.php', 1, 1, 1, 0, 1),
(3, 'segreteria.php', 1, 1, 1, 0, 1),
(3, 'settings.php', 0, 0, 0, 0, 0),
(3, 'smtp_settings.php', 0, 0, 0, 0, 0),
(3, 'system_update.php', 0, 0, 0, 0, 0),
(3, 'training_plans.php', 1, 1, 1, 0, 1),
(3, 'upload_certificato.php', 1, 1, 1, 0, 1),
(3, 'view_logs.php', 0, 0, 0, 0, 0),
(3, 'visualizza_storico.php', 1, 1, 1, 0, 1),
(4, 'brand_distributors.php', 1, 1, 1, 0, 1),
(4, 'brand_referents.php', 1, 0, 0, 0, 1),
(4, 'brand_technologies.php', 1, 1, 1, 0, 1),
(4, 'brand.php', 1, 1, 1, 0, 1),
(4, 'candidato_profilo.php', 1, 1, 1, 0, 1),
(4, 'catalogo_certificazioni.php', 1, 1, 1, 0, 1),
(4, 'config_notifiche.php', 0, 0, 0, 0, 0),
(4, 'db_upgrade.php', 0, 0, 0, 0, 0),
(4, 'documenti.php', 1, 1, 1, 0, 1),
(4, 'gap_analysis.php', 1, 1, 1, 0, 1),
(4, 'health_check.php', 0, 0, 0, 0, 0),
(4, 'manage_companies.php', 1, 0, 0, 0, 0),
(4, 'manage_employees.php', 0, 0, 0, 0, 0),
(4, 'manage_permissions.php', 0, 0, 0, 0, 0),
(4, 'manage_roles.php', 0, 0, 0, 0, 0),
(4, 'manage_work_modes.php', 0, 0, 0, 0, 0),
(4, 'manager_users.php', 0, 0, 0, 0, 0),
(4, 'mass_upload.php', 0, 0, 0, 0, 0),
(4, 'programmazione.php', 1, 1, 1, 0, 1),
(4, 'recruiting_agenzie.php', 0, 0, 0, 0, 0),
(4, 'recruiting_candidati.php', 1, 1, 1, 0, 1),
(4, 'recruiting_contratti.php', 0, 0, 0, 0, 0),
(4, 'recruiting_posizioni.php', 1, 1, 1, 0, 1),
(4, 'report_certificazioni.php', 1, 1, 1, 0, 1),
(4, 'segreteria.php', 1, 1, 1, 0, 1),
(4, 'settings.php', 0, 0, 0, 0, 0),
(4, 'smtp_settings.php', 0, 0, 0, 0, 0),
(4, 'system_update.php', 0, 0, 0, 0, 0),
(4, 'training_plans.php', 1, 1, 1, 0, 1),
(4, 'upload_certificato.php', 1, 1, 1, 0, 1),
(4, 'view_logs.php', 0, 0, 0, 0, 0),
(4, 'visualizza_storico.php', 1, 1, 1, 0, 1),
(5, 'brand_distributors.php', 0, 0, 0, 0, 0),
(5, 'brand_referents.php', 0, 0, 0, 0, 0),
(5, 'brand_technologies.php', 0, 0, 0, 0, 0),
(5, 'brand.php', 0, 0, 0, 0, 0),
(5, 'candidato_profilo.php', 1, 1, 1, 0, 1),
(5, 'catalogo_certificazioni.php', 1, 1, 1, 0, 1),
(5, 'config_notifiche.php', 0, 0, 0, 0, 0),
(5, 'db_upgrade.php', 0, 0, 0, 0, 0),
(5, 'documenti.php', 1, 1, 1, 0, 1),
(5, 'gap_analysis.php', 0, 0, 0, 0, 0),
(5, 'health_check.php', 0, 0, 0, 0, 0),
(5, 'manage_companies.php', 0, 0, 0, 0, 0),
(5, 'manage_employees.php', 1, 1, 1, 0, 1),
(5, 'manage_permissions.php', 0, 0, 0, 0, 0),
(5, 'manage_roles.php', 0, 0, 0, 0, 0),
(5, 'manage_work_modes.php', 0, 0, 0, 0, 0),
(5, 'manager_users.php', 0, 0, 0, 0, 0),
(5, 'mass_upload.php', 0, 0, 0, 0, 0),
(5, 'programmazione.php', 1, 1, 1, 0, 1),
(5, 'recruiting_agenzie.php', 1, 1, 1, 0, 1),
(5, 'recruiting_candidati.php', 1, 1, 1, 0, 1),
(5, 'recruiting_contratti.php', 1, 1, 1, 0, 1),
(5, 'recruiting_posizioni.php', 1, 1, 1, 0, 1),
(5, 'report_certificazioni.php', 1, 1, 1, 0, 1),
(5, 'segreteria.php', 0, 0, 0, 0, 0),
(5, 'settings.php', 0, 0, 0, 0, 0),
(5, 'smtp_settings.php', 0, 0, 0, 0, 0),
(5, 'system_update.php', 0, 0, 0, 0, 0),
(5, 'training_plans.php', 1, 1, 1, 0, 1),
(5, 'upload_certificato.php', 0, 0, 0, 0, 0),
(5, 'view_logs.php', 0, 0, 0, 0, 0),
(5, 'visualizza_storico.php', 1, 1, 1, 0, 1),
(6, '2fa_settings.php', 1, 1, 1, 1, 0),
(6, 'api_cert_codes.php', 1, 0, 0, 0, 0),
(6, 'documenti.php', 1, 1, 1, 0, 1),
(6, 'programmazione.php', 1, 1, 1, 0, 1),
(6, 'recruiting_posizioni.php', 1, 1, 1, 0, 1),
(6, 'report_certificazioni.php', 1, 1, 1, 0, 1),
(6, 'segreteria.php', 1, 1, 1, 0, 1),
(6, 'training_plans.php', 1, 1, 1, 0, 1),
(6, 'upload_certificato.php', 1, 1, 1, 0, 1),
(6, 'visualizza_storico.php', 1, 1, 1, 0, 1),
(7, 'api_cert_codes.php', 1, 0, 0, 0, 0),
(7, 'brand_distributors.php', 1, 1, 1, 0, 1),
(7, 'brand_referents.php', 1, 1, 1, 0, 1),
(7, 'brand_technologies.php', 1, 1, 1, 0, 1),
(7, 'brand.php', 1, 1, 1, 0, 1),
(7, 'catalogo_certificazioni.php', 1, 1, 1, 0, 1),
(7, 'gap_analysis.php', 1, 1, 1, 0, 1),
(7, 'manage_companies.php', 1, 1, 1, 0, 1),
(7, 'manage_employees.php', 1, 1, 1, 0, 1),
(7, 'mass_upload.php', 1, 1, 1, 0, 1),
(7, 'programmazione.php', 1, 1, 1, 0, 1),
(7, 'report_certificazioni.php', 1, 1, 1, 0, 1),
(7, 'segreteria.php', 1, 1, 1, 0, 1),
(7, 'training_plans.php', 1, 1, 1, 0, 1),
(7, 'upload_certificato.php', 1, 1, 1, 0, 1),
(7, 'visualizza_storico.php', 1, 1, 1, 0, 1);

-- --------------------------------------------------------

--
-- Struttura della tabella `technologies`
--

CREATE TABLE `technologies` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL COMMENT 'FK tech_categories',
  `slug` varchar(120) DEFAULT NULL COMMENT 'Slug per URL/filtri',
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(7) DEFAULT NULL COMMENT 'HEX per UI',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `technologies`
--

INSERT INTO `technologies` (`id`, `name`, `description`, `category_id`, `slug`, `icon`, `color`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Cloud & Infrastructure', NULL, NULL, 'cloud-infrastructure', NULL, NULL, 1, '2026-05-06 01:23:44', '2026-05-06 01:23:44'),
(3, 'Security', NULL, NULL, 'security', NULL, NULL, 1, '2026-05-06 01:23:44', '2026-05-06 01:23:44'),
(4, 'Data & AI', NULL, NULL, 'data-ai', NULL, NULL, 1, '2026-05-06 01:23:44', '2026-05-06 01:23:44'),
(5, 'DevOps', NULL, NULL, 'devops', NULL, NULL, 1, '2026-05-06 01:23:44', '2026-05-06 01:23:44'),
(6, 'Soft Skills', NULL, NULL, 'soft-skills', NULL, NULL, 1, '2026-05-06 01:23:44', '2026-05-06 01:23:44'),
(7, 'Cloud', NULL, NULL, 'cloud', NULL, NULL, 1, '2026-05-06 01:23:44', '2026-05-06 01:23:44'),
(8, 'Network', NULL, NULL, 'network', NULL, NULL, 1, '2026-05-06 01:23:44', '2026-05-06 01:23:44'),
(9, 'Infrastruttura', '', NULL, 'infrastruttura', '', '#0ea5e9', 1, '2026-05-06 16:09:48', NULL),
(10, 'Firewall', '', 2, 'firewall', '', '#0ea5e9', 1, '2026-05-06 16:13:01', NULL),
(11, 'Sistemi', '', 5, 'microsoft', '', '#0ea5e9', 1, '2026-05-06 16:15:29', '2026-05-06 16:16:07'),
(12, 'Data Analyst', '', NULL, 'data-analyst', '', '#0ea5e9', 1, '2026-05-07 10:55:05', NULL),
(13, 'Generale', 'Tecnologia generica (placeholder)', NULL, 'generale', 'fa-tag', '#94a3b8', 1, '2026-05-14 00:07:03', NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `tech_brands`
--

CREATE TABLE `tech_brands` (
  `technology_id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Brand di riferimento per questa tecnologia',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tecnologia ↔ Brand (un brand può coprire più tecnologie e viceversa)';

--
-- Dump dei dati per la tabella `tech_brands`
--

INSERT INTO `tech_brands` (`technology_id`, `brand_id`, `is_primary`, `notes`, `created_at`, `created_by`) VALUES
(9, 48, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 49, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 53, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 57, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 61, 1, NULL, '2026-05-06 16:09:48', 1),
(9, 62, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 65, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 68, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 69, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 70, 0, NULL, '2026-05-06 16:09:48', 1),
(9, 80, 0, NULL, '2026-05-06 16:09:48', 1),
(11, 63, 0, NULL, '2026-05-06 16:15:29', 1);

-- --------------------------------------------------------

--
-- Struttura della tabella `tech_categories`
--

CREATE TABLE `tech_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Es. Infrastructure, Software, Methodology',
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL COMMENT 'Self-reference per gerarchie',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `icon` varchar(50) DEFAULT NULL COMMENT 'Es. fa-cloud, fa-shield',
  `color` varchar(7) DEFAULT '#0ea5e9' COMMENT 'HEX per UI',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Categorie macro per raggruppare le tecnologie';

--
-- Dump dei dati per la tabella `tech_categories`
--

INSERT INTO `tech_categories` (`id`, `name`, `description`, `parent_id`, `display_order`, `icon`, `color`, `is_active`, `created_at`) VALUES
(1, 'Infrastructure', 'Networking, datacenter, cloud platforms', NULL, 10, 'fa-network-wired', '#0ea5e9', 1, '2026-05-06 01:23:45'),
(2, 'Security', 'Cybersecurity, IAM, compliance, GRC', NULL, 20, 'fa-shield-halved', '#dc2626', 1, '2026-05-06 01:23:45'),
(3, 'Data & AI', 'Database, analytics, machine learning, GenAI', NULL, 30, 'fa-brain', '#8b5cf6', 1, '2026-05-06 01:23:45'),
(4, 'DevOps', 'CI/CD, automation, IaC, container orchestration', NULL, 40, 'fa-code-branch', '#10b981', 1, '2026-05-06 01:23:45'),
(5, 'Software', 'Linguaggi, framework, IDE', NULL, 50, 'fa-code', '#f59e0b', 1, '2026-05-06 01:23:45'),
(6, 'Methodology', 'Agile, ITIL, project management', NULL, 60, 'fa-tasks', '#64748b', 1, '2026-05-06 01:23:45');

-- --------------------------------------------------------

--
-- Struttura della tabella `tech_certifications`
--

CREATE TABLE `tech_certifications` (
  `technology_id` int(11) NOT NULL,
  `certification_id` int(11) NOT NULL,
  `relevance` enum('primary','secondary','related') NOT NULL DEFAULT 'primary' COMMENT 'primary=core, secondary=correlata, related=tangenziale',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tecnologia ↔ Catalogo certificazioni (relazione N:M con peso di rilevanza)';

--
-- Dump dei dati per la tabella `tech_certifications`
--

INSERT INTO `tech_certifications` (`technology_id`, `certification_id`, `relevance`, `created_at`, `created_by`) VALUES
(1, 32, 'primary', '2026-05-06 01:23:45', NULL),
(1, 33, 'primary', '2026-05-06 01:23:45', NULL),
(3, 93, 'primary', '2026-05-07 18:16:18', 1),
(3, 94, 'primary', '2026-05-07 18:16:18', 1),
(3, 95, 'primary', '2026-05-07 18:16:18', 1),
(3, 107, 'primary', '2026-05-07 18:16:18', 1),
(7, 1, 'primary', '2026-05-06 01:23:45', NULL),
(7, 2, 'primary', '2026-05-06 01:23:45', NULL),
(7, 3, 'primary', '2026-05-06 01:23:45', NULL),
(7, 4, 'primary', '2026-05-06 01:23:45', NULL),
(7, 5, 'primary', '2026-05-06 01:23:45', NULL),
(7, 6, 'primary', '2026-05-06 01:23:45', NULL),
(7, 7, 'primary', '2026-05-06 01:23:45', NULL),
(8, 8, 'primary', '2026-05-06 01:23:45', NULL),
(8, 9, 'primary', '2026-05-06 01:23:45', NULL),
(8, 10, 'primary', '2026-05-06 01:23:45', NULL),
(8, 11, 'primary', '2026-05-06 01:23:45', NULL),
(8, 12, 'primary', '2026-05-06 01:23:45', NULL),
(8, 13, 'primary', '2026-05-06 01:23:45', NULL),
(8, 14, 'primary', '2026-05-06 01:23:45', NULL),
(8, 15, 'primary', '2026-05-06 01:23:45', NULL),
(8, 16, 'primary', '2026-05-06 01:23:45', NULL),
(8, 17, 'primary', '2026-05-06 01:23:45', NULL),
(8, 18, 'primary', '2026-05-06 01:23:45', NULL),
(8, 19, 'primary', '2026-05-06 01:23:45', NULL),
(8, 20, 'primary', '2026-05-06 01:23:45', NULL),
(8, 21, 'primary', '2026-05-06 01:23:45', NULL),
(8, 22, 'primary', '2026-05-06 01:23:45', NULL),
(8, 23, 'primary', '2026-05-06 01:23:45', NULL),
(8, 24, 'primary', '2026-05-06 01:23:45', NULL),
(8, 25, 'primary', '2026-05-06 01:23:45', NULL),
(8, 26, 'primary', '2026-05-06 01:23:45', NULL),
(8, 27, 'primary', '2026-05-06 01:23:45', NULL),
(8, 28, 'primary', '2026-05-06 01:23:45', NULL),
(8, 29, 'primary', '2026-05-06 01:23:45', NULL),
(8, 30, 'primary', '2026-05-06 01:23:45', NULL),
(8, 31, 'primary', '2026-05-06 01:23:45', NULL),
(8, 110, 'primary', '2026-05-07 18:16:18', 1),
(8, 111, 'primary', '2026-05-07 18:16:18', 1),
(8, 112, 'primary', '2026-05-07 18:16:18', 1),
(8, 113, 'primary', '2026-05-07 18:16:18', 1),
(8, 114, 'primary', '2026-05-07 18:16:18', 1),
(8, 115, 'primary', '2026-05-07 18:16:18', 1),
(8, 116, 'primary', '2026-05-07 18:16:18', 1),
(8, 117, 'primary', '2026-05-07 18:16:18', 1),
(8, 118, 'primary', '2026-05-07 18:16:18', 1),
(8, 119, 'primary', '2026-05-07 18:16:18', 1),
(8, 120, 'primary', '2026-05-07 18:16:18', 1),
(8, 121, 'primary', '2026-05-07 18:16:18', 1),
(8, 122, 'primary', '2026-05-07 18:16:18', 1),
(8, 123, 'primary', '2026-05-07 18:16:18', 1),
(8, 124, 'primary', '2026-05-07 18:16:18', 1),
(9, 90, 'primary', '2026-05-07 18:12:09', 1),
(9, 91, 'primary', '2026-05-07 18:12:09', 1),
(9, 92, 'primary', '2026-05-07 18:12:09', 1),
(9, 96, 'primary', '2026-05-07 18:16:18', 1),
(10, 95, 'primary', '2026-05-07 18:16:18', 1),
(10, 97, 'primary', '2026-05-07 18:16:18', 1),
(11, 98, 'primary', '2026-05-07 18:16:18', 1),
(11, 99, 'primary', '2026-05-07 18:16:18', 1),
(11, 100, 'primary', '2026-05-07 18:16:18', 1),
(11, 101, 'primary', '2026-05-07 18:16:18', 1),
(11, 102, 'primary', '2026-05-07 18:16:18', 1),
(11, 103, 'primary', '2026-05-07 18:16:18', 1),
(11, 105, 'primary', '2026-05-07 18:16:18', 1),
(11, 106, 'primary', '2026-05-07 18:16:18', 1),
(11, 108, 'primary', '2026-05-07 18:16:18', 1),
(11, 109, 'primary', '2026-05-07 18:16:18', 1),
(12, 104, 'primary', '2026-05-07 18:16:18', 1);

-- --------------------------------------------------------

--
-- Struttura della tabella `tech_employee_skills`
--

CREATE TABLE `tech_employee_skills` (
  `technology_id` int(11) NOT NULL,
  `employee_skill_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tecnologia ↔ Skill matrix dipendenti';

-- --------------------------------------------------------

--
-- Struttura della tabella `tech_user_certifications`
--

CREATE TABLE `tech_user_certifications` (
  `technology_id` int(11) NOT NULL,
  `user_certification_id` int(11) NOT NULL,
  `auto_inferred` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = ereditata da tech_certifications, 0 = manuale',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tecnologia ↔ Certificato di un dipendente (auto-inferito + manuale)';

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `plan_type` enum('formazione','esame_certificazione','rinnovo','workshop_tecnico','workshop_commerciale','convegno') NOT NULL DEFAULT 'formazione'
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
(1, 1, 1, 'admin@certv.local', '$2y$12$bWaaJ5a06Ba8DyLWBydjouAciA95btgt4R1tK7gvT13lXf8NM/Mb.', NULL, 'active', 1, '2026-03-28 20:59:37'),
(2, 4, 7, 'damiano.fossati@wetechs.it', '$2y$10$H44Fbee7hUmARRUUvMxCtevCW34Y5d0CXKVxVEftMdaFXvhz5TikO', NULL, 'active', 1, '2026-04-14 08:39:21'),
(3, 2, 1, 'antonello.orru@wetechs.it', '$2y$10$2hF77X53fG.4Ur37.waipe1zqISHN82UFYq6HKtdGfobdXZgputhS', NULL, 'active', 1, '2026-04-14 08:42:39'),
(4, 82, 2, 'erika.franceschini@wetechs.it', '$2y$10$MwyTJxfqMfj8j8PzERIgFujcnldL2PfmmqwNkpUghmpxSlhF4U2q2', NULL, 'active', 1, '2026-05-15 13:29:37');

-- --------------------------------------------------------

--
-- Struttura della tabella `user_2fa`
--

CREATE TABLE `user_2fa` (
  `user_id` int(11) NOT NULL,
  `totp_secret` varchar(64) DEFAULT NULL COMMENT 'Base32 secret RFC 6238',
  `totp_authorized` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Admin ha autorizzato l''utente a usare TOTP',
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `email_otp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `email_otp_authorized` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Admin ha autorizzato Email OTP per questo utente',
  `authorized_by` int(11) DEFAULT NULL COMMENT 'user_id dell''admin che ha autorizzato',
  `authorized_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL COMMENT 'Prima volta che 2FA è stata verificata',
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dump dei dati per la tabella `user_2fa`
--

INSERT INTO `user_2fa` (`user_id`, `totp_secret`, `totp_authorized`, `totp_enabled`, `email_otp_enabled`, `email_otp_authorized`, `authorized_by`, `authorized_at`, `verified_at`, `last_used_at`, `created_at`, `updated_at`) VALUES
(3, NULL, 0, 0, 1, 1, 1, '2026-04-28 21:24:34', NULL, NULL, '2026-04-28 21:24:34', '2026-04-28 21:24:34');

-- --------------------------------------------------------

--
-- Struttura della tabella `user_2fa_attempts`
--

CREATE TABLE `user_2fa_attempts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `method` enum('totp','email','recovery') NOT NULL,
  `success` tinyint(1) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `user_2fa_recovery_codes`
--

CREATE TABLE `user_2fa_recovery_codes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

--
-- Dump dei dati per la tabella `user_certifications`
--

INSERT INTO `user_certifications` (`id`, `employee_id`, `certification_id`, `issue_date`, `expiry_date`, `status`, `score`, `certificate_code`, `document_path`, `notes`, `uploaded_by`, `created_at`) VALUES
(1, 13, 125, '2026-02-13', NULL, 'active', NULL, '', NULL, '', 1, '2026-05-13 09:15:23'),
(2, 12, 17, '2001-04-02', '2029-02-12', 'active', NULL, '6ffe4a66-fa51-4f8b-acf5-fd18d89d4e5e', NULL, 'Importato da Credly il 2026-05-14 00:00\ncredly_badge_id:6ffe4a66-fa51-4f8b-acf5-fd18d89d4e5e\ncredly_template_id:59876d7c-8286-4186-b234-5afc23d51b06\nbadge_url:https://www.credly.com/badges/6ffe4a66-fa51-4f8b-acf5-fd18d89d4e5e', 1, '2026-05-13 22:00:42'),
(3, 12, 127, '2026-03-06', NULL, 'active', NULL, '13a5a29a-1493-4379-8531-2e0a385c0a4d', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:13a5a29a-1493-4379-8531-2e0a385c0a4d\ncredly_template_id:c444f85f-025f-4408-9015-cdda40bcae60\nbadge_url:https://www.credly.com/badges/13a5a29a-1493-4379-8531-2e0a385c0a4d', 1, '2026-05-13 22:07:03'),
(4, 12, 128, '2026-02-12', NULL, 'active', NULL, '07fbfbac-36e0-4ac3-bbf0-0f3732a3a399', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:07fbfbac-36e0-4ac3-bbf0-0f3732a3a399\ncredly_template_id:2703e463-b0d8-4ce5-a941-bbd12d40aa1c\nbadge_url:https://www.credly.com/badges/07fbfbac-36e0-4ac3-bbf0-0f3732a3a399', 1, '2026-05-13 22:07:03'),
(5, 12, 129, '2025-10-21', NULL, 'active', NULL, 'f2953e33-afc9-4813-805c-6f1230b85819', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:f2953e33-afc9-4813-805c-6f1230b85819\ncredly_template_id:190bfe58-43c0-4217-a0b2-79e289655e10\nbadge_url:https://www.credly.com/badges/f2953e33-afc9-4813-805c-6f1230b85819', 1, '2026-05-13 22:07:04'),
(6, 12, 130, '2025-10-21', NULL, 'active', NULL, '09364ca6-16ba-46fd-9134-ca3a84a42f52', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:09364ca6-16ba-46fd-9134-ca3a84a42f52\ncredly_template_id:6018ef0b-d33e-403d-9b9f-b516747e5c50\nbadge_url:https://www.credly.com/badges/09364ca6-16ba-46fd-9134-ca3a84a42f52', 1, '2026-05-13 22:07:04'),
(7, 12, 131, '2025-05-30', NULL, 'active', NULL, '3fd41573-81fc-4988-92aa-a365f0a71aac', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:3fd41573-81fc-4988-92aa-a365f0a71aac\ncredly_template_id:c82146dd-fb76-415b-9f15-ea4047daba01\nbadge_url:https://www.credly.com/badges/3fd41573-81fc-4988-92aa-a365f0a71aac', 1, '2026-05-13 22:07:04'),
(8, 12, 132, '2025-01-23', NULL, 'active', NULL, '4f1536f2-3f3a-48b3-a8a2-d0985c1f80cf', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:4f1536f2-3f3a-48b3-a8a2-d0985c1f80cf\ncredly_template_id:28bbd21e-dc21-4ac9-9b8b-0e2e60c4675e\nbadge_url:https://www.credly.com/badges/4f1536f2-3f3a-48b3-a8a2-d0985c1f80cf', 1, '2026-05-13 22:07:04'),
(9, 12, 133, '2025-01-23', NULL, 'active', NULL, '05b18804-6ebf-481b-a30e-4b5bfd336e70', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:05b18804-6ebf-481b-a30e-4b5bfd336e70\ncredly_template_id:a81a772e-d138-4d42-8d5b-ce5120c0eac1\nbadge_url:https://www.credly.com/badges/05b18804-6ebf-481b-a30e-4b5bfd336e70', 1, '2026-05-13 22:07:04'),
(10, 12, 134, '2025-01-22', NULL, 'active', NULL, '5c8a866f-5157-40a4-9827-8e86c2ee8e08', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:5c8a866f-5157-40a4-9827-8e86c2ee8e08\ncredly_template_id:2aff6d71-ae6f-44cb-a8ec-5349f807d49b\nbadge_url:https://www.credly.com/badges/5c8a866f-5157-40a4-9827-8e86c2ee8e08', 1, '2026-05-13 22:07:04'),
(11, 12, 135, '2024-08-29', '2026-08-29', 'active', NULL, '5c650342-812c-4697-8556-d599dead641f', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:5c650342-812c-4697-8556-d599dead641f\ncredly_template_id:b5fbfd37-39c9-4800-a348-75400f199f8f\nbadge_url:https://www.credly.com/badges/5c650342-812c-4697-8556-d599dead641f', 1, '2026-05-13 22:07:04'),
(12, 12, 136, '2024-08-29', NULL, 'active', NULL, '76e6b954-72d7-49c1-9891-02bd62fdc24f', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:76e6b954-72d7-49c1-9891-02bd62fdc24f\ncredly_template_id:b1be0714-51d3-4b2d-bf29-06f88bd66d17\nbadge_url:https://www.credly.com/badges/76e6b954-72d7-49c1-9891-02bd62fdc24f', 1, '2026-05-13 22:07:04'),
(13, 12, 137, '2024-07-11', '2026-08-29', 'active', NULL, '309443ec-e081-4f04-8119-ac237af764b1', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:309443ec-e081-4f04-8119-ac237af764b1\ncredly_template_id:16abd220-eac0-469f-b15f-300c9d97a343\nbadge_url:https://www.credly.com/badges/309443ec-e081-4f04-8119-ac237af764b1', 1, '2026-05-13 22:07:04'),
(14, 12, 138, '2024-07-11', NULL, 'active', NULL, 'fc045765-ffde-4433-ad42-5891c0e9a040', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:fc045765-ffde-4433-ad42-5891c0e9a040\ncredly_template_id:a988c07d-dd6c-4249-8f01-2fa8f9f3acdb\nbadge_url:https://www.credly.com/badges/fc045765-ffde-4433-ad42-5891c0e9a040', 1, '2026-05-13 22:07:04'),
(15, 12, 139, '2024-07-08', NULL, 'active', NULL, '7e482945-377d-4008-88a5-5c42a501aa3d', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:7e482945-377d-4008-88a5-5c42a501aa3d\ncredly_template_id:20576877-1003-444c-91c8-b7aba603253e\nbadge_url:https://www.credly.com/badges/7e482945-377d-4008-88a5-5c42a501aa3d', 1, '2026-05-13 22:07:04'),
(16, 12, 140, '2023-07-27', NULL, 'active', NULL, '21d8bdd6-07b1-4609-ab13-a090f4343cf5', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:21d8bdd6-07b1-4609-ab13-a090f4343cf5\ncredly_template_id:a78ce6a7-521d-4e2f-9408-386d3701bd6e\nbadge_url:https://www.credly.com/badges/21d8bdd6-07b1-4609-ab13-a090f4343cf5', 1, '2026-05-13 22:07:04'),
(17, 12, 141, '2023-05-30', '2026-08-29', 'active', NULL, '7b03de27-d08e-4f5e-843d-370253128f0a', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:7b03de27-d08e-4f5e-843d-370253128f0a\ncredly_template_id:f6159ec0-43b2-444b-a1e0-18a323f4833f\nbadge_url:https://www.credly.com/badges/7b03de27-d08e-4f5e-843d-370253128f0a', 1, '2026-05-13 22:07:04'),
(18, 12, 142, '2023-05-30', NULL, 'active', NULL, 'f1c52829-8a1a-40dd-a9fa-fba370149ab9', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:f1c52829-8a1a-40dd-a9fa-fba370149ab9\ncredly_template_id:5bdbc502-ac97-451f-8b91-75bff4049cb9\nbadge_url:https://www.credly.com/badges/f1c52829-8a1a-40dd-a9fa-fba370149ab9', 1, '2026-05-13 22:07:04'),
(19, 12, 143, '2023-05-30', NULL, 'active', NULL, 'be3edea3-717d-476b-9032-5dcd0b821bb7', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:be3edea3-717d-476b-9032-5dcd0b821bb7\ncredly_template_id:227d4f08-6330-4d6b-8782-6bfb11159d51\nbadge_url:https://www.credly.com/badges/be3edea3-717d-476b-9032-5dcd0b821bb7', 1, '2026-05-13 22:07:04'),
(20, 12, 144, '2022-11-28', NULL, 'active', NULL, 'bef486b7-6c76-45a5-84cb-ba41ef990b26', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:bef486b7-6c76-45a5-84cb-ba41ef990b26\ncredly_template_id:691140ff-b1ad-421c-856d-b78d9deb7b69\nbadge_url:https://www.credly.com/badges/bef486b7-6c76-45a5-84cb-ba41ef990b26', 1, '2026-05-13 22:07:04'),
(21, 12, 145, '2022-04-15', '2042-04-15', 'active', NULL, '5d192b7d-4e68-4052-8de3-d467e9558fec', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:5d192b7d-4e68-4052-8de3-d467e9558fec\ncredly_template_id:1901be49-c67b-48e4-9840-aeb98e433d1f\nbadge_url:https://www.credly.com/badges/5d192b7d-4e68-4052-8de3-d467e9558fec', 1, '2026-05-13 22:07:04'),
(22, 12, 146, '2022-04-14', '2042-04-14', 'active', NULL, 'ed258420-88e8-4d40-8866-54c634d25f3f', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:ed258420-88e8-4d40-8866-54c634d25f3f\ncredly_template_id:6b89ff53-217f-4471-8c01-11eef9a687fc\nbadge_url:https://www.credly.com/badges/ed258420-88e8-4d40-8866-54c634d25f3f', 1, '2026-05-13 22:07:04'),
(23, 12, 147, '2022-04-09', NULL, 'active', NULL, '35627694-d196-4eaa-a8d6-6d3bda78a7f7', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:35627694-d196-4eaa-a8d6-6d3bda78a7f7\ncredly_template_id:f64706e8-611b-4182-872c-128a63a59ec1\nbadge_url:https://www.credly.com/badges/35627694-d196-4eaa-a8d6-6d3bda78a7f7', 1, '2026-05-13 22:07:05'),
(24, 12, 148, '2022-04-08', NULL, 'active', NULL, '89d0cc5c-c561-4b35-8f0c-cb2223dfcb2d', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:89d0cc5c-c561-4b35-8f0c-cb2223dfcb2d\ncredly_template_id:5b18393f-de23-4972-8de7-046df682ff5b\nbadge_url:https://www.credly.com/badges/89d0cc5c-c561-4b35-8f0c-cb2223dfcb2d', 1, '2026-05-13 22:07:05'),
(25, 12, 149, '2021-12-03', '2041-12-03', 'active', NULL, 'f0bb030c-8d0b-4fa0-bd09-b657e0801380', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:f0bb030c-8d0b-4fa0-bd09-b657e0801380\ncredly_template_id:475d6541-8f29-49b6-b81d-b55c2415e26d\nbadge_url:https://www.credly.com/badges/f0bb030c-8d0b-4fa0-bd09-b657e0801380', 1, '2026-05-13 22:07:05'),
(26, 12, 150, '2020-02-24', NULL, 'active', NULL, '365a0259-bfae-4de0-9fea-b472c845057a', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:365a0259-bfae-4de0-9fea-b472c845057a\ncredly_template_id:50bf2406-4f9a-4d8c-8268-30a40afe44d1\nbadge_url:https://www.credly.com/badges/365a0259-bfae-4de0-9fea-b472c845057a', 1, '2026-05-13 22:07:05'),
(27, 12, 151, '2020-02-24', NULL, 'active', NULL, 'e2a4c249-8560-4da0-93fc-34a2917215af', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:e2a4c249-8560-4da0-93fc-34a2917215af\ncredly_template_id:54033bcb-637a-4cc4-900f-8db194071ae2\nbadge_url:https://www.credly.com/badges/e2a4c249-8560-4da0-93fc-34a2917215af', 1, '2026-05-13 22:07:05'),
(28, 12, 152, '2020-02-07', '2029-02-12', 'active', NULL, '27935018-d43d-4b06-819a-bd1d675b3ba1', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:27935018-d43d-4b06-819a-bd1d675b3ba1\ncredly_template_id:7dd2f32e-d5d8-478b-b999-12243404494c\nbadge_url:https://www.credly.com/badges/27935018-d43d-4b06-819a-bd1d675b3ba1', 1, '2026-05-13 22:07:05'),
(29, 12, 153, '2014-04-10', '2029-02-12', 'active', NULL, 'ddedf84b-d32c-4588-928c-52c00de795ad', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:ddedf84b-d32c-4588-928c-52c00de795ad\ncredly_template_id:ac579428-dda0-4491-86b9-e519f1ce7d24\nbadge_url:https://www.credly.com/badges/ddedf84b-d32c-4588-928c-52c00de795ad', 1, '2026-05-13 22:07:05'),
(30, 12, 154, '2014-04-10', '2029-02-12', 'active', NULL, '1f4cdc7f-0e95-477e-aa1e-1a9865479964', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:1f4cdc7f-0e95-477e-aa1e-1a9865479964\ncredly_template_id:b9154be1-ee3a-46a2-a387-6d0b48162754\nbadge_url:https://www.credly.com/badges/1f4cdc7f-0e95-477e-aa1e-1a9865479964', 1, '2026-05-13 22:07:05'),
(31, 12, 155, '2014-04-10', '2029-02-12', 'active', NULL, '413d1009-e54e-4e35-9911-089542493dc2', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:413d1009-e54e-4e35-9911-089542493dc2\ncredly_template_id:a817cea3-fa81-49e4-ae64-28685a8092a2\nbadge_url:https://www.credly.com/badges/413d1009-e54e-4e35-9911-089542493dc2', 1, '2026-05-13 22:07:05'),
(32, 12, 156, '2014-04-10', '2029-02-12', 'active', NULL, '86bee1d6-9752-419d-a1fe-03238281f776', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:86bee1d6-9752-419d-a1fe-03238281f776\ncredly_template_id:2aba1ffb-e5cb-4951-bed5-2e5f4daff7b5\nbadge_url:https://www.credly.com/badges/86bee1d6-9752-419d-a1fe-03238281f776', 1, '2026-05-13 22:07:05'),
(33, 12, 157, '2014-04-10', '2029-02-12', 'active', NULL, '352b921a-ae29-4b94-9427-1ce5e1423d4c', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:352b921a-ae29-4b94-9427-1ce5e1423d4c\ncredly_template_id:7bbc0730-ab31-4a7a-8698-7a156a463a13\nbadge_url:https://www.credly.com/badges/352b921a-ae29-4b94-9427-1ce5e1423d4c', 1, '2026-05-13 22:07:05'),
(34, 12, 158, '2014-04-10', '2029-02-12', 'active', NULL, '45505815-55f0-44c1-b645-45e9951bcb30', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:45505815-55f0-44c1-b645-45e9951bcb30\ncredly_template_id:b11c8a08-9204-45af-94ba-c634091dd710\nbadge_url:https://www.credly.com/badges/45505815-55f0-44c1-b645-45e9951bcb30', 1, '2026-05-13 22:07:05'),
(35, 12, 159, '2007-07-31', '2029-02-12', 'active', NULL, '53b5d483-5259-4a1f-824f-515d725f23f1', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:53b5d483-5259-4a1f-824f-515d725f23f1\ncredly_template_id:463a9839-c97e-42c2-987d-a52a815ba540\nbadge_url:https://www.credly.com/badges/53b5d483-5259-4a1f-824f-515d725f23f1', 1, '2026-05-13 22:07:05'),
(36, 12, 160, '2001-11-16', '2029-02-12', 'active', NULL, '725e4252-605a-4167-a801-7ecbfd31c633', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:725e4252-605a-4167-a801-7ecbfd31c633\ncredly_template_id:138e7e97-b2fc-417d-a69b-182fda54ec4c\nbadge_url:https://www.credly.com/badges/725e4252-605a-4167-a801-7ecbfd31c633', 1, '2026-05-13 22:07:05'),
(37, 12, 161, '2001-11-16', '2029-02-12', 'active', NULL, 'a406d257-954d-4d93-93e3-1cd57667ca00', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:a406d257-954d-4d93-93e3-1cd57667ca00\ncredly_template_id:84ab53c1-08f9-464f-be6c-5d98a72e3fa9\nbadge_url:https://www.credly.com/badges/a406d257-954d-4d93-93e3-1cd57667ca00', 1, '2026-05-13 22:07:05'),
(38, 12, 162, '2001-11-16', '2029-02-12', 'active', NULL, 'a50ad274-bfa4-4388-9a08-8d649680211d', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:a50ad274-bfa4-4388-9a08-8d649680211d\ncredly_template_id:e6c7fce6-bd0c-438d-96bd-034a06f8ea97\nbadge_url:https://www.credly.com/badges/a50ad274-bfa4-4388-9a08-8d649680211d', 1, '2026-05-13 22:07:05'),
(39, 12, 163, '2024-03-01', '2026-03-01', 'expired', NULL, 'eb184d91-615a-40fe-a515-7edcee88fbb7', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:eb184d91-615a-40fe-a515-7edcee88fbb7\ncredly_template_id:b442b730-e180-41c8-b72c-d450d5187b0b\nbadge_url:https://www.credly.com/badges/eb184d91-615a-40fe-a515-7edcee88fbb7', 1, '2026-05-13 22:07:05'),
(40, 12, 164, '2024-03-01', '2026-03-01', 'expired', NULL, '2c036a51-892a-46b5-8481-7bac19b6fd1d', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:2c036a51-892a-46b5-8481-7bac19b6fd1d\ncredly_template_id:c45aa744-2f02-432b-a9d8-6859169e5d27\nbadge_url:https://www.credly.com/badges/2c036a51-892a-46b5-8481-7bac19b6fd1d', 1, '2026-05-13 22:07:05'),
(41, 12, 165, '2024-03-01', '2026-03-01', 'expired', NULL, '5651c1c9-53fb-4c42-9be9-c34d6912ae6b', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:5651c1c9-53fb-4c42-9be9-c34d6912ae6b\ncredly_template_id:4666d775-ed93-427d-ae42-f69c3c6d9892\nbadge_url:https://www.credly.com/badges/5651c1c9-53fb-4c42-9be9-c34d6912ae6b', 1, '2026-05-13 22:07:05'),
(42, 12, 166, '2024-03-01', '2026-03-01', 'expired', NULL, '74aff08e-a9d9-4eb9-bed0-818a543a996c', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:74aff08e-a9d9-4eb9-bed0-818a543a996c\ncredly_template_id:a01b8067-3941-452f-a2a1-15a65d74ef03\nbadge_url:https://www.credly.com/badges/74aff08e-a9d9-4eb9-bed0-818a543a996c', 1, '2026-05-13 22:07:05'),
(43, 12, 167, '2007-07-31', '2023-08-07', 'expired', NULL, '3d8296bd-65f7-4896-a68a-61eccd0056fd', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:3d8296bd-65f7-4896-a68a-61eccd0056fd\ncredly_template_id:0cf2e977-6246-4d48-be9a-53e69255c110\nbadge_url:https://www.credly.com/badges/3d8296bd-65f7-4896-a68a-61eccd0056fd', 1, '2026-05-13 22:07:05'),
(44, 12, 168, '2001-11-16', '2023-08-07', 'expired', NULL, '21cf8d15-ae8a-4369-9091-926719fef78b', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:21cf8d15-ae8a-4369-9091-926719fef78b\ncredly_template_id:0542b7e2-bbfe-4ab4-8817-ec3db986b5bf\nbadge_url:https://www.credly.com/badges/21cf8d15-ae8a-4369-9091-926719fef78b', 1, '2026-05-13 22:07:05'),
(45, 12, 169, '2001-09-14', '2023-08-07', 'expired', NULL, 'ed4dba88-714b-4753-a003-4a6ad4f137da', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:ed4dba88-714b-4753-a003-4a6ad4f137da\ncredly_template_id:f13fa86c-a3d8-43fa-ab18-f6be9925699b\nbadge_url:https://www.credly.com/badges/ed4dba88-714b-4753-a003-4a6ad4f137da', 1, '2026-05-13 22:07:05'),
(46, 12, 170, '2001-04-02', '2023-08-07', 'expired', NULL, '84cb55f1-9da2-4de1-a128-344e819951fc', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:84cb55f1-9da2-4de1-a128-344e819951fc\ncredly_template_id:1e75a61c-8885-4f68-8ffe-2e19b77b527a\nbadge_url:https://www.credly.com/badges/84cb55f1-9da2-4de1-a128-344e819951fc', 1, '2026-05-13 22:07:05'),
(47, 12, 171, '2021-03-07', '2023-03-07', 'expired', NULL, 'd25c9423-8041-4fa4-a1bd-3185a5234b14', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:d25c9423-8041-4fa4-a1bd-3185a5234b14\ncredly_template_id:3891195b-6b08-401a-804f-fe995629c10e\nbadge_url:https://www.credly.com/badges/d25c9423-8041-4fa4-a1bd-3185a5234b14', 1, '2026-05-13 22:07:05'),
(48, 12, 167, '2001-09-14', '2004-09-14', 'expired', NULL, '71b28db0-3fd8-46bb-9435-e8ecc752f3dc', NULL, 'Importato da Credly il 2026-05-14 00:07\ncredly_badge_id:71b28db0-3fd8-46bb-9435-e8ecc752f3dc\ncredly_template_id:0cf2e977-6246-4d48-be9a-53e69255c110\nbadge_url:https://www.credly.com/badges/71b28db0-3fd8-46bb-9435-e8ecc752f3dc', 1, '2026-05-13 22:07:05'),
(49, 2, 172, '2025-01-01', NULL, 'active', NULL, 'linkedin_cert:1b2df03bc989fa936e00b037704445d7', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:1b2df03bc989fa936e00b037704445d7\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/d53cc93986c0c98c0ec066ff68c104d159ad03da58732dc89f3a22f487023515', 1, '2026-05-15 10:34:48'),
(50, 2, 173, '2025-01-01', NULL, 'active', NULL, 'linkedin_cert:a7cd7371532c39a0da1959d536226ca8', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:a7cd7371532c39a0da1959d536226ca8\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/332402522264fa700e7c79800665d3048c51aefae2f41114fca2266cfa7f577c', 1, '2026-05-15 10:34:48'),
(51, 2, 174, '2022-07-01', NULL, 'active', NULL, 'linkedin_cert:1affd90a71ff55e6e060e8c0b36bd742', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:1affd90a71ff55e6e060e8c0b36bd742\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/9767cb983739c489012db4d6a19d6b7bb5837fd1048ce7b4fac9670c8365cb51', 1, '2026-05-15 10:34:48'),
(52, 2, 175, '2025-01-01', NULL, 'active', NULL, 'linkedin_cert:0c21baf63514c79b74b7bbab0ee94294', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:0c21baf63514c79b74b7bbab0ee94294\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/4cfd4cb03a5253beaeb4ab6c2a200966d52e3a1f844fcafd14d6ea7430f7d5ed', 1, '2026-05-15 10:34:48'),
(53, 2, 176, '2025-02-01', NULL, 'active', NULL, 'linkedin_cert:bcaaa46e149df6b028250e4ae4bbce6b', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:bcaaa46e149df6b028250e4ae4bbce6b\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/9caa1acb98b41e9fa045d9d2fe42415c9f10e071f51ddf265588a4a904685684', 1, '2026-05-15 10:34:48'),
(54, 2, 177, '2025-02-01', NULL, 'active', NULL, 'linkedin_cert:69a35e42963e76fe92f672a58dfdca41', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:69a35e42963e76fe92f672a58dfdca41\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/01ca8d8d52d77aa2d6d411a3e46f74dccfe7937e143da847828a18829b524afc', 1, '2026-05-15 10:34:48'),
(55, 2, 178, '2025-03-01', NULL, 'active', NULL, 'linkedin_cert:a017a95fb9e9a668aeb9d12616a4955b', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:a017a95fb9e9a668aeb9d12616a4955b\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/9bf88b6420d6651b22d321870f66ea7e77a85de6bc0890ec5493b5e0d75c1bd8', 1, '2026-05-15 10:34:48'),
(56, 2, 179, '2025-04-01', NULL, 'active', NULL, 'linkedin_cert:aea34b00e3d4778f40f63f55b72a6c45', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:aea34b00e3d4778f40f63f55b72a6c45\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/a6683f4b539a1615c61baea19df17d4da8614a46916a6eb7ab1ae68b3b7d4932', 1, '2026-05-15 10:34:48'),
(57, 2, 180, '2025-04-01', NULL, 'active', NULL, 'linkedin_cert:c044e080e3a78f9576625106f61d1d89', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:c044e080e3a78f9576625106f61d1d89\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/080e173b246ca5ffce93b156dc1df1f9815891406e12a9fdcdf0382a61746b0c', 1, '2026-05-15 10:34:48'),
(58, 2, 181, '2025-07-01', NULL, 'active', NULL, 'linkedin_cert:6818a07b04a60477ffdc62a29fee0313', NULL, 'Importato da LinkedIn il 2026-05-15 12:34\nlinkedin_cert:6818a07b04a60477ffdc62a29fee0313\nlinkedin_authority:LinkedIn\nlinkedin_cred_url:https://www.linkedin.com/learning/certificates/d184fc2d14c3a81826d175aec3186c3561384c7bad04ecb6a253698b9af608f4', 1, '2026-05-15 10:34:48'),
(59, 87, 127, '2026-03-06', NULL, 'active', NULL, '13a5a29a-1493-4379-8531-2e0a385c0a4d', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:13a5a29a-1493-4379-8531-2e0a385c0a4d\ncredly_template_id:c444f85f-025f-4408-9015-cdda40bcae60\nbadge_url:https://www.credly.com/badges/13a5a29a-1493-4379-8531-2e0a385c0a4d', 1, '2026-05-15 10:44:29'),
(60, 87, 128, '2026-02-12', NULL, 'active', NULL, '07fbfbac-36e0-4ac3-bbf0-0f3732a3a399', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:07fbfbac-36e0-4ac3-bbf0-0f3732a3a399\ncredly_template_id:2703e463-b0d8-4ce5-a941-bbd12d40aa1c\nbadge_url:https://www.credly.com/badges/07fbfbac-36e0-4ac3-bbf0-0f3732a3a399', 1, '2026-05-15 10:44:29'),
(61, 87, 129, '2025-10-21', NULL, 'active', NULL, 'f2953e33-afc9-4813-805c-6f1230b85819', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:f2953e33-afc9-4813-805c-6f1230b85819\ncredly_template_id:190bfe58-43c0-4217-a0b2-79e289655e10\nbadge_url:https://www.credly.com/badges/f2953e33-afc9-4813-805c-6f1230b85819', 1, '2026-05-15 10:44:29'),
(62, 87, 130, '2025-10-21', NULL, 'active', NULL, '09364ca6-16ba-46fd-9134-ca3a84a42f52', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:09364ca6-16ba-46fd-9134-ca3a84a42f52\ncredly_template_id:6018ef0b-d33e-403d-9b9f-b516747e5c50\nbadge_url:https://www.credly.com/badges/09364ca6-16ba-46fd-9134-ca3a84a42f52', 1, '2026-05-15 10:44:29'),
(63, 87, 131, '2025-05-30', NULL, 'active', NULL, '3fd41573-81fc-4988-92aa-a365f0a71aac', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:3fd41573-81fc-4988-92aa-a365f0a71aac\ncredly_template_id:c82146dd-fb76-415b-9f15-ea4047daba01\nbadge_url:https://www.credly.com/badges/3fd41573-81fc-4988-92aa-a365f0a71aac', 1, '2026-05-15 10:44:29'),
(64, 87, 132, '2025-01-23', NULL, 'active', NULL, '4f1536f2-3f3a-48b3-a8a2-d0985c1f80cf', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:4f1536f2-3f3a-48b3-a8a2-d0985c1f80cf\ncredly_template_id:28bbd21e-dc21-4ac9-9b8b-0e2e60c4675e\nbadge_url:https://www.credly.com/badges/4f1536f2-3f3a-48b3-a8a2-d0985c1f80cf', 1, '2026-05-15 10:44:29'),
(65, 87, 133, '2025-01-23', NULL, 'active', NULL, '05b18804-6ebf-481b-a30e-4b5bfd336e70', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:05b18804-6ebf-481b-a30e-4b5bfd336e70\ncredly_template_id:a81a772e-d138-4d42-8d5b-ce5120c0eac1\nbadge_url:https://www.credly.com/badges/05b18804-6ebf-481b-a30e-4b5bfd336e70', 1, '2026-05-15 10:44:29'),
(66, 87, 134, '2025-01-22', NULL, 'active', NULL, '5c8a866f-5157-40a4-9827-8e86c2ee8e08', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:5c8a866f-5157-40a4-9827-8e86c2ee8e08\ncredly_template_id:2aff6d71-ae6f-44cb-a8ec-5349f807d49b\nbadge_url:https://www.credly.com/badges/5c8a866f-5157-40a4-9827-8e86c2ee8e08', 1, '2026-05-15 10:44:29'),
(67, 87, 135, '2024-08-29', '2026-08-29', 'active', NULL, '5c650342-812c-4697-8556-d599dead641f', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:5c650342-812c-4697-8556-d599dead641f\ncredly_template_id:b5fbfd37-39c9-4800-a348-75400f199f8f\nbadge_url:https://www.credly.com/badges/5c650342-812c-4697-8556-d599dead641f', 1, '2026-05-15 10:44:29'),
(68, 87, 136, '2024-08-29', NULL, 'active', NULL, '76e6b954-72d7-49c1-9891-02bd62fdc24f', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:76e6b954-72d7-49c1-9891-02bd62fdc24f\ncredly_template_id:b1be0714-51d3-4b2d-bf29-06f88bd66d17\nbadge_url:https://www.credly.com/badges/76e6b954-72d7-49c1-9891-02bd62fdc24f', 1, '2026-05-15 10:44:29'),
(69, 87, 137, '2024-07-11', '2026-08-29', 'active', NULL, '309443ec-e081-4f04-8119-ac237af764b1', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:309443ec-e081-4f04-8119-ac237af764b1\ncredly_template_id:16abd220-eac0-469f-b15f-300c9d97a343\nbadge_url:https://www.credly.com/badges/309443ec-e081-4f04-8119-ac237af764b1', 1, '2026-05-15 10:44:29'),
(70, 87, 138, '2024-07-11', NULL, 'active', NULL, 'fc045765-ffde-4433-ad42-5891c0e9a040', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:fc045765-ffde-4433-ad42-5891c0e9a040\ncredly_template_id:a988c07d-dd6c-4249-8f01-2fa8f9f3acdb\nbadge_url:https://www.credly.com/badges/fc045765-ffde-4433-ad42-5891c0e9a040', 1, '2026-05-15 10:44:29'),
(71, 87, 139, '2024-07-08', NULL, 'active', NULL, '7e482945-377d-4008-88a5-5c42a501aa3d', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:7e482945-377d-4008-88a5-5c42a501aa3d\ncredly_template_id:20576877-1003-444c-91c8-b7aba603253e\nbadge_url:https://www.credly.com/badges/7e482945-377d-4008-88a5-5c42a501aa3d', 1, '2026-05-15 10:44:29'),
(72, 87, 140, '2023-07-27', NULL, 'active', NULL, '21d8bdd6-07b1-4609-ab13-a090f4343cf5', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:21d8bdd6-07b1-4609-ab13-a090f4343cf5\ncredly_template_id:a78ce6a7-521d-4e2f-9408-386d3701bd6e\nbadge_url:https://www.credly.com/badges/21d8bdd6-07b1-4609-ab13-a090f4343cf5', 1, '2026-05-15 10:44:29'),
(73, 87, 141, '2023-05-30', '2026-08-29', 'active', NULL, '7b03de27-d08e-4f5e-843d-370253128f0a', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:7b03de27-d08e-4f5e-843d-370253128f0a\ncredly_template_id:f6159ec0-43b2-444b-a1e0-18a323f4833f\nbadge_url:https://www.credly.com/badges/7b03de27-d08e-4f5e-843d-370253128f0a', 1, '2026-05-15 10:44:29'),
(74, 87, 142, '2023-05-30', NULL, 'active', NULL, 'f1c52829-8a1a-40dd-a9fa-fba370149ab9', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:f1c52829-8a1a-40dd-a9fa-fba370149ab9\ncredly_template_id:5bdbc502-ac97-451f-8b91-75bff4049cb9\nbadge_url:https://www.credly.com/badges/f1c52829-8a1a-40dd-a9fa-fba370149ab9', 1, '2026-05-15 10:44:29'),
(75, 87, 143, '2023-05-30', NULL, 'active', NULL, 'be3edea3-717d-476b-9032-5dcd0b821bb7', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:be3edea3-717d-476b-9032-5dcd0b821bb7\ncredly_template_id:227d4f08-6330-4d6b-8782-6bfb11159d51\nbadge_url:https://www.credly.com/badges/be3edea3-717d-476b-9032-5dcd0b821bb7', 1, '2026-05-15 10:44:29'),
(76, 87, 144, '2022-11-28', NULL, 'active', NULL, 'bef486b7-6c76-45a5-84cb-ba41ef990b26', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:bef486b7-6c76-45a5-84cb-ba41ef990b26\ncredly_template_id:691140ff-b1ad-421c-856d-b78d9deb7b69\nbadge_url:https://www.credly.com/badges/bef486b7-6c76-45a5-84cb-ba41ef990b26', 1, '2026-05-15 10:44:29'),
(77, 87, 145, '2022-04-15', '2042-04-15', 'active', NULL, '5d192b7d-4e68-4052-8de3-d467e9558fec', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:5d192b7d-4e68-4052-8de3-d467e9558fec\ncredly_template_id:1901be49-c67b-48e4-9840-aeb98e433d1f\nbadge_url:https://www.credly.com/badges/5d192b7d-4e68-4052-8de3-d467e9558fec', 1, '2026-05-15 10:44:29'),
(78, 87, 146, '2022-04-14', '2042-04-14', 'active', NULL, 'ed258420-88e8-4d40-8866-54c634d25f3f', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:ed258420-88e8-4d40-8866-54c634d25f3f\ncredly_template_id:6b89ff53-217f-4471-8c01-11eef9a687fc\nbadge_url:https://www.credly.com/badges/ed258420-88e8-4d40-8866-54c634d25f3f', 1, '2026-05-15 10:44:29'),
(79, 87, 147, '2022-04-09', NULL, 'active', NULL, '35627694-d196-4eaa-a8d6-6d3bda78a7f7', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:35627694-d196-4eaa-a8d6-6d3bda78a7f7\ncredly_template_id:f64706e8-611b-4182-872c-128a63a59ec1\nbadge_url:https://www.credly.com/badges/35627694-d196-4eaa-a8d6-6d3bda78a7f7', 1, '2026-05-15 10:44:29'),
(80, 87, 148, '2022-04-08', NULL, 'active', NULL, '89d0cc5c-c561-4b35-8f0c-cb2223dfcb2d', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:89d0cc5c-c561-4b35-8f0c-cb2223dfcb2d\ncredly_template_id:5b18393f-de23-4972-8de7-046df682ff5b\nbadge_url:https://www.credly.com/badges/89d0cc5c-c561-4b35-8f0c-cb2223dfcb2d', 1, '2026-05-15 10:44:29'),
(81, 87, 149, '2021-12-03', '2041-12-03', 'active', NULL, 'f0bb030c-8d0b-4fa0-bd09-b657e0801380', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:f0bb030c-8d0b-4fa0-bd09-b657e0801380\ncredly_template_id:475d6541-8f29-49b6-b81d-b55c2415e26d\nbadge_url:https://www.credly.com/badges/f0bb030c-8d0b-4fa0-bd09-b657e0801380', 1, '2026-05-15 10:44:29'),
(82, 87, 150, '2020-02-24', NULL, 'active', NULL, '365a0259-bfae-4de0-9fea-b472c845057a', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:365a0259-bfae-4de0-9fea-b472c845057a\ncredly_template_id:50bf2406-4f9a-4d8c-8268-30a40afe44d1\nbadge_url:https://www.credly.com/badges/365a0259-bfae-4de0-9fea-b472c845057a', 1, '2026-05-15 10:44:29'),
(83, 87, 151, '2020-02-24', NULL, 'active', NULL, 'e2a4c249-8560-4da0-93fc-34a2917215af', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:e2a4c249-8560-4da0-93fc-34a2917215af\ncredly_template_id:54033bcb-637a-4cc4-900f-8db194071ae2\nbadge_url:https://www.credly.com/badges/e2a4c249-8560-4da0-93fc-34a2917215af', 1, '2026-05-15 10:44:29'),
(84, 87, 152, '2020-02-07', '2029-02-12', 'active', NULL, '27935018-d43d-4b06-819a-bd1d675b3ba1', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:27935018-d43d-4b06-819a-bd1d675b3ba1\ncredly_template_id:7dd2f32e-d5d8-478b-b999-12243404494c\nbadge_url:https://www.credly.com/badges/27935018-d43d-4b06-819a-bd1d675b3ba1', 1, '2026-05-15 10:44:29'),
(85, 87, 153, '2014-04-10', '2029-02-12', 'active', NULL, 'ddedf84b-d32c-4588-928c-52c00de795ad', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:ddedf84b-d32c-4588-928c-52c00de795ad\ncredly_template_id:ac579428-dda0-4491-86b9-e519f1ce7d24\nbadge_url:https://www.credly.com/badges/ddedf84b-d32c-4588-928c-52c00de795ad', 1, '2026-05-15 10:44:29'),
(86, 87, 154, '2014-04-10', '2029-02-12', 'active', NULL, '1f4cdc7f-0e95-477e-aa1e-1a9865479964', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:1f4cdc7f-0e95-477e-aa1e-1a9865479964\ncredly_template_id:b9154be1-ee3a-46a2-a387-6d0b48162754\nbadge_url:https://www.credly.com/badges/1f4cdc7f-0e95-477e-aa1e-1a9865479964', 1, '2026-05-15 10:44:29'),
(87, 87, 155, '2014-04-10', '2029-02-12', 'active', NULL, '413d1009-e54e-4e35-9911-089542493dc2', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:413d1009-e54e-4e35-9911-089542493dc2\ncredly_template_id:a817cea3-fa81-49e4-ae64-28685a8092a2\nbadge_url:https://www.credly.com/badges/413d1009-e54e-4e35-9911-089542493dc2', 1, '2026-05-15 10:44:29'),
(88, 87, 156, '2014-04-10', '2029-02-12', 'active', NULL, '86bee1d6-9752-419d-a1fe-03238281f776', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:86bee1d6-9752-419d-a1fe-03238281f776\ncredly_template_id:2aba1ffb-e5cb-4951-bed5-2e5f4daff7b5\nbadge_url:https://www.credly.com/badges/86bee1d6-9752-419d-a1fe-03238281f776', 1, '2026-05-15 10:44:29'),
(89, 87, 157, '2014-04-10', '2029-02-12', 'active', NULL, '352b921a-ae29-4b94-9427-1ce5e1423d4c', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:352b921a-ae29-4b94-9427-1ce5e1423d4c\ncredly_template_id:7bbc0730-ab31-4a7a-8698-7a156a463a13\nbadge_url:https://www.credly.com/badges/352b921a-ae29-4b94-9427-1ce5e1423d4c', 1, '2026-05-15 10:44:29'),
(90, 87, 158, '2014-04-10', '2029-02-12', 'active', NULL, '45505815-55f0-44c1-b645-45e9951bcb30', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:45505815-55f0-44c1-b645-45e9951bcb30\ncredly_template_id:b11c8a08-9204-45af-94ba-c634091dd710\nbadge_url:https://www.credly.com/badges/45505815-55f0-44c1-b645-45e9951bcb30', 1, '2026-05-15 10:44:29'),
(91, 87, 159, '2007-07-31', '2029-02-12', 'active', NULL, '53b5d483-5259-4a1f-824f-515d725f23f1', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:53b5d483-5259-4a1f-824f-515d725f23f1\ncredly_template_id:463a9839-c97e-42c2-987d-a52a815ba540\nbadge_url:https://www.credly.com/badges/53b5d483-5259-4a1f-824f-515d725f23f1', 1, '2026-05-15 10:44:29'),
(92, 87, 160, '2001-11-16', '2029-02-12', 'active', NULL, '725e4252-605a-4167-a801-7ecbfd31c633', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:725e4252-605a-4167-a801-7ecbfd31c633\ncredly_template_id:138e7e97-b2fc-417d-a69b-182fda54ec4c\nbadge_url:https://www.credly.com/badges/725e4252-605a-4167-a801-7ecbfd31c633', 1, '2026-05-15 10:44:29'),
(93, 87, 161, '2001-11-16', '2029-02-12', 'active', NULL, 'a406d257-954d-4d93-93e3-1cd57667ca00', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:a406d257-954d-4d93-93e3-1cd57667ca00\ncredly_template_id:84ab53c1-08f9-464f-be6c-5d98a72e3fa9\nbadge_url:https://www.credly.com/badges/a406d257-954d-4d93-93e3-1cd57667ca00', 1, '2026-05-15 10:44:29'),
(94, 87, 162, '2001-11-16', '2029-02-12', 'active', NULL, 'a50ad274-bfa4-4388-9a08-8d649680211d', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:a50ad274-bfa4-4388-9a08-8d649680211d\ncredly_template_id:e6c7fce6-bd0c-438d-96bd-034a06f8ea97\nbadge_url:https://www.credly.com/badges/a50ad274-bfa4-4388-9a08-8d649680211d', 1, '2026-05-15 10:44:29'),
(95, 87, 17, '2001-04-02', '2029-02-12', 'active', NULL, '6ffe4a66-fa51-4f8b-acf5-fd18d89d4e5e', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:6ffe4a66-fa51-4f8b-acf5-fd18d89d4e5e\ncredly_template_id:59876d7c-8286-4186-b234-5afc23d51b06\nbadge_url:https://www.credly.com/badges/6ffe4a66-fa51-4f8b-acf5-fd18d89d4e5e', 1, '2026-05-15 10:44:29'),
(96, 87, 163, '2024-03-01', '2026-03-01', 'expired', NULL, 'eb184d91-615a-40fe-a515-7edcee88fbb7', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:eb184d91-615a-40fe-a515-7edcee88fbb7\ncredly_template_id:b442b730-e180-41c8-b72c-d450d5187b0b\nbadge_url:https://www.credly.com/badges/eb184d91-615a-40fe-a515-7edcee88fbb7', 1, '2026-05-15 10:44:29'),
(97, 87, 164, '2024-03-01', '2026-03-01', 'expired', NULL, '2c036a51-892a-46b5-8481-7bac19b6fd1d', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:2c036a51-892a-46b5-8481-7bac19b6fd1d\ncredly_template_id:c45aa744-2f02-432b-a9d8-6859169e5d27\nbadge_url:https://www.credly.com/badges/2c036a51-892a-46b5-8481-7bac19b6fd1d', 1, '2026-05-15 10:44:29'),
(98, 87, 165, '2024-03-01', '2026-03-01', 'expired', NULL, '5651c1c9-53fb-4c42-9be9-c34d6912ae6b', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:5651c1c9-53fb-4c42-9be9-c34d6912ae6b\ncredly_template_id:4666d775-ed93-427d-ae42-f69c3c6d9892\nbadge_url:https://www.credly.com/badges/5651c1c9-53fb-4c42-9be9-c34d6912ae6b', 1, '2026-05-15 10:44:29'),
(99, 87, 166, '2024-03-01', '2026-03-01', 'expired', NULL, '74aff08e-a9d9-4eb9-bed0-818a543a996c', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:74aff08e-a9d9-4eb9-bed0-818a543a996c\ncredly_template_id:a01b8067-3941-452f-a2a1-15a65d74ef03\nbadge_url:https://www.credly.com/badges/74aff08e-a9d9-4eb9-bed0-818a543a996c', 1, '2026-05-15 10:44:29'),
(100, 87, 167, '2007-07-31', '2023-08-07', 'expired', NULL, '3d8296bd-65f7-4896-a68a-61eccd0056fd', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:3d8296bd-65f7-4896-a68a-61eccd0056fd\ncredly_template_id:0cf2e977-6246-4d48-be9a-53e69255c110\nbadge_url:https://www.credly.com/badges/3d8296bd-65f7-4896-a68a-61eccd0056fd', 1, '2026-05-15 10:44:29'),
(101, 87, 168, '2001-11-16', '2023-08-07', 'expired', NULL, '21cf8d15-ae8a-4369-9091-926719fef78b', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:21cf8d15-ae8a-4369-9091-926719fef78b\ncredly_template_id:0542b7e2-bbfe-4ab4-8817-ec3db986b5bf\nbadge_url:https://www.credly.com/badges/21cf8d15-ae8a-4369-9091-926719fef78b', 1, '2026-05-15 10:44:29'),
(102, 87, 169, '2001-09-14', '2023-08-07', 'expired', NULL, 'ed4dba88-714b-4753-a003-4a6ad4f137da', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:ed4dba88-714b-4753-a003-4a6ad4f137da\ncredly_template_id:f13fa86c-a3d8-43fa-ab18-f6be9925699b\nbadge_url:https://www.credly.com/badges/ed4dba88-714b-4753-a003-4a6ad4f137da', 1, '2026-05-15 10:44:29'),
(103, 87, 170, '2001-04-02', '2023-08-07', 'expired', NULL, '84cb55f1-9da2-4de1-a128-344e819951fc', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:84cb55f1-9da2-4de1-a128-344e819951fc\ncredly_template_id:1e75a61c-8885-4f68-8ffe-2e19b77b527a\nbadge_url:https://www.credly.com/badges/84cb55f1-9da2-4de1-a128-344e819951fc', 1, '2026-05-15 10:44:29'),
(104, 87, 171, '2021-03-07', '2023-03-07', 'expired', NULL, 'd25c9423-8041-4fa4-a1bd-3185a5234b14', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:d25c9423-8041-4fa4-a1bd-3185a5234b14\ncredly_template_id:3891195b-6b08-401a-804f-fe995629c10e\nbadge_url:https://www.credly.com/badges/d25c9423-8041-4fa4-a1bd-3185a5234b14', 1, '2026-05-15 10:44:29'),
(105, 87, 167, '2001-09-14', '2004-09-14', 'expired', NULL, '71b28db0-3fd8-46bb-9435-e8ecc752f3dc', NULL, 'Importato da Credly il 2026-05-15 12:44\ncredly_badge_id:71b28db0-3fd8-46bb-9435-e8ecc752f3dc\ncredly_template_id:0cf2e977-6246-4d48-be9a-53e69255c110\nbadge_url:https://www.credly.com/badges/71b28db0-3fd8-46bb-9435-e8ecc752f3dc', 1, '2026-05-15 10:44:29'),
(106, 76, 170, '2015-03-05', '2018-04-30', 'expired', NULL, '7bd39894-8ef6-4377-ae24-0918ef3b7bc8', NULL, 'Importato da Credly il 2026-05-15 12:45\ncredly_badge_id:7bd39894-8ef6-4377-ae24-0918ef3b7bc8\ncredly_template_id:1e75a61c-8885-4f68-8ffe-2e19b77b527a\nbadge_url:https://www.credly.com/badges/7bd39894-8ef6-4377-ae24-0918ef3b7bc8', 1, '2026-05-15 10:45:42'),
(107, 78, 182, '2024-07-05', NULL, 'active', NULL, 'f2208dbb-d524-4d9b-bb65-238fdbbaad7e', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:f2208dbb-d524-4d9b-bb65-238fdbbaad7e\ncredly_template_id:2ed4d42a-860d-4995-ac84-928701ac11ce\nbadge_url:https://www.credly.com/badges/f2208dbb-d524-4d9b-bb65-238fdbbaad7e', 1, '2026-05-15 10:47:28'),
(108, 78, 183, '2022-02-21', '2027-07-04', 'active', NULL, '59ca5c65-a711-43e3-bb30-eef20fc10a78', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:59ca5c65-a711-43e3-bb30-eef20fc10a78\ncredly_template_id:82c3f356-9b45-4bc5-a3c2-1a3e84c0376b\nbadge_url:https://www.credly.com/badges/59ca5c65-a711-43e3-bb30-eef20fc10a78', 1, '2026-05-15 10:47:28'),
(109, 78, 184, '2022-02-21', '2027-07-04', 'active', NULL, 'c54f7162-f76f-42ad-a064-18f40a8b5549', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:c54f7162-f76f-42ad-a064-18f40a8b5549\ncredly_template_id:023e83ef-88b2-4178-bf06-906df7486c40\nbadge_url:https://www.credly.com/badges/c54f7162-f76f-42ad-a064-18f40a8b5549', 1, '2026-05-15 10:47:28'),
(110, 78, 185, '2021-11-25', '2027-07-04', 'active', NULL, '310e6709-c243-453d-822b-ff0ab475b6ce', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:310e6709-c243-453d-822b-ff0ab475b6ce\ncredly_template_id:d1283a16-f009-4b9a-8458-0704efb41a54\nbadge_url:https://www.credly.com/badges/310e6709-c243-453d-822b-ff0ab475b6ce', 1, '2026-05-15 10:47:28'),
(111, 78, 150, '2020-02-24', NULL, 'active', NULL, '1c5fb554-3605-4fd0-8468-7979d5038726', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:1c5fb554-3605-4fd0-8468-7979d5038726\ncredly_template_id:50bf2406-4f9a-4d8c-8268-30a40afe44d1\nbadge_url:https://www.credly.com/badges/1c5fb554-3605-4fd0-8468-7979d5038726', 1, '2026-05-15 10:47:28'),
(112, 78, 151, '2020-02-24', NULL, 'active', NULL, 'ba1df511-802d-4b79-a3c9-f61fdf075cef', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:ba1df511-802d-4b79-a3c9-f61fdf075cef\ncredly_template_id:54033bcb-637a-4cc4-900f-8db194071ae2\nbadge_url:https://www.credly.com/badges/ba1df511-802d-4b79-a3c9-f61fdf075cef', 1, '2026-05-15 10:47:28'),
(113, 78, 161, '2014-10-29', '2027-07-04', 'active', NULL, '14e46fbe-661f-4c8a-b859-39754ebf367f', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:14e46fbe-661f-4c8a-b859-39754ebf367f\ncredly_template_id:84ab53c1-08f9-464f-be6c-5d98a72e3fa9\nbadge_url:https://www.credly.com/badges/14e46fbe-661f-4c8a-b859-39754ebf367f', 1, '2026-05-15 10:47:28'),
(114, 78, 160, '2011-06-15', '2027-07-04', 'active', NULL, '65a040fe-6cef-41fd-a11e-adea13c40e78', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:65a040fe-6cef-41fd-a11e-adea13c40e78\ncredly_template_id:138e7e97-b2fc-417d-a69b-182fda54ec4c\nbadge_url:https://www.credly.com/badges/65a040fe-6cef-41fd-a11e-adea13c40e78', 1, '2026-05-15 10:47:28'),
(115, 78, 162, '2011-06-15', '2027-07-04', 'active', NULL, '6515cd34-4c01-4c14-8230-d8014e7267ed', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:6515cd34-4c01-4c14-8230-d8014e7267ed\ncredly_template_id:e6c7fce6-bd0c-438d-96bd-034a06f8ea97\nbadge_url:https://www.credly.com/badges/6515cd34-4c01-4c14-8230-d8014e7267ed', 1, '2026-05-15 10:47:28'),
(116, 78, 159, '2011-06-15', '2027-07-04', 'active', NULL, '8abd4656-b16a-45c7-9574-b8ca59611d96', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:8abd4656-b16a-45c7-9574-b8ca59611d96\ncredly_template_id:463a9839-c97e-42c2-987d-a52a815ba540\nbadge_url:https://www.credly.com/badges/8abd4656-b16a-45c7-9574-b8ca59611d96', 1, '2026-05-15 10:47:28'),
(117, 78, 17, '2001-04-04', '2027-07-04', 'active', NULL, 'c012ae54-2c8e-4223-9939-c99721c20e5a', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:c012ae54-2c8e-4223-9939-c99721c20e5a\ncredly_template_id:59876d7c-8286-4186-b234-5afc23d51b06\nbadge_url:https://www.credly.com/badges/c012ae54-2c8e-4223-9939-c99721c20e5a', 1, '2026-05-15 10:47:28'),
(118, 78, 168, '2014-10-29', '2023-06-11', 'expired', NULL, 'a15140a5-30f4-4448-8cb5-79bc9500dd5b', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:a15140a5-30f4-4448-8cb5-79bc9500dd5b\ncredly_template_id:0542b7e2-bbfe-4ab4-8817-ec3db986b5bf\nbadge_url:https://www.credly.com/badges/a15140a5-30f4-4448-8cb5-79bc9500dd5b', 1, '2026-05-15 10:47:28'),
(119, 78, 167, '2011-06-15', '2023-06-11', 'expired', NULL, '3e771bb7-0437-4cbe-945a-97be40444db8', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:3e771bb7-0437-4cbe-945a-97be40444db8\ncredly_template_id:0cf2e977-6246-4d48-be9a-53e69255c110\nbadge_url:https://www.credly.com/badges/3e771bb7-0437-4cbe-945a-97be40444db8', 1, '2026-05-15 10:47:28'),
(120, 78, 169, '2010-05-18', '2023-06-11', 'expired', NULL, 'ad662af5-c336-4bfa-8e9c-7b1ddfe51d5e', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:ad662af5-c336-4bfa-8e9c-7b1ddfe51d5e\ncredly_template_id:f13fa86c-a3d8-43fa-ab18-f6be9925699b\nbadge_url:https://www.credly.com/badges/ad662af5-c336-4bfa-8e9c-7b1ddfe51d5e', 1, '2026-05-15 10:47:28'),
(121, 78, 170, '2001-04-04', '2023-06-11', 'expired', NULL, '726b6228-57be-4fc2-99d4-e7cc4499c019', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:726b6228-57be-4fc2-99d4-e7cc4499c019\ncredly_template_id:1e75a61c-8885-4f68-8ffe-2e19b77b527a\nbadge_url:https://www.credly.com/badges/726b6228-57be-4fc2-99d4-e7cc4499c019', 1, '2026-05-15 10:47:28'),
(122, 78, 168, '2001-11-27', '2004-11-27', 'expired', NULL, 'f74177be-67b9-44ab-949c-d33472221728', NULL, 'Importato da Credly il 2026-05-15 12:47\ncredly_badge_id:f74177be-67b9-44ab-949c-d33472221728\ncredly_template_id:0542b7e2-bbfe-4ab4-8817-ec3db986b5bf\nbadge_url:https://www.credly.com/badges/f74177be-67b9-44ab-949c-d33472221728', 1, '2026-05-15 10:47:28'),
(123, 11, 186, '2016-08-11', NULL, 'active', NULL, 'b82ebd2d-129c-492d-8c50-53fe0caad92a', NULL, 'Importato da Credly il 2026-05-15 12:48\ncredly_badge_id:b82ebd2d-129c-492d-8c50-53fe0caad92a\ncredly_template_id:1ba69201-d873-4406-9339-d066b878b133\nbadge_url:https://www.credly.com/badges/b82ebd2d-129c-492d-8c50-53fe0caad92a', 1, '2026-05-15 10:48:28'),
(124, 11, 187, '2016-08-11', NULL, 'active', NULL, '4774b0d6-9142-4ba6-9720-9b270f5bb3dc', NULL, 'Importato da Credly il 2026-05-15 12:48\ncredly_badge_id:4774b0d6-9142-4ba6-9720-9b270f5bb3dc\ncredly_template_id:b5a782e0-d45f-4cc5-83d7-592fb6744dff\nbadge_url:https://www.credly.com/badges/4774b0d6-9142-4ba6-9720-9b270f5bb3dc', 1, '2026-05-15 10:48:28'),
(125, 11, 188, '2016-07-06', NULL, 'active', NULL, '3848a792-88d8-417c-9df2-d7e57669613c', NULL, 'Importato da Credly il 2026-05-15 12:48\ncredly_badge_id:3848a792-88d8-417c-9df2-d7e57669613c\ncredly_template_id:4859d3a9-988f-4492-84cd-abc930555db6\nbadge_url:https://www.credly.com/badges/3848a792-88d8-417c-9df2-d7e57669613c', 1, '2026-05-15 10:48:28'),
(126, 11, 189, '2016-04-21', NULL, 'active', NULL, 'edf6306f-4969-42a0-930c-9d692a4ec707', NULL, 'Importato da Credly il 2026-05-15 12:48\ncredly_badge_id:edf6306f-4969-42a0-930c-9d692a4ec707\ncredly_template_id:a8c28d1c-e6d2-4ee9-8293-57c3c2e30a4b\nbadge_url:https://www.credly.com/badges/edf6306f-4969-42a0-930c-9d692a4ec707', 1, '2026-05-15 10:48:28'),
(127, 7, 17, '2020-10-19', '2026-08-02', 'expiring', NULL, '4e70d720-549d-405b-8687-90f62597dbbd', NULL, 'Importato da Credly il 2026-05-15 12:48\ncredly_badge_id:4e70d720-549d-405b-8687-90f62597dbbd\ncredly_template_id:59876d7c-8286-4186-b234-5afc23d51b06\nbadge_url:https://www.credly.com/badges/4e70d720-549d-405b-8687-90f62597dbbd', 1, '2026-05-15 10:48:30'),
(128, 23, 190, '2017-09-26', NULL, 'active', NULL, 'a59304a0-4914-4ee6-a695-8aa9bd21c1ee', NULL, 'Importato da Credly il 2026-05-15 12:51\ncredly_badge_id:a59304a0-4914-4ee6-a695-8aa9bd21c1ee\ncredly_template_id:8625249d-1a6b-40ea-9b61-5495769c8e5f\nbadge_url:https://www.credly.com/badges/a59304a0-4914-4ee6-a695-8aa9bd21c1ee', 1, '2026-05-15 10:51:14'),
(129, 53, 191, '2021-06-14', NULL, 'active', NULL, '2aac2f6a-d623-49f1-9059-a93209457706', NULL, 'Importato da Credly il 2026-05-15 12:53\ncredly_badge_id:2aac2f6a-d623-49f1-9059-a93209457706\ncredly_template_id:3129500d-fc23-4c6c-9648-428c4f7269c6\nbadge_url:https://www.credly.com/badges/2aac2f6a-d623-49f1-9059-a93209457706', 1, '2026-05-15 10:53:33'),
(130, 53, 192, '2019-01-28', NULL, 'active', NULL, '1dba7e59-d392-4c97-95b2-472792a52bee', NULL, 'Importato da Credly il 2026-05-15 12:53\ncredly_badge_id:1dba7e59-d392-4c97-95b2-472792a52bee\ncredly_template_id:c2a705e4-fe2d-45cc-a8c9-9e3aa8df05e9\nbadge_url:https://www.credly.com/badges/1dba7e59-d392-4c97-95b2-472792a52bee', 1, '2026-05-15 10:53:33'),
(131, 53, 193, '2019-01-24', NULL, 'active', NULL, 'd322240c-0955-4105-906d-face9de0ebf2', NULL, 'Importato da Credly il 2026-05-15 12:53\ncredly_badge_id:d322240c-0955-4105-906d-face9de0ebf2\ncredly_template_id:ca4b83f3-bb37-4983-9abf-8fd814a5f184\nbadge_url:https://www.credly.com/badges/d322240c-0955-4105-906d-face9de0ebf2', 1, '2026-05-15 10:53:33'),
(132, 53, 194, '2013-12-23', NULL, 'active', NULL, '2ed7d38a-df3d-467b-9f83-ffa880327687', NULL, 'Importato da Credly il 2026-05-15 12:53\ncredly_badge_id:2ed7d38a-df3d-467b-9f83-ffa880327687\ncredly_template_id:7b35ca52-1eaf-4aa9-a819-68fe8fa50aac\nbadge_url:https://www.credly.com/badges/2ed7d38a-df3d-467b-9f83-ffa880327687', 1, '2026-05-15 10:53:33'),
(133, 53, 195, '2013-08-07', NULL, 'active', NULL, '60089971-9551-4ae8-97f6-b32c36deb3ad', NULL, 'Importato da Credly il 2026-05-15 12:53\ncredly_badge_id:60089971-9551-4ae8-97f6-b32c36deb3ad\ncredly_template_id:b477cf0c-b4d5-4e9c-a0ef-0fc32c5bbaaa\nbadge_url:https://www.credly.com/badges/60089971-9551-4ae8-97f6-b32c36deb3ad', 1, '2026-05-15 10:53:33'),
(134, 53, 196, '2013-02-22', NULL, 'active', NULL, '477b789c-eb37-49e7-b272-59b7fda8a63e', NULL, 'Importato da Credly il 2026-05-15 12:53\ncredly_badge_id:477b789c-eb37-49e7-b272-59b7fda8a63e\ncredly_template_id:08e84eba-b7f2-4851-8d43-c2dc6166bb99\nbadge_url:https://www.credly.com/badges/477b789c-eb37-49e7-b272-59b7fda8a63e', 1, '2026-05-15 10:53:33'),
(135, 53, 197, '2009-09-17', NULL, 'active', NULL, '4cfdb224-723c-4736-aeaf-867640c7aebf', NULL, 'Importato da Credly il 2026-05-15 12:53\ncredly_badge_id:4cfdb224-723c-4736-aeaf-867640c7aebf\ncredly_template_id:e1389b75-56ee-4562-9efe-93d42afd6ecf\nbadge_url:https://www.credly.com/badges/4cfdb224-723c-4736-aeaf-867640c7aebf', 1, '2026-05-15 10:53:33'),
(136, 43, 17, '2022-12-14', '2027-09-08', 'active', NULL, 'd27247da-369e-4335-bc81-1ffe149d9502', NULL, 'Importato da Credly il 2026-05-15 14:03\ncredly_badge_id:d27247da-369e-4335-bc81-1ffe149d9502\ncredly_template_id:59876d7c-8286-4186-b234-5afc23d51b06\nbadge_url:https://www.credly.com/badges/d27247da-369e-4335-bc81-1ffe149d9502', 1, '2026-05-15 12:03:17'),
(137, 43, 185, '2021-09-15', '2027-09-08', 'active', NULL, '810bf9c0-8af0-408c-9da3-810956f25baa', NULL, 'Importato da Credly il 2026-05-15 14:03\ncredly_badge_id:810bf9c0-8af0-408c-9da3-810956f25baa\ncredly_template_id:d1283a16-f009-4b9a-8458-0704efb41a54\nbadge_url:https://www.credly.com/badges/810bf9c0-8af0-408c-9da3-810956f25baa', 1, '2026-05-15 12:03:17'),
(138, 14, 17, '2022-04-04', '2028-01-30', 'active', NULL, 'ede3b8bb-8320-4aec-9737-61cf30a7e41e', NULL, 'Importato da Credly il 2026-05-15 14:04\ncredly_badge_id:ede3b8bb-8320-4aec-9737-61cf30a7e41e\ncredly_template_id:59876d7c-8286-4186-b234-5afc23d51b06\nbadge_url:https://www.credly.com/badges/ede3b8bb-8320-4aec-9737-61cf30a7e41e', 1, '2026-05-15 12:04:13'),
(139, 8, 137, '2026-02-02', '2028-02-02', 'active', NULL, '1dd103b7-6269-4db1-8a6b-6e836e85fe8f', NULL, 'Importato da Credly il 2026-05-15 14:04\ncredly_badge_id:1dd103b7-6269-4db1-8a6b-6e836e85fe8f\ncredly_template_id:16abd220-eac0-469f-b15f-300c9d97a343\nbadge_url:https://www.credly.com/badges/1dd103b7-6269-4db1-8a6b-6e836e85fe8f', 1, '2026-05-15 12:04:57');
INSERT INTO `user_certifications` (`id`, `employee_id`, `certification_id`, `issue_date`, `expiry_date`, `status`, `score`, `certificate_code`, `document_path`, `notes`, `uploaded_by`, `created_at`) VALUES
(140, 8, 198, '2026-02-02', NULL, 'active', NULL, 'd216b279-21a0-4871-b3be-6c02c855fc23', NULL, 'Importato da Credly il 2026-05-15 14:04\ncredly_badge_id:d216b279-21a0-4871-b3be-6c02c855fc23\ncredly_template_id:7d917668-4eb6-4459-824f-a9faacac3b12\nbadge_url:https://www.credly.com/badges/d216b279-21a0-4871-b3be-6c02c855fc23', 1, '2026-05-15 12:04:57'),
(141, 8, 141, '2026-01-15', '2028-02-02', 'active', NULL, '9b6a28fb-1358-4757-8f42-e68e0e36d756', NULL, 'Importato da Credly il 2026-05-15 14:04\ncredly_badge_id:9b6a28fb-1358-4757-8f42-e68e0e36d756\ncredly_template_id:f6159ec0-43b2-444b-a1e0-18a323f4833f\nbadge_url:https://www.credly.com/badges/9b6a28fb-1358-4757-8f42-e68e0e36d756', 1, '2026-05-15 12:04:57'),
(142, 8, 199, '2026-01-15', NULL, 'active', NULL, '8b99cfe4-73bd-4c40-8216-30efbc53e44b', NULL, 'Importato da Credly il 2026-05-15 14:04\ncredly_badge_id:8b99cfe4-73bd-4c40-8216-30efbc53e44b\ncredly_template_id:13b8699a-d998-4674-8509-5dcaee16807b\nbadge_url:https://www.credly.com/badges/8b99cfe4-73bd-4c40-8216-30efbc53e44b', 1, '2026-05-15 12:04:57'),
(143, 8, 200, '2026-01-15', NULL, 'active', NULL, 'c55f7931-5b34-436e-b479-57f133566340', NULL, 'Importato da Credly il 2026-05-15 14:04\ncredly_badge_id:c55f7931-5b34-436e-b479-57f133566340\ncredly_template_id:9b3a946f-be9f-457e-b9e9-6f2eec0f60e8\nbadge_url:https://www.credly.com/badges/c55f7931-5b34-436e-b479-57f133566340', 1, '2026-05-15 12:04:57'),
(144, 8, 201, '2026-01-15', NULL, 'active', NULL, 'f3c2e65e-7540-4cf8-aa1d-2d370defbb31', NULL, 'Importato da Credly il 2026-05-15 14:04\ncredly_badge_id:f3c2e65e-7540-4cf8-aa1d-2d370defbb31\ncredly_template_id:a2969d0f-bc02-44fb-8c16-5c3e2fe0180e\nbadge_url:https://www.credly.com/badges/f3c2e65e-7540-4cf8-aa1d-2d370defbb31', 1, '2026-05-15 12:04:57'),
(145, 16, 202, '2024-12-04', NULL, 'active', NULL, '99eafb84-565e-4841-9561-f0a3300df57f', NULL, 'Importato da Credly il 2026-05-15 14:06\ncredly_badge_id:99eafb84-565e-4841-9561-f0a3300df57f\ncredly_template_id:1bf2a935-1b83-4877-8069-e7d9c44012c9\nbadge_url:https://www.credly.com/badges/99eafb84-565e-4841-9561-f0a3300df57f', 1, '2026-05-15 12:06:27'),
(146, 16, 203, '2024-12-04', NULL, 'active', NULL, '1346fc73-1b4c-45ad-9fa7-fccd463ecff0', NULL, 'Importato da Credly il 2026-05-15 14:06\ncredly_badge_id:1346fc73-1b4c-45ad-9fa7-fccd463ecff0\ncredly_template_id:e15a9c04-779d-4c69-81ab-aa8e8155a940\nbadge_url:https://www.credly.com/badges/1346fc73-1b4c-45ad-9fa7-fccd463ecff0', 1, '2026-05-15 12:06:27'),
(147, 16, 204, '2023-10-17', NULL, 'active', NULL, '7190222b-f61a-46cc-918d-39c00682ea5d', NULL, 'Importato da Credly il 2026-05-15 14:06\ncredly_badge_id:7190222b-f61a-46cc-918d-39c00682ea5d\ncredly_template_id:ae392266-61c9-4fc4-9373-bcb8506f40ac\nbadge_url:https://www.credly.com/badges/7190222b-f61a-46cc-918d-39c00682ea5d', 1, '2026-05-15 12:06:27'),
(148, 16, 205, '2024-12-04', NULL, 'active', NULL, 'ca4ae914-dffa-42bf-ad21-2fc3b27e86bf', NULL, 'Importato da Credly il 2026-05-15 14:06\ncredly_badge_id:ca4ae914-dffa-42bf-ad21-2fc3b27e86bf\ncredly_template_id:b06d5c75-e8a6-4572-8047-d487ba5aa240\nbadge_url:https://www.credly.com/badges/ca4ae914-dffa-42bf-ad21-2fc3b27e86bf', 1, '2026-05-15 12:06:27'),
(149, 16, 206, '2024-12-04', '2026-12-04', 'active', NULL, '7e22c8c0-90c6-403a-96f3-dc6adeada4ae', NULL, 'Importato da Credly il 2026-05-15 14:06\ncredly_badge_id:7e22c8c0-90c6-403a-96f3-dc6adeada4ae\ncredly_template_id:b867d500-dff9-42b8-8852-24d9a5a9993e\nbadge_url:https://www.credly.com/badges/7e22c8c0-90c6-403a-96f3-dc6adeada4ae', 1, '2026-05-15 12:06:27'),
(150, 16, 207, '2024-11-29', '2026-11-29', 'active', NULL, 'fecc8b97-65c6-4376-8d39-a6b80f7af235', NULL, 'Importato da Credly il 2026-05-15 14:06\ncredly_badge_id:fecc8b97-65c6-4376-8d39-a6b80f7af235\ncredly_template_id:afb010a7-dd6f-4618-858d-49ea08eb776b\nbadge_url:https://www.credly.com/badges/fecc8b97-65c6-4376-8d39-a6b80f7af235', 1, '2026-05-15 12:06:27'),
(151, 16, 208, '2024-11-27', '2026-11-27', 'active', NULL, 'd601c93d-a8ee-460b-9678-f9a8ffa0699c', NULL, 'Importato da Credly il 2026-05-15 14:06\ncredly_badge_id:d601c93d-a8ee-460b-9678-f9a8ffa0699c\ncredly_template_id:a40e7ad7-f9dc-48f4-9ecc-0550c80e6ec5\nbadge_url:https://www.credly.com/badges/d601c93d-a8ee-460b-9678-f9a8ffa0699c', 1, '2026-05-15 12:06:27'),
(152, 15, 140, '2023-03-23', NULL, 'active', NULL, '7c01cac3-9295-4492-a5f7-d83c72397f80', NULL, 'Importato da Credly il 2026-05-15 14:07\ncredly_badge_id:7c01cac3-9295-4492-a5f7-d83c72397f80\ncredly_template_id:a78ce6a7-521d-4e2f-9408-386d3701bd6e\nbadge_url:https://www.credly.com/badges/7c01cac3-9295-4492-a5f7-d83c72397f80', 1, '2026-05-15 12:07:34'),
(153, 81, 209, '2026-04-16', '2028-04-16', 'active', NULL, '538612ae-e04e-473c-9baf-3908ad77998b', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:538612ae-e04e-473c-9baf-3908ad77998b\ncredly_template_id:0a37cc61-7358-4038-b626-9e4f2bf1f404\nbadge_url:https://www.credly.com/badges/538612ae-e04e-473c-9baf-3908ad77998b', 1, '2026-05-15 12:08:27'),
(154, 81, 210, '2025-05-06', NULL, 'active', NULL, 'e821421e-e3e4-414a-a72d-8a8d1ed748b1', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:e821421e-e3e4-414a-a72d-8a8d1ed748b1\ncredly_template_id:edeed863-3565-4dc9-a7af-cbf96337e074\nbadge_url:https://www.credly.com/badges/e821421e-e3e4-414a-a72d-8a8d1ed748b1', 1, '2026-05-15 12:08:27'),
(155, 81, 211, '2024-06-06', NULL, 'active', NULL, 'e53dc7d3-da99-4074-abfa-5383eb6008bd', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:e53dc7d3-da99-4074-abfa-5383eb6008bd\ncredly_template_id:7894deba-9fb4-4ef6-abbc-41a1d77e0ba9\nbadge_url:https://www.credly.com/badges/e53dc7d3-da99-4074-abfa-5383eb6008bd', 1, '2026-05-15 12:08:27'),
(156, 81, 210, '2024-04-03', NULL, 'active', NULL, '48052438-b34d-4691-84d2-408ffdb4b061', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:48052438-b34d-4691-84d2-408ffdb4b061\ncredly_template_id:edeed863-3565-4dc9-a7af-cbf96337e074\nbadge_url:https://www.credly.com/badges/48052438-b34d-4691-84d2-408ffdb4b061', 1, '2026-05-15 12:08:27'),
(157, 81, 212, '2023-11-10', NULL, 'active', NULL, 'c806d91e-0cf5-4002-8a6c-bdf93bb62f78', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:c806d91e-0cf5-4002-8a6c-bdf93bb62f78\ncredly_template_id:88833881-a296-44b5-a149-77ad24d6a5f3\nbadge_url:https://www.credly.com/badges/c806d91e-0cf5-4002-8a6c-bdf93bb62f78', 1, '2026-05-15 12:08:27'),
(158, 81, 213, '2023-11-10', NULL, 'active', NULL, '4988da59-ff0a-403c-b100-04607ce8244f', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:4988da59-ff0a-403c-b100-04607ce8244f\ncredly_template_id:6b4a4114-b06c-4a5f-80ae-af05282cb2db\nbadge_url:https://www.credly.com/badges/4988da59-ff0a-403c-b100-04607ce8244f', 1, '2026-05-15 12:08:27'),
(159, 81, 214, '2023-11-10', NULL, 'active', NULL, 'b3789df3-5a2f-40a4-8b8b-a5d524f5910f', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:b3789df3-5a2f-40a4-8b8b-a5d524f5910f\ncredly_template_id:b7567001-fc13-4732-8118-9a720b80a261\nbadge_url:https://www.credly.com/badges/b3789df3-5a2f-40a4-8b8b-a5d524f5910f', 1, '2026-05-15 12:08:27'),
(160, 81, 215, '2022-11-09', NULL, 'active', NULL, '724a9750-c89d-4994-8c9f-ff0cd5aca2ca', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:724a9750-c89d-4994-8c9f-ff0cd5aca2ca\ncredly_template_id:361e4d2b-6f09-4841-a4f9-e435d9203558\nbadge_url:https://www.credly.com/badges/724a9750-c89d-4994-8c9f-ff0cd5aca2ca', 1, '2026-05-15 12:08:27'),
(161, 81, 216, '2022-02-15', NULL, 'active', NULL, 'ca2cc0ec-c37b-49cf-9889-1535a03341ce', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:ca2cc0ec-c37b-49cf-9889-1535a03341ce\ncredly_template_id:d54afa85-4ce3-4af2-8cdd-8020670eb210\nbadge_url:https://www.credly.com/badges/ca2cc0ec-c37b-49cf-9889-1535a03341ce', 1, '2026-05-15 12:08:27'),
(162, 81, 217, '2022-02-01', NULL, 'active', NULL, '915b2640-b5e3-4e71-bf59-fa0028b8c6c2', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:915b2640-b5e3-4e71-bf59-fa0028b8c6c2\ncredly_template_id:90582ecb-48a6-4e41-bada-acfcf9389252\nbadge_url:https://www.credly.com/badges/915b2640-b5e3-4e71-bf59-fa0028b8c6c2', 1, '2026-05-15 12:08:27'),
(163, 81, 218, '2021-08-02', NULL, 'active', NULL, 'f4d56752-49f6-4d4c-b2d6-5471dffe510f', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:f4d56752-49f6-4d4c-b2d6-5471dffe510f\ncredly_template_id:f27178d2-347e-43a7-9905-8a0b3bd9f530\nbadge_url:https://www.credly.com/badges/f4d56752-49f6-4d4c-b2d6-5471dffe510f', 1, '2026-05-15 12:08:27'),
(164, 81, 219, '2020-07-30', NULL, 'active', NULL, '8e021ec2-3f07-4205-9f73-ca2b663c45f4', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:8e021ec2-3f07-4205-9f73-ca2b663c45f4\ncredly_template_id:2577c4d9-0a20-4909-8a23-4f1df4922f1d\nbadge_url:https://www.credly.com/badges/8e021ec2-3f07-4205-9f73-ca2b663c45f4', 1, '2026-05-15 12:08:27'),
(165, 81, 220, '2020-07-30', NULL, 'active', NULL, '8614b5ad-ec37-46eb-bc3d-c6042ae7a81d', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:8614b5ad-ec37-46eb-bc3d-c6042ae7a81d\ncredly_template_id:e3e3f899-eefc-40ef-adc3-978016253c76\nbadge_url:https://www.credly.com/badges/8614b5ad-ec37-46eb-bc3d-c6042ae7a81d', 1, '2026-05-15 12:08:27'),
(166, 81, 221, '2020-07-16', NULL, 'active', NULL, 'c21812ad-833b-48a8-8919-cd24f764f44a', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:c21812ad-833b-48a8-8919-cd24f764f44a\ncredly_template_id:7b3eee82-79fd-41f6-9652-f124aaf50a16\nbadge_url:https://www.credly.com/badges/c21812ad-833b-48a8-8919-cd24f764f44a', 1, '2026-05-15 12:08:27'),
(167, 81, 222, '2019-10-28', NULL, 'active', NULL, '03aca2ca-82ef-4b95-af4b-bb89939c6ad1', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:03aca2ca-82ef-4b95-af4b-bb89939c6ad1\ncredly_template_id:c0fcae97-cbe5-4bfd-8fdb-1558596b33fd\nbadge_url:https://www.credly.com/badges/03aca2ca-82ef-4b95-af4b-bb89939c6ad1', 1, '2026-05-15 12:08:27'),
(168, 81, 223, '2018-12-21', NULL, 'active', NULL, 'd0036c6f-f025-4e00-ac59-0b0bd2b999bf', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:d0036c6f-f025-4e00-ac59-0b0bd2b999bf\ncredly_template_id:45151853-e04b-42f2-9d31-4af639ec3139\nbadge_url:https://www.credly.com/badges/d0036c6f-f025-4e00-ac59-0b0bd2b999bf', 1, '2026-05-15 12:08:28'),
(169, 81, 224, '2018-12-10', NULL, 'active', NULL, '4e12c890-a485-4125-a67d-334a7be311c9', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:4e12c890-a485-4125-a67d-334a7be311c9\ncredly_template_id:4f40c9f4-84bf-470d-9b2f-4d50e4d57083\nbadge_url:https://www.credly.com/badges/4e12c890-a485-4125-a67d-334a7be311c9', 1, '2026-05-15 12:08:28'),
(170, 81, 225, '2018-12-10', NULL, 'active', NULL, '1b0532df-8cdc-43ef-926e-4fd2f46bc667', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:1b0532df-8cdc-43ef-926e-4fd2f46bc667\ncredly_template_id:2f64b362-9528-46f2-9c6b-ae5af7b37afb\nbadge_url:https://www.credly.com/badges/1b0532df-8cdc-43ef-926e-4fd2f46bc667', 1, '2026-05-15 12:08:28'),
(171, 81, 226, '2024-03-27', '2026-03-27', 'expired', NULL, '839e6b0c-8681-44d2-b21f-eeff0691e9f6', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:839e6b0c-8681-44d2-b21f-eeff0691e9f6\ncredly_template_id:e1343edd-ce8e-464c-9ede-457eea76081f\nbadge_url:https://www.credly.com/badges/839e6b0c-8681-44d2-b21f-eeff0691e9f6', 1, '2026-05-15 12:08:28'),
(172, 81, 227, '2022-07-27', '2026-03-27', 'expired', NULL, 'ed8fad57-6406-42f8-99c0-56ac724a9ae6', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:ed8fad57-6406-42f8-99c0-56ac724a9ae6\ncredly_template_id:6d9bd115-1a77-4f53-8e37-91d158c342e9\nbadge_url:https://www.credly.com/badges/ed8fad57-6406-42f8-99c0-56ac724a9ae6', 1, '2026-05-15 12:08:28'),
(173, 81, 228, '2023-11-17', '2025-11-17', 'expired', NULL, '65866229-2646-4cf3-bd26-362782465ae6', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:65866229-2646-4cf3-bd26-362782465ae6\ncredly_template_id:fbe78d66-32f2-47c7-93ea-5cad5304a6b6\nbadge_url:https://www.credly.com/badges/65866229-2646-4cf3-bd26-362782465ae6', 1, '2026-05-15 12:08:28'),
(174, 81, 229, '2017-11-15', '2023-03-10', 'expired', NULL, '8ddaf0b2-d92c-427d-ac8d-a2fb35cb903f', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:8ddaf0b2-d92c-427d-ac8d-a2fb35cb903f\ncredly_template_id:0cf7144f-ee4a-4336-94f2-17953aa52fba\nbadge_url:https://www.credly.com/badges/8ddaf0b2-d92c-427d-ac8d-a2fb35cb903f', 1, '2026-05-15 12:08:28'),
(175, 81, 230, '2021-03-09', '2023-03-09', 'expired', NULL, '307f1932-f5a9-4603-bda1-f6670721f9b8', NULL, 'Importato da Credly il 2026-05-15 14:08\ncredly_badge_id:307f1932-f5a9-4603-bda1-f6670721f9b8\ncredly_template_id:5de0ccf2-88da-488c-b26f-4e47429d3c18\nbadge_url:https://www.credly.com/badges/307f1932-f5a9-4603-bda1-f6670721f9b8', 1, '2026-05-15 12:08:28'),
(176, 19, 231, '2026-05-01', NULL, 'active', NULL, '7e884cb8-0b7f-413b-9c5b-1b819e9e534f', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:7e884cb8-0b7f-413b-9c5b-1b819e9e534f\ncredly_template_id:227dafde-5f07-4968-ae2f-75e8246f85b3\nbadge_url:https://www.credly.com/badges/7e884cb8-0b7f-413b-9c5b-1b819e9e534f', 1, '2026-05-15 12:12:44'),
(177, 19, 137, '2025-10-17', '2027-10-17', 'active', NULL, 'ca7aa578-b3d5-42fb-856a-144dac1aa796', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:ca7aa578-b3d5-42fb-856a-144dac1aa796\ncredly_template_id:16abd220-eac0-469f-b15f-300c9d97a343\nbadge_url:https://www.credly.com/badges/ca7aa578-b3d5-42fb-856a-144dac1aa796', 1, '2026-05-15 12:12:44'),
(178, 19, 198, '2025-10-17', NULL, 'active', NULL, 'b68f8bb2-d715-49a0-80c6-99ff95d30850', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:b68f8bb2-d715-49a0-80c6-99ff95d30850\ncredly_template_id:7d917668-4eb6-4459-824f-a9faacac3b12\nbadge_url:https://www.credly.com/badges/b68f8bb2-d715-49a0-80c6-99ff95d30850', 1, '2026-05-15 12:12:44'),
(179, 19, 141, '2025-10-09', '2027-10-17', 'active', NULL, '33cdb0c1-3d9e-4149-a37f-622dcebd9799', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:33cdb0c1-3d9e-4149-a37f-622dcebd9799\ncredly_template_id:f6159ec0-43b2-444b-a1e0-18a323f4833f\nbadge_url:https://www.credly.com/badges/33cdb0c1-3d9e-4149-a37f-622dcebd9799', 1, '2026-05-15 12:12:44'),
(180, 19, 199, '2025-10-09', NULL, 'active', NULL, '11fd6988-0a66-4c56-9a75-4b2910eb1172', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:11fd6988-0a66-4c56-9a75-4b2910eb1172\ncredly_template_id:13b8699a-d998-4674-8509-5dcaee16807b\nbadge_url:https://www.credly.com/badges/11fd6988-0a66-4c56-9a75-4b2910eb1172', 1, '2026-05-15 12:12:44'),
(181, 19, 200, '2025-10-06', NULL, 'active', NULL, '28853b4e-4ab4-4645-9e98-234a4f15cb79', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:28853b4e-4ab4-4645-9e98-234a4f15cb79\ncredly_template_id:9b3a946f-be9f-457e-b9e9-6f2eec0f60e8\nbadge_url:https://www.credly.com/badges/28853b4e-4ab4-4645-9e98-234a4f15cb79', 1, '2026-05-15 12:12:44'),
(182, 19, 232, '2025-06-06', '2028-06-06', 'active', NULL, 'cc590e4c-2bbb-40a7-8f19-6527f01f1975', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:cc590e4c-2bbb-40a7-8f19-6527f01f1975\ncredly_template_id:6069fb52-0c27-42d7-852b-6aa86ea45e81\nbadge_url:https://www.credly.com/badges/cc590e4c-2bbb-40a7-8f19-6527f01f1975', 1, '2026-05-15 12:12:44'),
(183, 19, 233, '2024-09-30', NULL, 'active', NULL, 'cc3b9fc7-3f9d-4ead-8724-9cf22219c9b3', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:cc3b9fc7-3f9d-4ead-8724-9cf22219c9b3\ncredly_template_id:17f867df-3e05-42c9-8f4d-0e8a920b27d1\nbadge_url:https://www.credly.com/badges/cc3b9fc7-3f9d-4ead-8724-9cf22219c9b3', 1, '2026-05-15 12:12:44'),
(184, 19, 234, '2024-11-14', '2025-11-14', 'expired', NULL, '30f6a989-e8a2-41bf-ad00-6c62071aebb3', NULL, 'Importato da Credly il 2026-05-15 14:12\ncredly_badge_id:30f6a989-e8a2-41bf-ad00-6c62071aebb3\ncredly_template_id:13f8f7d6-1aa4-4584-8d65-eb1d3a6a85de\nbadge_url:https://www.credly.com/badges/30f6a989-e8a2-41bf-ad00-6c62071aebb3', 1, '2026-05-15 12:12:44'),
(185, 22, 235, '2026-01-17', NULL, 'active', NULL, '34585f51-af71-45a3-8efc-dfa2d7f15e2c', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:34585f51-af71-45a3-8efc-dfa2d7f15e2c\ncredly_template_id:adefb0c2-1909-4acb-9433-c601be85b25e\nbadge_url:https://www.credly.com/badges/34585f51-af71-45a3-8efc-dfa2d7f15e2c', 1, '2026-05-15 12:19:23'),
(186, 17, 236, '2022-11-15', NULL, 'active', NULL, 'e1b895c7-e1e2-42d9-aade-49c507d570d3', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:e1b895c7-e1e2-42d9-aade-49c507d570d3\ncredly_template_id:eb178496-4bcf-45d5-8a35-858a1da0f081\nbadge_url:https://www.credly.com/badges/e1b895c7-e1e2-42d9-aade-49c507d570d3', 1, '2026-05-15 12:19:26'),
(187, 17, 237, '2022-07-27', NULL, 'active', NULL, 'eeb85379-3735-43dc-bd91-300c2e472ec7', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:eeb85379-3735-43dc-bd91-300c2e472ec7\ncredly_template_id:778db280-4976-41e4-9eaa-f8834a0a8d46\nbadge_url:https://www.credly.com/badges/eeb85379-3735-43dc-bd91-300c2e472ec7', 1, '2026-05-15 12:19:26'),
(188, 17, 238, '2022-04-27', NULL, 'active', NULL, '52beb9f6-1476-419d-81e2-9c1ae81a93e4', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:52beb9f6-1476-419d-81e2-9c1ae81a93e4\ncredly_template_id:e0997fd0-d532-4ba0-a8a7-ee6cc6c7b8e9\nbadge_url:https://www.credly.com/badges/52beb9f6-1476-419d-81e2-9c1ae81a93e4', 1, '2026-05-15 12:19:26'),
(189, 24, 239, '2017-07-04', '2019-07-03', 'expired', NULL, '72448acc-69b8-4d6e-b92d-3bde5e01b9d6', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:72448acc-69b8-4d6e-b92d-3bde5e01b9d6\ncredly_template_id:3e296525-6133-41b7-b546-f734ddaa3d26\nbadge_url:https://www.credly.com/badges/72448acc-69b8-4d6e-b92d-3bde5e01b9d6', 1, '2026-05-15 12:19:29'),
(190, 31, 240, '2022-09-20', NULL, 'active', NULL, '85de53a2-cf99-4db8-aa71-38fed5bca138', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:85de53a2-cf99-4db8-aa71-38fed5bca138\ncredly_template_id:ae21d663-f8a1-4b19-b633-d4a19c3f91f9\nbadge_url:https://www.credly.com/badges/85de53a2-cf99-4db8-aa71-38fed5bca138', 1, '2026-05-15 12:19:31'),
(191, 31, 241, '2021-04-26', NULL, 'active', NULL, '813300cf-318e-4599-8092-b2a3beccca03', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:813300cf-318e-4599-8092-b2a3beccca03\ncredly_template_id:0897f225-a159-4372-9024-d9796fdd1da1\nbadge_url:https://www.credly.com/badges/813300cf-318e-4599-8092-b2a3beccca03', 1, '2026-05-15 12:19:31'),
(192, 31, 242, '2020-05-26', NULL, 'active', NULL, '21698aa5-cfac-40f3-8375-1a6fbf680a43', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:21698aa5-cfac-40f3-8375-1a6fbf680a43\ncredly_template_id:0d26071d-038c-4435-a70a-38f081e727b7\nbadge_url:https://www.credly.com/badges/21698aa5-cfac-40f3-8375-1a6fbf680a43', 1, '2026-05-15 12:19:31'),
(193, 31, 243, '2019-05-31', NULL, 'active', NULL, '14905e48-6a50-49d0-b2b4-9ba0354646dc', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:14905e48-6a50-49d0-b2b4-9ba0354646dc\ncredly_template_id:fdfcf5b2-0883-470e-a70f-5b7643e1f213\nbadge_url:https://www.credly.com/badges/14905e48-6a50-49d0-b2b4-9ba0354646dc', 1, '2026-05-15 12:19:31'),
(194, 69, 244, '2026-04-14', '2029-04-14', 'active', NULL, '700bcc8e-4613-4009-a4cc-af278ae878bf', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:700bcc8e-4613-4009-a4cc-af278ae878bf\ncredly_template_id:b7b7ba84-9e5e-4e9a-90b6-f0210bfee533\nbadge_url:https://www.credly.com/badges/700bcc8e-4613-4009-a4cc-af278ae878bf', 1, '2026-05-15 12:19:39'),
(195, 69, 245, '2026-02-06', '2027-12-02', 'active', NULL, 'b7e1aed1-67a3-4520-9f2a-05f06ed98da1', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:b7e1aed1-67a3-4520-9f2a-05f06ed98da1\ncredly_template_id:816460a4-0469-4983-9e3b-71e63a73bdbe\nbadge_url:https://www.credly.com/badges/b7e1aed1-67a3-4520-9f2a-05f06ed98da1', 1, '2026-05-15 12:19:39'),
(196, 69, 246, '2024-12-02', '2027-12-02', 'active', NULL, 'cfa82224-2b42-4983-9885-8bb16f2e12b5', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:cfa82224-2b42-4983-9885-8bb16f2e12b5\ncredly_template_id:f3ec489b-6ab3-48d9-b0ea-4c2a7dfbe997\nbadge_url:https://www.credly.com/badges/cfa82224-2b42-4983-9885-8bb16f2e12b5', 1, '2026-05-15 12:19:39'),
(197, 69, 247, '2024-12-02', '2029-02-06', 'active', NULL, 'ec4f63a6-d476-48ed-8b69-7a6230702add', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:ec4f63a6-d476-48ed-8b69-7a6230702add\ncredly_template_id:7b732551-76c2-499c-889a-472bc3327309\nbadge_url:https://www.credly.com/badges/ec4f63a6-d476-48ed-8b69-7a6230702add', 1, '2026-05-15 12:19:39'),
(198, 69, 248, '2024-12-02', '2027-12-02', 'active', NULL, '4a5d5ea8-767f-4097-8bf8-ae2bc9eb1c19', NULL, 'Importato da Credly il 2026-05-15 14:19\ncredly_badge_id:4a5d5ea8-767f-4097-8bf8-ae2bc9eb1c19\ncredly_template_id:942cbe0a-7c6f-4a3b-8871-ff883f1f0dda\nbadge_url:https://www.credly.com/badges/4a5d5ea8-767f-4097-8bf8-ae2bc9eb1c19', 1, '2026-05-15 12:19:39'),
(199, 25, 249, '2022-09-13', NULL, 'active', NULL, 'aa2660a3-378f-4b13-b2d1-49f4130b6cc6', NULL, 'Importato da Credly il 2026-05-15 14:20\ncredly_badge_id:aa2660a3-378f-4b13-b2d1-49f4130b6cc6\ncredly_template_id:25b0c8e3-23e9-4689-858b-8013db7b9347\nbadge_url:https://www.credly.com/badges/aa2660a3-378f-4b13-b2d1-49f4130b6cc6', 1, '2026-05-15 12:20:25'),
(200, 79, 250, '2021-11-19', NULL, 'active', NULL, '3f6ee26e-a618-4bbb-abee-4b51ef6c554e', NULL, 'Importato da Credly il 2026-05-15 14:25\ncredly_badge_id:3f6ee26e-a618-4bbb-abee-4b51ef6c554e\ncredly_template_id:cc2d0b5f-df9b-4396-b075-33526668a304\nbadge_url:https://www.credly.com/badges/3f6ee26e-a618-4bbb-abee-4b51ef6c554e', 1, '2026-05-15 12:25:39'),
(201, 74, 251, '2025-04-03', NULL, 'active', NULL, '000ef2bf-9439-4f0d-93bd-d6be65b7e632', NULL, 'Importato da Credly il 2026-05-15 14:25\ncredly_badge_id:000ef2bf-9439-4f0d-93bd-d6be65b7e632\ncredly_template_id:39708bbd-f5cc-4062-8023-74fc2d97ada4\nbadge_url:https://www.credly.com/badges/000ef2bf-9439-4f0d-93bd-d6be65b7e632', 1, '2026-05-15 12:25:47'),
(202, 74, 252, '2025-03-14', NULL, 'active', NULL, '9c93c834-3179-48ae-8f56-3c2ad136f3a6', NULL, 'Importato da Credly il 2026-05-15 14:25\ncredly_badge_id:9c93c834-3179-48ae-8f56-3c2ad136f3a6\ncredly_template_id:10b1a2de-f36b-4730-b1ca-8505e19f4390\nbadge_url:https://www.credly.com/badges/9c93c834-3179-48ae-8f56-3c2ad136f3a6', 1, '2026-05-15 12:25:47'),
(203, 74, 253, '2025-03-12', NULL, 'active', NULL, '4f228d29-ed7a-4aac-8248-14bb5a483bdb', NULL, 'Importato da Credly il 2026-05-15 14:25\ncredly_badge_id:4f228d29-ed7a-4aac-8248-14bb5a483bdb\ncredly_template_id:c3631bef-53d0-450e-aa8b-95e9ad624af4\nbadge_url:https://www.credly.com/badges/4f228d29-ed7a-4aac-8248-14bb5a483bdb', 1, '2026-05-15 12:25:47'),
(204, 33, 142, '2023-06-23', NULL, 'active', NULL, 'ddd5ecf2-50c9-4ffd-9b57-068d79855ae5', NULL, 'Importato da Credly il 2026-05-15 14:36\ncredly_badge_id:ddd5ecf2-50c9-4ffd-9b57-068d79855ae5\ncredly_template_id:5bdbc502-ac97-451f-8b91-75bff4049cb9\nbadge_url:https://www.credly.com/badges/ddd5ecf2-50c9-4ffd-9b57-068d79855ae5', 1, '2026-05-15 12:36:48'),
(205, 33, 143, '2023-06-14', NULL, 'active', NULL, 'dabe6f21-b21f-4f84-a6bd-9b4425a29155', NULL, 'Importato da Credly il 2026-05-15 14:36\ncredly_badge_id:dabe6f21-b21f-4f84-a6bd-9b4425a29155\ncredly_template_id:227d4f08-6330-4d6b-8782-6bfb11159d51\nbadge_url:https://www.credly.com/badges/dabe6f21-b21f-4f84-a6bd-9b4425a29155', 1, '2026-05-15 12:36:48'),
(206, 33, 141, '2023-06-23', '2025-06-23', 'expired', NULL, '7dfcd35d-9de5-49c7-a9ca-6bb7ed05cc90', NULL, 'Importato da Credly il 2026-05-15 14:36\ncredly_badge_id:7dfcd35d-9de5-49c7-a9ca-6bb7ed05cc90\ncredly_template_id:f6159ec0-43b2-444b-a1e0-18a323f4833f\nbadge_url:https://www.credly.com/badges/7dfcd35d-9de5-49c7-a9ca-6bb7ed05cc90', 1, '2026-05-15 12:36:48'),
(207, 50, 254, '2018-05-31', NULL, 'active', NULL, 'b1e3d291-0b72-4b6a-9d62-87197595900e', NULL, 'Importato da Credly il 2026-05-15 14:38\ncredly_badge_id:b1e3d291-0b72-4b6a-9d62-87197595900e\ncredly_template_id:f5d8419e-f846-4ace-a1cd-6bed2fb6c88c\nbadge_url:https://www.credly.com/badges/b1e3d291-0b72-4b6a-9d62-87197595900e', 1, '2026-05-15 12:38:23'),
(208, 50, 255, '2017-02-02', NULL, 'active', NULL, '0edd1d20-98a8-449c-8565-7bd666ce24ba', NULL, 'Importato da Credly il 2026-05-15 14:38\ncredly_badge_id:0edd1d20-98a8-449c-8565-7bd666ce24ba\ncredly_template_id:ee8c840a-1f8d-45e9-96ef-243018794258\nbadge_url:https://www.credly.com/badges/0edd1d20-98a8-449c-8565-7bd666ce24ba', 1, '2026-05-15 12:38:23'),
(209, 29, 256, '2024-10-21', NULL, 'active', NULL, '0c0fc75c-c78d-4033-8b7d-40561f90b0b0', NULL, 'Importato da Credly il 2026-05-15 14:41\ncredly_badge_id:0c0fc75c-c78d-4033-8b7d-40561f90b0b0\ncredly_template_id:16a73b3d-bf87-48a3-bcca-54dbb07d348e\nbadge_url:https://www.credly.com/badges/0c0fc75c-c78d-4033-8b7d-40561f90b0b0', 1, '2026-05-15 12:41:38'),
(210, 85, 137, '2025-07-05', '2027-07-05', 'active', NULL, '8f9ffc59-42a0-46a6-9bac-80b07793a56c', NULL, 'Importato da Credly il 2026-05-15 14:41\ncredly_badge_id:8f9ffc59-42a0-46a6-9bac-80b07793a56c\ncredly_template_id:16abd220-eac0-469f-b15f-300c9d97a343\nbadge_url:https://www.credly.com/badges/8f9ffc59-42a0-46a6-9bac-80b07793a56c', 1, '2026-05-15 12:41:43'),
(211, 85, 198, '2025-07-05', NULL, 'active', NULL, 'd7d2a9fc-f69e-4911-9546-6129fee253fb', NULL, 'Importato da Credly il 2026-05-15 14:41\ncredly_badge_id:d7d2a9fc-f69e-4911-9546-6129fee253fb\ncredly_template_id:7d917668-4eb6-4459-824f-a9faacac3b12\nbadge_url:https://www.credly.com/badges/d7d2a9fc-f69e-4911-9546-6129fee253fb', 1, '2026-05-15 12:41:43'),
(212, 85, 141, '2024-02-01', '2027-07-05', 'active', NULL, '2f0901d3-303d-420c-b199-b0093a4f3cb8', NULL, 'Importato da Credly il 2026-05-15 14:41\ncredly_badge_id:2f0901d3-303d-420c-b199-b0093a4f3cb8\ncredly_template_id:f6159ec0-43b2-444b-a1e0-18a323f4833f\nbadge_url:https://www.credly.com/badges/2f0901d3-303d-420c-b199-b0093a4f3cb8', 1, '2026-05-15 12:41:43'),
(213, 85, 257, '2024-02-01', NULL, 'active', NULL, 'a4ff3d91-90c3-4191-982b-0509a5d4229a', NULL, 'Importato da Credly il 2026-05-15 14:41\ncredly_badge_id:a4ff3d91-90c3-4191-982b-0509a5d4229a\ncredly_template_id:a9bf1cf9-1a8b-4e12-bbd5-65f0e76df4d1\nbadge_url:https://www.credly.com/badges/a4ff3d91-90c3-4191-982b-0509a5d4229a', 1, '2026-05-15 12:41:43'),
(214, 85, 258, '2024-01-29', NULL, 'active', NULL, '6bba5345-f6b7-44b5-be52-63dbdd4edeeb', NULL, 'Importato da Credly il 2026-05-15 14:41\ncredly_badge_id:6bba5345-f6b7-44b5-be52-63dbdd4edeeb\ncredly_template_id:fe3f3966-94f6-47ac-97a7-e07adb7c4673\nbadge_url:https://www.credly.com/badges/6bba5345-f6b7-44b5-be52-63dbdd4edeeb', 1, '2026-05-15 12:41:43'),
(215, 88, 259, '2019-10-15', '2039-10-15', 'active', NULL, '3ca73f8b-b140-4e21-b45d-494bc0a4f152', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:3ca73f8b-b140-4e21-b45d-494bc0a4f152\ncredly_template_id:a8c38833-5337-4054-a7e6-5cb96a5b72b4\nbadge_url:https://www.credly.com/badges/3ca73f8b-b140-4e21-b45d-494bc0a4f152', 1, '2026-05-15 12:49:44'),
(216, 88, 260, '2019-06-12', '2039-06-12', 'active', NULL, '0a76c257-9a7d-4cb0-90ef-9bc78944ab15', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:0a76c257-9a7d-4cb0-90ef-9bc78944ab15\ncredly_template_id:52348cb1-f040-4f19-8173-0ac0c20aa7e8\nbadge_url:https://www.credly.com/badges/0a76c257-9a7d-4cb0-90ef-9bc78944ab15', 1, '2026-05-15 12:49:44'),
(217, 88, 261, '2018-05-08', NULL, 'active', NULL, 'caf0ad1e-1fc8-47d0-a010-148cbe533b8d', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:caf0ad1e-1fc8-47d0-a010-148cbe533b8d\ncredly_template_id:d2d051fb-5e50-44d9-9b81-a1eb592fdf5b\nbadge_url:https://www.credly.com/badges/caf0ad1e-1fc8-47d0-a010-148cbe533b8d', 1, '2026-05-15 12:49:44'),
(218, 88, 259, '2019-10-15', '2022-10-15', 'expired', NULL, '2f47d7b4-53ca-4652-a992-aac5c08bcff5', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:2f47d7b4-53ca-4652-a992-aac5c08bcff5\ncredly_template_id:a8c38833-5337-4054-a7e6-5cb96a5b72b4\nbadge_url:https://www.credly.com/badges/2f47d7b4-53ca-4652-a992-aac5c08bcff5', 1, '2026-05-15 12:49:44'),
(219, 88, 260, '2019-06-12', '2022-06-12', 'expired', NULL, 'e8d8e253-2040-4326-8fcd-202882000e1f', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:e8d8e253-2040-4326-8fcd-202882000e1f\ncredly_template_id:52348cb1-f040-4f19-8173-0ac0c20aa7e8\nbadge_url:https://www.credly.com/badges/e8d8e253-2040-4326-8fcd-202882000e1f', 1, '2026-05-15 12:49:44'),
(220, 49, 170, '2009-07-23', '2018-07-10', 'expired', NULL, '96fdbc81-f29d-4e2d-8045-07d84fb8ef89', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:96fdbc81-f29d-4e2d-8045-07d84fb8ef89\ncredly_template_id:1e75a61c-8885-4f68-8ffe-2e19b77b527a\nbadge_url:https://www.credly.com/badges/96fdbc81-f29d-4e2d-8045-07d84fb8ef89', 1, '2026-05-15 12:49:47'),
(221, 49, 169, '2007-11-16', '2018-07-10', 'expired', NULL, 'b80e8e3a-4289-4e29-86d5-51f775a7a0b0', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:b80e8e3a-4289-4e29-86d5-51f775a7a0b0\ncredly_template_id:f13fa86c-a3d8-43fa-ab18-f6be9925699b\nbadge_url:https://www.credly.com/badges/b80e8e3a-4289-4e29-86d5-51f775a7a0b0', 1, '2026-05-15 12:49:47'),
(222, 92, 262, '2026-04-19', '2027-04-19', 'active', NULL, 'd4f8e039-03a8-426a-8e18-882c80ec9eba', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:d4f8e039-03a8-426a-8e18-882c80ec9eba\ncredly_template_id:da7432e1-bd5d-45aa-90da-d3e60c5530a2\nbadge_url:https://www.credly.com/badges/d4f8e039-03a8-426a-8e18-882c80ec9eba', 1, '2026-05-15 12:49:54'),
(223, 92, 263, '2026-04-07', NULL, 'active', NULL, '47cdc7e1-ab43-4c29-8399-1480136437a7', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:47cdc7e1-ab43-4c29-8399-1480136437a7\ncredly_template_id:ee08e4af-3017-4dd1-975e-b1ee1124467d\nbadge_url:https://www.credly.com/badges/47cdc7e1-ab43-4c29-8399-1480136437a7', 1, '2026-05-15 12:49:54'),
(224, 92, 264, '2026-03-31', '2027-03-31', 'active', NULL, 'b986cd75-2b0d-4295-a573-bcf75e44324a', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:b986cd75-2b0d-4295-a573-bcf75e44324a\ncredly_template_id:3f3e3497-c230-4609-96c5-075ccf03b6c1\nbadge_url:https://www.credly.com/badges/b986cd75-2b0d-4295-a573-bcf75e44324a', 1, '2026-05-15 12:49:54'),
(225, 92, 265, '2026-03-24', '2027-03-24', 'active', NULL, '2de2acb8-0b05-42cf-899b-b492df6d80d3', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:2de2acb8-0b05-42cf-899b-b492df6d80d3\ncredly_template_id:c925916c-0270-4f22-ada3-b49003c04419\nbadge_url:https://www.credly.com/badges/2de2acb8-0b05-42cf-899b-b492df6d80d3', 1, '2026-05-15 12:49:54'),
(226, 92, 266, '2026-03-23', '2027-03-23', 'active', NULL, 'd6d59d6c-1a5c-4ac0-b1e4-036a895471d7', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:d6d59d6c-1a5c-4ac0-b1e4-036a895471d7\ncredly_template_id:4c7993db-95d0-4830-be44-aceb090dc601\nbadge_url:https://www.credly.com/badges/d6d59d6c-1a5c-4ac0-b1e4-036a895471d7', 1, '2026-05-15 12:49:54'),
(227, 92, 267, '2026-03-23', '2027-03-23', 'active', NULL, '199c9518-2c39-4349-affd-54f48c6b6050', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:199c9518-2c39-4349-affd-54f48c6b6050\ncredly_template_id:f2055410-b1fa-4b3e-8661-c88db2153a76\nbadge_url:https://www.credly.com/badges/199c9518-2c39-4349-affd-54f48c6b6050', 1, '2026-05-15 12:49:54'),
(228, 92, 268, '2026-03-19', '2027-03-19', 'active', NULL, 'b3eab502-0038-4716-b432-0ea9b4e996d1', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:b3eab502-0038-4716-b432-0ea9b4e996d1\ncredly_template_id:210d7c1d-fbf8-46a6-b080-921dde2d4850\nbadge_url:https://www.credly.com/badges/b3eab502-0038-4716-b432-0ea9b4e996d1', 1, '2026-05-15 12:49:54'),
(229, 92, 269, '2026-03-19', '2027-03-19', 'active', NULL, 'fb8235ac-03b4-4ca1-8656-c9db94f2c325', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:fb8235ac-03b4-4ca1-8656-c9db94f2c325\ncredly_template_id:a837e1a0-7dca-4c43-be3b-88d38cf98bf6\nbadge_url:https://www.credly.com/badges/fb8235ac-03b4-4ca1-8656-c9db94f2c325', 1, '2026-05-15 12:49:54'),
(230, 92, 270, '2026-03-18', '2027-03-18', 'active', NULL, 'ee30b700-9558-460e-a410-63206316eafd', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:ee30b700-9558-460e-a410-63206316eafd\ncredly_template_id:ed5bd09f-4fd1-4318-bffa-76854b0fca2d\nbadge_url:https://www.credly.com/badges/ee30b700-9558-460e-a410-63206316eafd', 1, '2026-05-15 12:49:54'),
(231, 92, 271, '2026-03-17', '2027-03-17', 'active', NULL, '02b519b9-3b1c-45cf-8034-ab046bf39510', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:02b519b9-3b1c-45cf-8034-ab046bf39510\ncredly_template_id:0e8dfe3e-a5f7-4fd0-b167-7d10bb691650\nbadge_url:https://www.credly.com/badges/02b519b9-3b1c-45cf-8034-ab046bf39510', 1, '2026-05-15 12:49:54'),
(232, 92, 272, '2026-03-17', '2027-03-17', 'active', NULL, '0a22d2f8-84fc-4179-9443-87e9807ad567', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:0a22d2f8-84fc-4179-9443-87e9807ad567\ncredly_template_id:22044390-a3b7-4a6c-9069-9a89f0d97201\nbadge_url:https://www.credly.com/badges/0a22d2f8-84fc-4179-9443-87e9807ad567', 1, '2026-05-15 12:49:54'),
(233, 92, 273, '2025-11-25', '2026-11-25', 'active', NULL, '9d9f27fe-1b64-4fd7-bb09-b532f961e1b3', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:9d9f27fe-1b64-4fd7-bb09-b532f961e1b3\ncredly_template_id:360c8b24-ff8f-4983-bd6d-e0674752a420\nbadge_url:https://www.credly.com/badges/9d9f27fe-1b64-4fd7-bb09-b532f961e1b3', 1, '2026-05-15 12:49:54'),
(234, 92, 274, '2025-05-19', '2026-05-19', 'expiring', NULL, 'a1b99616-3c01-4d74-8685-03f7a1df34d8', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:a1b99616-3c01-4d74-8685-03f7a1df34d8\ncredly_template_id:57ac3de2-4780-4c40-97fe-b223299f58bc\nbadge_url:https://www.credly.com/badges/a1b99616-3c01-4d74-8685-03f7a1df34d8', 1, '2026-05-15 12:49:54'),
(235, 92, 275, '2023-06-28', '2026-05-22', 'expiring', NULL, 'da1740a6-cfe7-4a17-ab91-2a1bb3c76dd7', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:da1740a6-cfe7-4a17-ab91-2a1bb3c76dd7\ncredly_template_id:2ebd334c-2c9b-4fb7-b1cd-2811186cde13\nbadge_url:https://www.credly.com/badges/da1740a6-cfe7-4a17-ab91-2a1bb3c76dd7', 1, '2026-05-15 12:49:54'),
(236, 92, 276, '2023-06-18', '2026-06-03', 'expiring', NULL, 'c8ae420b-dafa-4cfe-8308-7e17e9dde00c', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:c8ae420b-dafa-4cfe-8308-7e17e9dde00c\ncredly_template_id:e0841303-a781-4eeb-a21c-2ce20c98e7f8\nbadge_url:https://www.credly.com/badges/c8ae420b-dafa-4cfe-8308-7e17e9dde00c', 1, '2026-05-15 12:49:54'),
(237, 92, 277, '2025-03-17', '2026-03-17', 'expired', NULL, 'c736df9b-620b-4e3e-9610-64ace9e2be98', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:c736df9b-620b-4e3e-9610-64ace9e2be98\ncredly_template_id:c508e487-e9a2-454f-be3c-5304c9a7d171\nbadge_url:https://www.credly.com/badges/c736df9b-620b-4e3e-9610-64ace9e2be98', 1, '2026-05-15 12:49:54'),
(238, 92, 278, '2024-09-18', '2025-09-09', 'expired', NULL, 'dcf22cb0-1d7e-4d3b-8d11-9ce159946e00', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:dcf22cb0-1d7e-4d3b-8d11-9ce159946e00\ncredly_template_id:ab67db11-9d94-45a4-a26d-0b7cddf34187\nbadge_url:https://www.credly.com/badges/dcf22cb0-1d7e-4d3b-8d11-9ce159946e00', 1, '2026-05-15 12:49:54'),
(239, 92, 279, '2022-10-12', '2024-06-05', 'expired', NULL, '600c34a6-b717-405a-8818-0e34e223d148', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:600c34a6-b717-405a-8818-0e34e223d148\ncredly_template_id:aa0d88cd-4b76-4e59-964b-8f8599577359\nbadge_url:https://www.credly.com/badges/600c34a6-b717-405a-8818-0e34e223d148', 1, '2026-05-15 12:49:54'),
(240, 92, 280, '2022-10-11', '2024-06-05', 'expired', NULL, 'e9db644f-8da4-4de9-a1a6-26bcb9d08378', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:e9db644f-8da4-4de9-a1a6-26bcb9d08378\ncredly_template_id:82084790-1ec0-48d5-948a-7f64a81943d1\nbadge_url:https://www.credly.com/badges/e9db644f-8da4-4de9-a1a6-26bcb9d08378', 1, '2026-05-15 12:49:54'),
(241, 92, 281, '2023-06-01', '2024-05-31', 'expired', NULL, 'd1e19fe2-aa58-4a9e-8279-7e4b23d77376', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:d1e19fe2-aa58-4a9e-8279-7e4b23d77376\ncredly_template_id:094adc8f-98e9-41c8-a7b2-9a361a63353c\nbadge_url:https://www.credly.com/badges/d1e19fe2-aa58-4a9e-8279-7e4b23d77376', 1, '2026-05-15 12:49:54'),
(242, 92, 282, '2023-06-01', '2024-05-31', 'expired', NULL, '5070abc8-f4da-4e42-a96d-620905e83bd1', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:5070abc8-f4da-4e42-a96d-620905e83bd1\ncredly_template_id:0e569599-5910-428b-af5e-47d71a21c203\nbadge_url:https://www.credly.com/badges/5070abc8-f4da-4e42-a96d-620905e83bd1', 1, '2026-05-15 12:49:54'),
(243, 92, 283, '2023-06-01', '2024-05-31', 'expired', NULL, '5b7a2416-4b62-49be-b527-f855c0d00b4a', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:5b7a2416-4b62-49be-b527-f855c0d00b4a\ncredly_template_id:0821db45-a88c-4ee7-b428-be4a5a0fff65\nbadge_url:https://www.credly.com/badges/5b7a2416-4b62-49be-b527-f855c0d00b4a', 1, '2026-05-15 12:49:54'),
(244, 92, 284, '2023-05-31', '2024-05-30', 'expired', NULL, '29f26d4b-6a74-4599-8b49-b46917bd02b8', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:29f26d4b-6a74-4599-8b49-b46917bd02b8\ncredly_template_id:3691f1c4-7bef-4de3-b6c4-6ef2e1bc7442\nbadge_url:https://www.credly.com/badges/29f26d4b-6a74-4599-8b49-b46917bd02b8', 1, '2026-05-15 12:49:54'),
(245, 92, 285, '2023-05-31', '2024-05-30', 'expired', NULL, '7c3ba5b9-d550-48ca-aee6-95bdbd653d7d', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:7c3ba5b9-d550-48ca-aee6-95bdbd653d7d\ncredly_template_id:e2903708-937e-443c-95b1-38000621f0d1\nbadge_url:https://www.credly.com/badges/7c3ba5b9-d550-48ca-aee6-95bdbd653d7d', 1, '2026-05-15 12:49:54'),
(246, 92, 286, '2023-05-30', '2024-05-29', 'expired', NULL, 'c855f738-d393-4a0a-b3a7-4db04892f280', NULL, 'Importato da Credly il 2026-05-15 14:49\ncredly_badge_id:c855f738-d393-4a0a-b3a7-4db04892f280\ncredly_template_id:5556c2e7-57f5-47ed-bf6c-7c830dc72f09\nbadge_url:https://www.credly.com/badges/c855f738-d393-4a0a-b3a7-4db04892f280', 1, '2026-05-15 12:49:54'),
(247, 86, 287, '2025-10-21', NULL, 'active', NULL, 'ddddccce-84dc-4a11-bda8-6bf72f84a731', NULL, 'Importato da Credly il 2026-05-15 15:02\ncredly_badge_id:ddddccce-84dc-4a11-bda8-6bf72f84a731\ncredly_template_id:7241b00e-c96b-4ad1-a97a-3a07c66ddfc0\nbadge_url:https://www.credly.com/badges/ddddccce-84dc-4a11-bda8-6bf72f84a731', 1, '2026-05-15 13:02:21'),
(248, 40, 288, '2025-10-29', '2026-12-15', 'active', NULL, 'b151b3b7-fc3c-45fc-97f1-4e1908b3c7c5', NULL, 'Importato da Credly il 2026-05-15 15:10\ncredly_badge_id:b151b3b7-fc3c-45fc-97f1-4e1908b3c7c5\ncredly_template_id:ef6da94f-f491-444a-9472-3c3f64240b31\nbadge_url:https://www.credly.com/badges/b151b3b7-fc3c-45fc-97f1-4e1908b3c7c5', 1, '2026-05-15 13:10:17'),
(249, 64, 201, '2026-02-06', NULL, 'active', NULL, '4b794096-5650-4f49-93f4-29dd67906ef0', NULL, 'Importato da Credly il 2026-05-15 15:10\ncredly_badge_id:4b794096-5650-4f49-93f4-29dd67906ef0\ncredly_template_id:a2969d0f-bc02-44fb-8c16-5c3e2fe0180e\nbadge_url:https://www.credly.com/badges/4b794096-5650-4f49-93f4-29dd67906ef0', 1, '2026-05-15 13:10:57'),
(250, 64, 141, '2026-01-29', '2028-01-29', 'active', NULL, '818369c5-3409-420a-bf2c-309edf668bab', NULL, 'Importato da Credly il 2026-05-15 15:10\ncredly_badge_id:818369c5-3409-420a-bf2c-309edf668bab\ncredly_template_id:f6159ec0-43b2-444b-a1e0-18a323f4833f\nbadge_url:https://www.credly.com/badges/818369c5-3409-420a-bf2c-309edf668bab', 1, '2026-05-15 13:10:57'),
(251, 64, 199, '2026-01-29', NULL, 'active', NULL, 'efdd4111-7107-4ad2-8570-79ac17694d7a', NULL, 'Importato da Credly il 2026-05-15 15:10\ncredly_badge_id:efdd4111-7107-4ad2-8570-79ac17694d7a\ncredly_template_id:13b8699a-d998-4674-8509-5dcaee16807b\nbadge_url:https://www.credly.com/badges/efdd4111-7107-4ad2-8570-79ac17694d7a', 1, '2026-05-15 13:10:57'),
(252, 64, 200, '2026-01-22', NULL, 'active', NULL, '63425491-20a4-437b-a372-ef21470cd91b', NULL, 'Importato da Credly il 2026-05-15 15:10\ncredly_badge_id:63425491-20a4-437b-a372-ef21470cd91b\ncredly_template_id:9b3a946f-be9f-457e-b9e9-6f2eec0f60e8\nbadge_url:https://www.credly.com/badges/63425491-20a4-437b-a372-ef21470cd91b', 1, '2026-05-15 13:10:57'),
(253, 64, 138, '2023-10-14', NULL, 'active', NULL, 'b4d10286-b057-404c-8947-fcb05a9af1df', NULL, 'Importato da Credly il 2026-05-15 15:10\ncredly_badge_id:b4d10286-b057-404c-8947-fcb05a9af1df\ncredly_template_id:a988c07d-dd6c-4249-8f01-2fa8f9f3acdb\nbadge_url:https://www.credly.com/badges/b4d10286-b057-404c-8947-fcb05a9af1df', 1, '2026-05-15 13:10:57'),
(254, 64, 137, '2023-10-14', '2025-10-14', 'expired', NULL, '57888922-e92b-4bd3-b2eb-042fb97858b3', NULL, 'Importato da Credly il 2026-05-15 15:10\ncredly_badge_id:57888922-e92b-4bd3-b2eb-042fb97858b3\ncredly_template_id:16abd220-eac0-469f-b15f-300c9d97a343\nbadge_url:https://www.credly.com/badges/57888922-e92b-4bd3-b2eb-042fb97858b3', 1, '2026-05-15 13:10:57');

-- --------------------------------------------------------

--
-- Struttura della tabella `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `page_name` varchar(100) NOT NULL,
  `can_view` tinyint(1) DEFAULT NULL,
  `can_create` tinyint(1) DEFAULT NULL,
  `can_edit` tinyint(1) DEFAULT NULL,
  `can_delete` tinyint(1) DEFAULT NULL,
  `can_export` tinyint(1) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
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
-- Indici per le tabelle `branding_settings`
--
ALTER TABLE `branding_settings`
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
-- Indici per le tabelle `brand_distributors`
--
ALTER TABLE `brand_distributors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_brand_dist` (`brand_id`,`distributor_id`),
  ADD KEY `idx_bd_brand` (`brand_id`),
  ADD KEY `idx_bd_dist` (`distributor_id`),
  ADD KEY `idx_bd_ranking` (`ranking`);

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
  ADD KEY `technology_id` (`technology_id`),
  ADD KEY `idx_cert_credly_template` (`credly_template_id`);

--
-- Indici per le tabelle `certification_versions`
--
ALTER TABLE `certification_versions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cv_cert` (`certification_id`);

--
-- Indici per le tabelle `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_clients_name` (`name`),
  ADD KEY `idx_clients_active` (`is_active`),
  ADD KEY `idx_clients_internal` (`internal_company_id`),
  ADD KEY `cli_fk_user` (`created_by`);

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
-- Indici per le tabelle `contract_documents`
--
ALTER TABLE `contract_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cd_contract` (`contract_id`),
  ADD KEY `idx_cd_status` (`status`);

--
-- Indici per le tabelle `distributors`
--
ALTER TABLE `distributors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_distributor_name` (`name`),
  ADD KEY `idx_dist_status` (`status`),
  ADD KEY `idx_dist_type` (`type`);

--
-- Indici per le tabelle `document_access_rules`
--
ALTER TABLE `document_access_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dar_type_role` (`doc_type`,`role_id`);

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
-- Indici per le tabelle `employee_credly_link`
--
ALTER TABLE `employee_credly_link`
  ADD PRIMARY KEY (`employee_id`),
  ADD KEY `idx_credly_username` (`credly_username`);

--
-- Indici per le tabelle `employee_linkedin_link`
--
ALTER TABLE `employee_linkedin_link`
  ADD PRIMARY KEY (`employee_id`),
  ADD KEY `idx_linkedin_vanity` (`linkedin_vanity`);

--
-- Indici per le tabelle `employee_skills`
--
ALTER TABLE `employee_skills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_emp_skill` (`employee_id`,`skill_name`),
  ADD KEY `idx_es_emp` (`employee_id`),
  ADD KEY `idx_es_skill` (`skill_name`),
  ADD KEY `es_fk_validator` (`validated_by`);

--
-- Indici per le tabelle `entity_change_log`
--
ALTER TABLE `entity_change_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ecl_entity` (`entity_table`,`entity_id`),
  ADD KEY `idx_ecl_source` (`change_source`,`source_ref_id`),
  ADD KEY `idx_ecl_user` (`changed_by`),
  ADD KEY `idx_ecl_date` (`changed_at`);

--
-- Indici per le tabelle `enum_proposals`
--
ALTER TABLE `enum_proposals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_enum_target_value` (`target_table`,`target_column`,`proposed_value`),
  ADD KEY `idx_ep_status` (`status`),
  ADD KEY `idx_ep_user` (`decided_by`);

--
-- Indici per le tabelle `import_jobs`
--
ALTER TABLE `import_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_imp_type_status` (`import_type`,`status`),
  ADD KEY `idx_imp_user` (`created_by`),
  ADD KEY `idx_imp_started` (`started_at`),
  ADD KEY `idx_imp_queue` (`status`,`queued_at`);

--
-- Indici per le tabelle `import_partial_completions`
--
ALTER TABLE `import_partial_completions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ipc_staging` (`staging_id`),
  ADD KEY `idx_ipc_target` (`target_table`,`target_id`),
  ADD KEY `ipc_fk_user` (`completed_by`);

--
-- Indici per le tabelle `import_staging_rows`
--
ALTER TABLE `import_staging_rows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_isr_job_status` (`job_id`,`status`),
  ADD KEY `idx_isr_row` (`job_id`,`row_number`),
  ADD KEY `isr_fk_user` (`last_edit_by`),
  ADD KEY `idx_isr_approved` (`job_id`,`status`,`approved_as`);

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
-- Indici per le tabelle `logistics_requests`
--
ALTER TABLE `logistics_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lr_employee` (`employee_id`),
  ADD KEY `idx_lr_status` (`status`);

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
-- Indici per le tabelle `person_documents`
--
ALTER TABLE `person_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pd_candidate` (`candidate_id`),
  ADD KEY `idx_pd_employee` (`employee_id`),
  ADD KEY `idx_pd_type` (`doc_type`);

--
-- Indici per le tabelle `planned_exams`
--
ALTER TABLE `planned_exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `certification_id` (`certification_id`);

--
-- Indici per le tabelle `positions_expected`
--
ALTER TABLE `positions_expected`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pe_position` (`job_position_id`);

--
-- Indici per le tabelle `position_clients`
--
ALTER TABLE `position_clients`
  ADD PRIMARY KEY (`position_id`,`client_id`),
  ADD KEY `idx_pcli_client` (`client_id`);

--
-- Indici per le tabelle `position_compensation_history`
--
ALTER TABLE `position_compensation_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pch_position` (`position_id`,`changed_at`),
  ADD KEY `idx_pch_changed_by` (`changed_by`);

--
-- Indici per le tabelle `position_master_texts`
--
ALTER TABLE `position_master_texts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pmt_type_current` (`text_type`,`is_current`);

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
-- Indici per le tabelle `position_status_history`
--
ALTER TABLE `position_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_psh_position` (`position_id`,`changed_at`),
  ADD KEY `idx_psh_changed_by` (`changed_by`);

--
-- Indici per le tabelle `position_templates`
--
ALTER TABLE `position_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ptpl_type` (`template_type`,`is_active`),
  ADD KEY `idx_ptpl_current` (`template_type`,`is_current`,`is_active`);

--
-- Indici per le tabelle `position_templates_history`
--
ALTER TABLE `position_templates_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pth_template` (`template_id`,`changed_at`),
  ADD KEY `idx_pth_changed_by` (`changed_by`);

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
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `t_fk_category` (`category_id`);

--
-- Indici per le tabelle `tech_brands`
--
ALTER TABLE `tech_brands`
  ADD PRIMARY KEY (`technology_id`,`brand_id`),
  ADD KEY `idx_tb_brand` (`brand_id`),
  ADD KEY `tb_fk_user` (`created_by`);

--
-- Indici per le tabelle `tech_categories`
--
ALTER TABLE `tech_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tech_cat_name` (`name`),
  ADD KEY `idx_tc_parent` (`parent_id`);

--
-- Indici per le tabelle `tech_certifications`
--
ALTER TABLE `tech_certifications`
  ADD PRIMARY KEY (`technology_id`,`certification_id`),
  ADD KEY `idx_tcrt_cert` (`certification_id`),
  ADD KEY `tcrt_fk_user` (`created_by`);

--
-- Indici per le tabelle `tech_employee_skills`
--
ALTER TABLE `tech_employee_skills`
  ADD PRIMARY KEY (`technology_id`,`employee_skill_id`),
  ADD KEY `idx_tes_skill` (`employee_skill_id`),
  ADD KEY `tes_fk_user` (`created_by`);

--
-- Indici per le tabelle `tech_user_certifications`
--
ALTER TABLE `tech_user_certifications`
  ADD PRIMARY KEY (`technology_id`,`user_certification_id`),
  ADD KEY `idx_tuc_cert` (`user_certification_id`),
  ADD KEY `tuc_fk_user` (`created_by`);

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
-- Indici per le tabelle `user_2fa`
--
ALTER TABLE `user_2fa`
  ADD PRIMARY KEY (`user_id`);

--
-- Indici per le tabelle `user_2fa_attempts`
--
ALTER TABLE `user_2fa_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_success_created` (`success`,`created_at`);

--
-- Indici per le tabelle `user_2fa_recovery_codes`
--
ALTER TABLE `user_2fa_recovery_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_unused` (`user_id`,`used_at`);

--
-- Indici per le tabelle `user_certifications`
--
ALTER TABLE `user_certifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `certification_id` (`certification_id`),
  ADD KEY `status` (`status`),
  ADD KEY `expiry_date` (`expiry_date`),
  ADD KEY `idx_uc_cert_code` (`certification_id`,`certificate_code`);

--
-- Indici per le tabelle `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_up_user_page` (`user_id`,`page_name`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=663;

--
-- AUTO_INCREMENT per la tabella `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

--
-- AUTO_INCREMENT per la tabella `brand_contacts_history`
--
ALTER TABLE `brand_contacts_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT per la tabella `brand_distributors`
--
ALTER TABLE `brand_distributors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT per la tabella `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT per la tabella `candidate_applications`
--
ALTER TABLE `candidate_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT per la tabella `certifications`
--
ALTER TABLE `certifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=289;

--
-- AUTO_INCREMENT per la tabella `certification_versions`
--
ALTER TABLE `certification_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT per la tabella `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT per la tabella `company_locations`
--
ALTER TABLE `company_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT per la tabella `contract_documents`
--
ALTER TABLE `contract_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `distributors`
--
ALTER TABLE `distributors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT per la tabella `document_access_rules`
--
ALTER TABLE `document_access_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=343;

--
-- AUTO_INCREMENT per la tabella `email_log`
--
ALTER TABLE `email_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT per la tabella `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=95;

--
-- AUTO_INCREMENT per la tabella `employee_skills`
--
ALTER TABLE `employee_skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `entity_change_log`
--
ALTER TABLE `entity_change_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT per la tabella `enum_proposals`
--
ALTER TABLE `enum_proposals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT per la tabella `import_jobs`
--
ALTER TABLE `import_jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT per la tabella `import_partial_completions`
--
ALTER TABLE `import_partial_completions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `import_staging_rows`
--
ALTER TABLE `import_staging_rows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=158;

--
-- AUTO_INCREMENT per la tabella `interview_scorecards`
--
ALTER TABLE `interview_scorecards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `job_positions`
--
ALTER TABLE `job_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT per la tabella `logistics_requests`
--
ALTER TABLE `logistics_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT per la tabella `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT per la tabella `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `person_documents`
--
ALTER TABLE `person_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT per la tabella `planned_exams`
--
ALTER TABLE `planned_exams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT per la tabella `positions_expected`
--
ALTER TABLE `positions_expected`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `position_compensation_history`
--
ALTER TABLE `position_compensation_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT per la tabella `position_master_texts`
--
ALTER TABLE `position_master_texts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT per la tabella `position_publications`
--
ALTER TABLE `position_publications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `position_status_history`
--
ALTER TABLE `position_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT per la tabella `position_templates`
--
ALTER TABLE `position_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT per la tabella `position_templates_history`
--
ALTER TABLE `position_templates_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT per la tabella `technologies`
--
ALTER TABLE `technologies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT per la tabella `tech_categories`
--
ALTER TABLE `tech_categories`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT per la tabella `user_2fa_attempts`
--
ALTER TABLE `user_2fa_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `user_2fa_recovery_codes`
--
ALTER TABLE `user_2fa_recovery_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `user_certifications`
--
ALTER TABLE `user_certifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=255;

--
-- AUTO_INCREMENT per la tabella `user_permissions`
--
ALTER TABLE `user_permissions`
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
-- Limiti per la tabella `brand_distributors`
--
ALTER TABLE `brand_distributors`
  ADD CONSTRAINT `fk_bd_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bd_dist` FOREIGN KEY (`distributor_id`) REFERENCES `distributors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

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
-- Limiti per la tabella `certification_versions`
--
ALTER TABLE `certification_versions`
  ADD CONSTRAINT `fk_cv_cert` FOREIGN KEY (`certification_id`) REFERENCES `certifications` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `cli_fk_company` FOREIGN KEY (`internal_company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `cli_fk_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `company_locations`
--
ALTER TABLE `company_locations`
  ADD CONSTRAINT `cl_fk1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `contract_documents`
--
ALTER TABLE `contract_documents`
  ADD CONSTRAINT `fk_cd_contract` FOREIGN KEY (`contract_id`) REFERENCES `agency_contracts` (`id`) ON DELETE CASCADE;

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
-- Limiti per la tabella `employee_credly_link`
--
ALTER TABLE `employee_credly_link`
  ADD CONSTRAINT `fk_ecl_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `employee_linkedin_link`
--
ALTER TABLE `employee_linkedin_link`
  ADD CONSTRAINT `fk_ell_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `employee_skills`
--
ALTER TABLE `employee_skills`
  ADD CONSTRAINT `es_fk_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `es_fk_validator` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `entity_change_log`
--
ALTER TABLE `entity_change_log`
  ADD CONSTRAINT `ecl_fk_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `enum_proposals`
--
ALTER TABLE `enum_proposals`
  ADD CONSTRAINT `ep_fk_user` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `import_jobs`
--
ALTER TABLE `import_jobs`
  ADD CONSTRAINT `imp_fk_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `import_partial_completions`
--
ALTER TABLE `import_partial_completions`
  ADD CONSTRAINT `ipc_fk_staging` FOREIGN KEY (`staging_id`) REFERENCES `import_staging_rows` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ipc_fk_user` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `import_staging_rows`
--
ALTER TABLE `import_staging_rows`
  ADD CONSTRAINT `isr_fk_job` FOREIGN KEY (`job_id`) REFERENCES `import_jobs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `isr_fk_user` FOREIGN KEY (`last_edit_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

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
-- Limiti per la tabella `logistics_requests`
--
ALTER TABLE `logistics_requests`
  ADD CONSTRAINT `fk_lr_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notif_fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `person_documents`
--
ALTER TABLE `person_documents`
  ADD CONSTRAINT `fk_pd_cand` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pd_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `planned_exams`
--
ALTER TABLE `planned_exams`
  ADD CONSTRAINT `pe_fk2` FOREIGN KEY (`certification_id`) REFERENCES `certifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pe_fk_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `positions_expected`
--
ALTER TABLE `positions_expected`
  ADD CONSTRAINT `fk_pe_position` FOREIGN KEY (`job_position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `position_clients`
--
ALTER TABLE `position_clients`
  ADD CONSTRAINT `pcli_fk_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pcli_fk_pos` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `position_compensation_history`
--
ALTER TABLE `position_compensation_history`
  ADD CONSTRAINT `pch_fk_position` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pch_fk_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `position_publications`
--
ALTER TABLE `position_publications`
  ADD CONSTRAINT `pp_fk1` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pp_fk2` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `position_status_history`
--
ALTER TABLE `position_status_history`
  ADD CONSTRAINT `psh_fk_position` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `psh_fk_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `position_templates_history`
--
ALTER TABLE `position_templates_history`
  ADD CONSTRAINT `pth_fk_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `rp_fk1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `technologies`
--
ALTER TABLE `technologies`
  ADD CONSTRAINT `t_fk_category` FOREIGN KEY (`category_id`) REFERENCES `tech_categories` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `tech_brands`
--
ALTER TABLE `tech_brands`
  ADD CONSTRAINT `tb_fk_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tb_fk_tech` FOREIGN KEY (`technology_id`) REFERENCES `technologies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tb_fk_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `tech_categories`
--
ALTER TABLE `tech_categories`
  ADD CONSTRAINT `tc_fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `tech_categories` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `tech_certifications`
--
ALTER TABLE `tech_certifications`
  ADD CONSTRAINT `tcrt_fk_cert` FOREIGN KEY (`certification_id`) REFERENCES `certifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tcrt_fk_tech` FOREIGN KEY (`technology_id`) REFERENCES `technologies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tcrt_fk_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `tech_employee_skills`
--
ALTER TABLE `tech_employee_skills`
  ADD CONSTRAINT `tes_fk_skill` FOREIGN KEY (`employee_skill_id`) REFERENCES `employee_skills` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tes_fk_tech` FOREIGN KEY (`technology_id`) REFERENCES `technologies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tes_fk_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Limiti per la tabella `tech_user_certifications`
--
ALTER TABLE `tech_user_certifications`
  ADD CONSTRAINT `tuc_fk_tech` FOREIGN KEY (`technology_id`) REFERENCES `technologies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tuc_fk_uc` FOREIGN KEY (`user_certification_id`) REFERENCES `user_certifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tuc_fk_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

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
-- Limiti per la tabella `user_2fa`
--
ALTER TABLE `user_2fa`
  ADD CONSTRAINT `fk_user_2fa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `user_2fa_recovery_codes`
--
ALTER TABLE `user_2fa_recovery_codes`
  ADD CONSTRAINT `fk_recovery_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `user_certifications`
--
ALTER TABLE `user_certifications`
  ADD CONSTRAINT `uc_fk2` FOREIGN KEY (`certification_id`) REFERENCES `certifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `uc_fk_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
