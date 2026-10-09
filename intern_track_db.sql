-- InternTrack OJT Management System - database (schema version 5)
--
-- FRESH INSTALL ONLY: import this file in phpMyAdmin into an empty database named `intern_track_db`.
-- EXISTING INSTALL:   do NOT import it. Replace the PHP files only; db.php upgrades your current
--                     database automatically and keeps all of your records and accounts.
--
-- Accounts created by this file:
--   Super Admin (HR Admin):  username  hr.admin   (password unchanged from your original setup)
--   Admin:                   username  admin      password  Admin@2026   (change it under Settings > My Account)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `intern_track_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `interns`
--
-- status:            'Active' or 'Completed'
-- validation_status: 'Pending' (self-registered, awaiting review), 'Approved' (accepted record), 'Rejected'
-- submitted_via:     'admin' (added from the dashboard) or 'register' (self-registered)
--

CREATE TABLE `interns` (
  `id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `school` varchar(100) DEFAULT NULL,
  `department` varchar(50) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `batch_year` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `validation_status` varchar(20) NOT NULL DEFAULT 'Approved',
  `submitted_via` varchar(20) NOT NULL DEFAULT 'admin',
  `rejection_reason` text DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--
-- role: 'super_admin' (HR Admin: full access) or 'admin' (day-to-day OJT management)
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `display_name` varchar(100) DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `created_at`, `display_name`, `role`, `is_active`, `last_login_at`) VALUES
(1, 'hr.admin', '$2y$10$hDwcUjx6tO67oy8G61SSHOkyfYq0xNQdKqOi3e0eVd2rzJN08y8JS', '2026-08-26 06:55:57', 'HR Admin', 'super_admin', 1, NULL),
(2, 'admin',    '$2y$10$AqG3pqoID/TVR.7Xg.PtdeArk8F.zVNcCPVzJ1K5wuCmcnJy6NRQW', '2026-08-26 06:55:57', 'Admin User', 'admin',       1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `role` varchar(20) DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('schema_version', '5'),
('registration_open', '1');

--
-- Indexes for dumped tables
--

ALTER TABLE `interns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `validation_status` (`validation_status`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- AUTO_INCREMENT for dumped tables
--

ALTER TABLE `interns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;