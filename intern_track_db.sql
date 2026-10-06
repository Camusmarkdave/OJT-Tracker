-- InternTrack database (updated)
--
-- FRESH INSTALL ONLY: import this file in phpMyAdmin into an empty database.
-- EXISTING INSTALL:   do NOT import it. Just replace the PHP files - db.php upgrades
--                     your current database automatically and keeps all your records.

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
-- validation_status: 'Pending' (self-registered, waiting for admin), 'Approved' (accepted record), 'Rejected'
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

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `display_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `created_at`, `display_name`) VALUES
(1, 'hr.admin', '$2y$10$hDwcUjx6tO67oy8G61SSHOkyfYq0xNQdKqOi3e0eVd2rzJN08y8JS', '2026-08-26 06:55:57', 'Admin User');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('schema_version', '3'),
('registration_open', '1');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `departments` (`id`, `name`) VALUES
(1, 'Finance'),
(2, 'HR'),
(3, 'IT'),
(4, 'Marketing'),
(5, 'Operations'),
(6, 'Sales');

--
-- Indexes for dumped tables
--

ALTER TABLE `interns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `validation_status` (`validation_status`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

ALTER TABLE `interns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;