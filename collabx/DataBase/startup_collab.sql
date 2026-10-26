-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 26, 2026 at 01:54 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `startup_collab`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_calculate_risk_score` (IN `p_project_id` INT, OUT `p_risk_score` INT)   BEGIN
    DECLARE v_funding_percent DECIMAL(6,2);
    DECLARE v_skill_gap_total INT;

    SELECT ROUND(IFNULL(SUM(i.amount),0) / p.required_budget * 100, 2)
    INTO v_funding_percent
    FROM Projects p
    LEFT JOIN Investments i ON p.project_id = i.project_id
    WHERE p.project_id = p_project_id
    GROUP BY p.required_budget;

    SELECT IFNULL(SUM(required_count - filled_count), 0)
    INTO v_skill_gap_total
    FROM ProjectSkills WHERE project_id = p_project_id;

    SET p_risk_score = (100 - IFNULL(v_funding_percent, 0)) + (v_skill_gap_total * 5);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_distribute_profit` (IN `p_revenue_id` INT, IN `p_commission_percent` DECIMAL(5,2))   BEGIN
    DECLARE v_project_id INT;
    DECLARE v_amount DECIMAL(14,2);
    DECLARE v_net_amount DECIMAL(14,2);
    DECLARE v_commission DECIMAL(14,2);
    DECLARE v_team_count INT;
    DECLARE v_share_percent DECIMAL(5,2);

    SELECT project_id, amount INTO v_project_id, v_amount FROM Revenue WHERE revenue_id = p_revenue_id;

    SET v_commission = v_amount * (p_commission_percent / 100);
    SET v_net_amount = v_amount - v_commission;

    SELECT COUNT(*) INTO v_team_count FROM TeamMembers WHERE project_id = v_project_id;

    IF v_team_count > 0 THEN
        SET v_share_percent = 100 / v_team_count;
        INSERT INTO ProfitShares (revenue_id, user_id, share_percentage, share_amount, paid_status)
        SELECT p_revenue_id, tm.user_id, v_share_percent,
               ROUND(v_net_amount * (v_share_percent / 100), 2), 'pending'
        FROM TeamMembers tm WHERE tm.project_id = v_project_id;
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_release_escrow` (IN `p_investment_id` INT)   BEGIN
    DECLARE v_owner_id INT;
    DECLARE v_amount DECIMAL(14,2);
    DECLARE v_project_id INT;
    DECLARE v_owner_wallet INT;

    SELECT i.amount, i.project_id INTO v_amount, v_project_id
    FROM Investments i WHERE i.investment_id = p_investment_id AND i.escrow_status = 'held';

    IF v_amount IS NOT NULL THEN
        SELECT owner_id INTO v_owner_id FROM Projects WHERE project_id = v_project_id;
        SELECT wallet_id INTO v_owner_wallet FROM Wallets WHERE user_id = v_owner_id;

        UPDATE Investments SET escrow_status = 'released', released_at = NOW()
        WHERE investment_id = p_investment_id;

        UPDATE Wallets SET balance = balance + v_amount WHERE wallet_id = v_owner_wallet;

        INSERT INTO Transactions (wallet_id, type, amount, reference)
        VALUES (v_owner_wallet, 'escrow_release', v_amount,
                CONCAT('Escrow released for investment #', p_investment_id));
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_release_salary` (IN `p_salary_id` INT)   BEGIN
    DECLARE v_user_id INT;
    DECLARE v_amount DECIMAL(14,2);
    DECLARE v_wallet_id INT;

    SELECT user_id, amount INTO v_user_id, v_amount
    FROM SalaryReleases WHERE salary_id = p_salary_id AND status = 'pending';

    IF v_amount IS NOT NULL THEN
        SELECT wallet_id INTO v_wallet_id FROM Wallets WHERE user_id = v_user_id;

        UPDATE SalaryReleases SET status = 'released', released_at = NOW() WHERE salary_id = p_salary_id;
        UPDATE Wallets SET balance = balance + v_amount WHERE wallet_id = v_wallet_id;

        INSERT INTO Transactions (wallet_id, type, amount, reference)
        VALUES (v_wallet_id, 'salary_release', v_amount, CONCAT('Salary release #', p_salary_id));
    END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `agreementparties`
--

CREATE TABLE `agreementparties` (
  `party_id` int(11) NOT NULL,
  `agreement_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('idea_owner','investor','manpower') NOT NULL,
  `profit_share_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `monthly_salary` decimal(14,2) DEFAULT 0.00,
  `is_signed` tinyint(1) DEFAULT 0,
  `signed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `agreementparties`
--

INSERT INTO `agreementparties` (`party_id`, `agreement_id`, `user_id`, `role`, `profit_share_percent`, `monthly_salary`, `is_signed`, `signed_at`) VALUES
(1, 1, 1, 'investor', 1.00, 20000.00, 1, '2026-09-22 22:56:13'),
(2, 3, 3, 'investor', 3.00, 60000.00, 0, NULL),
(3, 1, 3, 'investor', 15.00, 52000.00, 0, NULL);

--
-- Triggers `agreementparties`
--
DELIMITER $$
CREATE TRIGGER `trg_after_party_sign` AFTER UPDATE ON `agreementparties` FOR EACH ROW BEGIN
    DECLARE v_total INT;
    DECLARE v_signed INT;
    DECLARE v_project_id INT;

    IF NEW.is_signed = TRUE AND OLD.is_signed = FALSE THEN
        SELECT COUNT(*) INTO v_total FROM AgreementParties WHERE agreement_id = NEW.agreement_id;
        SELECT COUNT(*) INTO v_signed FROM AgreementParties WHERE agreement_id = NEW.agreement_id AND is_signed = TRUE;

        IF v_total = v_signed THEN
            UPDATE Agreements SET status = 'finalized', finalized_at = NOW() WHERE agreement_id = NEW.agreement_id;
            SELECT project_id INTO v_project_id FROM Agreements WHERE agreement_id = NEW.agreement_id;
            UPDATE Projects SET status = 'in_progress' WHERE project_id = v_project_id;
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `agreements`
--

CREATE TABLE `agreements` (
  `agreement_id` int(11) NOT NULL,
  `contract_ref_id` varchar(50) DEFAULT NULL,
  `project_id` int(11) NOT NULL,
  `status` enum('draft','pending_signatures','finalized','cancelled') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finalized_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `agreements`
--

INSERT INTO `agreements` (`agreement_id`, `contract_ref_id`, `project_id`, `status`, `created_at`, `finalized_at`) VALUES
(1, 'CX-AGR-20260923-6593', 1, '', '2026-09-22 22:42:34', '2026-09-22 22:56:13'),
(2, 'CX-AGR-20260923-3188', 4, 'pending_signatures', '2026-09-22 23:57:13', NULL),
(3, 'CX-AGR-20260924-8991', 6, 'pending_signatures', '2026-09-24 01:16:48', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `direct_messages`
--

CREATE TABLE `direct_messages` (
  `message_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `investments`
--

CREATE TABLE `investments` (
  `investment_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `investor_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL CHECK (`amount` > 0),
  `equity_percentage` decimal(5,2) DEFAULT 0.00,
  `escrow_status` enum('held','released','refunded') DEFAULT 'held',
  `status` enum('pending','confirmed','refunded') DEFAULT 'confirmed',
  `invested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `investments`
--

INSERT INTO `investments` (`investment_id`, `project_id`, `investor_id`, `amount`, `equity_percentage`, `escrow_status`, `status`, `invested_at`, `released_at`, `created_at`) VALUES
(1, 3, 3, 64222.00, 12.00, 'held', 'pending', '2026-09-22 15:12:13', NULL, '2026-09-22 15:12:13'),
(2, 4, 3, 100000.00, 10.00, 'held', '', '2026-09-22 15:30:12', NULL, '2026-09-22 15:30:12'),
(3, 5, 3, 45000.00, 4.00, 'held', 'pending', '2026-09-23 02:09:42', NULL, '2026-09-23 02:09:42'),
(4, 4, 3, 60000.00, 6.00, 'held', 'pending', '2026-09-23 02:10:01', NULL, '2026-09-23 02:10:01'),
(5, 5, 3, 5222.00, 5.00, 'held', 'pending', '2026-09-23 04:27:06', NULL, '2026-09-23 04:27:06'),
(6, 6, 3, 40000.00, 4.00, 'held', '', '2026-09-23 23:40:41', NULL, '2026-09-23 23:40:41'),
(7, 1, 3, 52000.00, 10.00, 'held', 'pending', '2026-09-24 03:41:24', NULL, '2026-09-24 03:41:24'),
(8, 1, 9, 30000.00, 5.00, 'held', 'pending', '2026-09-29 17:20:37', NULL, '2026-09-29 17:20:37'),
(9, 4, 9, 60000.00, 20.00, 'held', 'pending', '2026-09-29 17:40:20', NULL, '2026-09-29 17:40:20'),
(10, 11, 13, 45000.00, 6.00, 'held', 'pending', '2026-09-29 17:56:49', NULL, '2026-09-29 17:56:49'),
(11, 8, 13, 30000.00, 5.00, 'held', 'pending', '2026-09-29 17:57:00', NULL, '2026-09-29 17:57:00');

--
-- Triggers `investments`
--
DELIMITER $$
CREATE TRIGGER `trg_after_investment_insert` AFTER INSERT ON `investments` FOR EACH ROW BEGIN
    UPDATE Projects
    SET collected_budget = collected_budget + NEW.amount
    WHERE project_id = NEW.project_id;

    INSERT INTO Transactions (wallet_id, type, amount, reference)
    SELECT w.wallet_id, 'escrow_hold', NEW.amount,
           CONCAT('Investment held in escrow for project #', NEW.project_id)
    FROM Wallets w
    WHERE w.user_id = NEW.investor_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `joinrequests`
--

CREATE TABLE `joinrequests` (
  `request_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `skill_id` int(11) DEFAULT NULL,
  `expected_salary` varchar(50) DEFAULT NULL,
  `portfolio_link` varchar(255) DEFAULT NULL,
  `cover_note` text DEFAULT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `joinrequests`
--

INSERT INTO `joinrequests` (`request_id`, `project_id`, `user_id`, `skill_id`, `expected_salary`, `portfolio_link`, `cover_note`, `status`, `requested_at`) VALUES
(1, 5, 5, 1, NULL, NULL, NULL, 'pending', '2026-09-23 03:48:25'),
(2, 6, 7, 1, '20000', NULL, 'good', 'accepted', '2026-09-23 19:48:02'),
(3, 5, 7, 1, '25550', NULL, 'nice', 'accepted', '2026-09-23 20:04:26'),
(4, 5, 10, 1, '25000', NULL, '', 'accepted', '2026-09-29 17:34:23'),
(5, 6, 12, 1, '25000', NULL, 'bsh', 'pending', '2026-09-29 17:56:03');

--
-- Triggers `joinrequests`
--
DELIMITER $$
CREATE TRIGGER `trg_after_joinrequest_accept` AFTER UPDATE ON `joinrequests` FOR EACH ROW BEGIN
    IF NEW.status = 'accepted' AND OLD.status <> 'accepted' THEN
        UPDATE ProjectSkills
        SET filled_count = filled_count + 1
        WHERE project_id = NEW.project_id AND skill_id = NEW.skill_id;

        INSERT INTO TeamMembers (project_id, user_id, role)
        VALUES (NEW.project_id, NEW.user_id, 'Team Member');
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `milestones`
--

CREATE TABLE `milestones` (
  `milestone_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `fund_release_amount` decimal(14,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `milestones`
--

INSERT INTO `milestones` (`milestone_id`, `project_id`, `title`, `description`, `due_date`, `status`, `fund_release_amount`) VALUES
(1, 1, 'dhdh', 'scdcdcc', '2026-09-30', 'pending', 0.00),
(2, 6, 'frontend', 'compile this', '2026-09-30', 'completed', 0.00),
(3, 5, 'database', 'create fine databse', '2026-09-26', 'pending', 0.00),
(4, 5, 'backend', 'ccccccc', '2026-09-30', 'pending', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `negotiationmessages`
--

CREATE TABLE `negotiationmessages` (
  `message_id` int(11) NOT NULL,
  `negotiation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `negotiations`
--

CREATE TABLE `negotiations` (
  `negotiation_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('investor','manpower') NOT NULL,
  `proposed_percentage` decimal(5,2) NOT NULL,
  `proposed_salary` decimal(14,2) DEFAULT NULL,
  `proposed_by` enum('owner','party') NOT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `negotiations`
--

INSERT INTO `negotiations` (`negotiation_id`, `project_id`, `user_id`, `role`, `proposed_percentage`, `proposed_salary`, `proposed_by`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'investor', 4.00, 25000.00, 'party', 'pending', '2026-09-22 22:34:20', '2026-09-29 17:25:58'),
(2, 6, 3, 'investor', 2.00, 40000.00, '', 'accepted', '2026-09-24 00:23:44', '2026-09-27 17:46:46'),
(3, 1, 3, 'investor', 15.00, 52000.00, '', 'accepted', '2026-09-24 03:43:30', '2026-09-29 13:29:01');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT 'Notification',
  `message` varchar(255) NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `link` varchar(255) DEFAULT 'wallet.html',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `type`, `link`, `is_read`, `created_at`) VALUES
(1, 3, 'Notification', 'Your profit share of BDT 500 has been credited to your wallet.', 'info', 'wallet.html', 0, '2026-09-22 20:03:33'),
(2, 3, 'Notification', 'New high-yield AI startup project available for investment!', 'info', 'wallet.html', 0, '2026-09-22 20:03:33'),
(3, 1, 'Notification', 'Negotiation terms updated for project ID: #1', 'info', 'wallet.html', 0, '2026-09-22 22:34:20'),
(4, 1, 'Notification', 'Agreement contract synced: CX-AGR-20260923-6593', 'info', 'wallet.html', 0, '2026-09-22 22:50:15'),
(5, 3, 'Notification', 'Negotiation terms updated for project ID: #6', 'info', 'wallet.html', 0, '2026-09-24 00:23:44'),
(6, 3, 'Notification', 'Negotiation terms updated for project ID: #6', 'info', 'wallet.html', 1, '2026-09-24 00:32:35'),
(7, 3, 'Notification', 'Negotiation terms updated for project ID: #6', 'info', 'wallet.html', 0, '2026-09-24 00:54:09'),
(8, 3, 'Notification', 'Agreement contract synced: CX-AGR-20260924-8991', 'info', 'wallet.html', 1, '2026-09-24 01:16:48'),
(9, 3, 'Notification', 'Negotiation terms updated for project ID: #1', 'info', 'wallet.html', 1, '2026-09-24 03:43:30'),
(10, 3, 'Notification', 'Negotiation terms updated for project ID: #6', 'info', 'wallet.html', 1, '2026-09-27 16:37:11'),
(11, 4, 'Notification', 'Escrow funds for milestone \'frontend\' in your project \'smartAgri\' have been released by Admin!', 'info', 'wallet.html', 0, '2026-09-28 18:23:08'),
(12, 3, 'Notification', 'Milestone \'frontend\' for project \'smartAgri\' you invested in is completed and funds released.', 'info', 'wallet.html', 0, '2026-09-28 18:23:08'),
(13, 3, 'Notification', 'Negotiation accepted for \'jjjjj\'! Please sign the agreement.', 'info', 'wallet.html', 0, '2026-09-29 13:29:01'),
(14, 4, 'Notification', 'Negotiation accepted for \'jjjjj\'!', 'info', 'wallet.html', 0, '2026-09-29 13:29:01'),
(15, 3, 'Notification', 'Agreement contract synced: CX-AGR-20260923-6593', 'info', 'wallet.html', 0, '2026-09-29 13:29:08'),
(16, 1, 'Notification', 'Negotiation terms updated for project ID: #1', 'info', 'wallet.html', 0, '2026-09-29 17:25:58');

-- --------------------------------------------------------

--
-- Table structure for table `passwordresets`
--

CREATE TABLE `passwordresets` (
  `reset_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `request_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `platformsettings`
--

CREATE TABLE `platformsettings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `platformsettings`
--

INSERT INTO `platformsettings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('platform_commission_percent', '10.00', '2026-09-21 18:39:45');

-- --------------------------------------------------------

--
-- Table structure for table `profitshares`
--

CREATE TABLE `profitshares` (
  `profit_share_id` int(11) NOT NULL,
  `revenue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `share_percentage` decimal(5,2) NOT NULL CHECK (`share_percentage` >= 0 and `share_percentage` <= 100),
  `share_amount` decimal(14,2) NOT NULL,
  `paid_status` enum('pending','paid') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `profitshares`
--

INSERT INTO `profitshares` (`profit_share_id`, `revenue_id`, `user_id`, `share_percentage`, `share_amount`, `paid_status`) VALUES
(2, 1, 3, 12.00, 6000.00, 'paid');

-- --------------------------------------------------------

--
-- Table structure for table `projectinvitations`
--

CREATE TABLE `projectinvitations` (
  `invitation_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `invited_user_id` int(11) NOT NULL,
  `invited_role` enum('investor','manpower') NOT NULL,
  `status` enum('pending','accepted','rejected','locked_out') DEFAULT 'pending',
  `invited_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responded_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projectinvitations`
--

INSERT INTO `projectinvitations` (`invitation_id`, `project_id`, `invited_user_id`, `invited_role`, `status`, `invited_at`, `responded_at`) VALUES
(1, 3, 5, '', 'pending', '2026-09-23 00:54:33', NULL),
(2, 2, 5, '', 'pending', '2026-09-23 03:19:30', NULL),
(3, 1, 5, '', 'pending', '2026-09-23 03:23:41', NULL),
(4, 1, 5, '', 'pending', '2026-09-23 03:36:31', NULL);

--
-- Triggers `projectinvitations`
--
DELIMITER $$
CREATE TRIGGER `trg_after_invitation_accept` AFTER UPDATE ON `projectinvitations` FOR EACH ROW BEGIN
    IF NEW.status = 'accepted' AND OLD.status <> 'accepted' THEN
        UPDATE ProjectInvitations
        SET status = 'locked_out'
        WHERE project_id = NEW.project_id
          AND invited_role = NEW.invited_role
          AND invitation_id <> NEW.invitation_id
          AND status = 'pending';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `project_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `required_budget` decimal(14,2) NOT NULL CHECK (`required_budget` > 0),
  `collected_budget` decimal(14,2) DEFAULT 0.00,
  `expected_roi` decimal(6,2) DEFAULT 0.00,
  `risk_level` enum('Low','Medium','High') DEFAULT 'Medium',
  `status` enum('open','funding','team_building','negotiating','agreement_pending','in_progress','completed','cancelled') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tagline` varchar(200) DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `admin_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`project_id`, `owner_id`, `title`, `description`, `category`, `required_budget`, `collected_budget`, `expected_roi`, `risk_level`, `status`, `created_at`, `tagline`, `approval_status`, `is_featured`, `admin_note`) VALUES
(1, 4, 'jjjjj', 'uuuuuuuuu', 'AgriTech', 200000.00, 82000.00, 0.00, 'Medium', 'in_progress', '2026-09-22 08:57:25', '', 'pending', 0, NULL),
(2, 1, 'foodhub', 'foooooooooooood', 'E-commerce', 300000.00, 0.00, 0.00, 'Medium', '', '2026-09-22 09:44:04', '', 'rejected', 0, NULL),
(3, 1, 'robotics', 'ddddddddddd', 'EdTech', 563333.00, 64222.00, 0.00, 'Medium', '', '2026-09-22 09:46:23', '', 'approved', 0, NULL),
(4, 4, 'nnnnnn', 'wwdewdcdsd', 'HealthTech', 5566655.00, 220000.00, 0.00, 'Medium', '', '2026-09-22 09:47:13', '', 'approved', 0, NULL),
(5, 4, 'home', 'ffdsdfdsvsdvdsvds', 'HealthTech', 240000.00, 50222.00, 0.00, 'Medium', '', '2026-09-22 23:48:05', 'smart home', 'approved', 0, NULL),
(6, 4, 'smartAgri', 'Its a smart agricultural system Its a smart agricultural system Its a smart agricultural system', 'AgriTech', 600000.00, 40000.00, 0.00, 'Medium', 'open', '2026-09-23 16:56:07', 'Its a smart agricultural system', 'approved', 0, NULL),
(7, 4, 'waste management', 'its a website for waste manage', 'E-commerce', 200000.00, 0.00, 0.00, 'Medium', '', '2026-09-28 17:29:34', 'waste management system', 'pending', 0, NULL),
(8, 8, 'Animal Home', 'solving the daily life problems of the street animals', 'General', 250000.00, 30000.00, 0.00, 'Medium', '', '2026-09-29 17:43:11', 'A social home for street animals', 'pending', 0, NULL),
(9, 8, 'Skill exchange Platform', 'Skill exchanging platforms for all type of skilled students', 'EdTech', 4000000.00, 0.00, 0.00, 'Medium', '', '2026-09-29 17:44:58', 'Use your skill in a perfect and profittable place', 'pending', 0, NULL),
(10, 11, 'Smart Library', 'Making learning more easy and more fun', 'E-commerce', 600000.00, 0.00, 0.00, 'Medium', '', '2026-09-29 17:51:45', 'Smart Library Management System', 'pending', 0, NULL),
(11, 11, 'Lost And Found System', 'Removing the hassle and tension of lost and found staffs', 'General', 250000.00, 45000.00, 0.00, 'Medium', '', '2026-09-29 17:53:29', 'Get your lost things easily', 'pending', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `projectskills`
--

CREATE TABLE `projectskills` (
  `project_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `required_count` int(11) DEFAULT 1,
  `filled_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `revenue`
--

CREATE TABLE `revenue` (
  `revenue_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL CHECK (`amount` >= 0),
  `accounting_period` varchar(20) DEFAULT NULL,
  `source_ref` varchar(150) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `revenue`
--

INSERT INTO `revenue` (`revenue_id`, `project_id`, `amount`, `accounting_period`, `source_ref`, `recorded_at`) VALUES
(1, 3, 50000.00, NULL, NULL, '2026-09-22 18:55:19');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `reviewee_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salaryreleases`
--

CREATE TABLE `salaryreleases` (
  `salary_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL CHECK (`amount` > 0),
  `status` enum('pending','released') DEFAULT 'pending',
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `salaryreleases`
--

INSERT INTO `salaryreleases` (`salary_id`, `project_id`, `user_id`, `amount`, `status`, `released_at`, `created_at`) VALUES
(1, 6, 7, 25000.00, 'released', '2026-09-28 19:32:22', '2026-09-28 19:32:22');

-- --------------------------------------------------------

--
-- Table structure for table `skills`
--

CREATE TABLE `skills` (
  `skill_id` int(11) NOT NULL,
  `skill_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `skills`
--

INSERT INTO `skills` (`skill_id`, `skill_name`) VALUES
(5, 'Accounting/Finance'),
(7, 'Backend Development'),
(6, 'Business Strategy'),
(8, 'DevOps'),
(4, 'Digital Marketing'),
(2, 'Mobile App Development'),
(3, 'UI/UX Design'),
(1, 'Web Development');

-- --------------------------------------------------------

--
-- Table structure for table `teammembers`
--

CREATE TABLE `teammembers` (
  `team_member_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(80) DEFAULT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teammembers`
--

INSERT INTO `teammembers` (`team_member_id`, `project_id`, `user_id`, `role`, `joined_at`) VALUES
(1, 6, 7, 'Team Member', '2026-09-23 20:12:56'),
(2, 5, 7, 'Team Member', '2026-09-28 19:25:10'),
(3, 5, 10, 'Team Member', '2026-09-29 17:36:51');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `wallet_id` int(11) NOT NULL,
  `type` enum('deposit','withdraw','investment','profit_payout','commission','salary_release','escrow_hold','escrow_release') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `reference` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `wallet_id`, `type`, `amount`, `reference`, `created_at`) VALUES
(1, 3, 'escrow_hold', 64222.00, 'Investment held in escrow for project #3', '2026-09-22 15:12:13'),
(2, 3, 'escrow_hold', 100000.00, 'Investment held in escrow for project #4', '2026-09-22 15:30:12'),
(3, 3, '', 6000.00, 'Profit share payout for Project #3', '2026-09-22 18:55:19'),
(4, 3, 'withdraw', 500.00, 'Withdrawal request via Bkash (01700000000)', '2026-09-22 18:58:56'),
(5, 3, 'escrow_hold', 45000.00, 'Investment held in escrow for project #5', '2026-09-23 02:09:42'),
(6, 3, 'escrow_hold', 60000.00, 'Investment held in escrow for project #4', '2026-09-23 02:10:01'),
(7, 3, 'escrow_hold', 5222.00, 'Investment held in escrow for project #5', '2026-09-23 04:27:06'),
(8, 3, 'escrow_hold', 40000.00, 'Investment held in escrow for project #6', '2026-09-23 23:40:41'),
(9, 3, 'escrow_hold', 52000.00, 'Investment held in escrow for project #1', '2026-09-24 03:41:24'),
(10, 9, 'escrow_hold', 30000.00, 'Investment held in escrow for project #1', '2026-09-29 17:20:37'),
(11, 9, 'escrow_hold', 60000.00, 'Investment held in escrow for project #4', '2026-09-29 17:40:20'),
(12, 13, 'escrow_hold', 45000.00, 'Investment held in escrow for project #11', '2026-09-29 17:56:49'),
(13, 13, 'escrow_hold', 30000.00, 'Investment held in escrow for project #8', '2026-09-29 17:57:00'),
(14, 1, 'deposit', 500000.00, 'Deposit via BANK', '2026-09-29 23:49:17'),
(15, 9, 'deposit', 200000.00, 'Deposit via BKASH', '2026-09-29 23:57:42');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `primary_role` enum('idea_owner','investor','manpower') NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `idea_title` varchar(150) DEFAULT NULL,
  `investment_capacity` decimal(14,2) DEFAULT NULL,
  `nid_passport_no` varchar(50) DEFAULT NULL,
  `verification_status` enum('pending','verified','rejected') DEFAULT 'pending',
  `reputation_score` decimal(4,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `risk_appetite` enum('Low','Medium','High') DEFAULT NULL,
  `experience_years` decimal(4,1) DEFAULT NULL,
  `portfolio_url` varchar(255) DEFAULT NULL,
  `nid_document` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `name`, `email`, `password`, `phone`, `profile_picture`, `primary_role`, `is_admin`, `idea_title`, `investment_capacity`, `nid_passport_no`, `verification_status`, `reputation_score`, `created_at`, `risk_appetite`, `experience_years`, `portfolio_url`, `nid_document`, `is_active`, `last_login_at`) VALUES
(1, 'Super Admin', 'admin@collabbx.com', '$2y$10$RZ7beb8FYZXcutpl/EkEw.yXvn5r5D75V1UK9nHJo.OnMtutm/0D6', NULL, NULL, 'idea_owner', 1, NULL, NULL, NULL, 'verified', 0.00, '2026-09-21 20:01:28', NULL, NULL, NULL, NULL, 1, '2026-09-30 03:14:57'),
(2, 'tasnia', 'tasnia@gmail.com', '$2y$10$/3z/POfOy8qYwfMQG2XBpe9QLtdncaK1OMiqGiW26vRneT/9pwXve', '01712345678', NULL, 'idea_owner', 0, 'hellotech', NULL, '', 'pending', 0.00, '2026-09-22 07:29:39', NULL, NULL, NULL, NULL, 1, NULL),
(3, 'prome', 'prome@gmail.com', '$2y$10$k0lHXFFjQ6FfSll5vTPBPeLHP8AXM6gmfMlkR1QSjS2PMwtQ/KO32', '01713676727', NULL, 'investor', 0, '', 1000000.00, '', 'verified', 0.00, '2026-09-22 07:32:06', NULL, NULL, NULL, NULL, 1, '2026-09-29 22:42:02'),
(4, 'Annesha', 'annesha@gmail.com', '$2y$10$c151cAa9a7aLS3ejPKcFmeixMrg4uKUqoaeaR/4J64l7OwvMVSS4i', '01678910666', NULL, 'idea_owner', 0, 'Smart Animation Learning', NULL, '', 'verified', 0.00, '2026-09-22 08:00:08', NULL, NULL, NULL, NULL, 1, '2026-09-30 03:10:42'),
(5, 'adrit', 'adrit@gmail.com', '$2y$10$JnejC6OVbhnWK6zHooXs2e1mJwdopug7uFl2sbdzQdwydUV1doAVC', '01617889910', NULL, 'manpower', 0, '', NULL, '', 'verified', 0.00, '2026-09-22 08:08:04', NULL, NULL, NULL, NULL, 1, NULL),
(6, 'Anika', 'anika@gmail.com', '$2y$10$W8H5dhy8uHCj2rLiqD123.e9Wxbkh01Bk938tvxHnJkYYXYPCmeu.', '01783541777', NULL, 'idea_owner', 0, 'hospital management system', NULL, '', 'pending', 0.00, '2026-09-23 04:11:40', NULL, NULL, NULL, NULL, 1, NULL),
(7, 'brown', 'brown@gmail.com', '$2y$10$EP6T/nyTGpbMATo3X8E94esENY2iTDjbH7XSMa5fV6g1jm4cIUKCm', '01732145678', NULL, 'manpower', 0, '', NULL, '', 'pending', 0.00, '2026-09-23 18:38:22', NULL, NULL, NULL, NULL, 1, '2026-09-29 22:39:26'),
(8, 'mita', 'mita@gmail.com', '$2y$10$3Pn8CywcYBnThHpt5PJniORFsQcIvrKMnWkEE4Gej0SJymqZk7z52', '01783546787', NULL, 'idea_owner', 0, 'Web developer', NULL, '', 'pending', 0.00, '2026-09-29 13:31:44', NULL, NULL, NULL, NULL, 1, '2026-09-29 17:40:46'),
(9, 'mahin', 'mahin@gmail.com', '$2y$10$f37m7wAICRXrdmyQLjXhIuxkoXI2tpFCgRTIC2SFbfdM1WSggfdiO', '01783546786', NULL, 'investor', 0, '', 30000.00, '', 'pending', 0.00, '2026-09-29 13:32:50', NULL, NULL, NULL, NULL, 1, '2026-09-30 05:51:07'),
(10, 'mridul', 'mridul@gmail.com', '$2y$10$3eqI2FegTLjE1iRLs2B.QO5c6ohw4jHrJFOD9c5DXJfSOgKEW1Y.S', '01783546722', NULL, 'manpower', 0, '', NULL, '', 'pending', 0.00, '2026-09-29 13:33:50', NULL, NULL, NULL, NULL, 1, '2026-09-29 17:37:43'),
(11, 'Arnab', 'arnab@gmail.com', '$2y$10$DRND1TRdJgp4W2cYTq6yuODYBGyrUSdYe4.TTsvMUI/76mssuieTa', '01999888666', NULL, 'idea_owner', 0, 'Full stack developer', NULL, '', 'pending', 0.00, '2026-09-29 17:46:50', NULL, NULL, NULL, NULL, 1, '2026-09-29 17:49:29'),
(12, 'Diya', 'diya@gmail.com', '$2y$10$T7i6JVhFU3PocrfdpTSNmeBqCmeU/fegyWWKllKvdSZvtdQiHQ9.6', '01999888999', NULL, 'manpower', 0, '', NULL, '', 'pending', 0.00, '2026-09-29 17:48:02', NULL, NULL, NULL, NULL, 1, '2026-09-29 17:57:21'),
(13, 'Yamin', 'yamin@gmail.com', '$2y$10$LNinb/jt6tlsEEgPcf0OZeGocFOaOJjEC4mGbLfkvzB0W9qng63hS', '01777888999', NULL, 'investor', 0, '', 68000.00, '', 'pending', 0.00, '2026-09-29 17:49:02', NULL, NULL, NULL, NULL, 1, '2026-09-29 17:56:26');

-- --------------------------------------------------------

--
-- Table structure for table `userskills`
--

CREATE TABLE `userskills` (
  `user_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `proficiency_level` enum('Beginner','Intermediate','Expert') DEFAULT 'Intermediate'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_investor_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_investor_summary` (
`user_id` int(11)
,`name` varchar(100)
,`projects_invested_in` bigint(21)
,`total_invested` decimal(36,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_project_funding_progress`
-- (See below for the actual view)
--
CREATE TABLE `vw_project_funding_progress` (
`project_id` int(11)
,`title` varchar(150)
,`required_budget` decimal(14,2)
,`collected_amount` decimal(36,2)
,`funding_progress_percent` decimal(42,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_project_skill_gap`
-- (See below for the actual view)
--
CREATE TABLE `vw_project_skill_gap` (
`project_id` int(11)
,`skill_name` varchar(100)
,`required_count` int(11)
,`filled_count` int(11)
,`skill_gap` bigint(12)
);

-- --------------------------------------------------------

--
-- Table structure for table `wallets`
--

CREATE TABLE `wallets` (
  `wallet_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `balance` decimal(14,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wallets`
--

INSERT INTO `wallets` (`wallet_id`, `user_id`, `balance`) VALUES
(1, 1, 500000.00),
(2, 2, 0.00),
(3, 3, 5500.00),
(4, 4, 0.00),
(5, 5, 0.00),
(6, 6, 0.00),
(7, 7, 0.00),
(8, 8, 0.00),
(9, 9, 200000.00),
(10, 10, 0.00),
(11, 11, 0.00),
(12, 12, 0.00),
(13, 13, 0.00);

-- --------------------------------------------------------

--
-- Structure for view `vw_investor_summary`
--
DROP TABLE IF EXISTS `vw_investor_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_investor_summary`  AS SELECT `u`.`user_id` AS `user_id`, `u`.`name` AS `name`, count(distinct `i`.`project_id`) AS `projects_invested_in`, sum(`i`.`amount`) AS `total_invested` FROM (`users` `u` join `investments` `i` on(`u`.`user_id` = `i`.`investor_id`)) GROUP BY `u`.`user_id`, `u`.`name` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_project_funding_progress`
--
DROP TABLE IF EXISTS `vw_project_funding_progress`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_project_funding_progress`  AS SELECT `p`.`project_id` AS `project_id`, `p`.`title` AS `title`, `p`.`required_budget` AS `required_budget`, ifnull(sum(`i`.`amount`),0) AS `collected_amount`, round(ifnull(sum(`i`.`amount`),0) / `p`.`required_budget` * 100,2) AS `funding_progress_percent` FROM (`projects` `p` left join `investments` `i` on(`p`.`project_id` = `i`.`project_id` and `i`.`status` = 'confirmed')) GROUP BY `p`.`project_id`, `p`.`title`, `p`.`required_budget` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_project_skill_gap`
--
DROP TABLE IF EXISTS `vw_project_skill_gap`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_project_skill_gap`  AS SELECT `ps`.`project_id` AS `project_id`, `s`.`skill_name` AS `skill_name`, `ps`.`required_count` AS `required_count`, `ps`.`filled_count` AS `filled_count`, `ps`.`required_count`- `ps`.`filled_count` AS `skill_gap` FROM (`projectskills` `ps` join `skills` `s` on(`ps`.`skill_id` = `s`.`skill_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `agreementparties`
--
ALTER TABLE `agreementparties`
  ADD PRIMARY KEY (`party_id`),
  ADD UNIQUE KEY `agreement_id` (`agreement_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_agreementparties_agreement` (`agreement_id`);

--
-- Indexes for table `agreements`
--
ALTER TABLE `agreements`
  ADD PRIMARY KEY (`agreement_id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `direct_messages`
--
ALTER TABLE `direct_messages`
  ADD PRIMARY KEY (`message_id`);

--
-- Indexes for table `investments`
--
ALTER TABLE `investments`
  ADD PRIMARY KEY (`investment_id`),
  ADD KEY `idx_investments_project` (`project_id`),
  ADD KEY `idx_investments_investor` (`investor_id`);

--
-- Indexes for table `joinrequests`
--
ALTER TABLE `joinrequests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `skill_id` (`skill_id`),
  ADD KEY `idx_joinrequests_project` (`project_id`);

--
-- Indexes for table `milestones`
--
ALTER TABLE `milestones`
  ADD PRIMARY KEY (`milestone_id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `negotiationmessages`
--
ALTER TABLE `negotiationmessages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `negotiation_id` (`negotiation_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `negotiations`
--
ALTER TABLE `negotiations`
  ADD PRIMARY KEY (`negotiation_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_negotiations_project` (`project_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `passwordresets`
--
ALTER TABLE `passwordresets`
  ADD PRIMARY KEY (`reset_id`),
  ADD UNIQUE KEY `uq_reset_token` (`token_hash`),
  ADD KEY `idx_reset_user` (`user_id`);

--
-- Indexes for table `platformsettings`
--
ALTER TABLE `platformsettings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `profitshares`
--
ALTER TABLE `profitshares`
  ADD PRIMARY KEY (`profit_share_id`),
  ADD KEY `revenue_id` (`revenue_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `projectinvitations`
--
ALTER TABLE `projectinvitations`
  ADD PRIMARY KEY (`invitation_id`),
  ADD KEY `invited_user_id` (`invited_user_id`),
  ADD KEY `idx_invitations_project` (`project_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`project_id`),
  ADD KEY `idx_projects_owner` (`owner_id`),
  ADD KEY `idx_projects_approval` (`approval_status`,`status`);

--
-- Indexes for table `projectskills`
--
ALTER TABLE `projectskills`
  ADD PRIMARY KEY (`project_id`,`skill_id`),
  ADD KEY `skill_id` (`skill_id`);

--
-- Indexes for table `revenue`
--
ALTER TABLE `revenue`
  ADD PRIMARY KEY (`revenue_id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `reviewer_id` (`reviewer_id`),
  ADD KEY `reviewee_id` (`reviewee_id`);

--
-- Indexes for table `salaryreleases`
--
ALTER TABLE `salaryreleases`
  ADD PRIMARY KEY (`salary_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `skills`
--
ALTER TABLE `skills`
  ADD PRIMARY KEY (`skill_id`),
  ADD UNIQUE KEY `skill_name` (`skill_name`);

--
-- Indexes for table `teammembers`
--
ALTER TABLE `teammembers`
  ADD PRIMARY KEY (`team_member_id`),
  ADD UNIQUE KEY `project_id` (`project_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_teammembers_project` (`project_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `wallet_id` (`wallet_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `userskills`
--
ALTER TABLE `userskills`
  ADD PRIMARY KEY (`user_id`,`skill_id`),
  ADD KEY `skill_id` (`skill_id`);

--
-- Indexes for table `wallets`
--
ALTER TABLE `wallets`
  ADD PRIMARY KEY (`wallet_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `agreementparties`
--
ALTER TABLE `agreementparties`
  MODIFY `party_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `agreements`
--
ALTER TABLE `agreements`
  MODIFY `agreement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `direct_messages`
--
ALTER TABLE `direct_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `investments`
--
ALTER TABLE `investments`
  MODIFY `investment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `joinrequests`
--
ALTER TABLE `joinrequests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `milestones`
--
ALTER TABLE `milestones`
  MODIFY `milestone_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `negotiationmessages`
--
ALTER TABLE `negotiationmessages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `negotiations`
--
ALTER TABLE `negotiations`
  MODIFY `negotiation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `passwordresets`
--
ALTER TABLE `passwordresets`
  MODIFY `reset_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `profitshares`
--
ALTER TABLE `profitshares`
  MODIFY `profit_share_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `projectinvitations`
--
ALTER TABLE `projectinvitations`
  MODIFY `invitation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `project_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `revenue`
--
ALTER TABLE `revenue`
  MODIFY `revenue_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salaryreleases`
--
ALTER TABLE `salaryreleases`
  MODIFY `salary_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `skills`
--
ALTER TABLE `skills`
  MODIFY `skill_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `teammembers`
--
ALTER TABLE `teammembers`
  MODIFY `team_member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `wallets`
--
ALTER TABLE `wallets`
  MODIFY `wallet_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `agreementparties`
--
ALTER TABLE `agreementparties`
  ADD CONSTRAINT `agreementparties_ibfk_1` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`agreement_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `agreementparties_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `agreements`
--
ALTER TABLE `agreements`
  ADD CONSTRAINT `agreements_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE;

--
-- Constraints for table `investments`
--
ALTER TABLE `investments`
  ADD CONSTRAINT `investments_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `investments_ibfk_2` FOREIGN KEY (`investor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `joinrequests`
--
ALTER TABLE `joinrequests`
  ADD CONSTRAINT `joinrequests_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `joinrequests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `joinrequests_ibfk_3` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE;

--
-- Constraints for table `milestones`
--
ALTER TABLE `milestones`
  ADD CONSTRAINT `milestones_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE;

--
-- Constraints for table `negotiationmessages`
--
ALTER TABLE `negotiationmessages`
  ADD CONSTRAINT `negotiationmessages_ibfk_1` FOREIGN KEY (`negotiation_id`) REFERENCES `negotiations` (`negotiation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `negotiationmessages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `negotiations`
--
ALTER TABLE `negotiations`
  ADD CONSTRAINT `negotiations_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `negotiations_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `passwordresets`
--
ALTER TABLE `passwordresets`
  ADD CONSTRAINT `passwordresets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `profitshares`
--
ALTER TABLE `profitshares`
  ADD CONSTRAINT `profitshares_ibfk_1` FOREIGN KEY (`revenue_id`) REFERENCES `revenue` (`revenue_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `profitshares_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `projectinvitations`
--
ALTER TABLE `projectinvitations`
  ADD CONSTRAINT `projectinvitations_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `projectinvitations_ibfk_2` FOREIGN KEY (`invited_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `projectskills`
--
ALTER TABLE `projectskills`
  ADD CONSTRAINT `projectskills_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `projectskills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE;

--
-- Constraints for table `revenue`
--
ALTER TABLE `revenue`
  ADD CONSTRAINT `revenue_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`reviewee_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `salaryreleases`
--
ALTER TABLE `salaryreleases`
  ADD CONSTRAINT `salaryreleases_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `salaryreleases_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `teammembers`
--
ALTER TABLE `teammembers`
  ADD CONSTRAINT `teammembers_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `teammembers_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`wallet_id`) ON DELETE CASCADE;

--
-- Constraints for table `userskills`
--
ALTER TABLE `userskills`
  ADD CONSTRAINT `userskills_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `userskills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE;

--
-- Constraints for table `wallets`
--
ALTER TABLE `wallets`
  ADD CONSTRAINT `wallets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
