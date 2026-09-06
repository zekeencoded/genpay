-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 22, 2026 at 03:10 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ewallet`
--

-- --------------------------------------------------------

--
-- Table structure for table `archived_rejections`
--

CREATE TABLE `archived_rejections` (
  `id` int UNSIGNED NOT NULL,
  `original_application_id` int UNSIGNED NOT NULL,
  `rejected_at_step` tinyint UNSIGNED NOT NULL,
  `business_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `proprietor_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `contact_number` varchar(15) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `profile_picture` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `business_permit` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sanitary_permit` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `gjc_requirements` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `clearance` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_general_ci NOT NULL,
  `rejected_by` int UNSIGNED NOT NULL,
  `rejected_at` datetime NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reactivated` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_remember_tokens`
--

CREATE TABLE `auth_remember_tokens` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `selector` char(24) COLLATE utf8mb4_general_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_general_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auth_remember_tokens`
--

INSERT INTO `auth_remember_tokens` (`id`, `user_id`, `selector`, `token_hash`, `expires_at`, `created_at`) VALUES
(22, 1, '9905c98894ec613ee0660bb9', 'a220539ea50077163df51872e3c464b40ec08fe2642e3f1344fa6f7320cc9a41', '2026-08-09 22:50:47', '2026-07-10 22:50:47'),
(59, 1, 'b5909995f40f716113722ede', '5b43bfd628314a1c71ad2fd704d2d11bf0f516ce51fa28dddbde35408ac61592', '2026-08-19 13:20:28', '2026-07-20 13:20:28'),
(60, 16, 'b26ce1cb8042fd6963e212ab', '22544ce35ce08fbe777d36a204179ffb4a008f571e0cd75bd0dcb2c29f3db007', '2026-08-21 23:09:14', '2026-07-22 23:09:14');

-- --------------------------------------------------------

--
-- Table structure for table `cap_increase_log`
--

CREATE TABLE `cap_increase_log` (
  `id` int UNSIGNED NOT NULL,
  `super_admin_id` int UNSIGNED NOT NULL COMMENT 'FK -> users.id -- must be super-admin role',
  `old_cap` decimal(15,2) NOT NULL,
  `new_cap` decimal(15,2) NOT NULL,
  `amount_added` decimal(15,2) NOT NULL,
  `reason` text COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Mandatory justification for audit compliance',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source` enum('super_admin_mint','tuition_credit') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'super_admin_mint',
  `source_ref_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cap_increase_log`
--

INSERT INTO `cap_increase_log` (`id`, `super_admin_id`, `old_cap`, `new_cap`, `amount_added`, `reason`, `created_at`, `source`, `source_ref_id`) VALUES
(1, 7, 0.00, 200000.00, 200000.00, 'Initial system capitalization. Starting circulation cap set to 200,000.00 for S.Y. 2025-2026.', '2026-04-29 10:52:26', 'super_admin_mint', NULL),
(3, 12, 200000.00, 200100.00, 100.00, 'Approved by me', '2026-06-21 20:45:59', 'super_admin_mint', NULL),
(4, 12, 200100.00, 210100.00, 10000.00, 'Trip', '2026-06-21 20:46:32', 'super_admin_mint', NULL),
(5, 16, 210100.00, 250000.00, 39900.00, 'To make it equal', '2026-06-29 23:22:54', 'super_admin_mint', NULL),
(6, 16, 250000.00, 255000.00, 5000.00, 'Tuition-backed GenCoin credit. Waiver ref: WAIVER-42067.', '2026-07-11 22:59:08', 'tuition_credit', 1);

-- --------------------------------------------------------

--
-- Table structure for table `cart_orders`
--

CREATE TABLE `cart_orders` (
  `id` int UNSIGNED NOT NULL,
  `reference_no` varchar(30) COLLATE utf8mb4_general_ci NOT NULL,
  `student_user_id` int UNSIGNED NOT NULL,
  `student_wallet_id` int UNSIGNED NOT NULL,
  `merchant_user_id` int UNSIGNED NOT NULL,
  `merchant_wallet_id` int UNSIGNED NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `items_json` text COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` datetime DEFAULT NULL,
  `paid_ref` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cart_orders`
--

INSERT INTO `cart_orders` (`id`, `reference_no`, `student_user_id`, `student_wallet_id`, `merchant_user_id`, `merchant_wallet_id`, `description`, `items_json`, `amount`, `status`, `created_at`, `paid_at`, `paid_ref`) VALUES
(1, 'CART-20260622-49B503', 1, 2, 29, 13, '1x Calamares (1 cup), 2x Chicken Skin (1pc), 1x Fishball (10pcs), 2x Squidball (10pcs), 4x Kwek-Kwek (6pcs), 6x Tokneneng (4pcs)', '[{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":1,\"stock_qty\":60,\"line_total\":50},{\"id\":26,\"sku\":\"KWK-006\",\"name\":\"Chicken Skin (1pc)\",\"price\":15,\"qty\":2,\"stock_qty\":79,\"line_total\":30},{\"id\":23,\"sku\":\"KWK-003\",\"name\":\"Fishball (10pcs)\",\"price\":20,\"qty\":1,\"stock_qty\":148,\"line_total\":20},{\"id\":24,\"sku\":\"KWK-004\",\"name\":\"Squidball (10pcs)\",\"price\":25,\"qty\":2,\"stock_qty\":120,\"line_total\":50},{\"id\":21,\"sku\":\"KWK-001\",\"name\":\"Kwek-Kwek (6pcs)\",\"price\":35,\"qty\":4,\"stock_qty\":99,\"line_total\":140},{\"id\":22,\"sku\":\"KWK-002\",\"name\":\"Tokneneng (4pcs)\",\"price\":30,\"qty\":6,\"stock_qty\":100,\"line_total\":180}]', 470.00, 'voided', '2026-06-22 22:48:25', NULL, NULL),
(2, 'CART-20260622-FB99CB', 1, 2, 29, 13, '1x Chicken Skin (1pc), 1x Calamares (1 cup), 1x Kikiam (4pcs), 1x Tokneneng (4pcs), 3x Fishball (10pcs), 1x Squidball (10pcs), 1x Soda in Cup (16oz), 2x Bottled Water (500ml), 3x Tapsilog', '[{\"id\":26,\"sku\":\"KWK-006\",\"name\":\"Chicken Skin (1pc)\",\"price\":15,\"qty\":1,\"stock_qty\":79,\"line_total\":15},{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":1,\"stock_qty\":60,\"line_total\":50},{\"id\":25,\"sku\":\"KWK-005\",\"name\":\"Kikiam (4pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":98,\"line_total\":25},{\"id\":22,\"sku\":\"KWK-002\",\"name\":\"Tokneneng (4pcs)\",\"price\":30,\"qty\":1,\"stock_qty\":100,\"line_total\":30},{\"id\":23,\"sku\":\"KWK-003\",\"name\":\"Fishball (10pcs)\",\"price\":20,\"qty\":3,\"stock_qty\":148,\"line_total\":60},{\"id\":24,\"sku\":\"KWK-004\",\"name\":\"Squidball (10pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":120,\"line_total\":25},{\"id\":30,\"sku\":\"KWK-010\",\"name\":\"Soda in Cup (16oz)\",\"price\":20,\"qty\":1,\"stock_qty\":99,\"line_total\":20},{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":2,\"stock_qty\":94,\"line_total\":30},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":3,\"stock_qty\":49,\"line_total\":165}]', 420.00, 'paid', '2026-06-22 22:56:00', '2026-06-22 22:56:35', 'CART-20260622-FB99CB'),
(3, 'CART-20260622-35328D', 1, 2, 29, 13, '1x Kikiam (4pcs), 2x Chicken Skin (1pc), 1x Squidball (10pcs), 2x Calamares (1 cup), 1x Tapsilog, 2x Bottled Water (500ml), 1x Soda in Cup (16oz), 2x Fishball (10pcs), 1x Tokneneng (4pcs), 4x Kwek-Kwek (6pcs)', '[{\"id\":25,\"sku\":\"KWK-005\",\"name\":\"Kikiam (4pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":97,\"line_total\":25},{\"id\":26,\"sku\":\"KWK-006\",\"name\":\"Chicken Skin (1pc)\",\"price\":15,\"qty\":2,\"stock_qty\":78,\"line_total\":30},{\"id\":24,\"sku\":\"KWK-004\",\"name\":\"Squidball (10pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":119,\"line_total\":25},{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":2,\"stock_qty\":59,\"line_total\":100},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":46,\"line_total\":55},{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":2,\"stock_qty\":92,\"line_total\":30},{\"id\":30,\"sku\":\"KWK-010\",\"name\":\"Soda in Cup (16oz)\",\"price\":20,\"qty\":1,\"stock_qty\":98,\"line_total\":20},{\"id\":23,\"sku\":\"KWK-003\",\"name\":\"Fishball (10pcs)\",\"price\":20,\"qty\":2,\"stock_qty\":145,\"line_total\":40},{\"id\":22,\"sku\":\"KWK-002\",\"name\":\"Tokneneng (4pcs)\",\"price\":30,\"qty\":1,\"stock_qty\":99,\"line_total\":30},{\"id\":21,\"sku\":\"KWK-001\",\"name\":\"Kwek-Kwek (6pcs)\",\"price\":35,\"qty\":4,\"stock_qty\":99,\"line_total\":140}]', 495.00, 'paid', '2026-06-22 23:30:23', '2026-06-22 23:30:50', 'CART-20260622-35328D'),
(4, 'CART-20260623-E99CAE', 1, 2, 29, 13, '2x Kwek-Kwek (6pcs), 2x Tokneneng (4pcs), 1x Fishball (10pcs)', '[{\"id\":21,\"sku\":\"KWK-001\",\"name\":\"Kwek-Kwek (6pcs)\",\"price\":35,\"qty\":2,\"stock_qty\":95,\"line_total\":70},{\"id\":22,\"sku\":\"KWK-002\",\"name\":\"Tokneneng (4pcs)\",\"price\":30,\"qty\":2,\"stock_qty\":98,\"line_total\":60},{\"id\":23,\"sku\":\"KWK-003\",\"name\":\"Fishball (10pcs)\",\"price\":20,\"qty\":1,\"stock_qty\":143,\"line_total\":20}]', 150.00, 'voided', '2026-06-23 10:48:51', NULL, NULL),
(5, 'CART-20260623-5C1C63', 1, 2, 29, 13, '1x Kikiam (4pcs)', '[{\"id\":25,\"sku\":\"KWK-005\",\"name\":\"Kikiam (4pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":96,\"line_total\":25}]', 25.00, 'paid', '2026-06-23 10:52:40', '2026-06-23 10:52:58', 'CART-20260623-5C1C63'),
(6, 'CART-20260628-866377', 1, 2, 29, 13, '3x Bottled Water (500ml)', '[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":3,\"stock_qty\":91,\"line_total\":45}]', 45.00, 'paid', '2026-06-28 22:25:06', '2026-06-28 22:25:29', 'CART-20260628-866377'),
(7, 'CART-20260628-5D206E', 1, 2, 29, 13, '2x Chicken Skin (1pc)', '[{\"id\":26,\"sku\":\"KWK-006\",\"name\":\"Chicken Skin (1pc)\",\"price\":15,\"qty\":2,\"stock_qty\":76,\"line_total\":30}]', 30.00, 'paid', '2026-06-28 22:31:30', '2026-06-28 22:31:40', 'CART-20260628-5D206E'),
(8, 'CART-20260628-E9916B', 1, 2, 29, 13, '4x Tapsilog', '[{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":4,\"stock_qty\":45,\"line_total\":220}]', 220.00, 'paid', '2026-06-28 22:33:05', '2026-06-28 22:33:11', 'CART-20260628-E9916B'),
(9, 'CART-20260628-11DAD9', 1, 2, 29, 13, '5x Tapsilog', '[{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":5,\"stock_qty\":41,\"line_total\":275}]', 275.00, 'paid', '2026-06-28 22:33:59', '2026-06-28 22:34:10', 'CART-20260628-11DAD9'),
(10, 'CART-20260628-6B4F83', 1, 2, 29, 13, '2x Tapsilog', '[{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":2,\"stock_qty\":36,\"line_total\":110}]', 110.00, 'paid', '2026-06-28 22:40:37', '2026-06-28 22:40:58', 'CART-20260628-6B4F83'),
(11, 'CART-20260628-6AFF20', 1, 2, 29, 13, '11x Bottled Water (500ml)', '[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":11,\"stock_qty\":88,\"line_total\":165}]', 165.00, 'paid', '2026-06-28 22:52:53', '2026-06-28 22:53:37', 'CART-20260628-6AFF20'),
(12, 'CART-20260628-FB774E', 1, 2, 29, 13, '7x Bottled Water (500ml)', '[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":7,\"stock_qty\":77,\"line_total\":105}]', 105.00, 'voided', '2026-06-28 23:11:26', NULL, NULL),
(13, 'CART-20260709-062AA1', 1, 2, 29, 13, '1x Calamares (1 cup), 1x Tapsilog, 1x Bottled Water (500ml)', '[{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":1,\"stock_qty\":57,\"line_total\":50},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":34,\"line_total\":55},{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":77,\"line_total\":15}]', 120.00, 'paid', '2026-07-09 21:34:51', '2026-07-09 21:35:05', 'CART-20260709-062AA1'),
(14, 'CART-20260710-43AC1E', 1, 2, 29, 13, '1x Bottled Water (500ml), 1x Tapsilog, 1x Smart C', '[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":76,\"line_total\":15},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":33,\"line_total\":55},{\"id\":38,\"sku\":\"DRINK-001\",\"name\":\"Smart C\",\"price\":100,\"qty\":1,\"stock_qty\":9,\"line_total\":100}]', 170.00, 'paid', '2026-07-10 22:28:54', '2026-07-10 22:29:11', 'CART-20260710-43AC1E'),
(15, 'CART-20260712-C1CD1F', 1, 2, 29, 13, '2x Bottled Water (500ml), 1x Calamares (1 cup), 1x Kwek-Kwek (6pcs), 1x Smart C', '[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":2,\"stock_qty\":74,\"line_total\":30},{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":1,\"stock_qty\":55,\"line_total\":50},{\"id\":21,\"sku\":\"KWK-001\",\"name\":\"Kwek-Kwek (6pcs)\",\"price\":35,\"qty\":1,\"stock_qty\":93,\"line_total\":35},{\"id\":38,\"sku\":\"DRINK-001\",\"name\":\"Smart C\",\"price\":100,\"qty\":1,\"stock_qty\":7,\"line_total\":100}]', 215.00, 'paid', '2026-07-12 22:12:37', '2026-07-12 22:12:54', 'CART-20260712-C1CD1F'),
(16, 'CART-20260717-F53D53', 1, 2, 29, 13, '1x Bottled Water (500ml), 1x Tapsilog, 1x Smart C', '[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":72,\"line_total\":15},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":31,\"line_total\":55},{\"id\":38,\"sku\":\"DRINK-001\",\"name\":\"Smart C\",\"price\":100,\"qty\":1,\"stock_qty\":5,\"line_total\":100}]', 170.00, 'paid', '2026-07-17 23:49:34', '2026-07-17 23:49:42', 'CART-20260717-F53D53'),
(17, 'CART-20260720-AAAE7C', 1, 2, 29, 13, '1x Bottled Water (500ml)', '[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":71,\"line_total\":15}]', 15.00, 'paid', '2026-07-20 13:53:52', '2026-07-20 13:54:59', 'CART-20260720-AAAE7C');

-- --------------------------------------------------------

--
-- Table structure for table `course`
--

CREATE TABLE `course` (
  `courseID` int NOT NULL,
  `course_code` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `course_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course`
--

INSERT INTO `course` (`courseID`, `course_code`, `course_name`) VALUES
(1, 'BSA', 'Bachelor of Science in Accountancy'),
(2, 'BSMA', 'Bachelor of Science in Management Accounting'),
(3, 'BSAIS', 'Bachelor of Science in Accounting Information Systems'),
(4, 'BSIA', 'Bachelor of Science in Internal Auditing'),
(5, 'BSBA-FM', 'BSBA major in Financial Management'),
(6, 'BSBA-MM', 'BSBA major in Marketing Management'),
(7, 'BSBA-HRM', 'BSBA major in Human Resource Management'),
(8, 'BSBA-OM', 'BSBA major in Operations Management'),
(9, 'BS-ENTREP', 'Bachelor of Science in Entrepreneurship'),
(10, 'BSOA', 'Bachelor of Science in Office Administration'),
(11, 'BSIT', 'Bachelor of Science in Information Technology'),
(12, 'BEED', 'Bachelor of Elementary Education'),
(13, 'BSED-ENG', 'Bachelor of Secondary Education major in English'),
(14, 'BSED-MATH', 'Bachelor of Secondary Education major in Mathematics'),
(15, 'BSED-SCI', 'Bachelor of Secondary Education major in Science'),
(16, 'BPED', 'Bachelor of Physical Education'),
(17, 'BSHM', 'Bachelor of Science in Hospitality Management'),
(18, 'BACHELOROFSCIENCEINCOMPUTERSCIENCE', 'Bachelor of Science in Computer Science'),
(19, 'BACHELOROFSCIENCEINDATASCIENCE', 'Bachelor of Science in Data Science'),
(20, 'BACHELOROFARTSINPSYCHOLOGY', 'Bachelor of Arts in Psychology'),
(21, 'BACHELOROFSCIENCEINBUSINESSADMINISTRATION', 'Bachelor of Science in Business Administration'),
(22, 'BSCS', 'BSCS'),
(23, 'BSTM', 'BSTM');

-- --------------------------------------------------------

--
-- Table structure for table `encashment_requests`
--

CREATE TABLE `encashment_requests` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `merchant_wallet_id` int UNSIGNED DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `method` varchar(80) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Cashier Release',
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `reference_no` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `released_by` int UNSIGNED DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `rejected_by` int UNSIGNED DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fee_revenue_log`
--

CREATE TABLE `fee_revenue_log` (
  `id` int UNSIGNED NOT NULL,
  `transaction_ref` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `top_up_source` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `cash_amount` decimal(15,2) NOT NULL,
  `system_fee` decimal(15,2) NOT NULL,
  `merchant_fee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `merchant_wallet_id` int UNSIGNED DEFAULT NULL,
  `processed_by` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_revenue_log`
--

INSERT INTO `fee_revenue_log` (`id`, `transaction_ref`, `top_up_source`, `cash_amount`, `system_fee`, `merchant_fee`, `merchant_wallet_id`, `processed_by`, `created_at`) VALUES
(1, 'TXN-20260626-64911', 'merchant', 10.00, 0.20, 0.10, 13, 29, '2026-06-26 23:57:39'),
(2, 'TXN-20260626-71955', 'merchant', 60.00, 1.20, 0.60, 13, 29, '2026-06-27 00:13:16'),
(3, 'TXN-20260626-78829', 'merchant', 200.00, 4.00, 2.00, 13, 29, '2026-06-27 00:55:06'),
(4, 'TXN-20260628-84982', 'merchant', 200.00, 4.00, 2.00, 13, 29, '2026-06-28 21:25:14'),
(5, 'TXN-20260710-65759', 'merchant', 1000.00, 2.00, 1.00, 13, 29, '2026-07-10 22:17:40'),
(6, 'TXN-20260712-65525', 'finance', 10000.00, 20.00, 0.00, NULL, 16, '2026-07-12 21:58:52'),
(7, 'TXN-20260712-38941', 'finance', 5000.00, 10.00, 0.00, NULL, 16, '2026-07-12 22:01:16'),
(8, 'TXN-20260712-04896', 'merchant', 360.00, 0.72, 0.36, 13, 29, '2026-07-12 22:04:49'),
(9, 'TXN-20260712-56978', 'merchant', 200.00, 0.40, 0.20, 13, 29, '2026-07-12 22:21:12'),
(10, 'TXN-20260712-10269', 'merchant', 500.00, 1.00, 0.50, 13, 29, '2026-07-12 22:21:49'),
(11, 'TXN-20260717-48086', 'finance', 100.00, 0.20, 0.00, NULL, 16, '2026-07-17 23:19:05'),
(12, 'TXN-20260717-84944', 'finance', 50.00, 0.10, 0.00, NULL, 16, '2026-07-17 23:19:05'),
(13, 'TXN-20260717-72374', 'merchant', 40.00, 0.08, 0.04, 13, 16, '2026-07-17 23:34:05'),
(14, 'TXN-20260717-12079', 'finance', 25.00, 0.05, 0.00, NULL, 16, '2026-07-17 23:34:05'),
(15, 'TXN-20260717-87875', 'merchant', 1000.00, 2.00, 1.00, 13, 29, '2026-07-17 23:36:14');

-- --------------------------------------------------------

--
-- Table structure for table `fee_waiver_credits`
--

CREATE TABLE `fee_waiver_credits` (
  `id` int UNSIGNED NOT NULL,
  `student_user_id` int UNSIGNED NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('empty','pending','posted') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'empty',
  `waiver_file` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_waiver_credits`
--

INSERT INTO `fee_waiver_credits` (`id`, `student_user_id`, `amount`, `status`, `waiver_file`, `created_at`, `updated_at`) VALUES
(1, 1, 5000.00, 'posted', 'uploads/fee_waiver_credits/1/waiver_17838263485214.png', '2026-07-12 11:09:37', '2026-07-12 11:19:08'),
(2, 2, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(3, 5, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(4, 6, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(5, 10, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(6, 23, 5000.00, 'posted', 'uploads/fee_waiver_credits/6/waiver_17838615216703.pdf', '2026-07-12 11:09:37', '2026-07-12 21:05:21'),
(7, 247, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(8, 249, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(9, 251, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(10, 253, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(11, 255, 9000.00, 'pending', NULL, '2026-07-12 11:09:37', '2026-07-12 12:39:34'),
(12, 257, 5000.00, 'posted', 'uploads/fee_waiver_credits/12/waiver_17838279777706.pdf', '2026-07-12 11:09:37', '2026-07-12 11:46:17'),
(13, 258, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(14, 260, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(15, 261, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(16, 263, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(17, 265, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(18, 267, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(19, 268, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(20, 270, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(21, 272, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(22, 282, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(23, 283, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(24, 285, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(25, 287, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(26, 288, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(27, 290, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(28, 291, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(29, 293, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(30, 295, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(31, 297, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(32, 299, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(33, 301, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(34, 303, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(35, 305, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(36, 307, 5000.00, 'posted', 'uploads/fee_waiver_credits/36/waiver_17838277317052.jpg', '2026-07-12 11:09:37', '2026-07-12 11:42:11'),
(37, 309, 5000.00, 'posted', 'uploads/fee_waiver_credits/37/waiver_17838279062507.pdf', '2026-07-12 11:09:37', '2026-07-12 11:45:06'),
(38, 310, 5000.00, 'posted', 'uploads/fee_waiver_credits/38/waiver_17838292428696.pdf', '2026-07-12 11:09:37', '2026-07-12 12:07:22'),
(39, 312, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(40, 314, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(41, 316, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(42, 318, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(43, 319, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(44, 321, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(45, 322, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(46, 324, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(47, 325, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(48, 326, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(49, 327, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(50, 328, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(51, 330, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(52, 332, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(53, 334, 5000.00, 'pending', NULL, '2026-07-12 11:09:37', '2026-07-12 13:02:59'),
(54, 336, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(55, 338, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(56, 339, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(57, 341, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(58, 342, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(59, 344, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(60, 346, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(61, 348, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(62, 349, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(63, 351, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(64, 353, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(65, 355, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(66, 357, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(67, 359, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(68, 361, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(69, 363, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(70, 365, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(71, 367, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(72, 369, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(73, 371, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(74, 373, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(75, 375, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(76, 377, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(77, 379, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(78, 381, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(79, 383, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(80, 385, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(81, 386, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(82, 388, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(83, 389, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(84, 391, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(85, 393, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(86, 395, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(87, 397, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(88, 399, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(89, 400, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(90, 402, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(91, 404, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(92, 406, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(93, 408, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(94, 409, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(95, 411, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(96, 413, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(97, 415, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(98, 417, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(99, 419, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(100, 421, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(101, 423, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(102, 425, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(103, 427, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(104, 428, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(105, 430, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(106, 432, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(107, 434, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(108, 436, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(109, 437, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(110, 439, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(111, 440, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(112, 442, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(113, 443, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(114, 445, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(115, 447, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(116, 448, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(117, 450, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(118, 451, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(119, 452, 5000.00, 'pending', NULL, '2026-07-12 11:09:37', '2026-07-12 12:32:20'),
(120, 453, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(121, 455, NULL, 'empty', NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(122, 464, NULL, 'empty', NULL, '2026-07-14 09:48:29', '2026-07-14 09:48:29'),
(123, 465, NULL, 'empty', NULL, '2026-07-14 09:48:29', '2026-07-14 09:48:29'),
(124, 466, NULL, 'empty', NULL, '2026-07-14 09:48:29', '2026-07-14 09:48:29'),
(125, 467, NULL, 'empty', NULL, '2026-07-14 09:48:29', '2026-07-14 09:48:29'),
(126, 468, NULL, 'empty', NULL, '2026-07-14 09:48:30', '2026-07-14 09:48:30');

-- --------------------------------------------------------

--
-- Table structure for table `fee_waiver_credit_logs`
--

CREATE TABLE `fee_waiver_credit_logs` (
  `id` int UNSIGNED NOT NULL,
  `fee_waiver_credit_id` int UNSIGNED NOT NULL,
  `old_status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `new_status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `changed_by_user_id` int UNSIGNED NOT NULL,
  `changed_by_role` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_waiver_credit_logs`
--

INSERT INTO `fee_waiver_credit_logs` (`id`, `fee_waiver_credit_id`, `old_status`, `new_status`, `amount`, `changed_by_user_id`, `changed_by_role`, `changed_at`) VALUES
(1, 36, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 11:10:30'),
(2, 36, 'pending', 'empty', NULL, 16, 'finance', '2026-07-12 11:11:04'),
(3, 1, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 11:11:18'),
(4, 1, 'pending', 'posted', 5000.00, 16, 'finance', '2026-07-12 11:19:08'),
(5, 36, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 11:41:46'),
(6, 36, 'pending', 'posted', 5000.00, 16, 'finance', '2026-07-12 11:42:11'),
(7, 37, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 11:45:00'),
(8, 37, 'pending', 'posted', 5000.00, 16, 'finance', '2026-07-12 11:45:06'),
(9, 38, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 11:45:42'),
(10, 12, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 11:46:09'),
(11, 12, 'pending', 'posted', 5000.00, 16, 'finance', '2026-07-12 11:46:17'),
(12, 38, 'pending', 'empty', NULL, 16, 'finance', '2026-07-12 11:47:05'),
(13, 38, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 12:07:14'),
(14, 38, 'pending', 'posted', 5000.00, 16, 'finance', '2026-07-12 12:07:22'),
(15, 11, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 12:17:08'),
(16, 11, 'pending', 'empty', NULL, 16, 'finance', '2026-07-12 12:32:04'),
(17, 119, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 12:32:20'),
(18, 11, 'empty', 'pending', 9000.00, 16, 'finance', '2026-07-12 12:39:34'),
(19, 53, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 13:02:59'),
(20, 6, 'empty', 'pending', 5000.00, 16, 'finance', '2026-07-12 21:04:22'),
(21, 6, 'pending', 'posted', 5000.00, 16, 'finance', '2026-07-12 21:05:21');

-- --------------------------------------------------------

--
-- Table structure for table `imported_student_registry`
--

CREATE TABLE `imported_student_registry` (
  `id` int UNSIGNED NOT NULL,
  `import_batch_id` varchar(14) COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `student_id_number` varchar(80) COLLATE utf8mb4_general_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `course_program` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `phone_number` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_email` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_contact` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_user_id` int DEFAULT NULL,
  `parent_status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'none',
  `import_status` enum('imported','duplicate','failed') COLLATE utf8mb4_general_ci NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `imported_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `imported_student_registry`
--

INSERT INTO `imported_student_registry` (`id`, `import_batch_id`, `user_id`, `student_id_number`, `first_name`, `last_name`, `course_program`, `email`, `phone_number`, `parent_name`, `parent_email`, `parent_contact`, `parent_user_id`, `parent_status`, `import_status`, `message`, `imported_by`, `created_at`) VALUES
(126, '20260704135220', 247, 'GJC2026-2006', 'Juan Miguel', 'Dela Cruz', 'BSIT', 'juanmiguel.delacruz@student.gjc.edu.ph', '9171234570', 'Maria Dela Cruz', 'maria.delacruz@gmail.com', '9181234570', 248, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:20'),
(127, '20260704135220', 249, 'GJC2026-2007', 'Andrea Nicole', 'Villanueva', 'BSIT', 'andreanicole.villanueva@student.gjc.edu.ph', '9182345671', 'Roberto Villanueva', 'rvillanueva@yahoo.com', '9192345671', 250, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:21'),
(128, '20260704135220', 251, 'GJC2026-2008', 'Carlos', 'Peñaflor', 'BSA', 'carlos.penaflor@student.gjc.edu.ph', '9293456782', 'Lourdes Peñaflor', 'lpenaflor@gmail.com', '9203456782', 252, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:21'),
(129, '20260704135220', 253, 'GJC2026-2009', 'Sofia Isabel', 'Mendoza', 'BSA', 'sofiaisabel.mendoza@student.gjc.edu.ph', '9304567893', '', 'kujo7397@gmail.com', '', 254, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:21'),
(130, '20260704135220', 255, 'GJC2026-2010', 'Joshua', 'Aquino', 'BSHM', 'joshua.aquino@student.gjc.edu.ph', '9344567894', 'Grace Aquino', 'grace.aquino@gmail.com', '9354567894', 256, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:21'),
(131, '20260704135220', 257, 'GJC2026-2011', 'Angelica Mae', 'Aquino', 'BSED-ENG', 'angelicamae.aquino@student.gjc.edu.ph', '9344567895', 'Grace Aquino', 'grace.aquino@gmail.com', '9354567894', 256, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:21'),
(132, '20260704135220', 258, 'GJC2026-2012', 'Mark Anthony', 'Santiago', 'BSBA-MM', 'markanthony.santiago@student.gjc.edu.ph', '9455678906', 'Elena Santiago', 'elena.santiago@outlook.com', '9465678906', 259, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:21'),
(133, '20260704135220', 260, 'GJC2026-2013', 'Kristine Joy', 'Ocampo', 'BSED-MATH', 'kristinejoy.ocampo@student.gjc.edu.ph', '9455678907', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-04 13:52:21'),
(134, '20260704135220', 261, 'GJC2026-2014', 'Rafael', 'Manalo', 'BSIT', 'rafael.manalo@student.gjc.edu.ph', '9566789018', 'Antonio Manalo', 'tony.manalo@gmail.com', '6.39577E+11', 262, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:22'),
(135, '20260704135220', 263, 'GJC2026-2015', 'Bianca', 'Salazar', 'BSHM', 'bianca.salazar@student.gjc.edu.ph', '9677890129', 'Carmen Salazar', 'carmen.salazar@gmail.com', '9687890129', 264, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:22'),
(136, '20260704135220', 265, 'GJC2026-2016', 'John Paul', 'Domingo', 'BSBA-FM', 'johnpaul.domingo@student.gjc.edu.ph', '9788901230', 'Teresa Domingo', 'tdomingo@gmail.com', '9798901230', 266, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:22'),
(137, '20260704135220', 267, 'GJC2026-2017', 'Camille', 'Ignacio', 'BSED-ENG', 'camille.ignacio@student.gjc.edu.ph', '9899012341', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-04 13:52:22'),
(138, '20260704135220', 268, 'GJC2026-2018', 'Gabriel', 'Muñoz', 'BSA', 'gabriel.munoz@student.gjc.edu.ph', '9910123452', 'Ricardo Muñoz', 'ric.munoz@yahoo.com', '9920123452', 269, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:22'),
(139, '20260704135220', 270, 'GJC2026-2019', 'Patricia Anne', 'Del Rosario', 'BSBA-MM', 'patriciaanne.delrosario@student.gjc.edu.ph', '9021234563', 'Josefina Del Rosario', 'josie.delrosario@gmail.com', '9031234563', 271, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-04 13:52:22'),
(140, '20260704135220', 272, 'GJC2026-2020', 'Lorenzo', 'Trinidad', 'BSIT', 'lorenzo.trinidad@student.gjc.edu.ph', '9132345674', 'Ramon Trinidad', '', '9142345674', NULL, 'skipped', 'imported', 'Student account imported. Guardian skipped — parent_email is required to create a guardian account.', 16, '2026-07-04 13:52:23'),
(141, '20260707034410', 282, 'GJC2026-2021', 'Bianca', 'Ignacio', 'BSA', 'bianca.ignacio@student.gjc.edu.ph', '09680402501', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:10'),
(142, '20260707034410', 283, 'GJC2026-2022', 'Ryan', 'Valdez', 'BEED', 'ryan.valdez@student.gjc.edu.ph', '09831067988', 'Fernando Valdez', 'fernandovaldez76@yahoo.com', '09868759358', 284, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:11'),
(143, '20260707034410', 285, 'GJC2026-2023', 'Nathaniel', 'Peñaflor', 'BSBA-MM', 'nathaniel.penaflor@student.gjc.edu.ph', '09139656872', 'Fernando Peñaflor', 'fernandopenaflor@gmail.com', '09633229661', 286, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:11'),
(144, '20260707034410', 287, 'GJC2026-2024', 'Frances', 'Peñaflor', 'BSED-MATH', 'frances.penaflor@student.gjc.edu.ph', '09409572486', 'Fernando Peñaflor', 'fernandopenaflor@gmail.com', '09633229661', 286, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:11'),
(145, '20260707034410', 288, 'GJC2026-2025', 'Rafael', 'Bautista', 'BSA', 'rafael.bautista@student.gjc.edu.ph', '09652614498', 'Marilou Bautista', 'mariloubautista69@yahoo.com', '09653542596', 289, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:12'),
(146, '20260707034410', 290, 'GJC2026-2026', 'Francis', 'Gutierrez', 'BEED', 'francis.gutierrez@student.gjc.edu.ph', '09904077145', 'Dolores Gutierrez', '', '09954139822', NULL, 'skipped', 'imported', 'Student account imported. Guardian skipped — parent_email is required to create a guardian account.', 16, '2026-07-07 03:44:12'),
(147, '20260707034410', 291, 'GJC2026-2027', 'Diana', 'Rosales', 'BSCS', 'diana.rosales@student.gjc.edu.ph', '09219547810', 'Carmen Rosales', 'carmenrosales70@outlook.com', '09507000352', 292, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:12'),
(148, '20260707034410', 293, 'GJC2026-2028', 'Adrian', 'Diaz', 'BSBA-MM', 'adrian.diaz@student.gjc.edu.ph', '09142403927', 'Priscilla Diaz', 'priscilladiaz94@gmail.com', '09294850989', 294, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:13'),
(149, '20260707034410', 295, 'GJC2026-2029', 'Paolo', 'Diaz', 'BSBA-MM', 'paolo.diaz@student.gjc.edu.ph', '09500653263', 'Imelda Diaz', 'imeldadiaz72@outlook.com', '09190156218', 296, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:13'),
(150, '20260707034410', 297, 'GJC2026-2030', 'Jasmine', 'Bautista', 'BSTM', 'jasmine.bautista@student.gjc.edu.ph', '09269111526', 'Josefina Bautista', 'josefinabautista59@yahoo.com', '09224539856', 298, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:13'),
(151, '20260707034410', 299, 'GJC2026-2031', 'Nicole', 'Domingo', 'BSHM', 'nicole.domingo@student.gjc.edu.ph', '09403990366', 'Priscilla Domingo', 'priscilladomingo34@outlook.com', '09540089585', 300, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:14'),
(152, '20260707034410', 301, 'GJC2026-2032', 'Clarisse', 'Marquez', 'BSCS', 'clarisse.marquez@student.gjc.edu.ph', '09388263207', 'Rosario Marquez', 'rosariomarquez80@outlook.com', '09882718828', 302, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:14'),
(153, '20260707034410', 303, 'GJC2026-2033', 'Lorraine', 'Soriano', 'BSBA-MM', 'lorraine.soriano@student.gjc.edu.ph', '09306338733', 'Ernesto Soriano', 'ernestosoriano69@yahoo.com', '09535270957', 304, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:14'),
(154, '20260707034410', 305, 'GJC2026-2034', 'Christian', 'Pascual', 'BSTM', 'christian.pascual@student.gjc.edu.ph', '09660923810', 'Marilou Pascual', 'mariloupascual17@gmail.com', '09644249710', 306, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:15'),
(155, '20260707034410', 307, 'GJC2026-2035', 'Colleen', 'Agustin', 'BSA', 'colleen.agustin@student.gjc.edu.ph', '09728645911', 'Priscilla Agustin', 'priscillaagustin@gmail.com', '09748573316', 308, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:15'),
(156, '20260707034410', 309, 'GJC2026-2036', 'Jerome', 'Agustin', 'BSTM', 'jerome.agustin@student.gjc.edu.ph', '09959867585', 'Priscilla Agustin', 'priscillaagustin@gmail.com', '09748573316', 308, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:15'),
(157, '20260707034410', 310, 'GJC2026-2037', 'Mark Anthony', 'Alcantara', 'BSED-ENG', 'markanthony.alcantara@student.gjc.edu.ph', '09956873037', 'Fernando Alcantara', 'fernandoalcantara34@gmail.com', '09131243408', 311, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:16'),
(158, '20260707034410', 312, 'GJC2026-2038', 'Colleen', 'Lopez', 'BSED-MATH', 'colleen.lopez@student.gjc.edu.ph', '09237378200', 'Imelda Lopez', 'imeldalopez70@outlook.com', '09595604812', 313, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:16'),
(159, '20260707034410', 314, 'GJC2026-2039', 'Ana Sofia', 'Navarro', 'BSED-ENG', 'anasofia.navarro@student.gjc.edu.ph', '09370661786', 'Roberto Navarro', 'robertonavarro84@gmail.com', '09167189103', 315, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:16'),
(160, '20260707034410', 316, 'GJC2026-2040', 'Elijah', 'Espino', 'BEED', 'elijah.espino@student.gjc.edu.ph', '09288082463', 'Lourdes Espino', 'lourdesespino69@yahoo.com', '09115669748', 317, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:17'),
(161, '20260707034410', 318, 'GJC2026-2041', 'Katrina', 'Torres', 'BSBA-MM', 'katrina.torres@student.gjc.edu.ph', '09354547372', 'Carmen Torres', '', '09452474024', NULL, 'skipped', 'imported', 'Student account imported. Guardian skipped — parent_email is required to create a guardian account.', 16, '2026-07-07 03:44:17'),
(162, '20260707034410', 319, 'GJC2026-2042', 'Lorenzo', 'Ramos', 'BSCS', 'lorenzo.ramos@student.gjc.edu.ph', '09102439281', 'Ramon Ramos', 'ramonramos16@outlook.com', '09210529561', 320, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:17'),
(163, '20260707034410', 321, 'GJC2026-2043', 'Jasmine', 'Villegas', 'BSIT', 'jasmine.villegas@student.gjc.edu.ph', '09189025456', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:17'),
(164, '20260707034410', 322, 'GJC2026-2044', 'Diana', 'Castro', 'BSBA-MM', 'diana.castro@student.gjc.edu.ph', '09976694569', 'Fernando Castro', 'fernandocastro39@outlook.com', '09004522774', 323, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:17'),
(165, '20260707034410', 324, 'GJC2026-2045', 'Andrea', 'Mendoza', 'BSBA-FM', 'andrea.mendoza@student.gjc.edu.ph', '09589443425', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:18'),
(166, '20260707034410', 325, 'GJC2026-2046', 'Isabella', 'Rosales', 'BSED-ENG', 'isabella.rosales@student.gjc.edu.ph', '09760268507', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:18'),
(167, '20260707034410', 326, 'GJC2026-2047', 'Angelica Mae', 'Espino', 'BSHM', 'angelicamae.espino@student.gjc.edu.ph', '09469156403', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:18'),
(168, '20260707034410', 327, 'GJC2026-2048', 'Christian', 'Sarmiento', 'BSHM', 'christian.sarmiento@student.gjc.edu.ph', '09362985433', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:18'),
(169, '20260707034410', 328, 'GJC2026-2049', 'Jasmine', 'Soriano', 'BSBA-MM', 'jasmine.soriano@student.gjc.edu.ph', '09994184752', 'Maria Soriano', 'mariasoriano85@outlook.com', '09840677304', 329, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:19'),
(170, '20260707034410', 330, 'GJC2026-2050', 'Emmanuel', 'Hernandez', 'BSED-ENG', 'emmanuel.hernandez@student.gjc.edu.ph', '09431652707', 'Marilou Hernandez', 'marilouhernandez19@yahoo.com', '09785684437', 331, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:19'),
(171, '20260707034410', 332, 'GJC2026-2051', 'Ivan', 'Castro', 'BSTM', 'ivan.castro@student.gjc.edu.ph', '09337780069', 'Corazon Castro', 'corazoncastro84@gmail.com', '09461493668', 333, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:19'),
(172, '20260707034410', 334, 'GJC2026-2052', 'Trisha', 'Aquino', 'BSBA-FM', 'trisha.aquino@student.gjc.edu.ph', '09468425099', 'Grace Aquino', 'graceaquino98@outlook.com', '09611636452', 335, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:20'),
(173, '20260707034410', 336, 'GJC2026-2053', 'Marco', 'Diaz', 'BSED-MATH', 'marco.diaz@student.gjc.edu.ph', '09212776106', 'Fernando Diaz', 'fernandodiaz39@gmail.com', '09879978972', 337, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:20'),
(174, '20260707034410', 338, 'GJC2026-2054', 'Bea', 'Cruz', 'BSED-MATH', 'bea.cruz@student.gjc.edu.ph', '09702711595', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:20'),
(175, '20260707034410', 339, 'GJC2026-2055', 'Benedict', 'Peñaflor', 'BSBA-MM', 'benedict.penaflor@student.gjc.edu.ph', '09879983705', 'Ernesto Peñaflor', 'ernestopenaflor@gmail.com', '09199928840', 340, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:20'),
(176, '20260707034410', 341, 'GJC2026-2056', 'Samantha', 'Peñaflor', 'BSBA-FM', 'samantha.penaflor@student.gjc.edu.ph', '09589120859', 'Ernesto Peñaflor', 'ernestopenaflor@gmail.com', '09199928840', 340, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:21'),
(177, '20260707034410', 342, 'GJC2026-2057', 'Lorraine', 'Mendoza', 'BSBA-FM', 'lorraine.mendoza@student.gjc.edu.ph', '09034980659', 'Fernando Mendoza', 'fernandomendoza90@yahoo.com', '09655786869', 343, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:21'),
(178, '20260707034410', 344, 'GJC2026-2058', 'Ana Sofia', 'Pascual', 'BSBA-FM', 'anasofia.pascual@student.gjc.edu.ph', '09718137502', 'Grace Pascual', 'gracepascual59@gmail.com', '09283190811', 345, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:21'),
(179, '20260707034410', 346, 'GJC2026-2059', 'Trisha', 'Lopez', 'BSHM', 'trisha.lopez@student.gjc.edu.ph', '09026539546', 'Imelda Lopez', 'imeldalopez9@yahoo.com', '09098417483', 347, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:22'),
(180, '20260707034410', 348, 'GJC2026-2060', 'Elijah', 'Legaspi', 'BSTM', 'elijah.legaspi@student.gjc.edu.ph', '09724234404', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:22'),
(181, '20260707034410', 349, 'GJC2026-2061', 'Rafael', 'Mercado', 'BSA', 'rafael.mercado@student.gjc.edu.ph', '09526218375', 'Corazon Mercado', 'corazonmercado61@gmail.com', '+63948949023', 350, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:22'),
(182, '20260707034410', 351, 'GJC2026-2062', 'Erica', 'Marquez', 'BSHM', 'erica.marquez@student.gjc.edu.ph', '09377500166', 'Ernesto Marquez', 'ernestomarquez80@outlook.com', '09160790534', 352, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:22'),
(183, '20260707034410', 353, 'GJC2026-2063', 'Vincent', 'Sarmiento', 'BSED-ENG', 'vincent.sarmiento@student.gjc.edu.ph', '09525633744', 'Evangeline Sarmiento', 'evangelinesarmiento57@yahoo.com', '09032123071', 354, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:23'),
(184, '20260707034410', 355, 'GJC2026-2064', 'Veronica', 'Torres', 'BSTM', 'veronica.torres@student.gjc.edu.ph', '09593788211', 'Arturo Torres', 'arturotorres52@outlook.com', '09440872706', 356, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:23'),
(185, '20260707034410', 357, 'GJC2026-2065', 'Andrea', 'Gutierrez', 'BSBA-FM', 'andrea.gutierrez@student.gjc.edu.ph', '09088654578', 'Roberto Gutierrez', 'robertogutierrez23@outlook.com', '09043205340', 358, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:24'),
(186, '20260707034410', 359, 'GJC2026-2066', 'Ivan', 'Espino', 'BSED-MATH', 'ivan.espino@student.gjc.edu.ph', '09006276983', 'Ramon Espino', 'ramonespino78@yahoo.com', '09563937606', 360, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:24'),
(187, '20260707034410', 361, 'GJC2026-2067', 'Jerome', 'Ocampo', 'BSBA-MM', 'jerome.ocampo@student.gjc.edu.ph', '09210817880', 'Josefina Ocampo', 'josefinaocampo84@outlook.com', '09906503937', 362, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:24'),
(188, '20260707034410', 363, 'GJC2026-2068', 'Aaron', 'Zamora', 'BSCS', 'aaron.zamora@student.gjc.edu.ph', '09423598812', 'Evangeline Zamora', 'evangelinezamora49@gmail.com', '09745384839', 364, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:25'),
(189, '20260707034410', 365, 'GJC2026-2069', 'Emmanuel', 'Mercado', 'BSED-MATH', 'emmanuel.mercado@student.gjc.edu.ph', '09712861740', 'Grace Mercado', 'gracemercado92@outlook.com', '09005293098', 366, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:25'),
(190, '20260707034410', 367, 'GJC2026-2070', 'Maria Angelica', 'Trinidad', 'BSA', 'mariaangelica.trinidad@student.gjc.edu.ph', '09539575080', 'Elena Trinidad', 'elenatrinidad71@outlook.com', '09565720351', 368, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:25'),
(191, '20260707034410', 369, 'GJC2026-2071', 'Samantha', 'Trinidad', 'BSIT', 'samantha.trinidad@student.gjc.edu.ph', '09333840628', 'Lourdes Trinidad', 'lourdestrinidad9@yahoo.com', '09941179027', 370, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:26'),
(192, '20260707034410', 371, 'GJC2026-2072', 'Veronica', 'Buenaventura', 'BSED-ENG', 'veronica.buenaventura@student.gjc.edu.ph', '09144796467', 'Grace Buenaventura', 'gracebuenaventura48@yahoo.com', '09068530636', 372, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:26'),
(193, '20260707034410', 373, 'GJC2026-2073', 'Miguel', 'Sarmiento', 'BSBA-MM', 'miguel.sarmiento@student.gjc.edu.ph', '09901591772', 'Lourdes Sarmiento', 'lourdessarmiento66@outlook.com', '09669375536', 374, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:26'),
(194, '20260707034410', 375, 'GJC2026-2074', 'Paolo', 'Legaspi', 'BSBA-MM', 'paolo.legaspi@student.gjc.edu.ph', '09724354918', 'Cecilia Legaspi', 'cecilialegaspi26@gmail.com', '09522177132', 376, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:27'),
(195, '20260707034410', 377, 'GJC2026-2075', 'Bea', 'Pascual', 'BSBA-MM', 'bea.pascual@student.gjc.edu.ph', '09398826513', 'Imelda Pascual', 'imeldapascual62@outlook.com', '09571588531', 378, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:27'),
(196, '20260707034410', 379, 'GJC2026-2076', 'Isabella', 'Soriano', 'BSED-ENG', 'isabella.soriano@student.gjc.edu.ph', '09200442637', 'Arturo Soriano', 'arturosoriano75@gmail.com', '09256085632', 380, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:27'),
(197, '20260707034410', 381, 'GJC2026-2077', 'Veronica', 'Navarro', 'BSCS', 'veronica.navarro@student.gjc.edu.ph', '09095923048', 'Elena Navarro', 'elenanavarro98@yahoo.com', '09989711948', 382, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:28'),
(198, '20260707034410', 383, 'GJC2026-2078', 'Hazel', 'Hernandez', 'BSED-MATH', 'hazel.hernandez@student.gjc.edu.ph', '09715501344', 'Maria Hernandez', 'mariahernandez14@yahoo.com', '09586610395', 384, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:28'),
(199, '20260707034410', 385, 'GJC2026-2079', 'Colleen', 'Soriano', 'BEED', 'colleen.soriano@student.gjc.edu.ph', '09336855545', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:28'),
(200, '20260707034410', 386, 'GJC2026-2080', 'Justin', 'Santos', 'BSA', 'justin.santos@student.gjc.edu.ph', '09144425648', 'Corazon Santos', 'corazonsantos15@yahoo.com', '09516681609', 387, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:29'),
(201, '20260707034410', 388, 'GJC2026-2081', 'Ana Sofia', 'Estrella', 'BSBA-MM', 'anasofia.estrella@student.gjc.edu.ph', '09594394556', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:29'),
(202, '20260707034410', 389, 'GJC2026-2082', 'Nathaniel', 'Pineda', 'BSHM', 'nathaniel.pineda@student.gjc.edu.ph', '09227342425', 'Danilo Pineda', 'danilopineda34@outlook.com', '09597255833', 390, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:29'),
(203, '20260707034410', 391, 'GJC2026-2083', 'Cristina', 'Legaspi', 'BSIT', 'cristina.legaspi@student.gjc.edu.ph', '09905579918', 'Grace Legaspi', 'gracelegaspi79@outlook.com', '09594984193', 392, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:29'),
(204, '20260707034410', 393, 'GJC2026-2084', 'Pauline', 'Reyes', 'BSHM', 'pauline.reyes@student.gjc.edu.ph', '09032616644', 'Eduardo Reyes', 'eduardoreyes64@gmail.com', '09498411142', 394, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:30'),
(205, '20260707034410', 395, 'GJC2026-2085', 'Isabella', 'Pascual', 'BSBA-FM', 'isabella.pascual@student.gjc.edu.ph', '09329449379', 'Antonio Pascual', 'antoniopascual18@yahoo.com', '09013458731', 396, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:30'),
(206, '20260707034410', 397, 'GJC2026-2086', 'Ronald', 'Sarmiento', 'BSCS', 'ronald.sarmiento@student.gjc.edu.ph', '09145563020', 'Rogelio Sarmiento', 'rogeliosarmiento32@outlook.com', '09135125058', 398, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:30'),
(207, '20260707034410', 399, 'GJC2026-2087', 'Regine', 'Mendoza', 'BSED-MATH', 'regine.mendoza@student.gjc.edu.ph', '09750894211', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:31'),
(208, '20260707034410', 400, 'GJC2026-2088', 'Nathaniel', 'Buenaventura', 'BSBA-MM', 'nathaniel.buenaventura@student.gjc.edu.ph', '09411870669', 'Lourdes Buenaventura', 'lourdesbuenaventura49@yahoo.com', '09720026558', 401, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:31'),
(209, '20260707034410', 402, 'GJC2026-2089', 'Maria Angelica', 'Del Rosario', 'BSTM', 'mariaangelica.delrosario@student.gjc.edu.ph', '09230989644', 'Grace Del Rosario', 'gracedelrosario77@gmail.com', '09295578289', 403, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:31'),
(210, '20260707034410', 404, 'GJC2026-2090', 'Lorraine', 'Sarmiento', 'BSBA-MM', 'lorraine.sarmiento@student.gjc.edu.ph', '09036008562', 'Rodolfo Sarmiento', 'rodolfosarmiento41@outlook.com', '09951226980', 405, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:32'),
(211, '20260707034410', 406, 'GJC2026-2091', 'Veronica', 'Rivera', 'BSED-MATH', 'veronica.rivera@student.gjc.edu.ph', '09934521295', 'Dolores Rivera', 'doloresrivera2@gmail.com', '09353254094', 407, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:32'),
(212, '20260707034410', 408, 'GJC2026-2092', 'Paolo', 'Ocampo', 'BSIT', 'paolo.ocampo@student.gjc.edu.ph', '09580800160', 'Teresa Ocampo', '', '09417174956', NULL, 'skipped', 'imported', 'Student account imported. Guardian skipped — parent_email is required to create a guardian account.', 16, '2026-07-07 03:44:32'),
(213, '20260707034410', 409, 'GJC2026-2093', 'Juan Carlos', 'Domingo', 'BSBA-FM', 'juancarlos.domingo@student.gjc.edu.ph', '09823345926', 'Lourdes Domingo', 'lourdesdomingo8@outlook.com', '09564919526', 410, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:33'),
(214, '20260707034410', 411, 'GJC2026-2094', 'Isabella', 'Buenaventura', 'BSA', 'isabella.buenaventura@student.gjc.edu.ph', '09625618021', 'Nestor Buenaventura', 'nestorbuenaventura98@gmail.com', '+63905028840', 412, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:33'),
(215, '20260707034410', 413, 'GJC2026-2095', 'Abigail', 'Pascual', 'BSBA-FM', 'abigail.pascual@student.gjc.edu.ph', '09742531537', 'Josefina Pascual', 'josefinapascual21@outlook.com', '09048379370', 414, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:33'),
(216, '20260707034410', 415, 'GJC2026-2096', 'Frances', 'Del Rosario', 'BSIT', 'frances.delrosario@student.gjc.edu.ph', '09230999513', 'Roberto Del Rosario', 'robertodelrosario35@gmail.com', '09455105578', 416, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:34'),
(217, '20260707034410', 417, 'GJC2026-2097', 'Angelica Mae', 'Fernandez', 'BSHM', 'angelicamae.fernandez@student.gjc.edu.ph', '09366413376', 'Elena Fernandez', 'elenafernandez9@gmail.com', '09936833889', 418, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:34'),
(218, '20260707034410', 419, 'GJC2026-2098', 'Elijah', 'Del Rosario', 'BSED-ENG', 'elijah.delrosario@student.gjc.edu.ph', '09094459657', 'Ricardo Del Rosario', 'ricardodelrosario51@yahoo.com', '09687446267', 420, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:34'),
(219, '20260707034410', 421, 'GJC2026-2099', 'Dennis', 'Valdez', 'BSTM', 'dennis.valdez@student.gjc.edu.ph', '09194290873', 'Roberto Valdez', 'robertovaldez14@gmail.com', '09255554375', 422, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:35'),
(220, '20260707034410', 423, 'GJC2026-2100', 'Michelle', 'Domingo', 'BSED-ENG', 'michelle.domingo@student.gjc.edu.ph', '09378440227', 'Lourdes Domingo', 'lourdesdomingo61@gmail.com', '09211592416', 424, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:35'),
(221, '20260707034410', 425, 'GJC2026-2101', 'Dennis', 'Mendoza', 'BSED-ENG', 'dennis.mendoza@student.gjc.edu.ph', '09653868726', 'Arturo Mendoza', 'arturomendoza@gmail.com', '09181342856', 426, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:35'),
(222, '20260707034410', 427, 'GJC2026-2102', 'Hazel', 'Mendoza', 'BSHM', 'hazel.mendoza@student.gjc.edu.ph', '09321313554', 'Arturo Mendoza', 'arturomendoza@gmail.com', '09181342856', 426, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:35'),
(223, '20260707034410', 428, 'GJC2026-2103', 'Marco', 'Valdez', 'BSIT', 'marco.valdez@student.gjc.edu.ph', '09251477511', 'Nestor Valdez', 'nestorvaldez23@outlook.com', '09644741572', 429, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:36'),
(224, '20260707034410', 430, 'GJC2026-2104', 'Ronald', 'Mercado', 'BEED', 'ronald.mercado@student.gjc.edu.ph', '09122032537', 'Rogelio Mercado', 'rogeliomercado6@outlook.com', '09818276864', 431, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:36'),
(225, '20260707034410', 432, 'GJC2026-2105', 'Alvin', 'Peñaflor', 'BSED-ENG', 'alvin.penaflor@student.gjc.edu.ph', '09529393077', 'Rodolfo Peñaflor', 'rodolfopenaflor87@gmail.com', '09831663077', 433, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:36'),
(226, '20260707034410', 434, 'GJC2026-2106', 'Michelle', 'Tolentino', 'BSED-ENG', 'michelle.tolentino@student.gjc.edu.ph', '09261619262', 'Lourdes Tolentino', 'lourdestolentino9@outlook.com', '09706809149', 435, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:36'),
(227, '20260707034410', 436, 'GJC2026-2107', 'Jerome', 'Buenaventura', 'BSA', 'jerome.buenaventura@student.gjc.edu.ph', '09713817719', 'Eduardo Buenaventura', '', '09540318642', NULL, 'skipped', 'imported', 'Student account imported. Guardian skipped — parent_email is required to create a guardian account.', 16, '2026-07-07 03:44:37'),
(228, '20260707034410', 437, 'GJC2026-2108', 'Miguel', 'Padilla', 'BSIT', 'miguel.padilla@student.gjc.edu.ph', '09863557208', 'Evangeline Padilla', 'evangelinepadilla84@gmail.com', '09393120566', 438, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:37'),
(229, '20260707034410', 439, 'GJC2026-2109', 'Katrina', 'Castro', 'BSCS', 'katrina.castro@student.gjc.edu.ph', '09608033384', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:37'),
(230, '20260707034410', 440, 'GJC2026-2110', 'Hazel', 'Manalo', 'BSED-MATH', 'hazel.manalo@student.gjc.edu.ph', '09368606780', 'Rogelio Manalo', 'rogeliomanalo10@gmail.com', '09465100991', 441, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:37'),
(231, '20260707034410', 442, 'GJC2026-2111', 'Pauline', 'Diaz', 'BEED', 'pauline.diaz@student.gjc.edu.ph', '09637717719', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:37'),
(232, '20260707034410', 443, 'GJC2026-2112', 'Diana', 'Roque', 'BSBA-MM', 'diana.roque@student.gjc.edu.ph', '09708003919', 'Lourdes Roque', 'lourdesroque17@outlook.com', '09434532157', 444, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:38'),
(233, '20260707034410', 445, 'GJC2026-2113', 'Cristina', 'Estrella', 'BSBA-FM', 'cristina.estrella@student.gjc.edu.ph', '09723997757', 'Rosario Estrella', 'rosarioestrella29@yahoo.com', '09488730432', 446, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:38'),
(234, '20260707034410', 447, 'GJC2026-2114', 'Juan Carlos', 'Lopez', 'BSTM', 'juancarlos.lopez@student.gjc.edu.ph', '09388906871', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:38'),
(235, '20260707034410', 448, 'GJC2026-2115', 'Samantha', 'Del Rosario', 'BSCS', 'samantha.delrosario@student.gjc.edu.ph', '09900180549', 'Rosario Del Rosario', 'rosariodelrosario@gmail.com', '09409709857', 449, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:39'),
(236, '20260707034410', 450, 'GJC2026-2116', 'Paolo', 'Del Rosario', 'BSBA-FM', 'paolo.delrosario@student.gjc.edu.ph', '09893834291', 'Rosario Del Rosario', 'rosariodelrosario@gmail.com', '09409709857', 449, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:39'),
(237, '20260707034410', 451, 'GJC2026-2117', 'Regine', 'Villanueva', 'BSED-ENG', 'regine.villanueva@student.gjc.edu.ph', '09562904472', 'Antonio Villanueva', '', '09837628179', NULL, 'skipped', 'imported', 'Student account imported. Guardian skipped — parent_email is required to create a guardian account.', 16, '2026-07-07 03:44:39'),
(238, '20260707034410', 452, 'GJC2026-2118', 'Gabriel', 'Bautista', 'BSIT', 'gabriel.bautista@student.gjc.edu.ph', '09891836652', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-07 03:44:39'),
(239, '20260707034410', 453, 'GJC2026-2119', 'Miguel', 'Santos', 'BEED', 'miguel.santos@student.gjc.edu.ph', '09224502118', 'Carmen Santos', 'carmensantos83@yahoo.com', '09434392416', 454, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:39'),
(240, '20260707034410', 455, 'GJC2026-2120', 'Bianca', 'Tolentino', 'BSBA-FM', 'bianca.tolentino@student.gjc.edu.ph', '09577795939', 'Marilou Tolentino', 'mariloutolentino3@yahoo.com', '09709579916', 456, 'created', 'imported', 'Student account imported. Guardian created & linked.', 16, '2026-07-07 03:44:40'),
(241, '20260714014829', 464, '2026-0001', 'John', 'Doe', 'Bachelor of Science in Computer Science', 'john.doe@example.com', '+1-555-0101', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-14 01:48:29'),
(242, '20260714014829', 465, '2026-0002', 'Jane', 'Smith', 'Bachelor of Science in Information Technology', 'jane.smith@example.com', '+1-555-0102', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-14 01:48:29'),
(243, '20260714014829', 466, '2026-0003', 'Michael', 'Johnson', 'Bachelor of Science in Data Science', 'michael.j@example.com', '+1-555-0103', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-14 01:48:29'),
(244, '20260714014829', 467, '2026-0004', 'Emily', 'Davis', 'Bachelor of Arts in Psychology', 'emily.davis@example.com', '+1-555-0104', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-14 01:48:29'),
(245, '20260714014829', 468, '2026-0005', 'David', 'Martinez', 'Bachelor of Science in Business Administration', 'david.m@example.com', '+1-555-0105', '', '', '', NULL, 'none', 'imported', 'Student account imported.', 16, '2026-07-14 01:48:30');

-- --------------------------------------------------------

--
-- Table structure for table `meeting_holidays`
--

CREATE TABLE `meeting_holidays` (
  `id` int UNSIGNED NOT NULL,
  `holiday_date` date NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meeting_holidays`
--

INSERT INTO `meeting_holidays` (`id`, `holiday_date`, `name`, `created_by`, `created_at`) VALUES
(1, '2026-12-25', 'Christmas', 16, '2026-06-25 20:08:32');

-- --------------------------------------------------------

--
-- Table structure for table `meeting_settings`
--

CREATE TABLE `meeting_settings` (
  `id` tinyint UNSIGNED NOT NULL,
  `default_location` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'GJC Finance Office',
  `updated_by` int UNSIGNED DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `down_payment_default_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `wallet_inactivity_days` smallint UNSIGNED NOT NULL DEFAULT '30'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meeting_settings`
--

INSERT INTO `meeting_settings` (`id`, `default_location`, `updated_by`, `updated_at`, `down_payment_default_amount`, `wallet_inactivity_days`) VALUES
(1, 'GJC Finance Office', 16, '2026-06-26 21:21:47', 1000.00, 30);

-- --------------------------------------------------------

--
-- Table structure for table `merchant`
--

CREATE TABLE `merchant` (
  `merchantID` int NOT NULL,
  `userID` int NOT NULL,
  `stall_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `stall_id` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'FK -> stalls.stall_id',
  `operational_status` enum('active','temporarily_closed','suspended','inactive') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `notes` text COLLATE utf8mb4_general_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `merchant`
--

INSERT INTO `merchant` (`merchantID`, `userID`, `stall_name`, `stall_id`, `operational_status`, `created_at`, `updated_at`, `notes`) VALUES
(14, 29, 'Kikiam Ni Baste', 'B1', 'active', '2026-06-20 22:52:28', '2026-07-10 22:30:40', NULL),
(15, 35, 'Lolo Goyo\'s School Supplies', 'A2', 'active', '2026-06-23 00:26:30', '2026-06-23 00:26:30', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `merchant_accounts`
--

CREATE TABLE `merchant_accounts` (
  `id` int UNSIGNED NOT NULL,
  `application_id` int UNSIGNED NOT NULL COMMENT 'FK -> stall_applications.id',
  `user_id` int UNSIGNED NOT NULL COMMENT 'FK -> users.userID (the new merchant account)',
  `temp_password_plain` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Plaintext temp password - cleared after merchant changes password',
  `created_by` int UNSIGNED NOT NULL COMMENT 'FK -> users.userID (admin who executed Final Approval)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit bridge: stall application -> users account created on Final Approval';

-- --------------------------------------------------------

--
-- Table structure for table `merchant_applications`
--

CREATE TABLE `merchant_applications` (
  `id` int UNSIGNED NOT NULL,
  `business_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `owner_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `owner_email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `owner_contact` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `stall_number` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `product_types` text COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Comma-separated list of products to be sold',
  `stage` enum('submitted','compliance_review','exec_review','approved','rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'submitted',
  `compliance_notes` text COLLATE utf8mb4_general_ci,
  `exec_notes` text COLLATE utf8mb4_general_ci,
  `compliance_by` int UNSIGNED DEFAULT NULL COMMENT 'Admin who did compliance review',
  `compliance_at` datetime DEFAULT NULL,
  `exec_by` int UNSIGNED DEFAULT NULL COMMENT 'Super Admin who did exec sign-off',
  `exec_at` datetime DEFAULT NULL,
  `approved_by` int UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int UNSIGNED DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_general_ci,
  `generated_user_id` int UNSIGNED DEFAULT NULL COMMENT 'FK -> users.userID â€” set when account is auto-created on approval',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Multi-stage merchant onboarding pipeline with compliance and exec sign-off';

--
-- Dumping data for table `merchant_applications`
--

INSERT INTO `merchant_applications` (`id`, `business_name`, `owner_name`, `owner_email`, `owner_contact`, `stall_number`, `product_types`, `stage`, `compliance_notes`, `exec_notes`, `compliance_by`, `compliance_at`, `exec_by`, `exec_at`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, `rejection_reason`, `generated_user_id`, `created_at`, `updated_at`) VALUES
(1, 'Microsoft Corporation', 'Chienna Mae Gamboa', 'monicaemata118@gmail.com', '09614708712', NULL, 'Fries', 'submitted', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-10 00:04:39', '2026-06-10 00:04:39');

-- --------------------------------------------------------

--
-- Table structure for table `merchant_application_product_types`
--

CREATE TABLE `merchant_application_product_types` (
  `id` int UNSIGNED NOT NULL,
  `merchant_application_id` int UNSIGNED NOT NULL,
  `product_type` varchar(120) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `merchant_application_product_types`
--

INSERT INTO `merchant_application_product_types` (`id`, `merchant_application_id`, `product_type`) VALUES
(1, 1, 'Fries');

-- --------------------------------------------------------

--
-- Table structure for table `merchant_card_views`
--

CREATE TABLE `merchant_card_views` (
  `merchant_id` int NOT NULL,
  `last_viewed_at` datetime NOT NULL,
  `viewed_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `merchant_card_views`
--

INSERT INTO `merchant_card_views` (`merchant_id`, `last_viewed_at`, `viewed_by`) VALUES
(14, '2026-07-20 13:50:11', 16),
(15, '2026-07-19 22:10:17', 16);

-- --------------------------------------------------------

--
-- Table structure for table `merchant_inventory`
--

CREATE TABLE `merchant_inventory` (
  `id` int UNSIGNED NOT NULL,
  `merchant_user_id` int UNSIGNED NOT NULL COMMENT 'FK -> users.userID (Merchant Admin)',
  `sku` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Optional stock-keeping unit code',
  `product_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `category` varchar(60) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'general',
  `unit` varchar(30) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'piece' COMMENT 'piece, pack, bottle, kg, litre, etc.',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stock_qty` int NOT NULL DEFAULT '0',
  `min_stock_alert` int NOT NULL DEFAULT '5' COMMENT 'Low-stock warning threshold',
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `is_restricted` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Set to 1 if matched against restricted_products',
  `restriction_note` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `approved_by` int UNSIGNED DEFAULT NULL COMMENT 'FK -> users.userID (admin who cleared item)',
  `restricted_by` int UNSIGNED DEFAULT NULL COMMENT 'FK -> users.userID (admin who restricted the item)',
  `restricted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Per-merchant detailed product catalog with restriction checking';

--
-- Dumping data for table `merchant_inventory`
--

INSERT INTO `merchant_inventory` (`id`, `merchant_user_id`, `sku`, `product_name`, `description`, `category`, `unit`, `price`, `stock_qty`, `min_stock_alert`, `is_available`, `is_restricted`, `restriction_note`, `approved_by`, `restricted_by`, `restricted_at`, `created_at`, `updated_at`) VALUES
(21, 29, 'KWK-001', 'Kwek-Kwek (6pcs)', 'Orange-battered deep-fried quail eggs on a stick', 'street food', 'order', 35.00, 92, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-06-21 23:42:13', '2026-07-12 22:12:54'),
(22, 29, 'KWK-002', 'Tokneneng (4pcs)', 'Battered and deep-fried chicken/pork skin egg', 'street food', 'order', 30.00, 97, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-06-21 23:42:13', '2026-07-12 22:10:43'),
(23, 29, 'KWK-003', 'Fishball (10pcs)', 'Classic deep-fried fishballs with sauce', 'street food', 'order', 20.00, 92, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-06-21 23:42:13', '2026-07-12 23:23:59'),
(25, 29, 'KWK-005', 'Kikiam (4pcs)', 'Deep-fried Filipino-Chinese meat roll', 'street food', 'order', 25.00, 93, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-06-21 23:42:13', '2026-07-12 22:11:15'),
(26, 29, 'KWK-006', 'Chicken Skin (1pc)', 'Crispy deep-fried chicken skin', 'street food', 'order', 15.00, 72, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-06-21 23:42:13', '2026-07-12 23:23:12'),
(27, 29, 'KWK-007', 'Calamares (1 cup)', 'Crispy fried squid rings with vinegar dip', 'street food', 'cup', 50.00, 54, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-06-21 23:42:13', '2026-07-12 22:12:54'),
(28, 29, 'RICE01', 'Tapsilog', 'Sweet tapa with sunny side egg', 'food', 'serving', 55.00, 30, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-06-21 23:42:13', '2026-07-17 23:49:42'),
(29, 29, 'KWK-009', 'Bottled Water (500ml)', 'Chilled bottled water', 'beverage', 'bottle', 15.00, 70, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-06-21 23:42:13', '2026-07-20 13:54:59'),
(38, 29, 'DRINK-001', 'Smart C', 'Siomai', 'snack', 'piece', 100.00, 4, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-07-09 21:07:56', '2026-07-17 23:49:42'),
(39, 29, 'RM-001', 'Bubble Gum', 'Smart', 'food', 'pack', 15.00, 75, 5, 1, 0, NULL, NULL, NULL, NULL, '2026-07-11 20:50:51', '2026-07-12 22:10:43');

-- --------------------------------------------------------

--
-- Table structure for table `merchant_leases`
--

CREATE TABLE `merchant_leases` (
  `id` int UNSIGNED NOT NULL,
  `merchant_user_id` int UNSIGNED NOT NULL COMMENT 'FK -> users.userID (Merchant Admin)',
  `stall_number` varchar(30) COLLATE utf8mb4_general_ci NOT NULL,
  `stall_id` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'FK -> stalls.stall_id - NULL for pre-registry leases',
  `stall_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `monthly_rent` decimal(15,2) NOT NULL,
  `deposit_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `lease_start` date NOT NULL,
  `lease_end` date NOT NULL,
  `next_due_date` date NOT NULL,
  `status` enum('active','expired','terminated','pending') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `contract_notes` text COLLATE utf8mb4_general_ci,
  `created_by` int UNSIGNED NOT NULL COMMENT 'FK -> users.userID (admin who created)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Institutional vendor stall lease contracts';

--
-- Dumping data for table `merchant_leases`
--

INSERT INTO `merchant_leases` (`id`, `merchant_user_id`, `stall_number`, `stall_id`, `stall_name`, `monthly_rent`, `deposit_amount`, `lease_start`, `lease_end`, `next_due_date`, `status`, `contract_notes`, `created_by`, `created_at`, `updated_at`) VALUES
(8, 29, 'B1', 'B1', 'Kikiam ni Baste', 2500.00, 0.00, '2026-06-20', '2027-06-20', '2026-09-30', 'active', 'Backfilled 2026-07-10 for a merchant onboarded before the application workflow. Payment every 30th. No deposit/advance was recorded at onboarding.', 7, '2026-07-10 10:38:37', '2026-07-10 10:44:13'),
(9, 35, 'A2', 'A2', 'Lolo Goyo\'s School Supplies', 2500.00, 0.00, '2026-06-23', '2027-06-23', '2026-09-30', 'active', 'Backfilled 2026-07-10 for a merchant onboarded before the application workflow. Payment every 30th. No deposit/advance was recorded at onboarding.', 7, '2026-07-10 10:38:37', '2026-07-10 10:58:50');

-- --------------------------------------------------------

--
-- Table structure for table `merchant_qr_orders`
--

CREATE TABLE `merchant_qr_orders` (
  `id` int UNSIGNED NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `short_code` varchar(12) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `merchant_user_id` int UNSIGNED NOT NULL,
  `merchant_wallet_id` int UNSIGNED NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `items_json` text COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `paid_by` int UNSIGNED DEFAULT NULL,
  `paid_ref` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `merchant_qr_orders`
--

INSERT INTO `merchant_qr_orders` (`id`, `token`, `short_code`, `merchant_user_id`, `merchant_wallet_id`, `description`, `items_json`, `amount`, `status`, `expires_at`, `paid_by`, `paid_ref`, `paid_at`, `created_at`) VALUES
(4, '2a09d4dfeb18f2628e8d9e5b69386ae8', NULL, 29, 13, '1x Kwek-Kwek (6pcs), 1x Kikiam (4pcs), 1x Chicken Skin (1pc), 1x Sauce Refill (cup), 1x Soda in Cup (16oz)', '[{\"id\":21,\"name\":\"Kwek-Kwek (6pcs)\",\"qty\":1,\"price\":35},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25},{\"id\":26,\"name\":\"Chicken Skin (1pc)\",\"qty\":1,\"price\":15},{\"id\":28,\"name\":\"Sauce Refill (cup)\",\"qty\":1,\"price\":5},{\"id\":30,\"name\":\"Soda in Cup (16oz)\",\"qty\":1,\"price\":20}]', 100.00, 'paid', '2026-06-21 23:59:35', 1, 'POS-20260621-C75BAD', '2026-06-21 23:44:51', '2026-06-21 23:44:35'),
(5, '782a26fd1fbdd5cd5c50548b516b011d', NULL, 29, 13, '2x Fishball (10pcs), 1x Kikiam (4pcs)', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":2,\"price\":20},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25}]', 65.00, 'paid', '2026-06-22 00:08:48', 1, 'POS-20260621-2E4533', '2026-06-21 23:53:57', '2026-06-21 23:53:48'),
(6, 'a30d079a3313be7766fddf41cfde77ab', NULL, 29, 13, '1x Kikiam (4pcs), 1x Bottled Water (500ml), 1x Soda in Cup (16oz)', '[{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25},{\"id\":29,\"name\":\"Bottled Water (500ml)\",\"qty\":1,\"price\":15},{\"id\":30,\"name\":\"Soda in Cup (16oz)\",\"qty\":1,\"price\":20}]', 60.00, 'voided', '2026-06-22 20:35:15', NULL, NULL, NULL, '2026-06-22 20:20:15'),
(7, '72ca1c460e9522e4149f202e62dc3d43', NULL, 29, 13, '1x Fishball (10pcs), 1x Sauce Refill (cup), 1x Bottled Water (500ml)', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20},{\"id\":28,\"name\":\"Sauce Refill (cup)\",\"qty\":1,\"price\":5},{\"id\":29,\"name\":\"Bottled Water (500ml)\",\"qty\":1,\"price\":15}]', 40.00, 'expired', '2026-06-22 21:11:48', NULL, NULL, NULL, '2026-06-22 20:56:48'),
(8, '904bdf51f8644e799b44b3777676e2b3', NULL, 29, 13, '1x Fishball (10pcs), 1x Kikiam (4pcs)', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25}]', 45.00, 'expired', '2026-06-22 21:32:42', NULL, NULL, NULL, '2026-06-22 21:17:42'),
(9, '24f31639148eac6aca30ac44ac335840', NULL, 29, 13, '4x Kwek-Kwek (6pcs), 2x Kikiam (4pcs), 2x Chicken Skin (1pc), 1x Calamares (1 cup), 1x Tapsilog', '[{\"id\":21,\"name\":\"Kwek-Kwek (6pcs)\",\"qty\":4,\"price\":35},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":2,\"price\":25},{\"id\":26,\"name\":\"Chicken Skin (1pc)\",\"qty\":2,\"price\":15},{\"id\":27,\"name\":\"Calamares (1 cup)\",\"qty\":1,\"price\":50},{\"id\":28,\"name\":\"Tapsilog\",\"qty\":1,\"price\":55}]', 325.00, 'expired', '2026-06-28 15:24:36', NULL, NULL, NULL, '2026-06-28 23:09:36'),
(10, 'bdd9a711f084f1662e5713e157ad9816', 'ADB97TYX', 29, 13, '2x Fishball (10pcs), 4x Kikiam (4pcs), 1x Calamares (1 cup), 2x Tapsilog, 2x Smart C', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":2,\"price\":20},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":4,\"price\":25},{\"id\":27,\"name\":\"Calamares (1 cup)\",\"qty\":1,\"price\":50},{\"id\":28,\"name\":\"Tapsilog\",\"qty\":2,\"price\":55},{\"id\":38,\"name\":\"Smart C\",\"qty\":2,\"price\":100}]', 500.00, 'expired', '2026-07-10 10:27:07', NULL, NULL, NULL, '2026-07-10 18:22:07'),
(11, 'd99ae555f6484273bc2ba5b85b3c50e3', '8W642B4U', 29, 13, '1x Fishball (10pcs)', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20}]', 20.00, 'paid', '2026-07-10 22:01:18', 2, 'POS-20260710-E0588A', '2026-07-10 21:56:34', '2026-07-10 21:56:18'),
(14, '2ee44539dbd20a73f4cc5ba71bd8d91a', 'B3BN3HM7', 29, 13, '1x Fishball (10pcs)', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20}]', 20.00, 'paid', '2026-07-10 22:02:20', 2, 'POS-20260710-A19BEC', '2026-07-10 21:57:20', '2026-07-10 21:57:20'),
(15, '8acb6bbfadf131aac79c62dee9867671', '2TTZQX4B', 29, 13, '1x Tokneneng (4pcs), 1x Tapsilog, 1x Bottled Water (500ml), 1x Smart C', '[{\"id\":22,\"name\":\"Tokneneng (4pcs)\",\"qty\":1,\"price\":30},{\"id\":28,\"name\":\"Tapsilog\",\"qty\":1,\"price\":55},{\"id\":29,\"name\":\"Bottled Water (500ml)\",\"qty\":1,\"price\":15},{\"id\":38,\"name\":\"Smart C\",\"qty\":1,\"price\":100}]', 200.00, 'expired', '2026-07-10 14:19:03', NULL, NULL, NULL, '2026-07-10 22:14:03'),
(16, '5104e56e73d31b0f8e5c0a20f5b550c4', '9GEJNW9S', 29, 13, '1x Kwek-Kwek (6pcs), 1x Tapsilog, 1x Smart C', '[{\"id\":21,\"name\":\"Kwek-Kwek (6pcs)\",\"qty\":1,\"price\":35},{\"id\":28,\"name\":\"Tapsilog\",\"qty\":1,\"price\":55},{\"id\":38,\"name\":\"Smart C\",\"qty\":1,\"price\":100}]', 190.00, 'expired', '2026-07-10 14:20:37', NULL, NULL, NULL, '2026-07-10 22:15:37'),
(17, '52b15256fe00248e30846a6d605e85db', '6Q9W37BC', 29, 13, '1x Kwek-Kwek (6pcs), 1x Smart C', '[{\"id\":21,\"name\":\"Kwek-Kwek (6pcs)\",\"qty\":1,\"price\":35},{\"id\":38,\"name\":\"Smart C\",\"qty\":1,\"price\":100}]', 135.00, 'paid', '2026-07-10 14:21:14', 1, 'POS-20260710-43E4B3', '2026-07-10 22:16:27', '2026-07-10 22:16:14'),
(18, 'caba93dc5adeffb4470e96c634608f2a', '2GAWCTRZ', 29, 13, '14x Bubble Gum', '[{\"id\":39,\"name\":\"Bubble Gum\",\"qty\":14,\"price\":15}]', 210.00, 'paid', '2026-07-12 14:14:54', 1, 'POS-20260712-13555B', '2026-07-12 22:10:01', '2026-07-12 22:09:54'),
(19, 'f94617c5694508d50f60c4f1f39368c3', '83MF8TZF', 29, 13, '1x Kwek-Kwek (6pcs), 1x Tokneneng (4pcs), 1x Fishball (10pcs), 1x Kikiam (4pcs), 1x Chicken Skin (1pc), 1x Calamares (1 cup), 1x Tapsilog, 1x Bottled Water (500ml), 1x Smart C, 1x Bubble Gum', '[{\"id\":21,\"name\":\"Kwek-Kwek (6pcs)\",\"qty\":1,\"price\":35},{\"id\":22,\"name\":\"Tokneneng (4pcs)\",\"qty\":1,\"price\":30},{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25},{\"id\":26,\"name\":\"Chicken Skin (1pc)\",\"qty\":1,\"price\":15},{\"id\":27,\"name\":\"Calamares (1 cup)\",\"qty\":1,\"price\":50},{\"id\":28,\"name\":\"Tapsilog\",\"qty\":1,\"price\":55},{\"id\":29,\"name\":\"Bottled Water (500ml)\",\"qty\":1,\"price\":15},{\"id\":38,\"name\":\"Smart C\",\"qty\":1,\"price\":100},{\"id\":39,\"name\":\"Bubble Gum\",\"qty\":1,\"price\":15}]', 360.00, 'paid', '2026-07-12 14:15:32', 1, 'POS-20260712-57E1D5', '2026-07-12 22:10:43', '2026-07-12 22:10:32'),
(20, '2ba21aaec23d88e420835a8b67860fc1', '23FRS89B', 29, 13, '1x Fishball (10pcs), 1x Kikiam (4pcs)', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25}]', 45.00, 'paid', '2026-07-12 14:15:49', 1, 'POS-20260712-E0EA6E', '2026-07-12 22:11:15', '2026-07-12 22:10:49'),
(21, '219fb2adbc9d208d70cfa0850439e022', 'EWJ2FENB', 29, 13, '6x Fishball (10pcs), 1x Chicken Skin (1pc), 1x Smart C', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":6,\"price\":20},{\"id\":26,\"name\":\"Chicken Skin (1pc)\",\"qty\":1,\"price\":15},{\"id\":38,\"name\":\"Smart C\",\"qty\":1,\"price\":100}]', 235.00, 'paid', '2026-07-12 15:26:39', 1, 'POS-20260712-6921E0', '2026-07-12 23:23:12', '2026-07-12 23:21:39'),
(22, 'f3be7ec8f63551b6d96a98a264d98fe2', 'JWAHBAWZ', 29, 13, '41x Fishball (10pcs)', '[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":41,\"price\":20}]', 820.00, 'paid', '2026-07-12 15:28:52', 1, 'POS-20260712-0551AE', '2026-07-12 23:23:59', '2026-07-12 23:23:52');

-- --------------------------------------------------------

--
-- Table structure for table `merchant_rent_payments`
--

CREATE TABLE `merchant_rent_payments` (
  `id` int UNSIGNED NOT NULL,
  `lease_id` int UNSIGNED NOT NULL COMMENT 'FK -> merchant_leases.id',
  `amount_paid` decimal(15,2) NOT NULL,
  `period_covered` varchar(20) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'e.g. 2026-06',
  `payment_date` date NOT NULL,
  `payment_method` varchar(40) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'cash',
  `received_by` int UNSIGNED NOT NULL COMMENT 'FK -> users.userID (admin)',
  `reference_no` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit trail of rent payments by merchants';

--
-- Dumping data for table `merchant_rent_payments`
--

INSERT INTO `merchant_rent_payments` (`id`, `lease_id`, `amount_paid`, `period_covered`, `payment_date`, `payment_method`, `received_by`, `reference_no`, `notes`, `created_at`) VALUES
(4, 4, 3000.00, '2026-07', '2026-07-06', 'cash', 16, 'RENT-20260706-9AB698', 'Cashing', '2026-07-06 23:00:15'),
(6, 8, 2500.00, '2026-07', '2026-07-10', 'cash', 16, 'RENT-20260710-14CC3C', NULL, '2026-07-10 10:40:14'),
(7, 8, 2500.00, '2026-07', '2026-07-10', 'cash', 16, 'RENT-20260710-2E5646', NULL, '2026-07-10 10:44:13'),
(8, 9, 1500.00, '2026-06', '2026-07-11', 'cash', 16, 'RENT-20260710-CA6484', NULL, '2026-07-10 10:58:13'),
(9, 9, 1500.00, '2026-06', '2026-07-11', 'cash', 16, 'RENT-20260710-4878F4', NULL, '2026-07-10 10:58:14'),
(10, 9, 1500.00, '2026-06', '2026-07-11', 'cash', 16, 'RENT-20260710-48AF18', NULL, '2026-07-10 10:58:15'),
(11, 9, 2500.00, '2026-07', '2026-07-10', 'cash', 16, 'RENT-20260710-E5474E', NULL, '2026-07-10 10:58:39'),
(12, 9, 7000.00, '2026-07', '2026-07-10', 'cash', 16, 'RENT-20260710-339810', NULL, '2026-07-10 10:58:50');

-- --------------------------------------------------------

--
-- Table structure for table `merchant_wallets`
--

CREATE TABLE `merchant_wallets` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL COMMENT 'FK -> users.id (merchant role)',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Collected points pending settlement',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `merchant_wallets`
--

INSERT INTO `merchant_wallets` (`id`, `user_id`, `balance`, `created_at`, `updated_at`) VALUES
(13, 29, 1377.76, '2026-06-20 22:52:28', '2026-07-20 13:54:59'),
(14, 35, 0.00, '2026-06-23 00:26:30', '2026-06-23 00:26:30'),
(27, 38, 0.00, '2026-07-11 16:53:53', '2026-07-11 16:53:53'),
(28, 458, 0.00, '2026-07-12 23:09:43', '2026-07-12 23:09:43'),
(29, 460, 0.00, '2026-07-12 23:17:25', '2026-07-12 23:17:25');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `type` varchar(40) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'general',
  `icon` varchar(40) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'bell',
  `title` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `message` varchar(500) COLLATE utf8mb4_general_ci NOT NULL,
  `link` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `icon`, `title`, `message`, `link`, `is_read`, `created_at`) VALUES
(4, 23, 'topup', 'circle-plus', 'Top-Up Approved', '₱9,980.00 has been credited to your wallet.', 'http://localhost/genpay/student/history.php', 1, '2026-07-12 21:58:52'),
(5, 1, 'welcome', 'hand-sparkles', 'Welcome to GenPay!', 'Your wallet is ready. Top up, pay at the canteen, or send GenCoin to a friend — every action shows up here.', NULL, 1, '2026-07-12 22:00:23'),
(6, 23, 'topup', 'circle-plus', 'Top-Up Approved', '₱4,990.00 has been credited to your wallet.', 'http://localhost/genpay/student/history.php', 1, '2026-07-12 22:01:16'),
(8, 1, 'payment', 'cart-shopping', 'Payment Successful', 'You paid ₱215.00 at Jastine Manalastas.', 'https://butyric-ethel-volcanically.ngrok-free.dev/genpay/student/history.php', 1, '2026-07-12 22:12:54'),
(10, 1, 'topup', 'circle-plus', 'Wallet Loaded', 'Jastine Manalastas loaded ₱199.40 into your wallet.', 'http://localhost/genpay/student/history.php', 1, '2026-07-12 22:21:12'),
(11, 1, 'topup', 'circle-plus', 'Wallet Loaded', 'Jastine Manalastas loaded ₱498.50 into your wallet.', 'http://localhost/genpay/student/history.php', 1, '2026-07-12 22:21:49'),
(12, 23, 'transfer_out', 'paper-plane', 'GenCoin Sent', 'You sent ₱97.00 to Michael Keith Banez.', 'http://localhost/genpay/student/history.php', 0, '2026-07-14 10:02:04'),
(13, 1, 'transfer_in', 'arrow-down', 'GenCoin Received', 'Ezekiel Clarence Santiago sent you ₱97.00 (9.7 GC).', 'http://localhost/genpay/student/history.php', 0, '2026-07-14 10:02:04'),
(14, 23, 'transfer_out', 'paper-plane', 'GenCoin Sent', 'You sent ₱870.00 to Michael Keith Banez.', 'http://localhost/genpay/student/history.php', 0, '2026-07-14 10:02:42'),
(15, 1, 'transfer_in', 'arrow-down', 'GenCoin Received', 'Ezekiel Clarence Santiago sent you ₱870.00 (87.0 GC).', 'http://localhost/genpay/student/history.php', 0, '2026-07-14 10:02:42'),
(16, 6, 'welcome', 'hand-sparkles', 'Welcome to GenPay!', 'Your wallet is ready. Top up, pay at the canteen, or send GenCoin to a friend — every action shows up here.', NULL, 1, '2026-07-17 21:41:52'),
(17, 1, 'withdraw', 'money-bill-wave', 'Withdrawal Released', '₱265.90 cash withdrawal has been released by the cashier.', 'http://localhost/genpay/student/history.php', 0, '2026-07-17 22:17:19'),
(18, 6, 'withdraw', 'money-bill-wave', 'Withdrawal Released', '₱893.99 cash withdrawal has been released by the cashier.', 'http://localhost/genpay/student/history.php', 1, '2026-07-17 22:17:25'),
(19, 39, 'topup', 'circle-plus', 'Wallet Loaded', 'Jastine Manalastas loaded ₱997.00 into your wallet.', 'http://localhost/genpay/parent/wallet.php', 0, '2026-07-17 23:36:14'),
(20, 1, 'payment', 'cart-shopping', 'Payment Successful', 'You paid ₱170.00 at Jastine Manalastas.', 'https://butyric-ethel-volcanically.ngrok-free.dev/genpay/student/history.php', 0, '2026-07-17 23:49:42'),
(26, 29, 'compliance', 'triangle-exclamation', 'Your account is at risk', 'You\'ve now tried to add a restricted product 5 times (High caffeine). Repeated attempts to list banned items may result in your merchant account being suspended — please check the Restricted Products list before adding new items.', NULL, 1, '2026-07-18 21:36:02'),
(27, 7, 'compliance', 'triangle-exclamation', 'Merchant account at risk', 'Jastine Manalastas has hit 5 blocked attempts to list restricted products. Review their account on the Restricted Products page.', 'http://localhost/genpay/admin/restricted_products.php', 0, '2026-07-18 21:36:02'),
(29, 12, 'compliance', 'triangle-exclamation', 'Merchant account at risk', 'Jastine Manalastas has hit 5 blocked attempts to list restricted products. Review their account on the Restricted Products page.', 'http://localhost/genpay/admin/restricted_products.php', 0, '2026-07-18 21:36:02'),
(30, 16, 'compliance', 'triangle-exclamation', 'Merchant account at risk', 'Jastine Manalastas has hit 5 blocked attempts to list restricted products. Review their account on the Restricted Products page.', 'http://localhost/genpay/admin/restricted_products.php', 0, '2026-07-18 21:36:02'),
(31, 35, 'compliance', 'triangle-exclamation', 'Warning: restricted product attempts', 'You\'ve now had 3 blocked attempts to list restricted products (High caffeinated drinks is prohibited). 2 more and your merchant account will be suspended for 3 days — please check the Restricted Products list before adding new items.', NULL, 0, '2026-07-19 21:44:27'),
(32, 35, 'compliance', 'triangle-exclamation', 'Your account has been suspended', 'You reached 5 blocked attempts to list restricted products (Carbonated soft drink — high sugar content, DepEd nutritional guidelines). Your merchant account — including staff logins and stall sales — is suspended for 3 days, until Jul 22, 2026 9:44 PM. The GenPay finance team can lift it earlier.', NULL, 0, '2026-07-19 21:44:37'),
(33, 7, 'compliance', 'triangle-exclamation', 'Merchant account suspended', 'Greg Bautista Jr. hit 5 blocked attempts to list restricted products and has been auto-suspended until Jul 22, 2026 9:44 PM. You can lift the suspension early from the Restricted Products page.', 'http://localhost/genpay/admin/restricted_products.php', 0, '2026-07-19 21:44:37'),
(35, 12, 'compliance', 'triangle-exclamation', 'Merchant account suspended', 'Greg Bautista Jr. hit 5 blocked attempts to list restricted products and has been auto-suspended until Jul 22, 2026 9:44 PM. You can lift the suspension early from the Restricted Products page.', 'http://localhost/genpay/admin/restricted_products.php', 0, '2026-07-19 21:44:37'),
(36, 16, 'compliance', 'triangle-exclamation', 'Merchant account suspended', 'Greg Bautista Jr. hit 5 blocked attempts to list restricted products and has been auto-suspended until Jul 22, 2026 9:44 PM. You can lift the suspension early from the Restricted Products page.', 'http://localhost/genpay/admin/restricted_products.php', 0, '2026-07-19 21:44:37'),
(37, 1, 'allowance', 'hand-holding-dollar', 'Allowance Received', 'Miku Hatsune sent you ₱100.00.', 'http://localhost/genpay/student/history.php', 1, '2026-07-19 21:46:29'),
(38, 1, 'payment', 'cart-shopping', 'Payment Successful', 'You paid ₱15.00 at Jastine Manalastas.', 'https://butyric-ethel-volcanically.ngrok-free.dev/genpay/student/history.php', 0, '2026-07-20 13:54:59'),
(39, 29, 'sale', 'cart-shopping', 'Payment Received', 'Michael Keith Banez paid ₱15.00 at your stall.', 'https://butyric-ethel-volcanically.ngrok-free.dev/genpay/merchant/history.php', 0, '2026-07-20 13:54:59');

-- --------------------------------------------------------

--
-- Table structure for table `p2p_transfers`
--

CREATE TABLE `p2p_transfers` (
  `id` bigint UNSIGNED NOT NULL,
  `reference_no` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `from_wallet_id` int UNSIGNED NOT NULL COMMENT 'FK -> student_wallets.id',
  `to_wallet_id` int UNSIGNED NOT NULL COMMENT 'FK -> student_wallets.id',
  `from_user_id` int UNSIGNED NOT NULL,
  `to_user_id` int UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('completed','failed','reversed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'completed',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Peer-to-peer token transfers between students (atomic, transactionally safe)';

--
-- Dumping data for table `p2p_transfers`
--

INSERT INTO `p2p_transfers` (`id`, `reference_no`, `from_wallet_id`, `to_wallet_id`, `from_user_id`, `to_user_id`, `amount`, `message`, `status`, `created_at`) VALUES
(1, 'P2P-20260622-4ADE0DBB', 2, 4, 1, 23, 1000.00, 'Happy Birthday!', 'completed', '2026-06-22 22:29:14'),
(2, 'P2P-20260622-C0CED566', 2, 10, 1, 6, 200.00, 'Thanks!', 'completed', '2026-06-22 22:38:05'),
(3, 'P2P-20260705-82453E62', 2, 1, 1, 2, 1.00, 'For Lunch', 'completed', '2026-07-05 20:21:24'),
(4, 'P2P-20260705-C5E3AA15', 2, 1, 1, 2, 450.00, 'For Lunch', 'completed', '2026-07-05 20:58:28'),
(5, 'P2P-20260714-8C284C4F', 4, 2, 23, 1, 97.00, NULL, 'completed', '2026-07-14 10:02:03'),
(6, 'P2P-20260714-8A29E2F8', 4, 2, 23, 1, 870.00, NULL, 'completed', '2026-07-14 10:02:42');

-- --------------------------------------------------------

--
-- Table structure for table `parents`
--

CREATE TABLE `parents` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `low_balance_threshold` decimal(10,2) NOT NULL DEFAULT '50.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `parents`
--

INSERT INTO `parents` (`id`, `user_id`, `low_balance_threshold`, `created_at`) VALUES
(1, 39, 800.00, '2026-06-28 22:21:37'),
(89, 248, 50.00, '2026-07-04 21:52:20'),
(90, 250, 50.00, '2026-07-04 21:52:21'),
(91, 252, 50.00, '2026-07-04 21:52:21'),
(92, 254, 50.00, '2026-07-04 21:52:21'),
(93, 256, 50.00, '2026-07-04 21:52:21'),
(94, 259, 50.00, '2026-07-04 21:52:21'),
(95, 262, 50.00, '2026-07-04 21:52:22'),
(96, 264, 50.00, '2026-07-04 21:52:22'),
(97, 266, 50.00, '2026-07-04 21:52:22'),
(98, 269, 50.00, '2026-07-04 21:52:22'),
(99, 271, 50.00, '2026-07-04 21:52:22'),
(100, 284, 50.00, '2026-07-07 11:44:11'),
(101, 286, 50.00, '2026-07-07 11:44:11'),
(102, 289, 50.00, '2026-07-07 11:44:12'),
(103, 292, 50.00, '2026-07-07 11:44:12'),
(104, 294, 50.00, '2026-07-07 11:44:13'),
(105, 296, 50.00, '2026-07-07 11:44:13'),
(106, 298, 50.00, '2026-07-07 11:44:13'),
(107, 300, 50.00, '2026-07-07 11:44:14'),
(108, 302, 50.00, '2026-07-07 11:44:14'),
(109, 304, 50.00, '2026-07-07 11:44:14'),
(110, 306, 50.00, '2026-07-07 11:44:15'),
(111, 308, 50.00, '2026-07-07 11:44:15'),
(112, 311, 50.00, '2026-07-07 11:44:16'),
(113, 313, 50.00, '2026-07-07 11:44:16'),
(114, 315, 50.00, '2026-07-07 11:44:16'),
(115, 317, 50.00, '2026-07-07 11:44:17'),
(116, 320, 50.00, '2026-07-07 11:44:17'),
(117, 323, 50.00, '2026-07-07 11:44:17'),
(118, 329, 50.00, '2026-07-07 11:44:19'),
(119, 331, 50.00, '2026-07-07 11:44:19'),
(120, 333, 50.00, '2026-07-07 11:44:19'),
(121, 335, 50.00, '2026-07-07 11:44:20'),
(122, 337, 50.00, '2026-07-07 11:44:20'),
(123, 340, 50.00, '2026-07-07 11:44:20'),
(124, 343, 50.00, '2026-07-07 11:44:21'),
(125, 345, 50.00, '2026-07-07 11:44:21'),
(126, 347, 50.00, '2026-07-07 11:44:22'),
(127, 350, 50.00, '2026-07-07 11:44:22'),
(128, 352, 50.00, '2026-07-07 11:44:22'),
(129, 354, 50.00, '2026-07-07 11:44:23'),
(130, 356, 50.00, '2026-07-07 11:44:23'),
(131, 358, 50.00, '2026-07-07 11:44:24'),
(132, 360, 50.00, '2026-07-07 11:44:24'),
(133, 362, 50.00, '2026-07-07 11:44:24'),
(134, 364, 50.00, '2026-07-07 11:44:25'),
(135, 366, 50.00, '2026-07-07 11:44:25'),
(136, 368, 50.00, '2026-07-07 11:44:25'),
(137, 370, 50.00, '2026-07-07 11:44:26'),
(138, 372, 50.00, '2026-07-07 11:44:26'),
(139, 374, 50.00, '2026-07-07 11:44:26'),
(140, 376, 50.00, '2026-07-07 11:44:27'),
(141, 378, 50.00, '2026-07-07 11:44:27'),
(142, 380, 50.00, '2026-07-07 11:44:27'),
(143, 382, 50.00, '2026-07-07 11:44:28'),
(144, 384, 50.00, '2026-07-07 11:44:28'),
(145, 387, 50.00, '2026-07-07 11:44:29'),
(146, 390, 50.00, '2026-07-07 11:44:29'),
(147, 392, 50.00, '2026-07-07 11:44:29'),
(148, 394, 50.00, '2026-07-07 11:44:30'),
(149, 396, 50.00, '2026-07-07 11:44:30'),
(150, 398, 50.00, '2026-07-07 11:44:30'),
(151, 401, 50.00, '2026-07-07 11:44:31'),
(152, 403, 50.00, '2026-07-07 11:44:31'),
(153, 405, 50.00, '2026-07-07 11:44:32'),
(154, 407, 50.00, '2026-07-07 11:44:32'),
(155, 410, 50.00, '2026-07-07 11:44:33'),
(156, 412, 50.00, '2026-07-07 11:44:33'),
(157, 414, 50.00, '2026-07-07 11:44:33'),
(158, 416, 50.00, '2026-07-07 11:44:34'),
(159, 418, 50.00, '2026-07-07 11:44:34'),
(160, 420, 50.00, '2026-07-07 11:44:34'),
(161, 422, 50.00, '2026-07-07 11:44:35'),
(162, 424, 50.00, '2026-07-07 11:44:35'),
(163, 426, 50.00, '2026-07-07 11:44:35'),
(164, 429, 50.00, '2026-07-07 11:44:36'),
(165, 431, 50.00, '2026-07-07 11:44:36'),
(166, 433, 50.00, '2026-07-07 11:44:36'),
(167, 435, 50.00, '2026-07-07 11:44:36'),
(168, 438, 50.00, '2026-07-07 11:44:37'),
(169, 441, 50.00, '2026-07-07 11:44:37'),
(170, 444, 50.00, '2026-07-07 11:44:38'),
(171, 446, 50.00, '2026-07-07 11:44:38'),
(172, 449, 50.00, '2026-07-07 11:44:39'),
(173, 454, 50.00, '2026-07-07 11:44:39'),
(174, 456, 50.00, '2026-07-07 11:44:40');

-- --------------------------------------------------------

--
-- Table structure for table `parent_alerts`
--

CREATE TABLE `parent_alerts` (
  `id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED NOT NULL,
  `student_user_id` int UNSIGNED NOT NULL,
  `student_wallet_id` int UNSIGNED NOT NULL,
  `balance_at_alert` decimal(10,2) NOT NULL,
  `threshold` decimal(10,2) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `parent_alerts`
--

INSERT INTO `parent_alerts` (`id`, `parent_id`, `student_user_id`, `student_wallet_id`, `balance_at_alert`, `threshold`, `is_read`, `created_at`) VALUES
(1, 1, 1, 2, 716.90, 800.00, 1, '2026-06-28 22:53:37'),
(2, 1, 1, 2, 715.90, 800.00, 1, '2026-07-05 20:21:24'),
(3, 1, 1, 2, 145.90, 800.00, 1, '2026-07-09 21:35:05'),
(4, 1, 1, 2, 10.90, 800.00, 1, '2026-07-10 22:16:27'),
(5, 1, 1, 2, 626.82, 800.00, 0, '2026-07-12 22:10:43'),
(6, 1, 1, 2, 540.82, 800.00, 0, '2026-07-17 23:49:42'),
(7, 1, 1, 2, 625.82, 800.00, 0, '2026-07-20 13:54:59');

-- --------------------------------------------------------

--
-- Table structure for table `parent_student_links`
--

CREATE TABLE `parent_student_links` (
  `id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED NOT NULL,
  `student_user_id` int UNSIGNED NOT NULL,
  `linked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `parent_student_links`
--

INSERT INTO `parent_student_links` (`id`, `parent_id`, `student_user_id`, `linked_at`) VALUES
(1, 1, 1, '2026-06-28 22:22:52'),
(96, 89, 247, '2026-07-04 21:52:20'),
(97, 90, 249, '2026-07-04 21:52:21'),
(98, 91, 251, '2026-07-04 21:52:21'),
(99, 92, 253, '2026-07-04 21:52:21'),
(100, 93, 255, '2026-07-04 21:52:21'),
(101, 93, 257, '2026-07-04 21:52:21'),
(102, 94, 258, '2026-07-04 21:52:21'),
(103, 95, 261, '2026-07-04 21:52:22'),
(104, 96, 263, '2026-07-04 21:52:22'),
(105, 97, 265, '2026-07-04 21:52:22'),
(106, 98, 268, '2026-07-04 21:52:22'),
(107, 99, 270, '2026-07-04 21:52:22'),
(108, 100, 283, '2026-07-07 11:44:11'),
(109, 101, 285, '2026-07-07 11:44:11'),
(110, 101, 287, '2026-07-07 11:44:11'),
(111, 102, 288, '2026-07-07 11:44:12'),
(112, 103, 291, '2026-07-07 11:44:12'),
(113, 104, 293, '2026-07-07 11:44:13'),
(114, 105, 295, '2026-07-07 11:44:13'),
(115, 106, 297, '2026-07-07 11:44:13'),
(116, 107, 299, '2026-07-07 11:44:14'),
(117, 108, 301, '2026-07-07 11:44:14'),
(118, 109, 303, '2026-07-07 11:44:14'),
(119, 110, 305, '2026-07-07 11:44:15'),
(120, 111, 307, '2026-07-07 11:44:15'),
(121, 111, 309, '2026-07-07 11:44:15'),
(122, 112, 310, '2026-07-07 11:44:16'),
(123, 113, 312, '2026-07-07 11:44:16'),
(124, 114, 314, '2026-07-07 11:44:16'),
(125, 115, 316, '2026-07-07 11:44:17'),
(126, 116, 319, '2026-07-07 11:44:17'),
(127, 117, 322, '2026-07-07 11:44:17'),
(128, 118, 328, '2026-07-07 11:44:19'),
(129, 119, 330, '2026-07-07 11:44:19'),
(130, 120, 332, '2026-07-07 11:44:19'),
(131, 121, 334, '2026-07-07 11:44:20'),
(132, 122, 336, '2026-07-07 11:44:20'),
(133, 123, 339, '2026-07-07 11:44:20'),
(134, 123, 341, '2026-07-07 11:44:21'),
(135, 124, 342, '2026-07-07 11:44:21'),
(136, 125, 344, '2026-07-07 11:44:21'),
(137, 126, 346, '2026-07-07 11:44:22'),
(138, 127, 349, '2026-07-07 11:44:22'),
(139, 128, 351, '2026-07-07 11:44:22'),
(140, 129, 353, '2026-07-07 11:44:23'),
(141, 130, 355, '2026-07-07 11:44:23'),
(142, 131, 357, '2026-07-07 11:44:24'),
(143, 132, 359, '2026-07-07 11:44:24'),
(144, 133, 361, '2026-07-07 11:44:24'),
(145, 134, 363, '2026-07-07 11:44:25'),
(146, 135, 365, '2026-07-07 11:44:25'),
(147, 136, 367, '2026-07-07 11:44:25'),
(148, 137, 369, '2026-07-07 11:44:26'),
(149, 138, 371, '2026-07-07 11:44:26'),
(150, 139, 373, '2026-07-07 11:44:26'),
(151, 140, 375, '2026-07-07 11:44:27'),
(152, 141, 377, '2026-07-07 11:44:27'),
(153, 142, 379, '2026-07-07 11:44:27'),
(154, 143, 381, '2026-07-07 11:44:28'),
(155, 144, 383, '2026-07-07 11:44:28'),
(156, 145, 386, '2026-07-07 11:44:29'),
(157, 146, 389, '2026-07-07 11:44:29'),
(158, 147, 391, '2026-07-07 11:44:29'),
(159, 148, 393, '2026-07-07 11:44:30'),
(160, 149, 395, '2026-07-07 11:44:30'),
(161, 150, 397, '2026-07-07 11:44:30'),
(162, 151, 400, '2026-07-07 11:44:31'),
(163, 152, 402, '2026-07-07 11:44:31'),
(164, 153, 404, '2026-07-07 11:44:32'),
(165, 154, 406, '2026-07-07 11:44:32'),
(166, 155, 409, '2026-07-07 11:44:33'),
(167, 156, 411, '2026-07-07 11:44:33'),
(168, 157, 413, '2026-07-07 11:44:33'),
(169, 158, 415, '2026-07-07 11:44:34'),
(170, 159, 417, '2026-07-07 11:44:34'),
(171, 160, 419, '2026-07-07 11:44:34'),
(172, 161, 421, '2026-07-07 11:44:35'),
(173, 162, 423, '2026-07-07 11:44:35'),
(174, 163, 425, '2026-07-07 11:44:35'),
(175, 163, 427, '2026-07-07 11:44:35'),
(176, 164, 428, '2026-07-07 11:44:36'),
(177, 165, 430, '2026-07-07 11:44:36'),
(178, 166, 432, '2026-07-07 11:44:36'),
(179, 167, 434, '2026-07-07 11:44:36'),
(180, 168, 437, '2026-07-07 11:44:37'),
(181, 169, 440, '2026-07-07 11:44:37'),
(182, 170, 443, '2026-07-07 11:44:38'),
(183, 171, 445, '2026-07-07 11:44:38'),
(184, 172, 448, '2026-07-07 11:44:39'),
(185, 172, 450, '2026-07-07 11:44:39'),
(186, 173, 453, '2026-07-07 11:44:39'),
(187, 174, 455, '2026-07-07 11:44:40');

-- --------------------------------------------------------

--
-- Table structure for table `parent_topup_requests`
--

CREATE TABLE `parent_topup_requests` (
  `id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'finance',
  `merchant_id` int UNSIGNED DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reference_no` varchar(40) DEFAULT NULL,
  `fee_amount` decimal(15,2) DEFAULT NULL,
  `credited_amount` decimal(15,2) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  `processed_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parent_wallets`
--

CREATE TABLE `parent_wallets` (
  `id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `parent_wallets`
--

INSERT INTO `parent_wallets` (`id`, `parent_id`, `balance`, `created_at`, `updated_at`) VALUES
(1, 1, 897.00, '2026-07-17 23:19:05', '2026-07-19 21:46:29');

-- --------------------------------------------------------

--
-- Table structure for table `payment_verifications`
--

CREATE TABLE `payment_verifications` (
  `id` int UNSIGNED NOT NULL,
  `application_id` int UNSIGNED NOT NULL COMMENT 'FK -> stall_applications.id',
  `amount` decimal(10,2) NOT NULL DEFAULT '150.00' COMMENT 'Processing fee in PHP',
  `gcash_ref_number` varchar(60) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Admin-entered GCash reference number',
  `verified_by` int UNSIGNED NOT NULL COMMENT 'FK -> users.userID (admin who recorded the payment)',
  `verified_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Admin-recorded GCash payment proof for stall application processing fee';

-- --------------------------------------------------------

--
-- Table structure for table `qr_order_items`
--

CREATE TABLE `qr_order_items` (
  `id` int UNSIGNED NOT NULL,
  `merchant_qr_order_id` int UNSIGNED NOT NULL,
  `merchant_inventory_id` int UNSIGNED DEFAULT NULL,
  `item_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `qr_order_items`
--

INSERT INTO `qr_order_items` (`id`, `merchant_qr_order_id`, `merchant_inventory_id`, `item_name`, `qty`, `unit_price`) VALUES
(1, 4, 21, 'Kwek-Kwek (6pcs)', 1, 35.00),
(2, 4, 25, 'Kikiam (4pcs)', 1, 25.00),
(3, 4, 26, 'Chicken Skin (1pc)', 1, 15.00),
(4, 4, 28, 'Sauce Refill (cup)', 1, 5.00),
(5, 4, NULL, 'Soda in Cup (16oz)', 1, 20.00),
(6, 5, 23, 'Fishball (10pcs)', 2, 20.00),
(7, 5, 25, 'Kikiam (4pcs)', 1, 25.00),
(8, 6, 25, 'Kikiam (4pcs)', 1, 25.00),
(9, 6, 29, 'Bottled Water (500ml)', 1, 15.00),
(10, 6, NULL, 'Soda in Cup (16oz)', 1, 20.00),
(11, 7, 23, 'Fishball (10pcs)', 1, 20.00),
(12, 7, 28, 'Sauce Refill (cup)', 1, 5.00),
(13, 7, 29, 'Bottled Water (500ml)', 1, 15.00),
(14, 8, 23, 'Fishball (10pcs)', 1, 20.00),
(15, 8, 25, 'Kikiam (4pcs)', 1, 25.00),
(16, 9, 21, 'Kwek-Kwek (6pcs)', 4, 35.00),
(17, 9, 25, 'Kikiam (4pcs)', 2, 25.00),
(18, 9, 26, 'Chicken Skin (1pc)', 2, 15.00),
(19, 9, 27, 'Calamares (1 cup)', 1, 50.00),
(20, 9, 28, 'Tapsilog', 1, 55.00);

-- --------------------------------------------------------

--
-- Table structure for table `qr_tokens`
--

CREATE TABLE `qr_tokens` (
  `qrID` int NOT NULL,
  `userID` int NOT NULL,
  `qr_data` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restricted_products`
--

CREATE TABLE `restricted_products` (
  `id` int UNSIGNED NOT NULL,
  `product_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Exact or partial name to match against inventory',
  `category` varchar(60) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'general' COMMENT 'e.g. beverage, snack, junk_food',
  `reason` varchar(255) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Nutritional / health policy reason',
  `match_type` enum('exact','contains') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'contains' COMMENT 'exact=full name match, contains=substring match',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `flagged_by` int UNSIGNED NOT NULL COMMENT 'FK -> users.userID (admin)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `calories` smallint UNSIGNED DEFAULT NULL,
  `sugar_g` decimal(5,1) DEFAULT NULL,
  `fat_g` decimal(5,1) DEFAULT NULL,
  `protein_g` decimal(5,1) DEFAULT NULL,
  `sodium_mg` decimal(6,1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Global nutritional compliance blacklist â€” blocks merchant inventory encoding';

--
-- Dumping data for table `restricted_products`
--

INSERT INTO `restricted_products` (`id`, `product_name`, `category`, `reason`, `match_type`, `is_active`, `flagged_by`, `created_at`, `updated_at`, `calories`, `sugar_g`, `fat_g`, `protein_g`, `sodium_mg`) VALUES
(10, 'Red Horse', 'beverage', 'No alcohol inside the school premises', 'contains', 1, 16, '2026-07-06 17:51:19', '2026-07-06 17:51:19', NULL, NULL, NULL, NULL, NULL),
(11, 'Coca-Cola', 'beverage', 'High sugar content', 'contains', 1, 16, '2026-07-06 17:51:56', '2026-07-06 17:51:56', NULL, NULL, NULL, NULL, NULL),
(12, 'Sting Energy Drink', 'beverage', 'High caffeinated drinks is prohibited', 'contains', 1, 16, '2026-07-06 17:52:52', '2026-07-06 17:52:52', NULL, NULL, NULL, NULL, NULL),
(13, 'Cobra Energy Drink', 'beverage', 'High caffeine', 'contains', 1, 16, '2026-07-06 17:53:19', '2026-07-06 17:53:19', NULL, NULL, NULL, NULL, NULL),
(24, 'Royal', 'beverage', 'Carbonated soft drink — high sugar content, DepEd nutritional guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(25, 'Sprite', 'beverage', 'Carbonated soft drink — high sugar content, DepEd nutritional guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(26, 'Mountain Dew', 'beverage', 'Carbonated soft drink — high sugar content, DepEd nutritional guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(27, 'RC Cola', 'beverage', 'Carbonated soft drink — high sugar content, DepEd nutritional guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(28, 'Red Bull', 'beverage', 'Energy drink — high caffeine and sugar content, prohibited on campus', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(29, 'Piattos', 'junk_food', 'Salty snack — high sodium and low nutritional value, health guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(30, 'Nova', 'junk_food', 'Salty snack — high sodium and low nutritional value, health guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(31, 'Chippy', 'junk_food', 'Salty snack — high sodium and low nutritional value, health guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(32, 'Nagaraya', 'junk_food', 'Salty snack — high sodium and low nutritional value, health guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(33, 'Lucky Me', 'snack', 'Instant noodles — high sodium and low nutritional value, health guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(34, 'Chocnut', 'candy', 'Confectionery — high sugar content, DepEd nutritional guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(35, 'Cloud 9', 'candy', 'Chocolate bar — high sugar content, DepEd nutritional guidelines', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(36, 'Marlboro', 'general', 'Tobacco product — banned on campus and prohibited for minors (RA 9211)', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(37, 'Winston', 'general', 'Tobacco product — banned on campus and prohibited for minors (RA 9211)', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(38, 'San Miguel Pale Pilsen', 'general', 'Alcoholic beverage — prohibited on campus', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(39, 'Ginebra San Miguel', 'general', 'Alcoholic beverage — prohibited on campus', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(40, 'Emperador', 'general', 'Alcoholic beverage — prohibited on campus', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL),
(41, 'Tanduay', 'general', 'Alcoholic beverage — prohibited on campus', 'contains', 1, 7, '2026-07-06 23:30:36', '2026-07-06 23:30:36', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `roleID` int NOT NULL,
  `role_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role`
--

INSERT INTO `role` (`roleID`, `role_name`) VALUES
(1, 'student'),
(2, 'merchant'),
(4, 'finance'),
(5, 'merchant_admin'),
(6, 'merchant_staff');

-- --------------------------------------------------------

--
-- Table structure for table `school_revenue_ledger`
--

CREATE TABLE `school_revenue_ledger` (
  `id` int UNSIGNED NOT NULL,
  `topup_ref` varchar(40) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Reference from topup_requests',
  `user_id` int UNSIGNED NOT NULL COMMENT 'Student who topped up',
  `fee_amount` decimal(10,2) NOT NULL DEFAULT '2.00',
  `gross_amount` decimal(15,2) NOT NULL COMMENT 'Cash paid by student',
  `net_credited` decimal(15,2) NOT NULL COMMENT 'Tokens credited after fee',
  `credited_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tracks service fee revenue per automated top-up';

-- --------------------------------------------------------

--
-- Table structure for table `school_years`
--

CREATE TABLE `school_years` (
  `id` int NOT NULL,
  `school_year_name` varchar(9) COLLATE utf8mb4_general_ci NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `school_years`
--

INSERT INTO `school_years` (`id`, `school_year_name`, `start_date`, `end_date`, `is_active`, `created_at`) VALUES
(5, '2026-2027', '2026-07-20', '2027-05-04', 0, '2026-07-17 13:39:12');

-- --------------------------------------------------------

--
-- Table structure for table `school_year_balances`
--

CREATE TABLE `school_year_balances` (
  `id` int NOT NULL,
  `student_user_id` int NOT NULL,
  `school_year_id` int NOT NULL,
  `starting_balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `final_ending_balance` decimal(10,2) DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stalls`
--

CREATE TABLE `stalls` (
  `stall_id` varchar(10) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Alphanumeric e.g. A1, B3',
  `label` varchar(60) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Display label e.g. Stall A1',
  `row_label` char(1) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Grid row: A or B',
  `col_number` tinyint UNSIGNED NOT NULL COMMENT 'Grid column: 1-5',
  `area_sqm` decimal(6,2) DEFAULT NULL,
  `monthly_rate` decimal(15,2) DEFAULT NULL,
  `status` enum('vacant','occupied','pending_application') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'vacant',
  `merchant_id` int DEFAULT NULL COMMENT 'FK -> merchant.merchantID',
  `pending_expires_at` datetime DEFAULT NULL COMMENT 'NOW()+15min when pending_application',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Physical stall registry - source of truth for the public stall map';

--
-- Dumping data for table `stalls`
--

INSERT INTO `stalls` (`stall_id`, `label`, `row_label`, `col_number`, `area_sqm`, `monthly_rate`, `status`, `merchant_id`, `pending_expires_at`, `created_at`, `updated_at`) VALUES
('A1', 'Stall A1', 'A', 1, 12.00, 2500.00, 'vacant', NULL, NULL, '2026-06-15 14:34:42', '2026-07-10 10:38:37'),
('A2', 'Stall A2', 'A', 2, 12.00, 2500.00, 'occupied', 15, NULL, '2026-06-15 14:34:42', '2026-06-23 00:26:30'),
('A3', 'Stall A3', 'A', 3, 12.00, 2500.00, 'vacant', NULL, NULL, '2026-06-15 14:34:42', '2026-06-20 22:37:02'),
('A4', 'Stall A4', 'A', 4, 12.00, 2500.00, 'vacant', NULL, NULL, '2026-06-15 14:34:42', '2026-07-09 10:54:36'),
('A5', 'Stall A5', 'A', 5, 12.00, 2500.00, 'vacant', NULL, NULL, '2026-06-15 14:34:42', '2026-07-09 10:54:36'),
('B1', 'Stall B1', 'B', 1, 12.00, 2500.00, 'occupied', 14, NULL, '2026-06-15 14:34:42', '2026-06-20 22:52:28'),
('B2', 'Stall B2', 'B', 2, 12.00, 2500.00, 'vacant', NULL, NULL, '2026-06-15 14:34:42', '2026-06-20 22:37:02'),
('B3', 'Stall B3', 'B', 3, 12.00, 2500.00, 'vacant', NULL, NULL, '2026-06-15 14:34:42', '2026-06-20 22:27:38'),
('B4', 'Stall B4', 'B', 4, 12.00, 2500.00, 'vacant', NULL, NULL, '2026-06-15 14:34:42', '2026-06-15 14:34:42'),
('B5', 'Stall B5', 'B', 5, 12.00, 2500.00, 'vacant', NULL, NULL, '2026-06-15 14:34:42', '2026-07-06 12:23:15');

-- --------------------------------------------------------

--
-- Table structure for table `stall_applications`
--

CREATE TABLE `stall_applications` (
  `id` int UNSIGNED NOT NULL,
  `stall_id` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `business_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `first_name` varchar(60) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `middle_name` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_name` varchar(60) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `suffix` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sex` enum('male','female') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'male',
  `street` varchar(150) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `barangay` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `city` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `province` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `proprietor_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `contact_number` varchar(15) COLLATE utf8mb4_general_ci NOT NULL COMMENT '09XXXXXXXXX format',
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `profile_picture` varchar(500) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Relative path to upload',
  `business_permit` varchar(500) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Relative path to upload',
  `sanitary_permit` varchar(500) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Relative path to upload',
  `gjc_requirements` varchar(500) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Relative path to upload',
  `clearance` varchar(500) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Relative path to upload',
  `terms_accepted` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('pending_verification','awarded','rejected','cancelled','expired') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending_verification',
  `current_step` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `reviewed_by` int UNSIGNED DEFAULT NULL COMMENT 'FK -> users.userID',
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_general_ci,
  `contract_ref` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Auto-generated: SA-{zero-padded-id}-{year}',
  `signed_at` datetime DEFAULT NULL COMMENT 'Set when admin confirms contract in Step 2.2',
  `initially_approved_by` int UNSIGNED DEFAULT NULL COMMENT 'FK -> users.userID (admin who clicked Initial Approval)',
  `initially_approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `meetup_scheduled_at` datetime DEFAULT NULL,
  `meetup_location` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `meetup_notes` text COLLATE utf8mb4_general_ci,
  `meetup_scheduled_by` int UNSIGNED DEFAULT NULL,
  `meetup_scheduled_email_sent_at` datetime DEFAULT NULL,
  `down_payment_amount` decimal(10,2) DEFAULT NULL,
  `down_payment_reference` varchar(80) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `down_payment_notes` text COLLATE utf8mb4_general_ci,
  `down_payment_recorded_by` int UNSIGNED DEFAULT NULL,
  `down_payment_recorded_at` datetime DEFAULT NULL,
  `merchant_user_id` int UNSIGNED DEFAULT NULL,
  `temp_password_plain` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `preferred_stall_id` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contract_file` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contract_uploaded_at` datetime DEFAULT NULL,
  `contract_uploaded_by` int UNSIGNED DEFAULT NULL,
  `deposit_amount` decimal(10,2) DEFAULT NULL,
  `advance_amount` decimal(10,2) DEFAULT NULL,
  `rental_start_date` date DEFAULT NULL,
  `payment_schedule_day` tinyint UNSIGNED DEFAULT NULL,
  `awarded_by` int UNSIGNED DEFAULT NULL,
  `awarded_at` datetime DEFAULT NULL,
  `cancelled_by` int UNSIGNED DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` text COLLATE utf8mb4_general_ci,
  `first_viewed_at` datetime DEFAULT NULL,
  `first_viewed_by` int UNSIGNED DEFAULT NULL,
  `payment_method` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_ref_no` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Public stall applications with file paths';

--
-- Dumping data for table `stall_applications`
--

INSERT INTO `stall_applications` (`id`, `stall_id`, `business_name`, `first_name`, `middle_name`, `last_name`, `suffix`, `sex`, `street`, `barangay`, `city`, `province`, `proprietor_name`, `contact_number`, `email`, `profile_picture`, `business_permit`, `sanitary_permit`, `gjc_requirements`, `clearance`, `terms_accepted`, `status`, `current_step`, `reviewed_by`, `reviewed_at`, `rejection_reason`, `contract_ref`, `signed_at`, `initially_approved_by`, `initially_approved_at`, `created_at`, `updated_at`, `meetup_scheduled_at`, `meetup_location`, `meetup_notes`, `meetup_scheduled_by`, `meetup_scheduled_email_sent_at`, `down_payment_amount`, `down_payment_reference`, `down_payment_notes`, `down_payment_recorded_by`, `down_payment_recorded_at`, `merchant_user_id`, `temp_password_plain`, `preferred_stall_id`, `contract_file`, `contract_uploaded_at`, `contract_uploaded_by`, `deposit_amount`, `advance_amount`, `rental_start_date`, `payment_schedule_day`, `awarded_by`, `awarded_at`, `cancelled_by`, `cancelled_at`, `cancel_reason`, `first_viewed_at`, `first_viewed_by`, `payment_method`, `payment_ref_no`) VALUES
(28, NULL, 'Microsoft Corporation', 'Ezekiel Clarence', 'Santos', 'Santiago', NULL, 'male', 'Purok 4', 'San Fernando Sur', 'Cabiao', 'Nueva Ecija', 'Ezekiel Clarence Santos Santiago', '09614708398', 'ezekielclarence06@gmail.com', 'uploads/stall_applications/28/profile_picture_17839946688438.jpg', 'uploads/stall_applications/28/business_permit_17839946687716.pdf', 'uploads/stall_applications/28/sanitary_permit_17839946684043.jpg', 'uploads/stall_applications/28/gjc_requirements_17839946687255.pdf', 'uploads/stall_applications/28/clearance_17839946688389.pdf', 1, 'expired', 1, 16, '2026-07-17 20:52:15', 'Applicant did not attend the scheduled verification meeting on Jul 15, 2026 9:00 AM.', NULL, NULL, NULL, NULL, '2026-07-14 10:04:28', '2026-07-17 20:52:15', '2026-07-15 09:00:00', 'GJC Finance Office', NULL, NULL, '2026-07-14 10:04:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'A3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-14 10:05:02', 16, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_fees`
--

CREATE TABLE `student_fees` (
  `id` int UNSIGNED NOT NULL,
  `student_user_id` int UNSIGNED NOT NULL,
  `tuition_fee` decimal(10,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_fees`
--

INSERT INTO `student_fees` (`id`, `student_user_id`, `tuition_fee`, `created_at`, `updated_at`) VALUES
(1, 1, 0.07, '2026-07-12 11:09:37', '2026-07-12 11:15:38'),
(2, 2, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(3, 5, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(4, 6, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(5, 10, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(6, 23, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(7, 247, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(8, 249, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(9, 251, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(10, 253, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(11, 255, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(12, 257, 20000.00, '2026-07-12 11:09:37', '2026-07-12 11:46:00'),
(13, 258, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(14, 260, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(15, 261, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(16, 263, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(17, 265, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(18, 267, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(19, 268, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(20, 270, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(21, 272, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(22, 282, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(23, 283, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(24, 285, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(25, 287, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(26, 288, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(27, 290, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(28, 291, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(29, 293, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(30, 295, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(31, 297, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(32, 299, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(33, 301, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(34, 303, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(35, 305, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(36, 307, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(37, 309, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(38, 310, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(39, 312, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(40, 314, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(41, 316, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(42, 318, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(43, 319, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(44, 321, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(45, 322, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(46, 324, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(47, 325, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(48, 326, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(49, 327, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(50, 328, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(51, 330, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(52, 332, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(53, 334, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(54, 336, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(55, 338, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(56, 339, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(57, 341, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(58, 342, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(59, 344, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(60, 346, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(61, 348, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(62, 349, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(63, 351, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(64, 353, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(65, 355, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(66, 357, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(67, 359, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(68, 361, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(69, 363, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(70, 365, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(71, 367, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(72, 369, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(73, 371, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(74, 373, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(75, 375, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(76, 377, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(77, 379, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(78, 381, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(79, 383, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(80, 385, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(81, 386, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(82, 388, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(83, 389, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(84, 391, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(85, 393, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(86, 395, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(87, 397, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(88, 399, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(89, 400, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(90, 402, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(91, 404, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(92, 406, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(93, 408, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(94, 409, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(95, 411, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(96, 413, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(97, 415, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(98, 417, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(99, 419, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(100, 421, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(101, 423, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(102, 425, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(103, 427, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(104, 428, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(105, 430, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(106, 432, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(107, 434, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(108, 436, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(109, 437, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(110, 439, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(111, 440, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(112, 442, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(113, 443, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(114, 445, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(115, 447, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(116, 448, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(117, 450, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(118, 451, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(119, 452, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(120, 453, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37'),
(121, 455, NULL, '2026-07-12 11:09:37', '2026-07-12 11:09:37');

-- --------------------------------------------------------

--
-- Table structure for table `student_info`
--

CREATE TABLE `student_info` (
  `stud_infoID` int NOT NULL,
  `userID` int NOT NULL,
  `studentID` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `yr_lvl` varchar(11) COLLATE utf8mb4_general_ci NOT NULL,
  `courseID` int NOT NULL,
  `graduated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_info`
--

INSERT INTO `student_info` (`stud_infoID`, `userID`, `studentID`, `yr_lvl`, `courseID`, `graduated_at`) VALUES
(1, 23, 'GJC2026-0001', '1', 1, NULL),
(7, 6, 'GJC2026-0002', '4th Year', 18, '2026-07-17 21:39:57'),
(12, 1, 'GJC2026-0003', '1', 1, NULL),
(13, 2, 'GJC2026-0004', '1', 1, NULL),
(14, 5, 'GJC2026-0005', '1', 1, NULL),
(15, 10, 'GJC2026-0006', '1', 1, NULL),
(135, 247, 'GJC2026-0007', '1', 11, NULL),
(136, 249, 'GJC2026-0008', '1', 11, NULL),
(137, 251, 'GJC2026-0009', '1', 1, NULL),
(138, 253, 'GJC2026-0010', '1', 1, NULL),
(139, 255, 'GJC2026-0011', '1', 17, NULL),
(140, 257, 'GJC2026-0012', '1', 13, NULL),
(141, 258, 'GJC2026-0013', '1', 6, NULL),
(142, 260, 'GJC2026-0014', '1', 14, NULL),
(143, 261, 'GJC2026-0015', '1', 11, NULL),
(144, 263, 'GJC2026-0016', '1', 17, NULL),
(145, 265, 'GJC2026-0017', '1', 5, NULL),
(146, 267, 'GJC2026-0018', '1', 13, NULL),
(147, 268, 'GJC2026-0019', '1', 1, NULL),
(148, 270, 'GJC2026-0020', '1', 6, NULL),
(149, 272, 'GJC2026-0021', '1', 11, NULL),
(150, 282, 'GJC2026-0022', '1', 1, NULL),
(151, 283, 'GJC2026-0023', '1', 12, NULL),
(152, 285, 'GJC2026-0024', '1', 6, NULL),
(153, 287, 'GJC2026-0025', '1', 14, NULL),
(154, 288, 'GJC2026-0026', '1', 1, NULL),
(155, 290, 'GJC2026-0027', '1', 12, NULL),
(156, 291, 'GJC2026-0028', '1', 22, NULL),
(157, 293, 'GJC2026-0029', '1', 6, NULL),
(158, 295, 'GJC2026-0030', '1', 6, NULL),
(159, 297, 'GJC2026-0031', '1', 23, NULL),
(160, 299, 'GJC2026-0032', '1', 17, NULL),
(161, 301, 'GJC2026-0033', '1', 22, NULL),
(162, 303, 'GJC2026-0034', '1', 6, NULL),
(163, 305, 'GJC2026-0035', '1', 23, NULL),
(164, 307, 'GJC2026-0036', '1', 1, NULL),
(165, 309, 'GJC2026-0037', '1', 23, NULL),
(166, 310, 'GJC2026-0038', '1', 13, NULL),
(167, 312, 'GJC2026-0039', '1', 14, NULL),
(168, 314, 'GJC2026-0040', '1', 13, NULL),
(169, 316, 'GJC2026-0041', '1', 12, NULL),
(170, 318, 'GJC2026-0042', '1', 6, NULL),
(171, 319, 'GJC2026-0043', '1', 22, NULL),
(172, 321, 'GJC2026-0044', '1', 11, NULL),
(173, 322, 'GJC2026-0045', '1', 6, NULL),
(174, 324, 'GJC2026-0046', '1', 5, NULL),
(175, 325, 'GJC2026-0047', '1', 13, NULL),
(176, 326, 'GJC2026-0048', '1', 17, NULL),
(177, 327, 'GJC2026-0049', '1', 17, NULL),
(178, 328, 'GJC2026-0050', '1', 6, NULL),
(179, 330, 'GJC2026-0051', '1', 13, NULL),
(180, 332, 'GJC2026-0052', '1', 23, NULL),
(181, 334, 'GJC2026-0053', '1', 5, NULL),
(182, 336, 'GJC2026-0054', '1', 14, NULL),
(183, 338, 'GJC2026-0055', '1', 14, NULL),
(184, 339, 'GJC2026-0056', '1', 6, NULL),
(185, 341, 'GJC2026-0057', '1', 5, NULL),
(186, 342, 'GJC2026-0058', '1', 5, NULL),
(187, 344, 'GJC2026-0059', '1', 5, NULL),
(188, 346, 'GJC2026-0060', '1', 17, NULL),
(189, 348, 'GJC2026-0061', '1', 23, NULL),
(190, 349, 'GJC2026-0062', '1', 1, NULL),
(191, 351, 'GJC2026-0063', '1', 17, NULL),
(192, 353, 'GJC2026-0064', '1', 13, NULL),
(193, 355, 'GJC2026-0065', '1', 23, NULL),
(194, 357, 'GJC2026-0066', '1', 5, NULL),
(195, 359, 'GJC2026-0067', '1', 14, NULL),
(196, 361, 'GJC2026-0068', '1', 6, NULL),
(197, 363, 'GJC2026-0069', '1', 22, NULL),
(198, 365, 'GJC2026-0070', '1', 14, NULL),
(199, 367, 'GJC2026-0071', '1', 1, NULL),
(200, 369, 'GJC2026-0072', '1', 11, NULL),
(201, 371, 'GJC2026-0073', '1', 13, NULL),
(202, 373, 'GJC2026-0074', '1', 6, NULL),
(203, 375, 'GJC2026-0075', '1', 6, NULL),
(204, 377, 'GJC2026-0076', '1', 6, NULL),
(205, 379, 'GJC2026-0077', '1', 13, NULL),
(206, 381, 'GJC2026-0078', '1', 22, NULL),
(207, 383, 'GJC2026-0079', '1', 14, NULL),
(208, 385, 'GJC2026-0080', '1', 12, NULL),
(209, 386, 'GJC2026-0081', '1', 1, NULL),
(210, 388, 'GJC2026-0082', '1', 6, NULL),
(211, 389, 'GJC2026-0083', '1', 17, NULL),
(212, 391, 'GJC2026-0084', '1', 11, NULL),
(213, 393, 'GJC2026-0085', '1', 17, NULL),
(214, 395, 'GJC2026-0086', '1', 5, NULL),
(215, 397, 'GJC2026-0087', '1', 22, NULL),
(216, 399, 'GJC2026-0088', '1', 14, NULL),
(217, 400, 'GJC2026-0089', '1', 6, NULL),
(218, 402, 'GJC2026-0090', '1', 23, NULL),
(219, 404, 'GJC2026-0091', '1', 6, NULL),
(220, 406, 'GJC2026-0092', '1', 14, NULL),
(221, 408, 'GJC2026-0093', '1', 11, NULL),
(222, 409, 'GJC2026-0094', '1', 5, NULL),
(223, 411, 'GJC2026-0095', '1', 1, NULL),
(224, 413, 'GJC2026-0096', '1', 5, NULL),
(225, 415, 'GJC2026-0097', '1', 11, NULL),
(226, 417, 'GJC2026-0098', '1', 17, NULL),
(227, 419, 'GJC2026-0099', '1', 13, NULL),
(228, 421, 'GJC2026-0100', '1', 23, NULL),
(229, 423, 'GJC2026-0101', '1', 13, NULL),
(230, 425, 'GJC2026-0102', '1', 13, NULL),
(231, 427, 'GJC2026-0103', '1', 17, NULL),
(232, 428, 'GJC2026-0104', '1', 11, NULL),
(233, 430, 'GJC2026-0105', '1', 12, NULL),
(234, 432, 'GJC2026-0106', '1', 13, NULL),
(235, 434, 'GJC2026-0107', '1', 13, NULL),
(236, 436, 'GJC2026-0108', '1', 1, NULL),
(237, 437, 'GJC2026-0109', '1', 11, NULL),
(238, 439, 'GJC2026-0110', '1', 22, NULL),
(239, 440, 'GJC2026-0111', '1', 14, NULL),
(240, 442, 'GJC2026-0112', '1', 12, NULL),
(241, 443, 'GJC2026-0113', '1', 6, NULL),
(242, 445, 'GJC2026-0114', '1', 5, NULL),
(243, 447, 'GJC2026-0115', '1', 23, NULL),
(244, 448, 'GJC2026-0116', '1', 22, NULL),
(245, 450, 'GJC2026-0117', '1', 5, NULL),
(246, 451, 'GJC2026-0118', '1', 13, NULL),
(247, 452, 'GJC2026-0119', '1', 11, NULL),
(248, 453, 'GJC2026-0120', '1', 12, NULL),
(249, 455, 'GJC2026-0121', '1', 5, NULL),
(250, 464, 'GJC2026-0122', '1', 18, NULL),
(251, 465, 'GJC2026-0123', '1', 11, NULL),
(252, 466, 'GJC2026-0124', '1', 19, NULL),
(253, 467, 'GJC2026-0125', '1', 20, NULL),
(254, 468, 'GJC2026-0126', '1', 21, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_wallets`
--

CREATE TABLE `student_wallets` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL COMMENT 'FK -> users.id (student role)',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Current spendable balance in PHP points',
  `daily_spend_limit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_frozen` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_wallets`
--

INSERT INTO `student_wallets` (`id`, `user_id`, `balance`, `daily_spend_limit`, `is_frozen`, `created_at`, `updated_at`) VALUES
(1, 2, 936.00, 0.00, 0, '2026-05-13 00:00:00', '2026-07-10 21:57:20'),
(2, 1, 625.82, 0.00, 0, '2026-05-13 00:00:00', '2026-07-20 13:54:59'),
(3, 10, 0.00, 0.00, 0, '2026-06-09 21:32:05', '2026-06-09 21:32:05'),
(4, 23, 20003.00, 0.00, 0, '2026-06-16 01:10:47', '2026-07-14 10:02:42'),
(10, 6, 0.00, 0.00, 1, '2026-06-22 22:19:34', '2026-07-17 22:17:25'),
(130, 247, 0.00, 0.00, 0, '2026-07-04 21:52:20', '2026-07-04 21:52:20'),
(131, 249, 0.00, 0.00, 0, '2026-07-04 21:52:21', '2026-07-04 21:52:21'),
(132, 251, 0.00, 0.00, 0, '2026-07-04 21:52:21', '2026-07-04 21:52:21'),
(133, 253, 0.00, 0.00, 0, '2026-07-04 21:52:21', '2026-07-04 21:52:21'),
(134, 255, 0.00, 0.00, 0, '2026-07-04 21:52:21', '2026-07-04 21:52:21'),
(135, 257, 0.00, 0.00, 0, '2026-07-04 21:52:21', '2026-07-04 21:52:21'),
(136, 258, 0.00, 0.00, 0, '2026-07-04 21:52:21', '2026-07-04 21:52:21'),
(137, 260, 0.00, 0.00, 0, '2026-07-04 21:52:21', '2026-07-04 21:52:21'),
(138, 261, 0.00, 0.00, 0, '2026-07-04 21:52:22', '2026-07-04 21:52:22'),
(139, 263, 0.00, 0.00, 0, '2026-07-04 21:52:22', '2026-07-04 21:52:22'),
(140, 265, 0.00, 0.00, 0, '2026-07-04 21:52:22', '2026-07-04 21:52:22'),
(141, 267, 0.00, 0.00, 0, '2026-07-04 21:52:22', '2026-07-04 21:52:22'),
(142, 268, 0.00, 0.00, 0, '2026-07-04 21:52:22', '2026-07-04 21:52:22'),
(143, 270, 0.00, 0.00, 0, '2026-07-04 21:52:22', '2026-07-04 21:52:22'),
(144, 272, 0.00, 0.00, 0, '2026-07-04 21:52:23', '2026-07-04 21:52:23'),
(145, 282, 0.00, 0.00, 0, '2026-07-07 11:44:10', '2026-07-07 11:44:10'),
(146, 283, 0.00, 0.00, 0, '2026-07-07 11:44:11', '2026-07-07 11:44:11'),
(147, 285, 0.00, 0.00, 0, '2026-07-07 11:44:11', '2026-07-07 11:44:11'),
(148, 287, 0.00, 0.00, 0, '2026-07-07 11:44:11', '2026-07-07 11:44:11'),
(149, 288, 0.00, 0.00, 0, '2026-07-07 11:44:12', '2026-07-07 11:44:12'),
(150, 290, 0.00, 0.00, 0, '2026-07-07 11:44:12', '2026-07-07 11:44:12'),
(151, 291, 0.00, 0.00, 0, '2026-07-07 11:44:12', '2026-07-07 11:44:12'),
(152, 293, 0.00, 0.00, 0, '2026-07-07 11:44:12', '2026-07-07 11:44:12'),
(153, 295, 0.00, 0.00, 0, '2026-07-07 11:44:13', '2026-07-07 11:44:13'),
(154, 297, 0.00, 0.00, 0, '2026-07-07 11:44:13', '2026-07-07 11:44:13'),
(155, 299, 0.00, 0.00, 0, '2026-07-07 11:44:13', '2026-07-07 11:44:13'),
(156, 301, 0.00, 0.00, 0, '2026-07-07 11:44:14', '2026-07-07 11:44:14'),
(157, 303, 0.00, 0.00, 0, '2026-07-07 11:44:14', '2026-07-07 11:44:14'),
(158, 305, 0.00, 0.00, 0, '2026-07-07 11:44:14', '2026-07-07 11:44:14'),
(159, 307, 0.00, 0.00, 0, '2026-07-07 11:44:15', '2026-07-07 11:44:15'),
(160, 309, 0.00, 0.00, 0, '2026-07-07 11:44:15', '2026-07-07 11:44:15'),
(161, 310, 0.00, 0.00, 0, '2026-07-07 11:44:15', '2026-07-07 11:44:15'),
(162, 312, 0.00, 0.00, 0, '2026-07-07 11:44:16', '2026-07-07 11:44:16'),
(163, 314, 0.00, 0.00, 0, '2026-07-07 11:44:16', '2026-07-07 11:44:16'),
(164, 316, 0.00, 0.00, 0, '2026-07-07 11:44:16', '2026-07-07 11:44:16'),
(165, 318, 0.00, 0.00, 0, '2026-07-07 11:44:17', '2026-07-07 11:44:17'),
(166, 319, 0.00, 0.00, 0, '2026-07-07 11:44:17', '2026-07-07 11:44:17'),
(167, 321, 0.00, 0.00, 0, '2026-07-07 11:44:17', '2026-07-07 11:44:17'),
(168, 322, 0.00, 0.00, 0, '2026-07-07 11:44:17', '2026-07-07 11:44:17'),
(169, 324, 0.00, 0.00, 0, '2026-07-07 11:44:18', '2026-07-07 11:44:18'),
(170, 325, 0.00, 0.00, 0, '2026-07-07 11:44:18', '2026-07-07 11:44:18'),
(171, 326, 0.00, 0.00, 0, '2026-07-07 11:44:18', '2026-07-07 11:44:18'),
(172, 327, 0.00, 0.00, 0, '2026-07-07 11:44:18', '2026-07-07 11:44:18'),
(173, 328, 0.00, 0.00, 0, '2026-07-07 11:44:18', '2026-07-07 11:44:18'),
(174, 330, 0.00, 0.00, 0, '2026-07-07 11:44:19', '2026-07-07 11:44:19'),
(175, 332, 0.00, 0.00, 0, '2026-07-07 11:44:19', '2026-07-07 11:44:19'),
(176, 334, 0.00, 0.00, 0, '2026-07-07 11:44:19', '2026-07-07 11:44:19'),
(177, 336, 0.00, 0.00, 0, '2026-07-07 11:44:20', '2026-07-07 11:44:20'),
(178, 338, 0.00, 0.00, 0, '2026-07-07 11:44:20', '2026-07-07 11:44:20'),
(179, 339, 0.00, 0.00, 0, '2026-07-07 11:44:20', '2026-07-07 11:44:20'),
(180, 341, 0.00, 0.00, 0, '2026-07-07 11:44:21', '2026-07-07 11:44:21'),
(181, 342, 0.00, 0.00, 0, '2026-07-07 11:44:21', '2026-07-07 11:44:21'),
(182, 344, 0.00, 0.00, 0, '2026-07-07 11:44:21', '2026-07-07 11:44:21'),
(183, 346, 0.00, 0.00, 0, '2026-07-07 11:44:21', '2026-07-07 11:44:21'),
(184, 348, 0.00, 0.00, 0, '2026-07-07 11:44:22', '2026-07-07 11:44:22'),
(185, 349, 0.00, 0.00, 0, '2026-07-07 11:44:22', '2026-07-07 11:44:22'),
(186, 351, 0.00, 0.00, 0, '2026-07-07 11:44:22', '2026-07-07 11:44:22'),
(187, 353, 0.00, 0.00, 0, '2026-07-07 11:44:23', '2026-07-07 11:44:23'),
(188, 355, 0.00, 0.00, 0, '2026-07-07 11:44:23', '2026-07-07 11:44:23'),
(189, 357, 0.00, 0.00, 0, '2026-07-07 11:44:23', '2026-07-07 11:44:23'),
(190, 359, 0.00, 0.00, 0, '2026-07-07 11:44:24', '2026-07-07 11:44:24'),
(191, 361, 0.00, 0.00, 0, '2026-07-07 11:44:24', '2026-07-07 11:44:24'),
(192, 363, 0.00, 0.00, 0, '2026-07-07 11:44:24', '2026-07-07 11:44:24'),
(193, 365, 0.00, 0.00, 0, '2026-07-07 11:44:25', '2026-07-07 11:44:25'),
(194, 367, 0.00, 0.00, 0, '2026-07-07 11:44:25', '2026-07-07 11:44:25'),
(195, 369, 0.00, 0.00, 0, '2026-07-07 11:44:25', '2026-07-07 11:44:25'),
(196, 371, 0.00, 0.00, 0, '2026-07-07 11:44:26', '2026-07-07 11:44:26'),
(197, 373, 0.00, 0.00, 0, '2026-07-07 11:44:26', '2026-07-07 11:44:26'),
(198, 375, 0.00, 0.00, 0, '2026-07-07 11:44:27', '2026-07-07 11:44:27'),
(199, 377, 0.00, 0.00, 0, '2026-07-07 11:44:27', '2026-07-07 11:44:27'),
(200, 379, 0.00, 0.00, 0, '2026-07-07 11:44:27', '2026-07-07 11:44:27'),
(201, 381, 0.00, 0.00, 0, '2026-07-07 11:44:28', '2026-07-07 11:44:28'),
(202, 383, 0.00, 0.00, 0, '2026-07-07 11:44:28', '2026-07-07 11:44:28'),
(203, 385, 0.00, 0.00, 0, '2026-07-07 11:44:28', '2026-07-07 11:44:28'),
(204, 386, 0.00, 0.00, 0, '2026-07-07 11:44:28', '2026-07-07 11:44:28'),
(205, 388, 0.00, 0.00, 0, '2026-07-07 11:44:29', '2026-07-07 11:44:29'),
(206, 389, 0.00, 0.00, 0, '2026-07-07 11:44:29', '2026-07-07 11:44:29'),
(207, 391, 0.00, 0.00, 0, '2026-07-07 11:44:29', '2026-07-07 11:44:29'),
(208, 393, 0.00, 0.00, 0, '2026-07-07 11:44:30', '2026-07-07 11:44:30'),
(209, 395, 0.00, 0.00, 0, '2026-07-07 11:44:30', '2026-07-07 11:44:30'),
(210, 397, 0.00, 0.00, 0, '2026-07-07 11:44:30', '2026-07-07 11:44:30'),
(211, 399, 0.00, 0.00, 0, '2026-07-07 11:44:31', '2026-07-07 11:44:31'),
(212, 400, 0.00, 0.00, 0, '2026-07-07 11:44:31', '2026-07-07 11:44:31'),
(213, 402, 0.00, 0.00, 0, '2026-07-07 11:44:31', '2026-07-07 11:44:31'),
(214, 404, 0.00, 0.00, 0, '2026-07-07 11:44:32', '2026-07-07 11:44:32'),
(215, 406, 0.00, 0.00, 0, '2026-07-07 11:44:32', '2026-07-07 11:44:32'),
(216, 408, 0.00, 0.00, 0, '2026-07-07 11:44:32', '2026-07-07 11:44:32'),
(217, 409, 0.00, 0.00, 0, '2026-07-07 11:44:33', '2026-07-07 11:44:33'),
(218, 411, 0.00, 0.00, 0, '2026-07-07 11:44:33', '2026-07-07 11:44:33'),
(219, 413, 0.00, 0.00, 0, '2026-07-07 11:44:33', '2026-07-07 11:44:33'),
(220, 415, 0.00, 0.00, 0, '2026-07-07 11:44:34', '2026-07-07 11:44:34'),
(221, 417, 0.00, 0.00, 0, '2026-07-07 11:44:34', '2026-07-07 11:44:34'),
(222, 419, 0.00, 0.00, 0, '2026-07-07 11:44:34', '2026-07-07 11:44:34'),
(223, 421, 0.00, 0.00, 0, '2026-07-07 11:44:35', '2026-07-07 11:44:35'),
(224, 423, 0.00, 0.00, 0, '2026-07-07 11:44:35', '2026-07-07 11:44:35'),
(225, 425, 0.00, 0.00, 0, '2026-07-07 11:44:35', '2026-07-07 11:44:35'),
(226, 427, 0.00, 0.00, 0, '2026-07-07 11:44:35', '2026-07-07 11:44:35'),
(227, 428, 0.00, 0.00, 0, '2026-07-07 11:44:36', '2026-07-07 11:44:36'),
(228, 430, 0.00, 0.00, 0, '2026-07-07 11:44:36', '2026-07-07 11:44:36'),
(229, 432, 0.00, 0.00, 0, '2026-07-07 11:44:36', '2026-07-07 11:44:36'),
(230, 434, 0.00, 0.00, 0, '2026-07-07 11:44:36', '2026-07-07 11:44:36'),
(231, 436, 0.00, 0.00, 0, '2026-07-07 11:44:37', '2026-07-07 11:44:37'),
(232, 437, 0.00, 0.00, 0, '2026-07-07 11:44:37', '2026-07-07 11:44:37'),
(233, 439, 0.00, 0.00, 0, '2026-07-07 11:44:37', '2026-07-07 11:44:37'),
(234, 440, 0.00, 0.00, 0, '2026-07-07 11:44:37', '2026-07-07 11:44:37'),
(235, 442, 0.00, 0.00, 0, '2026-07-07 11:44:37', '2026-07-07 11:44:37'),
(236, 443, 0.00, 0.00, 0, '2026-07-07 11:44:38', '2026-07-07 11:44:38'),
(237, 445, 0.00, 0.00, 0, '2026-07-07 11:44:38', '2026-07-07 11:44:38'),
(238, 447, 0.00, 0.00, 0, '2026-07-07 11:44:38', '2026-07-07 11:44:38'),
(239, 448, 0.00, 0.00, 0, '2026-07-07 11:44:38', '2026-07-07 11:44:38'),
(240, 450, 0.00, 0.00, 0, '2026-07-07 11:44:39', '2026-07-07 11:44:39'),
(241, 451, 0.00, 0.00, 0, '2026-07-07 11:44:39', '2026-07-07 11:44:39'),
(242, 452, 0.00, 0.00, 0, '2026-07-07 11:44:39', '2026-07-07 11:44:39'),
(243, 453, 0.00, 0.00, 0, '2026-07-07 11:44:39', '2026-07-07 11:44:39'),
(244, 455, 0.00, 0.00, 0, '2026-07-07 11:44:39', '2026-07-07 11:44:39'),
(245, 464, 0.00, 0.00, 0, '2026-07-14 09:48:29', '2026-07-14 09:48:29'),
(246, 465, 0.00, 0.00, 0, '2026-07-14 09:48:29', '2026-07-14 09:48:29'),
(247, 466, 0.00, 0.00, 0, '2026-07-14 09:48:29', '2026-07-14 09:48:29'),
(248, 467, 0.00, 0.00, 0, '2026-07-14 09:48:29', '2026-07-14 09:48:29'),
(249, 468, 0.00, 0.00, 0, '2026-07-14 09:48:30', '2026-07-14 09:48:30');

-- --------------------------------------------------------

--
-- Table structure for table `systemic_audit_trail`
--

CREATE TABLE `systemic_audit_trail` (
  `log_id` int NOT NULL,
  `user_id` int NOT NULL,
  `user_role` enum('Finance','Student','Merchant','Vendor/Staff') COLLATE utf8mb4_general_ci NOT NULL,
  `action_type` enum('LOGIN','LOGOUT','PASSWORD_CHANGE','TRANSACTION','MENU_MUTATION','STALL_UPDATE','USER_IMPORT','MERCHANT_CREATE','USER_ACCOUNT','MERCHANT_ONBOARDING','PRODUCT_RESTRICTION','LOGIN_FAILED','TUITION_CREDIT','FEE_WAIVER_STATUS_CHANGE','SCHOOL_YEAR_CREATED','SCHOOL_YEAR_ROLLOVER','STUDENT_GRADUATED','SY_TXN_BACKFILL') COLLATE utf8mb4_general_ci NOT NULL,
  `stall_id` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `affected_table` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `old_value` text COLLATE utf8mb4_general_ci,
  `new_value` text COLLATE utf8mb4_general_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci NOT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `systemic_audit_trail`
--

INSERT INTO `systemic_audit_trail` (`log_id`, `user_id`, `user_role`, `action_type`, `stall_id`, `affected_table`, `old_value`, `new_value`, `ip_address`, `user_agent`, `timestamp`) VALUES
(1, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-15 14:01:08'),
(2, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-15 14:01:43'),
(5, 2, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-15 14:07:46'),
(6, 2, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-15 14:53:18'),
(7, 2, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260615-37E9DF\",\"transaction_type\":\"payment\",\"amount\":225,\"student_wallet_id\":1,\"merchant_wallet_id\":1,\"items\":[{\"id\":20,\"name\":\"Tapsilog\",\"qty\":3,\"price\":75}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-15 15:01:06'),
(9, 16, 'Finance', 'STALL_UPDATE', 'A3', 'stalls', '{\"stall_id\":\"A3\",\"label\":\"Stall A3\",\"row_label\":\"A\",\"col_number\":3,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"pending_application\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 23:09:11\"}', '{\"stall_id\":\"A3\",\"label\":\"Stall A3\",\"row_label\":\"A\",\"col_number\":3,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":6,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 23:09:11\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-15 15:14:01'),
(13, 16, 'Finance', 'STALL_UPDATE', 'A2', 'stalls', '{\"stall_id\":\"A2\",\"label\":\"Stall A2\",\"row_label\":\"A\",\"col_number\":2,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"pending_application\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 21:12:06\"}', '{\"stall_id\":\"A2\",\"label\":\"Stall A2\",\"row_label\":\"A\",\"col_number\":2,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":8,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 21:12:06\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-15 16:36:55'),
(19, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-15 17:39:02'),
(20, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-15 17:39:18'),
(21, 23, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-15 17:39:36'),
(22, 23, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-15 17:40:59'),
(23, 7, 'Finance', 'STALL_UPDATE', 'A4', 'stalls', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-16 01:41:03\"}', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":9,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-16 01:41:03\"}', '0.0.0.0', 'Unknown', '2026-06-20 13:37:43'),
(24, 7, 'Finance', 'STALL_UPDATE', 'A4', 'stalls', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 21:38:38\"}', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":10,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 21:38:38\"}', '0.0.0.0', 'Unknown', '2026-06-20 13:47:42'),
(25, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 13:48:27'),
(26, 12, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 13:48:58'),
(27, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 13:50:51'),
(28, 12, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 13:56:59'),
(29, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 13:58:37'),
(30, 12, 'Finance', 'STALL_UPDATE', 'A4', 'stalls', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 21:48:58\"}', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":11,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 21:48:58\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 14:07:06'),
(31, 12, 'Finance', 'STALL_UPDATE', 'B2', 'stalls', '{\"stall_id\":\"B2\",\"label\":\"Stall B2\",\"row_label\":\"B\",\"col_number\":2,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 14:34:42\"}', '{\"stall_id\":\"B2\",\"label\":\"Stall B2\",\"row_label\":\"B\",\"col_number\":2,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":12,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 14:34:42\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 14:21:24'),
(35, 12, 'Finance', 'STALL_UPDATE', 'B1', 'stalls', '{\"stall_id\":\"B1\",\"label\":\"Stall B1\",\"row_label\":\"B\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 14:34:42\"}', '{\"stall_id\":\"B1\",\"label\":\"Stall B1\",\"row_label\":\"B\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":14,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 14:34:42\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 14:52:28'),
(36, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 14:52:58'),
(37, 29, 'Merchant', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 14:53:09'),
(38, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 14:53:28'),
(39, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 14:53:47'),
(40, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 15:00:05'),
(41, 7, 'Finance', 'TRANSACTION', NULL, 'system_settings', '{\"cashier_vault_points\":\"195850.00\"}', '{\"cashier_vault_points\":\"196075.00\"}', '0.0.0.0', 'Unknown', '2026-06-20 16:06:15'),
(42, 12, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-20 16:08:11'),
(43, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 04:22:14'),
(44, 12, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 04:59:57'),
(45, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:01:23'),
(46, 1, 'Student', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:01:49'),
(47, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:02:04'),
(48, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:02:43'),
(49, 16, 'Finance', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:02:54'),
(50, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:06:33'),
(51, 10, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":10,\"email\":\"daitodump@gmail.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:06:44'),
(52, 23, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'curl/8.17.0', '2026-06-21 05:15:43'),
(53, 10, 'Student', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:20:57'),
(54, 10, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:30:24'),
(55, 23, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'curl/8.17.0', '2026-06-21 05:33:07'),
(56, 23, 'Student', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'curl/8.17.0', '2026-06-21 05:33:16'),
(57, 23, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'curl/8.17.0', '2026-06-21 05:40:17'),
(58, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:46:51'),
(59, 23, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'curl/8.17.0', '2026-06-21 05:50:29'),
(60, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:55:01'),
(61, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:55:15'),
(62, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:55:58'),
(63, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 05:57:24'),
(64, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'curl/8.17.0', '2026-06-21 06:02:56'),
(65, 12, 'Finance', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'curl/8.17.0', '2026-06-21 06:03:15'),
(66, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'curl/8.17.0', '2026-06-21 06:09:05'),
(67, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'curl/8.17.0', '2026-06-21 06:14:13'),
(68, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 12:24:08'),
(69, 12, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260621-27910\",\"transaction_type\":\"cap_increase\",\"amount\":100,\"student_wallet_id\":null,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":196075,\"vault_after\":196175,\"total_in_circulation\":200100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 12:45:59'),
(70, 12, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260621-61508\",\"transaction_type\":\"cap_increase\",\"amount\":10000,\"student_wallet_id\":null,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":196175,\"vault_after\":206175,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 12:46:32'),
(71, 12, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 14:23:11'),
(72, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 14:23:50'),
(73, 16, 'Finance', 'USER_IMPORT', NULL, 'imported_student_registry', NULL, '{\"import_batch_id\":\"20260621224218\",\"total_rows\":5,\"imported\":5,\"duplicates\":0,\"failed\":0,\"created_user_ids\":[30,31,32,33,34]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 14:42:19'),
(74, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 14:43:15'),
(75, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 14:43:45'),
(80, 2, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-21 15:35:06'),
(81, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-21 15:35:21'),
(82, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:35:51'),
(83, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:36:03'),
(84, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:37:25'),
(85, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:37:33'),
(86, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:37:39'),
(87, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:37:48'),
(88, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:38:06'),
(89, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:38:22'),
(90, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":29,\"event\":\"password_active\"}', '{\"event\":\"password_reset\",\"user_id\":29,\"reset_by\":\"admin_request\",\"force_password_change\":1}', '0.0.0.0', 'Unknown', '2026-06-21 15:39:37'),
(91, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:40:10'),
(92, 29, 'Merchant', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:40:27'),
(93, 29, 'Merchant', 'MENU_MUTATION', NULL, 'merchant_inventory', NULL, '{\"event\":\"bulk_seed\",\"merchant_user_id\":29,\"count\":10,\"note\":\"Sample products added for Baste\'s Kwek\"}', '0.0.0.0', 'Unknown', '2026-06-21 15:42:13'),
(94, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260621-C75BAD\",\"transaction_type\":\"payment\",\"amount\":100,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":21,\"name\":\"Kwek-Kwek (6pcs)\",\"qty\":1,\"price\":35},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25},{\"id\":26,\"name\":\"Chicken Skin (1pc)\",\"qty\":1,\"price\":15},{\"id\":28,\"name\":\"Sauce Refill (cup)\",\"qty\":1,\"price\":5},{\"id\":30,\"name\":\"Soda in Cup (16oz)\",\"qty\":1,\"price\":20}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-21 15:44:51'),
(95, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:47:21'),
(96, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 15:47:53'),
(97, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260621-2E4533\",\"transaction_type\":\"payment\",\"amount\":65,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":2,\"price\":20},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-21 15:53:57'),
(98, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 11:56:29'),
(99, 29, 'Merchant', 'TRANSACTION', NULL, 'merchant_qr_orders', '{\"id\":6,\"token\":\"a30d079a3313be7766fddf41cfde77ab\",\"merchant_user_id\":29,\"merchant_wallet_id\":13,\"description\":\"1x Kikiam (4pcs), 1x Bottled Water (500ml), 1x Soda in Cup (16oz)\",\"items_json\":\"[{\\\"id\\\":25,\\\"name\\\":\\\"Kikiam (4pcs)\\\",\\\"qty\\\":1,\\\"price\\\":25},{\\\"id\\\":29,\\\"name\\\":\\\"Bottled Water (500ml)\\\",\\\"qty\\\":1,\\\"price\\\":15},{\\\"id\\\":30,\\\"name\\\":\\\"Soda in Cup (16oz)\\\",\\\"qty\\\":1,\\\"price\\\":20}]\",\"amount\":\"60.00\",\"status\":\"pending\",\"expires_at\":\"2026-06-22 20:35:15\",\"paid_by\":null,\"paid_ref\":null,\"paid_at\":null,\"created_at\":\"2026-06-22 20:20:15\"}', '{\"status\":\"voided\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 12:25:31'),
(100, 2, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 12:53:51'),
(101, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 12:54:04'),
(102, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 13:16:33'),
(103, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 13:16:44'),
(104, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', '{\"id\":28,\"merchant_user_id\":29,\"sku\":\"KWK-008\",\"product_name\":\"Sauce Refill (cup)\",\"description\":\"Sweet and spicy sauce refill\",\"category\":\"add-on\",\"unit\":\"cup\",\"price\":\"5.00\",\"stock_qty\":199,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null,\"approved_by\":null,\"restricted_by\":null,\"restricted_at\":null,\"created_at\":\"2026-06-21 23:42:13\",\"updated_at\":\"2026-06-21 23:44:51\"}', '{\"id\":28,\"merchant_user_id\":29,\"sku\":\"RICE01\",\"product_name\":\"Tapsilog\",\"description\":\"Sweet tapa with sunny side egg\",\"category\":\"food\",\"unit\":\"serving\",\"price\":55,\"stock_qty\":50,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 13:21:33'),
(105, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260622-82B8BD\",\"transaction_type\":\"payment\",\"amount\":70,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":50,\"line_total\":55},{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":100,\"line_total\":15}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 13:37:38'),
(106, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-22 13:57:01'),
(107, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 13:58:00'),
(108, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 14:00:37'),
(109, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260622-B8EA85\",\"transaction_type\":\"payment\",\"amount\":75,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":5,\"stock_qty\":99,\"line_total\":75}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 14:02:23'),
(110, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 14:16:52'),
(111, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-22 14:17:21'),
(112, 6, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":6,\"email\":\"jose.garcia@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-22 14:18:37'),
(113, 6, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":6,\"email\":\"jose.garcia@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-22 14:19:07'),
(114, 6, 'Student', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-22 14:19:31'),
(115, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260622-73987\",\"transaction_type\":\"cash_in\",\"amount\":499.99,\"student_wallet_id\":10,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":206175,\"vault_after\":205675.01,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 14:21:25'),
(116, 16, 'Finance', 'TRANSACTION', NULL, 'topup_requests', '{\"id\":5,\"status\":\"pending\"}', '{\"id\":5,\"status\":\"approved\",\"approved_by\":16,\"student_wallet_id\":10,\"amount\":499.99,\"reference_no\":\"TXN-20260622-73987\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 14:21:25'),
(117, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"P2P-20260622-4ADE0DBB\",\"transaction_type\":\"p2p_transfer\",\"amount\":1000,\"from_user_id\":1,\"to_user_id\":23,\"from_wallet_id\":2,\"to_wallet_id\":4,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 14:29:14'),
(118, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"P2P-20260622-C0CED566\",\"transaction_type\":\"p2p_transfer\",\"amount\":200,\"from_user_id\":1,\"to_user_id\":6,\"from_wallet_id\":2,\"to_wallet_id\":10,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 14:38:05'),
(119, 29, 'Merchant', 'TRANSACTION', NULL, 'cart_orders', '{\"id\":1,\"reference_no\":\"CART-20260622-49B503\",\"student_user_id\":1,\"student_wallet_id\":2,\"merchant_user_id\":29,\"merchant_wallet_id\":13,\"description\":\"1x Calamares (1 cup), 2x Chicken Skin (1pc), 1x Fishball (10pcs), 2x Squidball (10pcs), 4x Kwek-Kwek (6pcs), 6x Tokneneng (4pcs)\",\"items_json\":\"[{\\\"id\\\":27,\\\"sku\\\":\\\"KWK-007\\\",\\\"name\\\":\\\"Calamares (1 cup)\\\",\\\"price\\\":50,\\\"qty\\\":1,\\\"stock_qty\\\":60,\\\"line_total\\\":50},{\\\"id\\\":26,\\\"sku\\\":\\\"KWK-006\\\",\\\"name\\\":\\\"Chicken Skin (1pc)\\\",\\\"price\\\":15,\\\"qty\\\":2,\\\"stock_qty\\\":79,\\\"line_total\\\":30},{\\\"id\\\":23,\\\"sku\\\":\\\"KWK-003\\\",\\\"name\\\":\\\"Fishball (10pcs)\\\",\\\"price\\\":20,\\\"qty\\\":1,\\\"stock_qty\\\":148,\\\"line_total\\\":20},{\\\"id\\\":24,\\\"sku\\\":\\\"KWK-004\\\",\\\"name\\\":\\\"Squidball (10pcs)\\\",\\\"price\\\":25,\\\"qty\\\":2,\\\"stock_qty\\\":120,\\\"line_total\\\":50},{\\\"id\\\":21,\\\"sku\\\":\\\"KWK-001\\\",\\\"name\\\":\\\"Kwek-Kwek (6pcs)\\\",\\\"price\\\":35,\\\"qty\\\":4,\\\"stock_qty\\\":99,\\\"line_total\\\":140},{\\\"id\\\":22,\\\"sku\\\":\\\"KWK-002\\\",\\\"name\\\":\\\"Tokneneng (4pcs)\\\",\\\"price\\\":30,\\\"qty\\\":6,\\\"stock_qty\\\":100,\\\"line_total\\\":180}]\",\"amount\":\"470.00\",\"status\":\"pending\",\"created_at\":\"2026-06-22 22:48:25\",\"paid_at\":null,\"paid_ref\":null}', '{\"status\":\"voided\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 14:55:19'),
(120, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260622-FB99CB\",\"transaction_type\":\"payment\",\"amount\":420,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":26,\"sku\":\"KWK-006\",\"name\":\"Chicken Skin (1pc)\",\"price\":15,\"qty\":1,\"stock_qty\":79,\"line_total\":15},{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":1,\"stock_qty\":60,\"line_total\":50},{\"id\":25,\"sku\":\"KWK-005\",\"name\":\"Kikiam (4pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":98,\"line_total\":25},{\"id\":22,\"sku\":\"KWK-002\",\"name\":\"Tokneneng (4pcs)\",\"price\":30,\"qty\":1,\"stock_qty\":100,\"line_total\":30},{\"id\":23,\"sku\":\"KWK-003\",\"name\":\"Fishball (10pcs)\",\"price\":20,\"qty\":3,\"stock_qty\":148,\"line_total\":60},{\"id\":24,\"sku\":\"KWK-004\",\"name\":\"Squidball (10pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":120,\"line_total\":25},{\"id\":30,\"sku\":\"KWK-010\",\"name\":\"Soda in Cup (16oz)\",\"price\":20,\"qty\":1,\"stock_qty\":99,\"line_total\":20},{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":2,\"stock_qty\":94,\"line_total\":30},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":3,\"stock_qty\":49,\"line_total\":165}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 14:56:35'),
(121, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 15:21:00'),
(122, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 15:21:20'),
(123, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260622-35328D\",\"transaction_type\":\"payment\",\"amount\":495,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":25,\"sku\":\"KWK-005\",\"name\":\"Kikiam (4pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":97,\"line_total\":25},{\"id\":26,\"sku\":\"KWK-006\",\"name\":\"Chicken Skin (1pc)\",\"price\":15,\"qty\":2,\"stock_qty\":78,\"line_total\":30},{\"id\":24,\"sku\":\"KWK-004\",\"name\":\"Squidball (10pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":119,\"line_total\":25},{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":2,\"stock_qty\":59,\"line_total\":100},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":46,\"line_total\":55},{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":2,\"stock_qty\":92,\"line_total\":30},{\"id\":30,\"sku\":\"KWK-010\",\"name\":\"Soda in Cup (16oz)\",\"price\":20,\"qty\":1,\"stock_qty\":98,\"line_total\":20},{\"id\":23,\"sku\":\"KWK-003\",\"name\":\"Fishball (10pcs)\",\"price\":20,\"qty\":2,\"stock_qty\":145,\"line_total\":40},{\"id\":22,\"sku\":\"KWK-002\",\"name\":\"Tokneneng (4pcs)\",\"price\":30,\"qty\":1,\"stock_qty\":99,\"line_total\":30},{\"id\":21,\"sku\":\"KWK-001\",\"name\":\"Kwek-Kwek (6pcs)\",\"price\":35,\"qty\":4,\"stock_qty\":99,\"line_total\":140}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 15:30:50'),
(124, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-22 16:03:22'),
(125, 16, 'Finance', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:07:50'),
(126, 16, 'Finance', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:07:56'),
(127, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:08:02'),
(128, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:16:06'),
(129, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:25:38'),
(130, 16, 'Finance', 'STALL_UPDATE', 'A2', 'stalls', '{\"stall_id\":\"A2\",\"label\":\"Stall A2\",\"row_label\":\"A\",\"col_number\":2,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 22:37:02\"}', '{\"stall_id\":\"A2\",\"label\":\"Stall A2\",\"row_label\":\"A\",\"col_number\":2,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":15,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 22:37:02\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:26:30'),
(131, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:26:59'),
(132, 35, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":35,\"email\":\"noahgray430@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:27:08'),
(133, 35, 'Merchant', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:27:17'),
(134, 35, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:28:48'),
(135, 35, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":35,\"email\":\"noahgray430@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:29:05'),
(136, 35, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-22 16:30:24'),
(137, 16, 'Finance', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:45:20'),
(138, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:45:31'),
(139, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:46:34'),
(140, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:46:52'),
(141, 29, 'Merchant', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:47:02'),
(142, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:47:32'),
(143, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-23 02:47:49'),
(144, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', '{\"reference_no\":\"CART-20260623-E99CAE\",\"status\":\"pending\"}', '{\"reference_no\":\"CART-20260623-E99CAE\",\"status\":\"voided\",\"cancelled_by\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-23 02:50:00'),
(145, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-23 02:50:09'),
(146, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-23 02:50:31'),
(147, 16, 'Finance', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:50:44'),
(148, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:51:00'),
(149, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:51:09'),
(150, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:51:19'),
(151, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260623-5C1C63\",\"transaction_type\":\"payment\",\"amount\":25,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":25,\"sku\":\"KWK-005\",\"name\":\"Kikiam (4pcs)\",\"price\":25,\"qty\":1,\"stock_qty\":96,\"line_total\":25}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-23 02:52:58'),
(152, 6, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":6,\"email\":\"jose.garcia@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 14; 2409BRN2CA Build/UP1A.231005.007) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.7827.91 Mobile Safari/537.36 OPX/3.3', '2026-06-23 02:53:29'),
(153, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260623-64586\",\"transaction_type\":\"cash_in\",\"amount\":100,\"student_wallet_id\":2,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":205675.01,\"vault_after\":205575.01,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:54:02'),
(154, 16, 'Finance', 'TRANSACTION', NULL, 'topup_requests', '{\"id\":6,\"status\":\"pending\"}', '{\"id\":6,\"status\":\"approved\",\"approved_by\":16,\"student_wallet_id\":2,\"amount\":100,\"reference_no\":\"TXN-20260623-64586\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 02:54:02'),
(155, 6, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":6,\"email\":\"jose.garcia@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-23 02:56:31');
INSERT INTO `systemic_audit_trail` (`log_id`, `user_id`, `user_role`, `action_type`, `stall_id`, `affected_table`, `old_value`, `new_value`, `ip_address`, `user_agent`, `timestamp`) VALUES
(156, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 08:48:28'),
(157, 16, 'Finance', 'STALL_UPDATE', 'A1', 'stalls', '{\"stall_id\":\"A1\",\"label\":\"Stall A1\",\"row_label\":\"A\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 22:37:02\"}', '{\"stall_id\":\"A1\",\"label\":\"Stall A1\",\"row_label\":\"A\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":16,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 22:37:02\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-23 09:09:21'),
(163, 16, 'Finance', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-24 06:19:06'),
(164, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-24 06:19:16'),
(165, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-25 12:07:14'),
(166, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 12:43:03'),
(167, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 13:00:15'),
(168, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 13:19:29'),
(169, 16, 'Finance', 'STALL_UPDATE', 'B5', 'stalls', '{\"stall_id\":\"B5\",\"label\":\"Stall B5\",\"row_label\":\"B\",\"col_number\":5,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 14:34:42\"}', '{\"stall_id\":\"B5\",\"label\":\"Stall B5\",\"row_label\":\"B\",\"col_number\":5,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":17,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-15 14:34:42\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 13:41:24'),
(170, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 13:48:18'),
(171, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 14:38:52'),
(172, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260626-62444\",\"transaction_type\":\"cash_in\",\"amount\":100,\"student_wallet_id\":2,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":205575.01,\"vault_after\":205475.01,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:40:11'),
(173, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260626-75579\",\"transaction_type\":\"cash_in\",\"amount\":1000,\"student_wallet_id\":2,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":205475.01,\"vault_after\":204475.01,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:41:20'),
(174, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260626-84029\",\"transaction_type\":\"cash_in\",\"amount\":50,\"student_wallet_id\":2,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":204475.01,\"vault_after\":204425.01,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:45:47'),
(175, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260626-52660\",\"transaction_type\":\"cash_in\",\"amount\":500,\"student_wallet_id\":1,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":204425.01,\"vault_after\":203925.01,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:46:27'),
(176, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":1,\"is_active\":1}', '{\"event\":\"status_changed\",\"id\":1,\"is_active\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:51:40'),
(177, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":1,\"is_active\":0}', '{\"event\":\"status_changed\",\"id\":1,\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:51:45'),
(178, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":1,\"is_active\":1}', '{\"event\":\"status_changed\",\"id\":1,\"is_active\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 14:51:45'),
(179, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":1,\"is_active\":0}', '{\"event\":\"status_changed\",\"id\":1,\"is_active\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:51:45'),
(180, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":1,\"is_active\":0}', '{\"event\":\"status_changed\",\"id\":1,\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:51:46'),
(181, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":1,\"is_active\":1}', '{\"event\":\"status_changed\",\"id\":1,\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 14:51:46'),
(182, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":4,\"product_name\":\"Cobra Energy Drink\",\"category\":\"beverage\",\"match_type\":\"contains\",\"reason\":\"Too much caffeine intake\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:53:49'),
(183, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 14:54:07'),
(184, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 14:54:55'),
(185, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', NULL, '{\"id\":32,\"merchant_user_id\":29,\"sku\":\"DRNK\",\"product_name\":\"Coca-Cola\",\"description\":\"Tignan kung bakit!\",\"category\":\"food\",\"unit\":\"pack\",\"price\":10,\"stock_qty\":5,\"min_stock_alert\":5,\"is_available\":0,\"is_restricted\":1,\"restriction_note\":\"High sugar content â€” DepEd nutritional guidelines\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 14:55:25'),
(186, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', NULL, '{\"id\":33,\"merchant_user_id\":29,\"sku\":\"DRNK1\",\"product_name\":\"Cobra\",\"description\":\"Tignan kung bakit!\",\"category\":\"beverage\",\"unit\":\"bottle\",\"price\":10,\"stock_qty\":5,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 14:58:19'),
(187, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":4,\"product_name\":\"Cobra Energy Drink\"}', '{\"event\":\"deleted\",\"id\":4}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:58:53'),
(188, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":5,\"product_name\":\"Cobra\",\"category\":\"beverage\",\"match_type\":\"contains\",\"reason\":\"Too much caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 14:59:11'),
(189, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', '{\"id\":33,\"merchant_user_id\":29,\"sku\":\"DRNK1\",\"product_name\":\"Cobra\",\"description\":\"Tignan kung bakit!\",\"category\":\"beverage\",\"unit\":\"bottle\",\"price\":\"10.00\",\"stock_qty\":5,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null,\"approved_by\":null,\"restricted_by\":null,\"restricted_at\":null,\"created_at\":\"2026-06-26 22:58:19\",\"updated_at\":\"2026-06-26 22:58:19\"}', '{\"id\":33,\"merchant_user_id\":29,\"sku\":\"DRNK2\",\"product_name\":\"Cobra Milk Tea\",\"description\":\"Tignan kung bakit!\",\"category\":\"beverage\",\"unit\":\"bottle\",\"price\":10,\"stock_qty\":5,\"min_stock_alert\":10,\"is_available\":0,\"is_restricted\":1,\"restriction_note\":\"Too much caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 14:59:33'),
(190, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 15:05:19'),
(191, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":6,\"product_name\":\"Alcohol\",\"category\":\"beverage\",\"match_type\":\"contains\",\"reason\":\"no drunky\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 15:31:17'),
(192, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 15:54:55'),
(193, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 15:54:58'),
(194, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 15:55:07'),
(195, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 15:55:31'),
(196, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260626-64911\",\"transaction_type\":\"cash_in\",\"amount\":9.7,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":203925.01,\"vault_after\":203915.21000000002,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 15:57:39'),
(197, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_wallet_load\",\"reference\":\"TXN-20260626-64911\",\"cash_amount\":10,\"system_fee\":0.2,\"merchant_fee\":0.1,\"credited_amount\":9.7,\"merchant_wallet\":13,\"student_wallet\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 15:57:39'),
(198, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260626-71955\",\"transaction_type\":\"cash_in\",\"amount\":58.2,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":203915.21,\"vault_after\":203856.41,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:13:16'),
(199, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_wallet_load\",\"reference\":\"TXN-20260626-71955\",\"cash_amount\":60,\"system_fee\":1.2,\"merchant_fee\":0.6,\"credited_amount\":58.2,\"merchant_wallet\":13,\"student_wallet\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:13:16'),
(200, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', NULL, '{\"event\":\"created\",\"user_id\":38,\"name\":\"Monica Emata\",\"email\":\"virgelopez611@gmail.com\",\"role\":\"merchant_staff\",\"merchant_owner_id\":29}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:31:45'),
(201, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":38,\"status\":\"Active\"}', '{\"event\":\"deactivated\",\"user_id\":38,\"changed_by\":29,\"new_status\":\"Inactive\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:31:53'),
(202, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":38,\"status\":\"Inactive\"}', '{\"event\":\"reactivated\",\"user_id\":38,\"changed_by\":29,\"new_status\":\"Active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:31:58'),
(203, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:32:10'),
(204, 38, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":38,\"email\":\"virgelopez611@gmail.com\",\"roleID\":6,\"sub_role\":\"merchant_staff\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:32:20'),
(205, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":38,\"status\":\"Active\"}', '{\"event\":\"deactivated\",\"user_id\":38,\"changed_by\":29,\"new_status\":\"Inactive\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:32:41'),
(206, 38, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:35:17'),
(207, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 16:36:18'),
(208, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":38,\"status\":\"Inactive\"}', '{\"event\":\"reactivated\",\"user_id\":38,\"changed_by\":29,\"new_status\":\"Active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:37:03'),
(209, 38, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":38,\"email\":\"virgelopez611@gmail.com\",\"roleID\":6,\"sub_role\":\"merchant_staff\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 16:37:04'),
(210, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":38,\"status\":\"Active\"}', '{\"event\":\"deactivated\",\"user_id\":38,\"changed_by\":29,\"new_status\":\"Inactive\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:37:19'),
(211, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":38,\"status\":\"Inactive\"}', '{\"event\":\"reactivated\",\"user_id\":38,\"changed_by\":29,\"new_status\":\"Active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:37:36'),
(212, 38, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":38,\"email\":\"virgelopez611@gmail.com\",\"roleID\":6,\"sub_role\":\"merchant_staff\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-26 16:37:39'),
(213, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":38,\"status\":\"Active\"}', '{\"event\":\"deactivated\",\"user_id\":38,\"changed_by\":29,\"new_status\":\"Inactive\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:37:56'),
(214, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:47:18'),
(215, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":38,\"status\":\"Inactive\"}', '{\"event\":\"reactivated\",\"user_id\":38,\"changed_by\":29,\"new_status\":\"Active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:54:33'),
(216, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260626-78829\",\"transaction_type\":\"cash_in\",\"amount\":194,\"student_wallet_id\":10,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":203856.41,\"vault_after\":203660.41,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:55:06'),
(217, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_wallet_load\",\"reference\":\"TXN-20260626-78829\",\"cash_amount\":200,\"system_fee\":4,\"merchant_fee\":2,\"credited_amount\":194,\"merchant_wallet\":13,\"student_wallet\":10}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-26 16:55:06'),
(218, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:19:50'),
(219, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:20:40'),
(220, 16, 'Finance', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:21:05'),
(221, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:21:13'),
(222, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:40:31'),
(223, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', '{\"id\":29,\"merchant_user_id\":29,\"sku\":\"KWK-009\",\"product_name\":\"Bottled Water (500ml)\",\"description\":\"Chilled bottled water\",\"category\":\"beverage\",\"unit\":\"bottle\",\"price\":\"15.00\",\"stock_qty\":90,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null,\"approved_by\":null,\"restricted_by\":null,\"restricted_at\":null,\"created_at\":\"2026-06-21 23:42:13\",\"updated_at\":\"2026-06-22 23:30:50\"}', '{\"id\":29,\"merchant_user_id\":29,\"sku\":\"KWK-009\",\"product_name\":\"Bottled Water (500ml)\",\"description\":\"Chilled bottled water\",\"category\":\"beverage\",\"unit\":\"bottle\",\"price\":15,\"stock_qty\":91,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:45:13'),
(224, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', '{\"id\":33,\"merchant_user_id\":29,\"sku\":\"DRNK2\",\"product_name\":\"Cobra Milk Tea\",\"description\":\"Tignan kung bakit!\",\"category\":\"beverage\",\"unit\":\"bottle\",\"price\":\"10.00\",\"stock_qty\":5,\"min_stock_alert\":10,\"is_available\":0,\"is_restricted\":1,\"restriction_note\":\"Too much caffeine\",\"approved_by\":null,\"restricted_by\":null,\"restricted_at\":null,\"created_at\":\"2026-06-26 22:58:19\",\"updated_at\":\"2026-06-26 22:59:33\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:45:23'),
(225, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', '{\"id\":30,\"merchant_user_id\":29,\"sku\":\"KWK-010\",\"product_name\":\"Soda in Cup (16oz)\",\"description\":\"Iced soft drink in cup\",\"category\":\"beverage\",\"unit\":\"cup\",\"price\":\"20.00\",\"stock_qty\":97,\"min_stock_alert\":5,\"is_available\":0,\"is_restricted\":1,\"restriction_note\":\"Restricted by school nutritional compliance review.\",\"approved_by\":null,\"restricted_by\":16,\"restricted_at\":\"2026-06-26 22:57:03\",\"created_at\":\"2026-06-21 23:42:13\",\"updated_at\":\"2026-06-26 22:57:03\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:45:26'),
(226, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', '{\"id\":32,\"merchant_user_id\":29,\"sku\":\"DRNK\",\"product_name\":\"Coca-Cola\",\"description\":\"Tignan kung bakit!\",\"category\":\"food\",\"unit\":\"pack\",\"price\":\"10.00\",\"stock_qty\":5,\"min_stock_alert\":5,\"is_available\":0,\"is_restricted\":1,\"restriction_note\":\"High sugar content â€” DepEd nutritional guidelines\",\"approved_by\":null,\"restricted_by\":null,\"restricted_at\":null,\"created_at\":\"2026-06-26 22:55:25\",\"updated_at\":\"2026-06-26 22:55:25\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 02:45:28'),
(227, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 03:09:06'),
(228, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 03:35:54'),
(229, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-27 12:17:44'),
(230, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 12:11:52'),
(231, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":6,\"is_active\":1}', '{\"event\":\"status_changed\",\"id\":6,\"is_active\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 12:16:07'),
(232, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":6,\"is_active\":0}', '{\"event\":\"status_changed\",\"id\":6,\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 12:16:10'),
(233, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":6,\"is_active\":1}', '{\"event\":\"status_changed\",\"id\":6,\"is_active\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 12:16:30'),
(234, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":6,\"is_active\":0}', '{\"event\":\"status_changed\",\"id\":6,\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 12:16:32'),
(235, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 12:18:25'),
(236, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 12:18:38'),
(237, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 12:18:48'),
(238, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 12:18:54'),
(239, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 12:19:05'),
(240, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 12:19:22'),
(241, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 12:39:05'),
(242, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 13:11:42'),
(243, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 13:11:52'),
(244, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 13:11:52'),
(245, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260628-84982\",\"transaction_type\":\"cash_in\",\"amount\":194,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":203660.41,\"vault_after\":203664.41,\"total_in_circulation\":210100,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 13:25:14'),
(246, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_wallet_load\",\"reference\":\"TXN-20260628-84982\",\"cash_amount\":200,\"system_fee\":4,\"merchant_fee\":2,\"credited_amount\":194,\"merchant_wallet\":13,\"student_wallet\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 13:25:14'),
(247, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', '{\"id\":24,\"merchant_user_id\":29,\"sku\":\"KWK-004\",\"product_name\":\"Squidball (10pcs)\",\"description\":\"Deep-fried squidballs with sauce\",\"category\":\"street food\",\"unit\":\"order\",\"price\":\"25.00\",\"stock_qty\":118,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null,\"approved_by\":null,\"restricted_by\":null,\"restricted_at\":null,\"created_at\":\"2026-06-21 23:42:13\",\"updated_at\":\"2026-06-22 23:30:50\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 13:26:01'),
(248, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 13:28:05'),
(249, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 13:31:28'),
(250, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":6,\"is_active\":1}', '{\"event\":\"status_changed\",\"id\":6,\"is_active\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 14:12:42'),
(251, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":6,\"is_active\":0}', '{\"event\":\"status_changed\",\"id\":6,\"is_active\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 14:12:52'),
(252, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 14:22:03'),
(253, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 14:22:11'),
(254, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 14:22:32'),
(255, 39, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 14:23:07'),
(256, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 14:23:16'),
(257, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-28 14:24:45'),
(258, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260628-866377\",\"transaction_type\":\"payment\",\"amount\":45,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":3,\"stock_qty\":91,\"line_total\":45}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-28 14:25:29'),
(259, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260628-5D206E\",\"transaction_type\":\"payment\",\"amount\":30,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":26,\"sku\":\"KWK-006\",\"name\":\"Chicken Skin (1pc)\",\"price\":15,\"qty\":2,\"stock_qty\":76,\"line_total\":30}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-28 14:31:40'),
(260, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260628-E9916B\",\"transaction_type\":\"payment\",\"amount\":220,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":4,\"stock_qty\":45,\"line_total\":220}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-28 14:33:11'),
(261, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260628-11DAD9\",\"transaction_type\":\"payment\",\"amount\":275,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":5,\"stock_qty\":41,\"line_total\":275}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-28 14:34:10'),
(262, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260628-6B4F83\",\"transaction_type\":\"payment\",\"amount\":110,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":2,\"stock_qty\":36,\"line_total\":110}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-28 14:40:58'),
(263, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260628-6AFF20\",\"transaction_type\":\"payment\",\"amount\":165,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":11,\"stock_qty\":88,\"line_total\":165}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-28 14:53:37'),
(264, 39, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 15:13:56'),
(265, 16, 'Finance', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 15:14:15'),
(266, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 15:14:29'),
(267, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 08:15:36'),
(268, 29, 'Merchant', 'TRANSACTION', NULL, 'cart_orders', '{\"id\":12,\"reference_no\":\"CART-20260628-FB774E\",\"student_user_id\":1,\"student_wallet_id\":2,\"merchant_user_id\":29,\"merchant_wallet_id\":13,\"description\":\"7x Bottled Water (500ml)\",\"items_json\":\"[{\\\"id\\\":29,\\\"sku\\\":\\\"KWK-009\\\",\\\"name\\\":\\\"Bottled Water (500ml)\\\",\\\"price\\\":15,\\\"qty\\\":7,\\\"stock_qty\\\":77,\\\"line_total\\\":105}]\",\"amount\":\"105.00\",\"status\":\"pending\",\"created_at\":\"2026-06-28 23:11:26\",\"paid_at\":null,\"paid_ref\":null}', '{\"status\":\"voided\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 08:15:43'),
(269, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 11:20:37'),
(270, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 11:22:34'),
(271, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 11:22:45'),
(272, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 11:35:47'),
(273, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 11:36:04'),
(274, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 11:42:02'),
(275, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 11:44:59'),
(276, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 15:11:45'),
(277, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260629-12261\",\"transaction_type\":\"cap_increase\",\"amount\":39900,\"student_wallet_id\":null,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":203664.41,\"vault_after\":243564.41,\"total_in_circulation\":250000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 15:22:54'),
(278, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":6,\"product_name\":\"Alcohol\"}', '{\"event\":\"deleted\",\"id\":6}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 15:24:56'),
(279, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":5,\"product_name\":\"Cobra\"}', '{\"event\":\"deleted\",\"id\":5}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 15:25:00'),
(280, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":7,\"product_name\":\"Red Horse\",\"category\":\"alcohol\",\"match_type\":\"contains\",\"reason\":\"Inappropriate to drink at school premises\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 15:26:09'),
(281, 16, 'Finance', 'MERCHANT_CREATE', NULL, 'users', NULL, '{\"created_user_id\":40,\"merchant_id\":18,\"first_name\":\"Aolf\",\"last_name\":\"Emata\",\"email\":\"gregbautista20@gmail.com\",\"phone\":\"96147083011\",\"username\":\"aolf123\",\"business_name\":\"Manukang Campus\",\"stall_id\":\"A5\",\"notes\":\"\",\"roleID\":5,\"sub_role\":\"merchant_admin\",\"forced_password_change\":false,\"email_sent\":false}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 15:59:41'),
(287, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-30 05:16:53'),
(288, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-30 05:17:05'),
(289, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 02:13:50'),
(290, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 03:46:10'),
(291, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 03:49:42'),
(292, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":8,\"product_name\":\"Piattos\",\"category\":\"snack\",\"match_type\":\"contains\",\"reason\":\"High Sodium\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 03:50:41'),
(293, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":3,\"product_name\":\"Junk Food\"}', '{\"event\":\"deleted\",\"id\":3}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 03:51:16'),
(294, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":2,\"product_name\":\"Energy Drink\"}', '{\"event\":\"deleted\",\"id\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 03:51:22'),
(295, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":9,\"product_name\":\"Cobra\",\"category\":\"beverage\",\"match_type\":\"contains\",\"reason\":\"High Caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 03:51:38'),
(296, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 03:52:06'),
(297, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 03:52:23'),
(298, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-01 04:13:32'),
(299, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-03 10:16:07'),
(300, 16, 'Finance', 'USER_IMPORT', NULL, 'imported_student_registry', NULL, '{\"import_batch_id\":\"20260703105918\",\"file_name\":\"sample_students_import.csv\",\"total_rows\":5,\"imported\":5,\"duplicates\":0,\"failed\":0,\"parents_created\":3,\"parents_linked\":3,\"created_user_ids\":[41,43,44,46,47]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-03 10:59:19'),
(303, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 13:25:08');
INSERT INTO `systemic_audit_trail` (`log_id`, `user_id`, `user_role`, `action_type`, `stall_id`, `affected_table`, `old_value`, `new_value`, `ip_address`, `user_agent`, `timestamp`) VALUES
(304, 16, 'Finance', 'USER_IMPORT', NULL, 'imported_student_registry', NULL, '{\"import_batch_id\":\"20260704132558\",\"file_name\":\"100 GJC Students.csv\",\"total_rows\":100,\"imported\":99,\"duplicates\":1,\"failed\":0,\"parents_created\":79,\"parents_linked\":79,\"created_user_ids\":[48,49,51,53,54,56,57,59,61,63,65,67,69,71,73,75,76,78,80,82,84,85,87,88,90,91,92,93,94,96,98,100,102,104,105,107,108,110,112,114,115,117,119,121,123,125,127,129,131,133,135,137,139,141,143,145,147,149,151,152,154,155,157,159,161,163,165,166,168,170,172,174,175,177,179,181,183,185,187,189,191,193,194,196,198,200,202,203,205,206,208,209,211,213,214,216,217,218,219]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 13:26:13'),
(311, 16, 'Finance', 'USER_IMPORT', NULL, 'imported_student_registry', NULL, '{\"import_batch_id\":\"20260704134017\",\"file_name\":\"16 GJC Students.csv\",\"total_rows\":15,\"imported\":15,\"duplicates\":0,\"failed\":0,\"parents_created\":12,\"parents_linked\":12,\"created_user_ids\":[221,223,225,227,229,231,232,234,235,237,239,241,242,244,246]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 13:40:20'),
(312, 16, 'Finance', 'USER_IMPORT', NULL, 'imported_student_registry', NULL, '{\"import_batch_id\":\"20260704135220\",\"file_name\":\"16 GJC Students.csv\",\"total_rows\":15,\"imported\":15,\"duplicates\":0,\"failed\":0,\"parents_created\":12,\"parents_linked\":12,\"parents_emailed\":11,\"parents_email_failed\":0,\"created_user_ids\":[247,249,251,253,255,257,258,260,261,263,265,267,268,270,272]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 13:53:04'),
(314, 254, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":254,\"email\":\"kujo7397@gmail.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-04 13:54:25'),
(315, 254, 'Student', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-04 13:54:39'),
(316, 254, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-04 14:35:52'),
(317, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 15:09:36'),
(318, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-04 15:09:44'),
(319, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-04 15:09:55'),
(320, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 15:12:27'),
(321, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 15:12:39'),
(322, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 15:12:46'),
(323, 16, 'Finance', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 15:15:37'),
(324, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-04 15:15:42'),
(325, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-05 12:15:55'),
(326, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"P2P-20260705-82453E62\",\"transaction_type\":\"p2p_transfer\",\"amount\":1,\"from_user_id\":1,\"to_user_id\":2,\"from_wallet_id\":2,\"to_wallet_id\":1,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-05 12:21:24'),
(327, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-05 12:37:42'),
(328, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-05 12:45:22'),
(329, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-05 12:56:42'),
(330, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-05 12:57:29'),
(331, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-05 12:57:42'),
(332, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"P2P-20260705-C5E3AA15\",\"transaction_type\":\"p2p_transfer\",\"amount\":450,\"from_user_id\":1,\"to_user_id\":2,\"from_wallet_id\":2,\"to_wallet_id\":1,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-05 12:58:28'),
(333, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-05 13:45:27'),
(334, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-05 13:52:34'),
(335, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-05 13:52:45'),
(336, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-05 13:52:55'),
(337, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0', '2026-07-05 14:19:31'),
(338, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-05 16:55:48'),
(339, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-05 16:57:10'),
(340, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-05 16:57:20'),
(341, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 03:54:44'),
(342, 16, 'Finance', 'STALL_UPDATE', 'A4', 'stalls', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 22:37:02\"}', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":22,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-06-20 22:37:02\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 04:05:11'),
(343, 16, 'Finance', 'STALL_UPDATE', 'A1', 'stalls', '{\"stall_id\":\"A1\",\"label\":\"Stall A1\",\"row_label\":\"A\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-06 12:23:15\"}', '{\"stall_id\":\"A1\",\"label\":\"Stall A1\",\"row_label\":\"A\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":23,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-06 12:23:15\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 04:39:24'),
(344, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:49:58'),
(345, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":7,\"product_name\":\"Red Horse\"}', '{\"event\":\"deleted\",\"id\":7}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:50:14'),
(346, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":9,\"product_name\":\"Cobra\"}', '{\"event\":\"deleted\",\"id\":9}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:50:32'),
(347, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":8,\"product_name\":\"Piattos\"}', '{\"event\":\"deleted\",\"id\":8}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:50:36'),
(348, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":10,\"product_name\":\"Red Horse\",\"category\":\"beverage\",\"match_type\":\"contains\",\"reason\":\"No alcohol inside the school premises\",\"existing_disabled\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:51:19'),
(349, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', '{\"id\":1,\"product_name\":\"Coca-Cola\"}', '{\"event\":\"deleted\",\"id\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:51:32'),
(350, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":11,\"product_name\":\"Coca-Cola\",\"category\":\"beverage\",\"match_type\":\"contains\",\"reason\":\"High sugar content\",\"existing_disabled\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:51:56'),
(351, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":12,\"product_name\":\"Sting Energy Drink\",\"category\":\"beverage\",\"match_type\":\"contains\",\"reason\":\"High caffeinated drinks is prohibited\",\"existing_disabled\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:52:52'),
(352, 16, 'Finance', 'PRODUCT_RESTRICTION', NULL, 'restricted_products', NULL, '{\"event\":\"flagged\",\"id\":13,\"product_name\":\"Cobra Energy Drink\",\"category\":\"beverage\",\"match_type\":\"contains\",\"reason\":\"High caffeine\",\"existing_disabled\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:53:19'),
(353, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:55:29'),
(354, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', NULL, '{\"id\":34,\"merchant_user_id\":29,\"sku\":\"DRINK-004\",\"product_name\":\"C0br4\",\"description\":\"Smart C\",\"category\":\"beverage\",\"unit\":\"bottle\",\"price\":200,\"stock_qty\":100,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:56:18'),
(355, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', '{\"id\":34,\"merchant_user_id\":29,\"sku\":\"DRINK-004\",\"product_name\":\"C0br4\",\"description\":\"Smart C\",\"category\":\"beverage\",\"unit\":\"bottle\",\"price\":\"200.00\",\"stock_qty\":100,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null,\"approved_by\":null,\"restricted_by\":null,\"restricted_at\":null,\"created_at\":\"2026-07-06 17:56:18\",\"updated_at\":\"2026-07-06 17:56:18\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 09:56:37'),
(356, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"c0br4 drink\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 10:13:09'),
(357, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"K0br4 Bottle\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 10:13:39'),
(358, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"koka c0la\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 10:14:06'),
(359, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-07-06 14:31:22'),
(360, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 14:55:32'),
(361, 16, 'Finance', 'STALL_UPDATE', 'A5', 'stalls', '{\"stall_id\":\"A5\",\"label\":\"Stall A5\",\"row_label\":\"A\",\"col_number\":5,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-06 12:23:15\"}', '{\"stall_id\":\"A5\",\"label\":\"Stall A5\",\"row_label\":\"A\",\"col_number\":5,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":24,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-06 12:23:15\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 14:58:27'),
(387, 16, 'Finance', 'STALL_UPDATE', 'A1', 'stalls', '{\"stall_id\":\"A1\",\"label\":\"Stall A1\",\"row_label\":\"A\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-06 18:21:20\"}', '{\"stall_id\":\"A1\",\"label\":\"Stall A1\",\"row_label\":\"A\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":25,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-06 18:21:20\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-06 15:45:53'),
(396, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-07 02:52:24'),
(397, 16, 'Finance', 'STALL_UPDATE', 'A4', 'stalls', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-06 12:23:15\"}', '{\"stall_id\":\"A4\",\"label\":\"Stall A4\",\"row_label\":\"A\",\"col_number\":4,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":26,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-06 12:23:15\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-07 03:17:04'),
(406, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 02:48:19'),
(407, 16, 'Finance', 'STALL_UPDATE', 'A1', 'stalls', '{\"stall_id\":\"A1\",\"label\":\"Stall A1\",\"row_label\":\"A\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"vacant\",\"merchant_id\":null,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-09 10:54:36\"}', '{\"stall_id\":\"A1\",\"label\":\"Stall A1\",\"row_label\":\"A\",\"col_number\":1,\"area_sqm\":\"12.00\",\"monthly_rate\":\"2500.00\",\"status\":\"occupied\",\"merchant_id\":27,\"pending_expires_at\":null,\"created_at\":\"2026-06-15 14:34:42\",\"updated_at\":\"2026-07-09 10:54:36\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 03:19:34'),
(408, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:27:47'),
(409, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:28:09'),
(410, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:33:51'),
(411, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:34:33'),
(415, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:36:50'),
(416, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:47:21'),
(417, 16, 'Finance', 'STALL_UPDATE', NULL, 'stall_applications', '{\"id\":21,\"meetup_scheduled_at\":\"2026-07-10 09:00:00\"}', '{\"id\":21,\"meetup_scheduled_at\":\"2026-07-10 11:00:00\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:47:43'),
(419, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:53:54'),
(420, 16, 'Finance', 'STALL_UPDATE', NULL, 'stall_applications', '{\"id\":22,\"meetup_scheduled_at\":\"2026-07-10 09:00:00\"}', '{\"id\":22,\"meetup_scheduled_at\":\"2026-07-10 13:00:00\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 11:57:33'),
(421, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 13:07:24'),
(422, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', NULL, '{\"id\":38,\"merchant_user_id\":29,\"sku\":\"DRINK-001\",\"product_name\":\"Smart C\",\"description\":\"Siomai\",\"category\":\"snack\",\"unit\":\"piece\",\"price\":100,\"stock_qty\":10,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 13:07:56'),
(423, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"Coca-cola\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 13:09:33'),
(424, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"Coca-cola\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 13:09:36'),
(425, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-09 13:33:12'),
(426, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-09 13:33:14'),
(427, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260709-062AA1\",\"transaction_type\":\"payment\",\"amount\":120,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":1,\"stock_qty\":57,\"line_total\":50},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":34,\"line_total\":55},{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":77,\"line_total\":15}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-09 13:35:05'),
(428, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 13:42:46'),
(429, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 13:42:59'),
(430, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-09 13:43:21'),
(431, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:27:02'),
(432, 16, 'Finance', 'TRANSACTION', '7', 'merchant_rent_payments', NULL, '{\"lease_id\":7,\"amount_paid\":2500,\"period_covered\":\"2026-07\",\"payment_method\":\"cash\",\"reference_no\":\"RENT-20260710-E0D952\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:34:13'),
(433, 16, 'Finance', 'TRANSACTION', '8', 'merchant_rent_payments', NULL, '{\"lease_id\":8,\"amount_paid\":2500,\"period_covered\":\"2026-07\",\"payment_method\":\"cash\",\"reference_no\":\"RENT-20260710-14CC3C\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:40:14'),
(434, 16, 'Finance', 'TRANSACTION', '8', 'merchant_rent_payments', NULL, '{\"lease_id\":8,\"amount_paid\":2500,\"period_covered\":\"2026-07\",\"payment_method\":\"cash\",\"reference_no\":\"RENT-20260710-2E5646\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:44:14'),
(435, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:48:07'),
(436, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:48:11'),
(437, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:48:23'),
(438, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:48:29'),
(439, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:48:44'),
(440, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:49:16'),
(441, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:49:41'),
(442, 16, 'Finance', 'TRANSACTION', '9', 'merchant_rent_payments', NULL, '{\"lease_id\":9,\"amount_paid\":2500,\"period_covered\":\"2026-07\",\"payment_method\":\"cash\",\"reference_no\":\"RENT-20260710-E5474E\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:58:39'),
(443, 16, 'Finance', 'TRANSACTION', '9', 'merchant_rent_payments', NULL, '{\"lease_id\":9,\"amount_paid\":7000,\"period_covered\":\"2026-07\",\"payment_method\":\"cash\",\"reference_no\":\"RENT-20260710-339810\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 02:58:50'),
(444, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:16:33'),
(445, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:16:44'),
(446, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:16:51'),
(447, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:28:55'),
(448, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:45:05'),
(449, 23, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:45:40'),
(450, 2, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:45:42'),
(451, 23, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:46:08'),
(452, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 03:46:18'),
(453, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 04:53:44'),
(454, 2, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 04:53:50'),
(455, 2, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 04:54:04'),
(456, 2, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 04:54:19'),
(457, 2, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 04:54:22'),
(458, 2, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":2,\"email\":\"otto.cruz@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 04:54:46'),
(459, 2, 'Student', 'PASSWORD_CHANGE', NULL, 'users', '{\"force_password_change\":1,\"is_first_login\":1,\"password_changed\":0}', '{\"force_password_change\":0,\"is_first_login\":0,\"password_changed\":1}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 04:55:09'),
(460, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 09:02:14'),
(461, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 09:02:28'),
(462, 2, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260710-E0588A\",\"transaction_type\":\"payment\",\"amount\":20,\"student_wallet_id\":1,\"merchant_wallet_id\":13,\"items\":[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20}],\"status\":\"completed\"}', '0.0.0.0', 'Unknown', '2026-07-10 13:56:34'),
(463, 2, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260710-A19BEC\",\"transaction_type\":\"payment\",\"amount\":20,\"student_wallet_id\":1,\"merchant_wallet_id\":13,\"items\":[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20}],\"status\":\"completed\"}', '0.0.0.0', 'Unknown', '2026-07-10 13:57:20'),
(464, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 14:00:50'),
(465, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 14:12:42'),
(466, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260710-43E4B3\",\"transaction_type\":\"payment\",\"amount\":135,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":21,\"name\":\"Kwek-Kwek (6pcs)\",\"qty\":1,\"price\":35},{\"id\":38,\"name\":\"Smart C\",\"qty\":1,\"price\":100}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 14:16:27'),
(467, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260710-65759\",\"transaction_type\":\"cash_in\",\"amount\":997,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":243564.41,\"vault_after\":243566.41,\"total_in_circulation\":250000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 14:17:40'),
(468, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_wallet_load\",\"reference\":\"TXN-20260710-65759\",\"cash_amount\":1000,\"system_fee\":2,\"merchant_fee\":1,\"credited_amount\":997,\"merchant_wallet\":13,\"student_wallet\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 14:17:40'),
(469, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260710-43AC1E\",\"transaction_type\":\"payment\",\"amount\":170,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":76,\"line_total\":15},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":33,\"line_total\":55},{\"id\":38,\"sku\":\"DRINK-001\",\"name\":\"Smart C\",\"price\":100,\"qty\":1,\"stock_qty\":9,\"line_total\":100}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-10 14:29:11'),
(470, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'merchant', '{\"event\":\"business_profile_update\",\"stall_name\":\"Kikiam ni Baste\"}', '{\"event\":\"business_profile_update\",\"stall_name\":\"Kikiam Ni Baste\",\"logo_updated\":false}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-10 14:30:40'),
(471, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-10 14:32:41'),
(472, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-10 14:33:34'),
(473, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-10 14:50:47'),
(474, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-11 10:44:19'),
(475, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 10:59:49'),
(476, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 11:00:03'),
(477, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-11 11:54:29'),
(478, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-11 12:31:31'),
(479, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-11 12:31:38'),
(480, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-07-11 12:32:33'),
(481, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-11 12:36:11'),
(482, 16, 'Finance', 'STALL_UPDATE', NULL, 'stall_applications', '{\"id\":23,\"meetup_scheduled_at\":\"2026-07-13 09:00:00\"}', '{\"id\":23,\"meetup_scheduled_at\":\"2026-07-16 10:00:00\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:41:43'),
(483, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-11 12:46:28'),
(484, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:48:41'),
(485, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:19'),
(486, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:21'),
(487, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:21'),
(488, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:22'),
(489, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:22'),
(490, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:22'),
(491, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:22'),
(492, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:22'),
(493, 29, 'Merchant', 'MENU_MUTATION', NULL, 'menu_items', NULL, '{\"id\":39,\"merchant_user_id\":29,\"sku\":\"RM-001\",\"product_name\":\"Bubble Gum\",\"description\":\"Smart\",\"category\":\"food\",\"unit\":\"pack\",\"price\":15,\"stock_qty\":90,\"min_stock_alert\":5,\"is_available\":1,\"is_restricted\":0,\"restriction_note\":null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 12:50:51'),
(494, 16, 'Finance', 'TUITION_CREDIT', NULL, 'tuition_credits', NULL, '{\"event\":\"create\",\"id\":1,\"student_user_id\":23,\"amount\":5000,\"waiver_reference_no\":\"WAIVER-42067\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 14:58:22'),
(495, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260711-84054\",\"transaction_type\":\"tuition_credit\",\"amount\":5000,\"student_wallet_id\":4,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":243566.41,\"vault_after\":243566.41,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 14:59:08'),
(496, 16, 'Finance', 'TUITION_CREDIT', NULL, 'tuition_credits', '{\"id\":1,\"status\":\"pending_payment\"}', '{\"id\":1,\"status\":\"active\",\"payment_confirmed_by\":16,\"credited_txn_ref\":\"TXN-20260711-84054\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 14:59:08'),
(497, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 14:59:21'),
(498, 23, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 14:59:52'),
(499, 23, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 15:00:01'),
(500, 23, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-11 15:00:15');
INSERT INTO `systemic_audit_trail` (`log_id`, `user_id`, `user_role`, `action_type`, `stall_id`, `affected_table`, `old_value`, `new_value`, `ip_address`, `user_agent`, `timestamp`) VALUES
(501, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":307,\"status\":\"empty\"}', '{\"student_user_id\":307,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:10:30'),
(502, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":307,\"status\":\"pending\",\"amount\":\"5000.00\"}', '{\"student_user_id\":307,\"status\":\"empty\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:11:04'),
(503, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":1,\"status\":\"empty\"}', '{\"student_user_id\":1,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:11:18'),
(504, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:11:31'),
(505, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:17:32'),
(506, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":1,\"status\":\"pending\"}', '{\"student_user_id\":1,\"status\":\"posted\",\"waiver_file\":\"uploads/fee_waiver_credits/1/waiver_17838263485214.png\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:19:08'),
(507, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":307,\"status\":\"empty\"}', '{\"student_user_id\":307,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:41:46'),
(508, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":307,\"status\":\"pending\"}', '{\"student_user_id\":307,\"status\":\"posted\",\"waiver_file\":\"uploads/fee_waiver_credits/36/waiver_17838277317052.jpg\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:42:11'),
(509, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":309,\"status\":\"empty\"}', '{\"student_user_id\":309,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:45:00'),
(510, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":309,\"status\":\"pending\"}', '{\"student_user_id\":309,\"status\":\"posted\",\"waiver_file\":\"uploads/fee_waiver_credits/37/waiver_17838279062507.pdf\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:45:06'),
(511, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":310,\"status\":\"empty\"}', '{\"student_user_id\":310,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:45:42'),
(512, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":257,\"status\":\"empty\"}', '{\"student_user_id\":257,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:46:09'),
(513, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":257,\"status\":\"pending\"}', '{\"student_user_id\":257,\"status\":\"posted\",\"waiver_file\":\"uploads/fee_waiver_credits/12/waiver_17838279777706.pdf\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:46:17'),
(514, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":310,\"status\":\"pending\",\"amount\":\"5000.00\"}', '{\"student_user_id\":310,\"status\":\"empty\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 03:47:05'),
(515, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":310,\"status\":\"empty\"}', '{\"student_user_id\":310,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 04:07:14'),
(516, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":310,\"status\":\"pending\"}', '{\"student_user_id\":310,\"status\":\"posted\",\"waiver_file\":\"uploads/fee_waiver_credits/38/waiver_17838292428696.pdf\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 04:07:22'),
(517, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":255,\"status\":\"empty\"}', '{\"student_user_id\":255,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 04:17:08'),
(518, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":255,\"status\":\"pending\",\"amount\":\"5000.00\"}', '{\"student_user_id\":255,\"status\":\"empty\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 04:32:04'),
(519, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":452,\"status\":\"empty\"}', '{\"student_user_id\":452,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 04:32:20'),
(520, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":255,\"status\":\"empty\"}', '{\"student_user_id\":255,\"status\":\"pending\",\"amount\":9000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 04:39:34'),
(521, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":334,\"status\":\"empty\"}', '{\"student_user_id\":334,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 05:02:59'),
(522, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":23,\"status\":\"empty\"}', '{\"student_user_id\":23,\"status\":\"pending\",\"amount\":5000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 13:04:22'),
(523, 16, 'Finance', 'FEE_WAIVER_STATUS_CHANGE', NULL, 'fee_waiver_credits', '{\"student_user_id\":23,\"status\":\"pending\"}', '{\"student_user_id\":23,\"status\":\"posted\",\"waiver_file\":\"uploads/fee_waiver_credits/6/waiver_17838615216703.pdf\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 13:05:21'),
(524, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 13:06:16'),
(525, 23, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 13:06:54'),
(526, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260712-65525\",\"transaction_type\":\"cash_in\",\"amount\":9980,\"student_wallet_id\":4,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":243566.41,\"vault_after\":233586.41,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 13:58:52'),
(527, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-07-12 14:00:23'),
(528, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260712-38941\",\"transaction_type\":\"cash_in\",\"amount\":4990,\"student_wallet_id\":4,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":233586.41,\"vault_after\":228596.41,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 14:01:16'),
(529, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-12 14:02:04'),
(530, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260712-04896\",\"transaction_type\":\"cash_in\",\"amount\":358.92,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":228596.41,\"vault_after\":228597.13,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 14:04:49'),
(531, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_wallet_load\",\"reference\":\"TXN-20260712-04896\",\"cash_amount\":360,\"system_fee\":0.72,\"merchant_fee\":0.36,\"credited_amount\":358.92,\"merchant_wallet\":13,\"student_wallet\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 14:04:49'),
(532, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260712-13555B\",\"transaction_type\":\"payment\",\"amount\":210,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":39,\"name\":\"Bubble Gum\",\"qty\":14,\"price\":15}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-12 14:10:01'),
(533, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260712-57E1D5\",\"transaction_type\":\"payment\",\"amount\":360,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":21,\"name\":\"Kwek-Kwek (6pcs)\",\"qty\":1,\"price\":35},{\"id\":22,\"name\":\"Tokneneng (4pcs)\",\"qty\":1,\"price\":30},{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25},{\"id\":26,\"name\":\"Chicken Skin (1pc)\",\"qty\":1,\"price\":15},{\"id\":27,\"name\":\"Calamares (1 cup)\",\"qty\":1,\"price\":50},{\"id\":28,\"name\":\"Tapsilog\",\"qty\":1,\"price\":55},{\"id\":29,\"name\":\"Bottled Water (500ml)\",\"qty\":1,\"price\":15},{\"id\":38,\"name\":\"Smart C\",\"qty\":1,\"price\":100},{\"id\":39,\"name\":\"Bubble Gum\",\"qty\":1,\"price\":15}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-12 14:10:43'),
(534, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260712-E0EA6E\",\"transaction_type\":\"payment\",\"amount\":45,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":1,\"price\":20},{\"id\":25,\"name\":\"Kikiam (4pcs)\",\"qty\":1,\"price\":25}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-12 14:11:15'),
(535, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260712-C1CD1F\",\"transaction_type\":\"payment\",\"amount\":215,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":2,\"stock_qty\":74,\"line_total\":30},{\"id\":27,\"sku\":\"KWK-007\",\"name\":\"Calamares (1 cup)\",\"price\":50,\"qty\":1,\"stock_qty\":55,\"line_total\":50},{\"id\":21,\"sku\":\"KWK-001\",\"name\":\"Kwek-Kwek (6pcs)\",\"price\":35,\"qty\":1,\"stock_qty\":93,\"line_total\":35},{\"id\":38,\"sku\":\"DRINK-001\",\"name\":\"Smart C\",\"price\":100,\"qty\":1,\"stock_qty\":7,\"line_total\":100}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-12 14:12:54'),
(536, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260712-56978\",\"transaction_type\":\"cash_in\",\"amount\":199.4,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":228597.13,\"vault_after\":228597.53,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 14:21:12'),
(537, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_wallet_load\",\"reference\":\"TXN-20260712-56978\",\"cash_amount\":200,\"system_fee\":0.4,\"merchant_fee\":0.2,\"credited_amount\":199.4,\"merchant_wallet\":13,\"student_wallet\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 14:21:12'),
(538, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260712-10269\",\"transaction_type\":\"cash_in\",\"amount\":498.5,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":228597.53,\"vault_after\":228598.53,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 14:21:49'),
(539, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_wallet_load\",\"reference\":\"TXN-20260712-10269\",\"cash_amount\":500,\"system_fee\":1,\"merchant_fee\":0.5,\"credited_amount\":498.5,\"merchant_wallet\":13,\"student_wallet\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 14:21:49'),
(540, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-12 14:41:22'),
(545, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', NULL, '{\"event\":\"created\",\"user_id\":462,\"name\":\"Chienna Mae Gamboa\",\"email\":\"chiennagamboa321@gmail.com\",\"role\":\"merchant_staff\",\"position\":\"Cashier\",\"merchant_owner_id\":29}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 15:18:29'),
(546, 462, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":462,\"email\":\"chiennagamboa321@gmail.com\",\"roleID\":6,\"sub_role\":\"merchant_staff\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-12 15:18:36'),
(547, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":462,\"status\":\"Active\"}', '{\"event\":\"deactivated\",\"user_id\":462,\"changed_by\":29,\"new_status\":\"Inactive\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 15:19:14'),
(548, 29, 'Merchant', 'USER_ACCOUNT', NULL, 'users', '{\"user_id\":462,\"status\":\"Inactive\"}', '{\"event\":\"reactivated\",\"user_id\":462,\"changed_by\":29,\"new_status\":\"Active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 15:19:35'),
(549, 462, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":462,\"email\":\"chiennagamboa321@gmail.com\",\"roleID\":6,\"sub_role\":\"merchant_staff\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-12 15:19:39'),
(550, 462, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-12 15:19:58'),
(551, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-12 15:20:44'),
(552, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-12 15:22:11'),
(553, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 15:22:39'),
(554, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-12 15:22:59'),
(555, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260712-6921E0\",\"transaction_type\":\"payment\",\"amount\":235,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":6,\"price\":20},{\"id\":26,\"name\":\"Chicken Skin (1pc)\",\"qty\":1,\"price\":15},{\"id\":38,\"name\":\"Smart C\",\"qty\":1,\"price\":100}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-12 15:23:12'),
(556, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"POS-20260712-0551AE\",\"transaction_type\":\"payment\",\"amount\":820,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":23,\"name\":\"Fishball (10pcs)\",\"qty\":41,\"price\":20}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6.8 Mobile/15E148 Safari/604.1', '2026-07-12 15:23:59'),
(557, 16, 'Finance', 'STALL_UPDATE', NULL, 'stall_applications', '{\"id\":22,\"meetup_scheduled_at\":\"2026-07-10 13:00:00\"}', '{\"id\":22,\"meetup_scheduled_at\":\"2026-07-13 16:00:00\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 15:27:34'),
(562, 39, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-12 16:16:58'),
(563, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 14:38:31'),
(564, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 14:53:13'),
(565, 35, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":35,\"email\":\"noahgray430@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 14:53:26'),
(566, 35, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 14:55:13'),
(567, 35, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":35,\"email\":\"noahgray430@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 14:55:36'),
(568, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 01:35:45'),
(569, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 01:37:13'),
(570, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 01:47:24'),
(571, 16, 'Finance', 'USER_IMPORT', NULL, 'imported_student_registry', NULL, '{\"import_batch_id\":\"20260714014829\",\"file_name\":\"student_records_ph_numbers.csv\",\"total_rows\":5,\"imported\":5,\"duplicates\":0,\"failed\":0,\"parents_created\":0,\"parents_linked\":0,\"parents_emailed\":0,\"parents_email_failed\":0,\"created_user_ids\":[464,465,466,467,468]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 01:48:30'),
(572, 39, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 01:57:41'),
(573, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 01:58:08'),
(574, 1, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 01:59:23'),
(575, 23, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":23,\"email\":\"student@gjc.edu.ph\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 01:59:50'),
(576, 23, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"P2P-20260714-8C284C4F\",\"transaction_type\":\"p2p_transfer\",\"amount\":97,\"from_user_id\":23,\"to_user_id\":1,\"from_wallet_id\":4,\"to_wallet_id\":2,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 02:02:03'),
(577, 23, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"P2P-20260714-8A29E2F8\",\"transaction_type\":\"p2p_transfer\",\"amount\":870,\"from_user_id\":23,\"to_user_id\":1,\"from_wallet_id\":4,\"to_wallet_id\":2,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 02:02:42'),
(578, 16, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":16,\"email\":\"finance@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 12:51:58'),
(579, 16, 'Finance', 'STALL_UPDATE', NULL, 'stall_applications', '{\"id\":28,\"status\":\"pending_verification\",\"meetup_scheduled_at\":\"2026-07-15 09:00:00\"}', '{\"id\":28,\"status\":\"expired\",\"event\":\"auto_expired_no_show\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 12:52:15'),
(580, 16, 'Finance', 'SCHOOL_YEAR_CREATED', NULL, 'school_years', NULL, '{\"school_year_id\":5,\"school_year_name\":\"2026-2027\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 13:39:12'),
(581, 16, 'Finance', 'STUDENT_GRADUATED', NULL, 'student_info', '{\"yr_lvl\":\"4th Year\",\"graduated_at\":null}', '{\"yr_lvl\":\"4th Year\",\"graduated_count\":1,\"student_user_ids\":[6]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 13:39:57'),
(582, 6, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":6,\"email\":\"jose.garcia@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 13:41:33'),
(583, 6, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":6,\"email\":\"jose.garcia@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 13:41:41'),
(584, 6, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":6,\"email\":\"jose.garcia@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 13:41:47'),
(585, 6, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":6,\"email\":\"jose.garcia@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 13:41:52'),
(586, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260717-93991\",\"transaction_type\":\"student_withdraw\",\"amount\":265.9,\"student_wallet_id\":2,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":228598.53,\"vault_after\":228864.43,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 14:17:19'),
(587, 16, 'Finance', 'TRANSACTION', NULL, 'withdrawal_requests', '{\"id\":1,\"status\":\"pending\"}', '{\"id\":1,\"status\":\"released\",\"released_by\":16,\"student_wallet_id\":2,\"amount\":265.9,\"reference_no\":\"TXN-20260717-93991\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 14:17:19'),
(588, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260717-94145\",\"transaction_type\":\"student_withdraw\",\"amount\":893.99,\"student_wallet_id\":10,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":228864.43,\"vault_after\":229758.41999999998,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 14:17:25'),
(589, 16, 'Finance', 'TRANSACTION', NULL, 'withdrawal_requests', '{\"id\":3,\"status\":\"pending\"}', '{\"id\":3,\"status\":\"released\",\"released_by\":16,\"student_wallet_id\":10,\"amount\":893.99,\"reference_no\":\"TXN-20260717-94145\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 14:17:25'),
(590, 16, 'Finance', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 14:46:33'),
(591, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 14:46:49'),
(592, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260717-48086\",\"transaction_type\":\"cash_in\",\"amount\":99.8,\"student_wallet_id\":null,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":229758.42,\"vault_after\":229658.62000000002,\"total_in_circulation\":255000,\"status\":\"completed\"}', '0.0.0.0', 'Unknown', '2026-07-17 15:19:05'),
(593, 39, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260717-90263\",\"transaction_type\":\"allowance\",\"amount\":30,\"student_wallet_id\":2,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":229658.62,\"vault_after\":229658.62,\"total_in_circulation\":255000,\"status\":\"completed\"}', '0.0.0.0', 'Unknown', '2026-07-17 15:19:05'),
(594, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260717-84944\",\"transaction_type\":\"cash_in\",\"amount\":49.9,\"student_wallet_id\":2,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":229658.62,\"vault_after\":229608.72,\"total_in_circulation\":255000,\"status\":\"completed\"}', '0.0.0.0', 'Unknown', '2026-07-17 15:19:05'),
(595, 6, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 15:20:55'),
(596, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260717-72374\",\"transaction_type\":\"cash_in\",\"amount\":39.88,\"student_wallet_id\":null,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":229758.42,\"vault_after\":229758.5,\"total_in_circulation\":255000,\"status\":\"completed\"}', '0.0.0.0', 'Unknown', '2026-07-17 15:34:05'),
(597, 16, 'Finance', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260717-12079\",\"transaction_type\":\"cash_in\",\"amount\":24.95,\"student_wallet_id\":null,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":229758.5,\"vault_after\":229733.55,\"total_in_circulation\":255000,\"status\":\"completed\"}', '0.0.0.0', 'Unknown', '2026-07-17 15:34:05'),
(598, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 15:35:01'),
(599, 29, 'Merchant', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260717-87875\",\"transaction_type\":\"cash_in\",\"amount\":997,\"student_wallet_id\":null,\"merchant_wallet_id\":13,\"voucher_id\":null,\"vault_before\":229758.42,\"vault_after\":229760.42,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 15:36:14'),
(600, 29, 'Merchant', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"merchant_parent_wallet_load\",\"reference\":\"TXN-20260717-87875\",\"cash_amount\":1000,\"system_fee\":2,\"merchant_fee\":1,\"credited_amount\":997,\"merchant_wallet\":13,\"parent_wallet\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 15:36:14'),
(601, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 15:46:54'),
(602, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 15:47:06'),
(603, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260717-F53D53\",\"transaction_type\":\"payment\",\"amount\":170,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":72,\"line_total\":15},{\"id\":28,\"sku\":\"RICE01\",\"name\":\"Tapsilog\",\"price\":55,\"qty\":1,\"stock_qty\":31,\"line_total\":55},{\"id\":38,\"sku\":\"DRINK-001\",\"name\":\"Smart C\",\"price\":100,\"qty\":1,\"stock_qty\":5,\"line_total\":100}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-07-17 15:49:42'),
(604, 462, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":462,\"email\":\"chiennagamboa321@gmail.com\",\"roleID\":6,\"sub_role\":\"merchant_staff\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-17 16:14:28'),
(605, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-07-18 11:35:17'),
(606, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-07-18 13:03:59'),
(607, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-07-18 13:12:56'),
(608, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"Coca-Cola Zero Test 1\",\"matched_reason\":\"High sugar content\"}', '::1', 'curl/8.17.0', '2026-07-18 13:33:41'),
(609, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"Coca-Cola Zero Test 2\",\"matched_reason\":\"High sugar content\"}', '::1', 'curl/8.17.0', '2026-07-18 13:33:41'),
(610, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"Coca-Cola Zero Test 3\",\"matched_reason\":\"High sugar content\"}', '::1', 'curl/8.17.0', '2026-07-18 13:33:41'),
(611, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"Coca-Cola Zero Test 4\",\"matched_reason\":\"High sugar content\"}', '::1', 'curl/8.17.0', '2026-07-18 13:33:41'),
(612, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"Coca-Cola Zero Test 5\",\"matched_reason\":\"High sugar content\"}', '::1', 'curl/8.17.0', '2026-07-18 13:33:41'),
(613, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"Coca-Cola Zero Test 6\",\"matched_reason\":\"High sugar content\"}', '::1', 'curl/8.17.0', '2026-07-18 13:34:02'),
(614, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:35:47'),
(615, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:35:49'),
(616, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:00'),
(617, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:02'),
(618, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:02'),
(619, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:03'),
(620, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:03'),
(621, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:03'),
(622, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:03'),
(623, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:03'),
(624, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-18 13:36:03'),
(625, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:18:10'),
(626, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"coca\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:19:31'),
(627, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"coca\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:19:33'),
(628, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"coca\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:19:34'),
(629, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"coca\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:19:35'),
(630, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"coca\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:19:36'),
(631, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"coca\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:19:38'),
(632, 29, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":29,\"attempted_name\":\"Coca-cola\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:29:43'),
(633, 29, 'Merchant', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:43:25'),
(634, 35, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":35,\"email\":\"noahgray430@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:43:35'),
(635, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"Coca-cola\",\"matched_reason\":\"High sugar content\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:43:52'),
(636, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"Cobra\",\"matched_reason\":\"High caffeine\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:44:06'),
(637, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"sting\",\"matched_reason\":\"High caffeinated drinks is prohibited\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:44:27'),
(638, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"sprite\",\"matched_reason\":\"Carbonated soft drink — high sugar content, DepEd nutritional guidelines\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:44:31'),
(639, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'merchant_inventory', NULL, '{\"event\":\"blocked_add\",\"merchant_user_id\":35,\"attempted_name\":\"royal\",\"matched_reason\":\"Carbonated soft drink — high sugar content, DepEd nutritional guidelines\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:44:37'),
(640, 35, 'Merchant', 'PRODUCT_RESTRICTION', NULL, 'users', NULL, '{\"event\":\"auto_suspended\",\"merchant_user_id\":35,\"strikes\":5,\"suspended_days\":3,\"suspended_until\":\"Jul 22, 2026 9:44 PM\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:44:37'),
(641, 39, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":39,\"email\":\"hatsunemiku@email.com\",\"roleID\":7,\"sub_role\":\"parent\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:46:02'),
(642, 39, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"TXN-20260719-34692\",\"transaction_type\":\"allowance\",\"amount\":100,\"student_wallet_id\":2,\"merchant_wallet_id\":null,\"voucher_id\":null,\"vault_before\":229760.42,\"vault_after\":229760.42,\"total_in_circulation\":255000,\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:46:29'),
(643, 39, 'Student', 'TRANSACTION', NULL, 'transactions', NULL, '{\"event\":\"allowance_send\",\"reference_no\":\"TXN-20260719-34692\",\"amount\":100,\"parent_wallet_id\":1,\"student_wallet_id\":2,\"student_user_id\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:46:29'),
(644, 39, 'Student', 'LOGOUT', NULL, 'users', '{\"session\":\"active\"}', '{\"session\":\"destroyed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:50:51'),
(645, 12, 'Finance', 'LOGIN', NULL, 'users', NULL, '{\"userID\":12,\"email\":\"superadmin@gjc.edu.ph\",\"roleID\":4,\"sub_role\":\"super_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 13:51:05'),
(646, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-19 23:25:58'),
(647, 29, 'Merchant', 'LOGIN', NULL, 'users', NULL, '{\"userID\":29,\"email\":\"ezekielclarencesantiago68@gmail.com\",\"roleID\":2,\"sub_role\":\"merchant_admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 05:18:45'),
(648, 1, 'Student', 'LOGIN_FAILED', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 05:19:07');
INSERT INTO `systemic_audit_trail` (`log_id`, `user_id`, `user_role`, `action_type`, `stall_id`, `affected_table`, `old_value`, `new_value`, `ip_address`, `user_agent`, `timestamp`) VALUES
(649, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 05:19:16'),
(650, 1, 'Student', 'LOGIN', NULL, 'users', NULL, '{\"userID\":1,\"email\":\"michael@email.com\",\"roleID\":1,\"sub_role\":\"student\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-07-20 05:21:20'),
(651, 1, 'Student', 'TRANSACTION', NULL, 'e_wallet_transactions', NULL, '{\"reference_no\":\"CART-20260720-AAAE7C\",\"transaction_type\":\"payment\",\"amount\":15,\"student_wallet_id\":2,\"merchant_wallet_id\":13,\"items\":[{\"id\":29,\"sku\":\"KWK-009\",\"name\":\"Bottled Water (500ml)\",\"price\":15,\"qty\":1,\"stock_qty\":71,\"line_total\":15}],\"status\":\"completed\"}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-07-20 05:54:59');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `total_circulation_cap` decimal(15,2) NOT NULL DEFAULT '200000.00' COMMENT 'Total money supply cap -- super-admin only',
  `cashier_vault_points` decimal(15,2) NOT NULL DEFAULT '200000.00' COMMENT 'Unsold points sitting in the cashiers vault',
  `last_cap_increased_by` int UNSIGNED DEFAULT NULL COMMENT 'FK -> users.id of the super-admin who last raised the cap',
  `last_cap_increased_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `token_rate` decimal(10,4) NOT NULL DEFAULT '0.1000' COMMENT '1 PHP = 0.1 Tokens (â‚±10 per token). Cosmetic display only.',
  `service_fee` decimal(10,2) NOT NULL DEFAULT '2.00' COMMENT 'Fee deducted from credited amount on each top-up (â‚±2)',
  `school_revenue_balance` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Cumulative service fee revenue collected by the school'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `total_circulation_cap`, `cashier_vault_points`, `last_cap_increased_by`, `last_cap_increased_at`, `updated_at`, `token_rate`, `service_fee`, `school_revenue_balance`) VALUES
(1, 255000.00, 229760.42, 16, '2026-06-29 23:22:54', '2026-07-17 23:36:14', 0.1000, 2.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `topup`
--

CREATE TABLE `topup` (
  `topupID` int NOT NULL,
  `adminID` int NOT NULL,
  `userID` int NOT NULL,
  `wallet_id` int NOT NULL,
  `amount` int NOT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `date_time` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `topup_requests`
--

CREATE TABLE `topup_requests` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `student_wallet_id` int UNSIGNED DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(80) COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `reference_no` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `approved_by` int UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int UNSIGNED DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `top_up_source` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `fee_amount` decimal(15,2) DEFAULT NULL,
  `credited_amount` decimal(15,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `topup_requests`
--

INSERT INTO `topup_requests` (`id`, `user_id`, `student_wallet_id`, `amount`, `payment_method`, `status`, `reference_no`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, `created_at`, `top_up_source`, `fee_amount`, `credited_amount`) VALUES
(1, 2, 1, 1000.00, 'Cash at Cashier', 'approved', 'TXN-20260513-04199', 7, '2026-05-13 16:01:50', NULL, NULL, '2026-05-13 16:01:32', NULL, NULL, NULL),
(2, 1, 2, 2000.00, 'Cash at Cashier', 'approved', 'TXN-20260513-02871', 7, '2026-05-13 16:04:48', NULL, NULL, '2026-05-13 16:02:38', NULL, NULL, NULL),
(3, 1, 2, 2000.00, 'GCash', 'approved', 'TXN-20260513-58155', 7, '2026-05-13 16:05:16', NULL, NULL, '2026-05-13 16:04:22', NULL, NULL, NULL),
(4, 1, 2, 2000.00, 'Maya', 'approved', 'TXN-20260513-21460', 7, '2026-05-13 17:44:54', NULL, NULL, '2026-05-13 16:04:39', NULL, NULL, NULL),
(5, 6, 10, 499.99, 'Cash at Cashier', 'approved', 'TXN-20260622-73987', 16, '2026-06-22 22:21:25', NULL, NULL, '2026-06-22 22:21:11', NULL, NULL, NULL),
(6, 1, 2, 100.00, 'Cash at Cashier', 'approved', 'TXN-20260623-64586', 16, '2026-06-23 10:54:02', NULL, NULL, '2026-06-23 10:53:56', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `transaction`
--

CREATE TABLE `transaction` (
  `transactionID` int NOT NULL,
  `wallet_id` int NOT NULL,
  `merchantID` int NOT NULL,
  `amount` bigint NOT NULL,
  `date_time` datetime NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `reference_no` varchar(30) COLLATE utf8mb4_general_ci NOT NULL,
  `transaction_type` enum('cash_in','payment','voucher_payment','merchant_settle','voucher_create','voucher_expire','cap_increase','p2p_transfer','service_fee','tuition_credit','student_withdraw','refund','allowance') COLLATE utf8mb4_general_ci NOT NULL,
  `initiated_by` int UNSIGNED NOT NULL COMMENT 'FK -> users.id -- who triggered this transaction',
  `student_wallet_id` int UNSIGNED DEFAULT NULL,
  `merchant_wallet_id` int UNSIGNED DEFAULT NULL,
  `voucher_id` int UNSIGNED DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `vault_before` decimal(15,2) NOT NULL COMMENT 'Vault snapshot before',
  `vault_after` decimal(15,2) NOT NULL COMMENT 'Vault snapshot after',
  `total_in_circulation` decimal(15,2) NOT NULL COMMENT 'vault_after + all wallet balances + all active voucher balances',
  `status` enum('pending','completed','failed','reversed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'completed',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `top_up_source` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `base_amount` decimal(15,2) DEFAULT NULL,
  `fee_amount` decimal(15,2) DEFAULT NULL,
  `credited_amount` decimal(15,2) DEFAULT NULL,
  `school_year_id` int DEFAULT NULL,
  `parent_wallet_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `reference_no`, `transaction_type`, `initiated_by`, `student_wallet_id`, `merchant_wallet_id`, `voucher_id`, `amount`, `vault_before`, `vault_after`, `total_in_circulation`, `status`, `notes`, `created_at`, `top_up_source`, `base_amount`, `fee_amount`, `credited_amount`, `school_year_id`, `parent_wallet_id`) VALUES
(1, 'TXN-20260513-04199', 'cash_in', 7, 1, NULL, NULL, 1000.00, 200000.00, 199000.00, 200000.00, 'completed', NULL, '2026-05-13 16:01:50', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 'TXN-20260513-02871', 'cash_in', 7, 2, NULL, NULL, 2000.00, 199000.00, 197000.00, 200000.00, 'completed', NULL, '2026-05-13 16:04:48', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 'TXN-20260513-58155', 'cash_in', 7, 2, NULL, NULL, 2000.00, 197000.00, 195000.00, 200000.00, 'completed', NULL, '2026-05-13 16:05:16', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 'TXN-20260513-21460', 'cash_in', 7, 2, NULL, NULL, 2000.00, 199250.00, 197250.00, 200000.00, 'completed', NULL, '2026-05-13 17:44:54', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'VOU-20260514-00001', 'voucher_create', 7, NULL, NULL, 1, 500.00, 197250.00, 196750.00, 200000.00, 'completed', 'Voucher VCH-9F948010 issued to Ezekiel Clarence · exp 2026-05-15 03:38:25', '2026-05-14 03:38:25', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'VOU-20260608-00002', 'voucher_create', 7, NULL, NULL, 2, 900.00, 196750.00, 195850.00, 200000.00, 'completed', 'Voucher VCH-EC2381D1 issued to Paolo Varon - exp 2026-06-09 19:16:19', '2026-06-08 19:16:19', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'TXN-20260621-27910', 'cap_increase', 12, NULL, NULL, NULL, 100.00, 196075.00, 196175.00, 200100.00, 'completed', 'Cap raised from Php 200000.00 to Php 200100. Reason: Approved by me', '2026-06-21 20:45:59', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 'TXN-20260621-61508', 'cap_increase', 12, NULL, NULL, NULL, 10000.00, 196175.00, 206175.00, 210100.00, 'completed', 'Cap raised from Php 200100.00 to Php 210100. Reason: Trip', '2026-06-21 20:46:32', NULL, NULL, NULL, NULL, NULL, NULL),
(14, 'POS-20260621-C75BAD', 'payment', 1, 2, 13, NULL, 100.00, 206175.00, 206175.00, 210100.00, 'completed', 'POS QR Sale: 1x Kwek-Kwek (6pcs), 1x Kikiam (4pcs), 1x Chicken Skin (1pc), 1x Sauce Refill (cup), 1x Soda in Cup (16oz)', '2026-06-21 23:44:51', NULL, NULL, NULL, NULL, NULL, NULL),
(15, 'POS-20260621-2E4533', 'payment', 1, 2, 13, NULL, 65.00, 206175.00, 206175.00, 210100.00, 'completed', 'POS QR Sale: 2x Fishball (10pcs), 1x Kikiam (4pcs)', '2026-06-21 23:53:57', NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'CART-20260622-82B8BD', 'payment', 1, 2, 13, NULL, 70.00, 206175.00, 206175.00, 210100.00, 'completed', 'Cart Sale: 1x Tapsilog, 1x Bottled Water (500ml)', '2026-06-22 21:37:38', NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'CART-20260622-B8EA85', 'payment', 1, 2, 13, NULL, 75.00, 206175.00, 206175.00, 210100.00, 'completed', 'Cart Sale: 5x Bottled Water (500ml)', '2026-06-22 22:02:23', NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'TXN-20260622-73987', 'cash_in', 16, 10, NULL, NULL, 499.99, 206175.00, 205675.01, 210100.00, 'completed', NULL, '2026-06-22 22:21:25', NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'P2P-20260622-4ADE0DBB', 'p2p_transfer', 1, 2, NULL, NULL, 1000.00, 205675.01, 205675.01, 210100.00, 'completed', 'P2P Transfer to Ezekiel Clarence Santiago — Happy Birthday!', '2026-06-22 22:29:14', NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'P2P-20260622-C0CED566', 'p2p_transfer', 1, 2, NULL, NULL, 200.00, 205675.01, 205675.01, 210100.00, 'completed', 'P2P Transfer to Joshtine Nel Tubon — Thanks!', '2026-06-22 22:38:05', NULL, NULL, NULL, NULL, NULL, NULL),
(21, 'CART-20260622-FB99CB', 'payment', 1, 2, 13, NULL, 420.00, 205675.01, 205675.01, 210100.00, 'completed', '1x Chicken Skin (1pc), 1x Calamares (1 cup), 1x Kikiam (4pcs), 1x Tokneneng (4pcs), 3x Fishball (10pcs), 1x Squidball (10pcs), 1x Soda in Cup (16oz), 2x Bottled Water (500ml), 3x Tapsilog', '2026-06-22 22:56:35', NULL, NULL, NULL, NULL, NULL, NULL),
(22, 'CART-20260622-35328D', 'payment', 1, 2, 13, NULL, 495.00, 205675.01, 205675.01, 210100.00, 'completed', '1x Kikiam (4pcs), 2x Chicken Skin (1pc), 1x Squidball (10pcs), 2x Calamares (1 cup), 1x Tapsilog, 2x Bottled Water (500ml), 1x Soda in Cup (16oz), 2x Fishball (10pcs), 1x Tokneneng (4pcs), 4x Kwek-Kwek (6pcs)', '2026-06-22 23:30:50', NULL, NULL, NULL, NULL, NULL, NULL),
(23, 'CART-20260623-5C1C63', 'payment', 1, 2, 13, NULL, 25.00, 205675.01, 205675.01, 210100.00, 'completed', '1x Kikiam (4pcs)', '2026-06-23 10:52:58', NULL, NULL, NULL, NULL, NULL, NULL),
(24, 'TXN-20260623-64586', 'cash_in', 16, 2, NULL, NULL, 100.00, 205675.01, 205575.01, 210100.00, 'completed', NULL, '2026-06-23 10:54:02', NULL, NULL, NULL, NULL, NULL, NULL),
(25, 'TXN-20260626-62444', 'cash_in', 16, 2, NULL, NULL, 100.00, 205575.01, 205475.01, 210100.00, 'completed', NULL, '2026-06-26 22:40:11', NULL, NULL, NULL, NULL, NULL, NULL),
(26, 'TXN-20260626-75579', 'cash_in', 16, 2, NULL, NULL, 1000.00, 205475.01, 204475.01, 210100.00, 'completed', NULL, '2026-06-26 22:41:20', NULL, NULL, NULL, NULL, NULL, NULL),
(27, 'TXN-20260626-84029', 'cash_in', 16, 2, NULL, NULL, 50.00, 204475.01, 204425.01, 210100.00, 'completed', NULL, '2026-06-26 22:45:47', NULL, NULL, NULL, NULL, NULL, NULL),
(28, 'TXN-20260626-52660', 'cash_in', 16, 1, NULL, NULL, 500.00, 204425.01, 203925.01, 210100.00, 'completed', NULL, '2026-06-26 22:46:27', NULL, NULL, NULL, NULL, NULL, NULL),
(29, 'TXN-20260626-64911', 'cash_in', 29, 2, 13, NULL, 9.70, 203925.01, 203915.21, 210100.00, 'completed', 'Top-up via merchant. Cash: ₱10, System fee (2%): ₱0.2, Merchant fee (1%): ₱0.1, Credited: ₱9.7.', '2026-06-26 23:57:39', 'merchant', 10.00, 0.30, 9.70, NULL, NULL),
(30, 'TXN-20260626-71955', 'cash_in', 29, 2, 13, NULL, 58.20, 203915.21, 203856.41, 210100.00, 'completed', 'Top-up via merchant. Cash: ₱60, System fee (2%): ₱1.2, Merchant fee (1%): ₱0.6, Credited: ₱58.2.', '2026-06-27 00:13:16', 'merchant', 60.00, 1.80, 58.20, NULL, NULL),
(31, 'TXN-20260626-78829', 'cash_in', 29, 10, 13, NULL, 194.00, 203856.41, 203660.41, 210100.00, 'completed', 'Top-up via merchant. Cash: ₱200, System fee (2%): ₱4, Merchant fee (1%): ₱2, Credited: ₱194.', '2026-06-27 00:55:06', 'merchant', 200.00, 6.00, 194.00, NULL, NULL),
(32, 'TXN-20260628-84982', 'cash_in', 29, 2, 13, NULL, 194.00, 203660.41, 203664.41, 210100.00, 'completed', 'Merchant send to student. Sent: ₱200, System fee (2%): ₱4, Merchant cut (1%): ₱2, Credited: ₱194.', '2026-06-28 21:25:14', 'merchant', 200.00, 6.00, 194.00, NULL, NULL),
(33, 'CART-20260628-866377', 'payment', 1, 2, 13, NULL, 45.00, 203664.41, 203664.41, 210100.00, 'completed', '3x Bottled Water (500ml)', '2026-06-28 22:25:29', NULL, NULL, NULL, NULL, NULL, NULL),
(34, 'CART-20260628-5D206E', 'payment', 1, 2, 13, NULL, 30.00, 203664.41, 203664.41, 210100.00, 'completed', '2x Chicken Skin (1pc)', '2026-06-28 22:31:40', NULL, NULL, NULL, NULL, NULL, NULL),
(35, 'CART-20260628-E9916B', 'payment', 1, 2, 13, NULL, 220.00, 203664.41, 203664.41, 210100.00, 'completed', '4x Tapsilog', '2026-06-28 22:33:11', NULL, NULL, NULL, NULL, NULL, NULL),
(36, 'CART-20260628-11DAD9', 'payment', 1, 2, 13, NULL, 275.00, 203664.41, 203664.41, 210100.00, 'completed', '5x Tapsilog', '2026-06-28 22:34:10', NULL, NULL, NULL, NULL, NULL, NULL),
(37, 'CART-20260628-6B4F83', 'payment', 1, 2, 13, NULL, 110.00, 203664.41, 203664.41, 210100.00, 'completed', '2x Tapsilog', '2026-06-28 22:40:58', NULL, NULL, NULL, NULL, NULL, NULL),
(38, 'CART-20260628-6AFF20', 'payment', 1, 2, 13, NULL, 165.00, 203664.41, 203664.41, 210100.00, 'completed', '11x Bottled Water (500ml)', '2026-06-28 22:53:37', NULL, NULL, NULL, NULL, NULL, NULL),
(39, 'TXN-20260629-12261', 'cap_increase', 16, NULL, NULL, NULL, 39900.00, 203664.41, 243564.41, 250000.00, 'completed', 'Cap raised from Php 210100.00 to Php 250000. Reason: To make it equal', '2026-06-29 23:22:54', NULL, NULL, NULL, NULL, NULL, NULL),
(40, 'P2P-20260705-82453E62', 'p2p_transfer', 1, 2, NULL, NULL, 1.00, 243564.41, 243564.41, 250000.00, 'completed', 'P2P Transfer to Zeke Clarence — For Lunch', '2026-07-05 20:21:24', NULL, NULL, NULL, NULL, NULL, NULL),
(41, 'P2P-20260705-C5E3AA15', 'p2p_transfer', 1, 2, NULL, NULL, 450.00, 243564.41, 243564.41, 250000.00, 'completed', 'P2P Transfer to Zeke Clarence — For Lunch', '2026-07-05 20:58:28', NULL, NULL, NULL, NULL, NULL, NULL),
(42, 'CART-20260709-062AA1', 'payment', 1, 2, 13, NULL, 120.00, 243564.41, 243564.41, 250000.00, 'completed', '1x Calamares (1 cup), 1x Tapsilog, 1x Bottled Water (500ml)', '2026-07-09 21:35:05', NULL, NULL, NULL, NULL, NULL, NULL),
(43, 'POS-20260710-E0588A', 'payment', 2, 1, 13, NULL, 20.00, 243564.41, 243564.41, 250000.00, 'completed', 'POS QR Sale: 1x Fishball (10pcs)', '2026-07-10 21:56:34', NULL, NULL, NULL, NULL, NULL, NULL),
(44, 'POS-20260710-A19BEC', 'payment', 2, 1, 13, NULL, 20.00, 243564.41, 243564.41, 250000.00, 'completed', 'POS QR Sale: 1x Fishball (10pcs)', '2026-07-10 21:57:20', NULL, NULL, NULL, NULL, NULL, NULL),
(45, 'POS-20260710-43E4B3', 'payment', 1, 2, 13, NULL, 135.00, 243564.41, 243564.41, 250000.00, 'completed', 'POS QR Sale: 1x Kwek-Kwek (6pcs), 1x Smart C', '2026-07-10 22:16:27', NULL, NULL, NULL, NULL, NULL, NULL),
(46, 'TXN-20260710-65759', 'cash_in', 29, 2, 13, NULL, 997.00, 243564.41, 243566.41, 250000.00, 'completed', 'Merchant send to student. Sent: ₱1000, System fee (2%): ₱2, Merchant cut (1%): ₱1, Credited: ₱997.', '2026-07-10 22:17:40', 'merchant', 1000.00, 3.00, 997.00, NULL, NULL),
(47, 'CART-20260710-43AC1E', 'payment', 1, 2, 13, NULL, 170.00, 243566.41, 243566.41, 250000.00, 'completed', '1x Bottled Water (500ml), 1x Tapsilog, 1x Smart C', '2026-07-10 22:29:11', NULL, NULL, NULL, NULL, NULL, NULL),
(48, 'TXN-20260711-84054', 'tuition_credit', 16, 4, NULL, NULL, 5000.00, 243566.41, 243566.41, 255000.00, 'completed', 'Tuition-backed GenCoin credit. Waiver ref: WAIVER-42067. Cap raised Php 250000.00 -> Php 255000.', '2026-07-11 22:59:08', NULL, NULL, NULL, NULL, NULL, NULL),
(49, 'TXN-20260712-65525', 'cash_in', 16, 4, NULL, NULL, 9980.00, 243566.41, 233586.41, 255000.00, 'completed', 'Top-up via finance. Cash: ₱10000, System fee (2%): ₱20, Credited: ₱9980. Note: Congrats!', '2026-07-12 21:58:52', 'finance', 10000.00, 20.00, 9980.00, NULL, NULL),
(50, 'TXN-20260712-38941', 'cash_in', 16, 4, NULL, NULL, 4990.00, 233586.41, 228596.41, 255000.00, 'completed', 'Top-up via finance. Cash: ₱5000, System fee (2%): ₱10, Credited: ₱4990.', '2026-07-12 22:01:16', 'finance', 5000.00, 10.00, 4990.00, NULL, NULL),
(51, 'TXN-20260712-04896', 'cash_in', 29, 2, 13, NULL, 358.92, 228596.41, 228597.13, 255000.00, 'completed', 'Merchant send to student. Sent: ₱360, System fee (2%): ₱0.72, Merchant cut (1%): ₱0.36, Credited: ₱358.92.', '2026-07-12 22:04:49', 'merchant', 360.00, 1.08, 358.92, NULL, NULL),
(52, 'POS-20260712-13555B', 'payment', 1, 2, 13, NULL, 210.00, 228597.13, 228597.13, 255000.00, 'completed', 'POS QR Sale: 14x Bubble Gum', '2026-07-12 22:10:01', NULL, NULL, NULL, NULL, NULL, NULL),
(53, 'POS-20260712-57E1D5', 'payment', 1, 2, 13, NULL, 360.00, 228597.13, 228597.13, 255000.00, 'completed', 'POS QR Sale: 1x Kwek-Kwek (6pcs), 1x Tokneneng (4pcs), 1x Fishball (10pcs), 1x Kikiam (4pcs), 1x Chicken Skin (1pc), 1x Calamares (1 cup), 1x Tapsilog, 1x Bottled Water (500ml), 1x Smart C, 1x Bubble Gum', '2026-07-12 22:10:43', NULL, NULL, NULL, NULL, NULL, NULL),
(54, 'POS-20260712-E0EA6E', 'payment', 1, 2, 13, NULL, 45.00, 228597.13, 228597.13, 255000.00, 'completed', 'POS QR Sale: 1x Fishball (10pcs), 1x Kikiam (4pcs)', '2026-07-12 22:11:15', NULL, NULL, NULL, NULL, NULL, NULL),
(55, 'CART-20260712-C1CD1F', 'payment', 1, 2, 13, NULL, 215.00, 228597.13, 228597.13, 255000.00, 'completed', '2x Bottled Water (500ml), 1x Calamares (1 cup), 1x Kwek-Kwek (6pcs), 1x Smart C', '2026-07-12 22:12:54', NULL, NULL, NULL, NULL, NULL, NULL),
(56, 'TXN-20260712-56978', 'cash_in', 29, 2, 13, NULL, 199.40, 228597.13, 228597.53, 255000.00, 'completed', 'Merchant send to student. Sent: ₱200, System fee (2%): ₱0.4, Merchant cut (1%): ₱0.2, Credited: ₱199.4.', '2026-07-12 22:21:12', 'merchant', 200.00, 0.60, 199.40, NULL, NULL),
(57, 'TXN-20260712-10269', 'cash_in', 29, 2, 13, NULL, 498.50, 228597.53, 228598.53, 255000.00, 'completed', 'Merchant send to student. Sent: ₱500, System fee (2%): ₱1, Merchant cut (1%): ₱0.5, Credited: ₱498.5.', '2026-07-12 22:21:49', 'merchant', 500.00, 1.50, 498.50, NULL, NULL),
(58, 'POS-20260712-6921E0', 'payment', 1, 2, 13, NULL, 235.00, 228598.53, 228598.53, 255000.00, 'completed', 'POS QR Sale: 6x Fishball (10pcs), 1x Chicken Skin (1pc), 1x Smart C', '2026-07-12 23:23:12', NULL, NULL, NULL, NULL, NULL, NULL),
(59, 'POS-20260712-0551AE', 'payment', 1, 2, 13, NULL, 820.00, 228598.53, 228598.53, 255000.00, 'completed', 'POS QR Sale: 41x Fishball (10pcs)', '2026-07-12 23:23:59', NULL, NULL, NULL, NULL, NULL, NULL),
(60, 'P2P-20260714-8C284C4F', 'p2p_transfer', 23, 4, NULL, NULL, 97.00, 228598.53, 228598.53, 255000.00, 'completed', 'P2P Transfer to Michael Keith Banez', '2026-07-14 10:02:03', NULL, NULL, NULL, NULL, NULL, NULL),
(61, 'P2P-20260714-8A29E2F8', 'p2p_transfer', 23, 4, NULL, NULL, 870.00, 228598.53, 228598.53, 255000.00, 'completed', 'P2P Transfer to Michael Keith Banez', '2026-07-14 10:02:42', NULL, NULL, NULL, NULL, NULL, NULL),
(62, 'TXN-20260717-93991', 'student_withdraw', 16, 2, NULL, NULL, 265.90, 228598.53, 228864.43, 255000.00, 'completed', NULL, '2026-07-17 22:17:19', NULL, NULL, NULL, NULL, NULL, NULL),
(63, 'TXN-20260717-94145', 'student_withdraw', 16, 10, NULL, NULL, 893.99, 228864.43, 229758.42, 255000.00, 'completed', NULL, '2026-07-17 22:17:25', NULL, NULL, NULL, NULL, NULL, NULL),
(69, 'TXN-20260717-87875', 'cash_in', 29, NULL, 13, NULL, 997.00, 229758.42, 229760.42, 255000.00, 'completed', 'Merchant send to parent. Sent: ₱1000, System fee (2%): ₱2, Merchant cut (1%): ₱1, Credited: ₱997.', '2026-07-17 23:36:14', 'merchant', 1000.00, 3.00, 997.00, NULL, 1),
(70, 'CART-20260717-F53D53', 'payment', 1, 2, 13, NULL, 170.00, 229760.42, 229760.42, 254003.00, 'completed', '1x Bottled Water (500ml), 1x Tapsilog, 1x Smart C', '2026-07-17 23:49:42', NULL, NULL, NULL, NULL, NULL, NULL),
(71, 'TXN-20260719-34692', 'allowance', 39, 2, NULL, NULL, 100.00, 229760.42, 229760.42, 255000.00, 'completed', 'Allowance: For Lunch', '2026-07-19 21:46:29', NULL, NULL, NULL, NULL, NULL, 1),
(72, 'CART-20260720-AAAE7C', 'payment', 1, 2, 13, NULL, 15.00, 229760.42, 229760.42, 254103.00, 'completed', '1x Bottled Water (500ml)', '2026-07-20 13:54:59', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tuition_credits`
--

CREATE TABLE `tuition_credits` (
  `id` int UNSIGNED NOT NULL,
  `student_user_id` int UNSIGNED NOT NULL,
  `student_wallet_id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `waiver_reference_no` varchar(80) NOT NULL,
  `waiver_file` varchar(500) DEFAULT NULL,
  `waiver_notes` text,
  `status` enum('pending_payment','active','rejected','cancelled') NOT NULL DEFAULT 'pending_payment',
  `created_by` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_confirmed_by` int UNSIGNED DEFAULT NULL,
  `payment_confirmed_at` datetime DEFAULT NULL,
  `payment_reference_no` varchar(80) DEFAULT NULL,
  `credited_txn_ref` varchar(40) DEFAULT NULL,
  `rejected_by` int UNSIGNED DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text,
  `cancelled_by` int UNSIGNED DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tuition_credits`
--

INSERT INTO `tuition_credits` (`id`, `student_user_id`, `student_wallet_id`, `parent_id`, `amount`, `waiver_reference_no`, `waiver_file`, `waiver_notes`, `status`, `created_by`, `created_at`, `payment_confirmed_by`, `payment_confirmed_at`, `payment_reference_no`, `credited_txn_ref`, `rejected_by`, `rejected_at`, `rejection_reason`, `cancelled_by`, `cancelled_at`, `updated_at`) VALUES
(1, 23, 4, NULL, 5000.00, 'WAIVER-42067', 'uploads/tuition_credits/1/waiver_17837819029437.png', 'Scholar', 'active', 16, '2026-07-11 22:58:22', 16, '2026-07-11 22:59:08', 'OR-2026', 'TXN-20260711-84054', NULL, NULL, NULL, NULL, NULL, '2026-07-11 22:59:08');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `userID` int NOT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `middle_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `suffix` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_number` bigint NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `roleID` int NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `mint_pin` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'bcrypt hash of the super-admin mint PIN -- required above monthly limit',
  `profile_img` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sub_role` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Granular role: super_admin | merchant_admin | merchant_staff | student',
  `position` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = merchant must change password on next login',
  `merchant_owner_id` int UNSIGNED DEFAULT NULL COMMENT 'FK -> users.userID â€” links Merchant Staff to their Merchant Admin',
  `is_first_login` tinyint(1) NOT NULL DEFAULT '0',
  `password_changed` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('Active','Inactive') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Active',
  `temp_password` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `restricted_violation_count` int UNSIGNED NOT NULL DEFAULT '0',
  `restricted_violation_last_at` datetime DEFAULT NULL,
  `restricted_suspended_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`userID`, `last_name`, `first_name`, `middle_name`, `suffix`, `contact_number`, `email`, `roleID`, `password`, `mint_pin`, `profile_img`, `created_at`, `sub_role`, `position`, `force_password_change`, `merchant_owner_id`, `is_first_login`, `password_changed`, `status`, `temp_password`, `restricted_violation_count`, `restricted_violation_last_at`, `restricted_suspended_until`) VALUES
(1, 'Banez', 'Michael Keith', 'Garciua', '', 9171234567, 'michael@email.com', 1, '$2y$10$KvXWV/yfD1qOl8HpD.fXwOkiuiUdMGNnIKWogYqH8yd7TQPAEIMXm', NULL, 'assets/profile_photos/1.png', '2026-04-29 13:46:44', 'student', NULL, 0, NULL, 0, 1, 'Active', NULL, 0, NULL, NULL),
(2, 'Clarence', 'Zeke', 'Dela', '', 9179876543, 'otto.cruz@email.com', 1, '$2y$10$RzfxXs2bgpue92Ija1v9i.24s4Em3xuc0a8XIUTm.1AteYuBa0r.S', NULL, 'assets/profile_photos/2.jpg', '2026-04-29 13:46:44', 'student', NULL, 0, NULL, 0, 1, 'Active', NULL, 0, NULL, NULL),
(5, 'Ramos', 'Maria', 'Bautista', '', 9175556789, 'maria.ramos@email.com', 1, '$2y$10$.dg8CYfcrIIcrwdymHAei.csBgCdNpzBq3GcEo/hgzlLMy9K7G/KO', NULL, 'f', '2026-04-29 13:46:44', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GenPay@2026', 0, NULL, NULL),
(6, 'Tubon', 'Joshtine Nel', 'Mendoza', '', 9176667890, 'jose.garcia@email.com', 1, '$2y$10$iZSD.A82/ivWzCO8eTppPuJ09v/dpFs7KSLmaZlXrrmFaNLtPwW3O', NULL, 'f', '2026-04-29 13:46:44', 'student', NULL, 0, NULL, 0, 1, 'Active', NULL, 0, NULL, NULL),
(7, 'Reyes', 'Ana', 'Lopez', '', 9170001111, 'ana.reyes@email.com', 4, '$2y$10$.dg8CYfcrIIcrwdymHAei.csBgCdNpzBq3GcEo/hgzlLMy9K7G/KO', NULL, 'f', '2026-04-29 13:46:44', 'super_admin', NULL, 1, NULL, 1, 0, 'Inactive', 'GenPay@2026', 0, NULL, NULL),
(10, 'Samson', 'Jasmin', '', '', 0, 'daitodump@gmail.com', 1, '$2y$10$F4.JY8pzihGflPavNIU5kOMHyh6fdtNiT5DnVAcFBEt8HorKnT5hm', NULL, '', '2026-06-09 13:32:05', NULL, NULL, 0, NULL, 0, 1, 'Active', NULL, 0, NULL, NULL),
(12, 'Admin', 'Super', '', '', 9000000000, 'superadmin@gjc.edu.ph', 4, '$2y$10$sBRgQY3pqNa5auMJtfwmpuexeNHsycazm3fOpKtpTVmZN/jAuYfXW', NULL, 'default.png', '2026-06-09 14:18:17', 'super_admin', NULL, 0, NULL, 0, 1, 'Inactive', NULL, 0, NULL, NULL),
(16, 'Office', 'Finance', '', '', 9000000001, 'finance@gjc.edu.ph', 4, '$2y$10$sDtHk0UVUmnJ3BusTeP7ie.gjRanguhS0ev.ZJykzEjUEPvwcMPRO', NULL, '', '2026-06-15 08:33:54', 'super_admin', NULL, 0, NULL, 0, 1, 'Active', NULL, 0, NULL, NULL),
(23, 'Santiago', 'Ezekiel Clarence', '', '', 9000000002, 'student@gjc.edu.ph', 1, '$2y$10$lXrTwfaJkuNCH87hwfucs.QgpSFaGBSjAiuwh3C3rBN85VMFPe0mi', NULL, 'assets/profile_photos/23.jpg', '2026-06-15 17:10:47', 'student', NULL, 0, NULL, 0, 1, 'Active', NULL, 0, NULL, NULL),
(29, 'Manalastas', 'Jastine', '', '', 9614708398, 'ezekielclarencesantiago68@gmail.com', 2, '$2y$10$ZC6r7IlBrIntircjGAsgX.BYoGNnhst.3tCnNHPXkJCXJMBzEw9ku', NULL, 'assets/merchant_logos/14.png', '2026-06-20 14:52:28', 'merchant_admin', NULL, 0, NULL, 0, 1, 'Active', NULL, 18, '2026-07-19 21:29:43', NULL),
(35, 'Jr.', 'Greg Bautista', '', '', 9614708712, 'noahgray430@gmail.com', 2, '$2y$10$f23crq4y6/etL76WQy1FZOnb6cK7U1gk5RoJ32zFZUo.AB05vVH9i', NULL, 'assets/merchant_logos/15.png', '2026-06-22 16:26:30', 'merchant_admin', NULL, 0, NULL, 0, 1, 'Active', NULL, 0, '2026-07-19 21:44:37', '2026-07-22 21:44:37'),
(38, 'Emata', 'Monica', '', '', 9614708391, 'virgelopez611@gmail.com', 6, '$2y$10$tbnObivHPJURlFDKCt/nxugckbP3iSc7zTnaqaff.P7TQL8FN3XLa', NULL, '', '2026-06-26 16:31:45', 'merchant_staff', NULL, 0, 29, 0, 1, 'Active', NULL, 0, NULL, NULL),
(39, 'Hatsune', 'Miku', '', '', 0, 'hatsunemiku@email.com', 7, '$2y$10$DPlkrvgptsuzK0Z0W7lHvOSryIsFwmBoZl1rdd3MMEZBRggbvvDUu', NULL, 'assets/profile_photos/39.jpg', '2026-06-28 14:21:37', NULL, NULL, 0, NULL, 0, 1, 'Active', NULL, 0, NULL, NULL),
(247, 'Dela Cruz', 'Juan Miguel', '', '', 9171234570, 'juanmiguel.delacruz@student.gjc.edu.ph', 1, '$2y$10$Rm61qO.0aCwllsAbVZDaVeUl9K/IAQbDq30R8V0OizXBP82K9z6Ya', NULL, '', '2026-07-04 13:52:20', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2006', 0, NULL, NULL),
(248, 'Dela Cruz', 'Maria', '', '', 9181234570, 'maria.delacruz@gmail.com', 7, '$2y$10$Cfr6oZ3O0wTryWNc0JrxUee/XNBmHqPM6MxnyYeWg6KJFL7GrlJMC', NULL, '', '2026-07-04 13:52:20', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'i4YXEB8Q6J', 0, NULL, NULL),
(249, 'Villanueva', 'Andrea Nicole', '', '', 9182345671, 'andreanicole.villanueva@student.gjc.edu.ph', 1, '$2y$10$.r6BpdRtVadAp0Rz6PVHSe9cRIvzHjV9kkhqqoS/9A/9J5tSOz902', NULL, '', '2026-07-04 13:52:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2007', 0, NULL, NULL),
(250, 'Villanueva', 'Roberto', '', '', 9192345671, 'rvillanueva@yahoo.com', 7, '$2y$10$8.wTpge9lsZQMH8QJjh/nOTaDQUmOB/9lrJQEuNeyF45UMzpQuQoa', NULL, '', '2026-07-04 13:52:21', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'giLw2rUpxx', 0, NULL, NULL),
(251, 'Peñaflor', 'Carlos', '', '', 9293456782, 'carlos.penaflor@student.gjc.edu.ph', 1, '$2y$10$uVoBTRzG9S0XIS3sCnx43e12SD.GhItGx6ubSYFoWQ2k81hOHNk7W', NULL, '', '2026-07-04 13:52:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2008', 0, NULL, NULL),
(252, 'Peñaflor', 'Lourdes', '', '', 9203456782, 'lpenaflor@gmail.com', 7, '$2y$10$fD3srcRhTaM07Zg/miYxE.TU2TD4Y1YLFJICs9ckn97KyBd6B/JAa', NULL, '', '2026-07-04 13:52:21', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'ZPvqXKurtn', 0, NULL, NULL),
(253, 'Mendoza', 'Sofia Isabel', '', '', 9304567893, 'sofiaisabel.mendoza@student.gjc.edu.ph', 1, '$2y$10$wrYAVpXDCTGf/S9Alhs6F.DvVU4KRtbpFSAkRD84EXIWXCxfDioCG', NULL, '', '2026-07-04 13:52:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2009', 0, NULL, NULL),
(254, '', 'kujo7397', '', '', 0, 'kujo7397@gmail.com', 7, '$2y$10$1hPlOvRkGFtaiAQlhx06i.1eVF/0/CidHVZwGw0i.2OEYw3thWuP2', NULL, '', '2026-07-04 13:52:21', 'parent', NULL, 0, NULL, 0, 1, 'Active', NULL, 0, NULL, NULL),
(255, 'Aquino', 'Joshua', '', '', 9344567894, 'joshua.aquino@student.gjc.edu.ph', 1, '$2y$10$8VT1PRaaeuO/1GGVmSx23eMfzP4zcUS49UFR5UMDdv5OPG/oAhv4K', NULL, '', '2026-07-04 13:52:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2010', 0, NULL, NULL),
(256, 'Aquino', 'Grace', '', '', 9354567894, 'grace.aquino@gmail.com', 7, '$2y$10$B8agYj/315uJT6lIRtUm1u4Hai3dVcS7ja2MXA3ub16bc.HA5B5ni', NULL, '', '2026-07-04 13:52:21', 'parent', NULL, 1, NULL, 1, 0, 'Active', '7Ljw5inrfC', 0, NULL, NULL),
(257, 'Aquino', 'Angelica Mae', '', '', 9344567895, 'angelicamae.aquino@student.gjc.edu.ph', 1, '$2y$10$hTiBymAlQuNceKm5KRO2WOFDlSGLTb2gH1dpxYGmKu8IIOPf9Twq2', NULL, '', '2026-07-04 13:52:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2011', 0, NULL, NULL),
(258, 'Santiago', 'Mark Anthony', '', 'Jr.', 9455678906, 'markanthony.santiago@student.gjc.edu.ph', 1, '$2y$10$wziwQw2ppcceBHYhcoG/4e72OFfdeyYbA.sBfnin6Z02vjZeXWdwq', NULL, '', '2026-07-04 13:52:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2012', 0, NULL, NULL),
(259, 'Santiago', 'Elena', '', '', 9465678906, 'elena.santiago@outlook.com', 7, '$2y$10$5PzyYD7h3oKV8LVjvg6nb.zcek/N91B47x5j9gOXoUIe9V9oN4kgu', NULL, '', '2026-07-04 13:52:21', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'qTabUGbyYs', 0, NULL, NULL),
(260, 'Ocampo', 'Kristine Joy', '', '', 9455678907, 'kristinejoy.ocampo@student.gjc.edu.ph', 1, '$2y$10$Q/rHtdgasXNtIA1M9LXHnetRm6ODcMavNC/B3Js3LVDBDgHpbJg7y', NULL, '', '2026-07-04 13:52:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2013', 0, NULL, NULL),
(261, 'Manalo', 'Rafael', '', 'II', 9566789018, 'rafael.manalo@student.gjc.edu.ph', 1, '$2y$10$3GfTWDZtQVTt48elU/Wybu8peF1ZrzhpgmK67xzh7vygjZfq2W2z6', NULL, '', '2026-07-04 13:52:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2014', 0, NULL, NULL),
(262, 'Manalo', 'Antonio', '', '', 63957711, 'tony.manalo@gmail.com', 7, '$2y$10$bBydpqa7GrherjkGIZ.ZnO.iff./C1kS416V2gUG2L2R5NPvl0STS', NULL, '', '2026-07-04 13:52:22', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'Vqw5jsmmZh', 0, NULL, NULL),
(263, 'Salazar', 'Bianca', '', '', 9677890129, 'bianca.salazar@student.gjc.edu.ph', 1, '$2y$10$lOemLiz00fAi6XRfCr4s9.gFBxGMo4cJQADWyqJGWjeCp.rnNOgNK', NULL, '', '2026-07-04 13:52:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2015', 0, NULL, NULL),
(264, 'Salazar', 'Carmen', '', '', 9687890129, 'carmen.salazar@gmail.com', 7, '$2y$10$2hPjXkfpiSmtGYv4KoX1iucbI0rbZ/w7sQBLkHYT4uPKkc8MyBMAa', NULL, '', '2026-07-04 13:52:22', 'parent', NULL, 1, NULL, 1, 0, 'Active', '6iURm8RMXN', 0, NULL, NULL),
(265, 'Domingo', 'John Paul', '', '', 9788901230, 'johnpaul.domingo@student.gjc.edu.ph', 1, '$2y$10$d1LqRszO1jt0za59ygd9zukCIJEziRx/i/KhVHno7qBzPdik8biC.', NULL, '', '2026-07-04 13:52:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2016', 0, NULL, NULL),
(266, 'Domingo', 'Teresa', '', '', 9798901230, 'tdomingo@gmail.com', 7, '$2y$10$da8RvkWRhlGNtzAOIsBmNOeknR0nlvJM98lcIMMbMUkOO/h4Koj.K', NULL, '', '2026-07-04 13:52:22', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'wfDf8ucC9B', 0, NULL, NULL),
(267, 'Ignacio', 'Camille', '', '', 9899012341, 'camille.ignacio@student.gjc.edu.ph', 1, '$2y$10$xwk/hsfZCDtGPDiymWuRN.V9yXyx9aDNc751IPj3QJ.kC3ttp3O4m', NULL, '', '2026-07-04 13:52:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2017', 0, NULL, NULL),
(268, 'Muñoz', 'Gabriel', '', 'III', 9910123452, 'gabriel.munoz@student.gjc.edu.ph', 1, '$2y$10$HggQMsXyuK.Ly/DjvF1XDelot0vMtScFfE2XsEk99BKDqSMEPfBcC', NULL, '', '2026-07-04 13:52:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2018', 0, NULL, NULL),
(269, 'Muñoz', 'Ricardo', '', '', 9920123452, 'ric.munoz@yahoo.com', 7, '$2y$10$ZBe2SayjQA1ES/Bb.s3p/OOl5/NWfIu9YFQvf0Mz4rIzBelUFrqxm', NULL, '', '2026-07-04 13:52:22', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'niaAu3Zs9p', 0, NULL, NULL),
(270, 'Del Rosario', 'Patricia Anne', '', '', 9021234563, 'patriciaanne.delrosario@student.gjc.edu.ph', 1, '$2y$10$BM9j48hYA9/rcyd8QxU7ieCQ9abvFwMFj4qocPgZG96BMz6XaRAfW', NULL, '', '2026-07-04 13:52:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2019', 0, NULL, NULL),
(271, 'Del Rosario', 'Josefina', '', '', 9031234563, 'josie.delrosario@gmail.com', 7, '$2y$10$MV6eQfWBmmGWAu/Vw0r/K.p2vgZ6canOQa3GRQQF80mSs.Z3FWt86', NULL, '', '2026-07-04 13:52:22', 'parent', NULL, 1, NULL, 1, 0, 'Active', '8qKq9aDfqC', 0, NULL, NULL),
(272, 'Trinidad', 'Lorenzo', '', '', 9132345674, 'lorenzo.trinidad@student.gjc.edu.ph', 1, '$2y$10$snqGYoueMRFKMTQlqM39ieTq0CJDn5W2ImcJSeYv7mTe4mMhCDNuu', NULL, '', '2026-07-04 13:52:23', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2020', 0, NULL, NULL),
(281, 'Santiago', 'Ezekiel Clarence', '', '', 9614708398, 'ezekielclarence6@gmail.com', 6, '$2y$10$FAJkqhhFC6nZ04RRKoiX5udiyMmR47WUTJYrYKYy8LVurXrwlXruK', NULL, '', '2026-07-07 03:26:00', 'merchant_staff', NULL, 0, 280, 0, 1, 'Inactive', NULL, 0, NULL, NULL),
(282, 'Ignacio', 'Bianca', '', '', 9680402501, 'bianca.ignacio@student.gjc.edu.ph', 1, '$2y$10$Zl6RxYHY7esZMInZan5wOurmsSglNQYgFUT6lGoU9InV/C77EmNH2', NULL, '', '2026-07-07 03:44:10', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2021', 0, NULL, NULL),
(283, 'Valdez', 'Ryan', '', '', 9831067988, 'ryan.valdez@student.gjc.edu.ph', 1, '$2y$10$wIJUIkEr8CoqSUWIm73C4uOsJ6YlBfyguA7rEC5JJwkl9xuhqzjT6', NULL, '', '2026-07-07 03:44:11', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2022', 0, NULL, NULL),
(284, 'Valdez', 'Fernando', '', '', 9868759358, 'fernandovaldez76@yahoo.com', 7, '$2y$10$mMwo4.jmNnyRDW7hXSo45OKe90/rQqM7g4hJ9s10wLmcK9wj2SuPi', NULL, '', '2026-07-07 03:44:11', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'hVi3JcutP7', 0, NULL, NULL),
(285, 'Peñaflor', 'Nathaniel', '', '', 9139656872, 'nathaniel.penaflor@student.gjc.edu.ph', 1, '$2y$10$IHZqgoDMf/8QJ49DrduEXuePM9We9SD/7D/bBka0ZmicLFNXiBf7K', NULL, '', '2026-07-07 03:44:11', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2023', 0, NULL, NULL),
(286, 'Peñaflor', 'Fernando', '', '', 9633229661, 'fernandopenaflor@gmail.com', 7, '$2y$10$qpw5zjWgljpdQJT.qfbQJeckXXokCFXsqh/mdnH.h8j5r182Rk2oq', NULL, '', '2026-07-07 03:44:11', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'zSVSK4BZHj', 0, NULL, NULL),
(287, 'Peñaflor', 'Frances', '', '', 9409572486, 'frances.penaflor@student.gjc.edu.ph', 1, '$2y$10$efszUPWHgXj0qUw.Wes/e.alf5NvZJ318K0zrLr45z/nWT/beFMgW', NULL, '', '2026-07-07 03:44:11', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2024', 0, NULL, NULL),
(288, 'Bautista', 'Rafael', '', '', 9652614498, 'rafael.bautista@student.gjc.edu.ph', 1, '$2y$10$QfqbVDWDskmaTHNmY0/Wj.hu4WXyKdGEeT71Wlw.0fiOqdbp5zCjy', NULL, '', '2026-07-07 03:44:12', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2025', 0, NULL, NULL),
(289, 'Bautista', 'Marilou', '', '', 9653542596, 'mariloubautista69@yahoo.com', 7, '$2y$10$wYa/V5ugmaCD/y2mIedl2.Y8ehaffsJTXePR5yC0qMjZ5o5eqlvTG', NULL, '', '2026-07-07 03:44:12', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'pZBWxar8EG', 0, NULL, NULL),
(290, 'Gutierrez', 'Francis', '', '', 9904077145, 'francis.gutierrez@student.gjc.edu.ph', 1, '$2y$10$yoE0Au0xCw.3085AJytTr.3sc0MkJc4./JKKWyo.hYaCtSwvypKHm', NULL, '', '2026-07-07 03:44:12', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2026', 0, NULL, NULL),
(291, 'Rosales', 'Diana', '', '', 9219547810, 'diana.rosales@student.gjc.edu.ph', 1, '$2y$10$MAsHiYZ2HD8XY.vcNPCxtuHPXyUPcvdrfS7ZrtoelNxWE90OZjYoW', NULL, '', '2026-07-07 03:44:12', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2027', 0, NULL, NULL),
(292, 'Rosales', 'Carmen', '', '', 9507000352, 'carmenrosales70@outlook.com', 7, '$2y$10$3cO8U0W9YUhdi45fWWDM4eRwUZT/PPlo3Nth01iJ2.AMgOQ2Xybui', NULL, '', '2026-07-07 03:44:12', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'wW5StHU3yu', 0, NULL, NULL),
(293, 'Diaz', 'Adrian', '', '', 9142403927, 'adrian.diaz@student.gjc.edu.ph', 1, '$2y$10$uqVXbkm1A0IYJQ9qnXDcWezWTgp30raTiR1LboN6RjfDp6qXn9P8u', NULL, '', '2026-07-07 03:44:12', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2028', 0, NULL, NULL),
(294, 'Diaz', 'Priscilla', '', '', 9294850989, 'priscilladiaz94@gmail.com', 7, '$2y$10$RCG3h4f0QYpGtNLe9Qx/zuYrqXOB6jA4ZCSbby.qcc4jJboxDquEq', NULL, '', '2026-07-07 03:44:13', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'vSDvMDGd23', 0, NULL, NULL),
(295, 'Diaz', 'Paolo', '', '', 9500653263, 'paolo.diaz@student.gjc.edu.ph', 1, '$2y$10$m/p0hrMDt6I0LM9GNMThxOhc/KPWbsJyT3eqxOgkG4fDe5ORLfzia', NULL, '', '2026-07-07 03:44:13', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2029', 0, NULL, NULL),
(296, 'Diaz', 'Imelda', '', '', 9190156218, 'imeldadiaz72@outlook.com', 7, '$2y$10$BvGk13x2jPZXqZaQAu5uA.yDgT1zo95Fhm4w8cZFPbMMJr/yfT19S', NULL, '', '2026-07-07 03:44:13', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'rsvFbKFgrB', 0, NULL, NULL),
(297, 'Bautista', 'Jasmine', '', '', 9269111526, 'jasmine.bautista@student.gjc.edu.ph', 1, '$2y$10$.x2N38JoKZKEFQI3UWSMo.5NMcP6fwTz0JOKBJW1LbZKXT8czdZme', NULL, '', '2026-07-07 03:44:13', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2030', 0, NULL, NULL),
(298, 'Bautista', 'Josefina', '', '', 9224539856, 'josefinabautista59@yahoo.com', 7, '$2y$10$ONnzDf1z4F.fADdpNT503uEJ1ah9wAKS/rDBvms2wu0BtinhapkWa', NULL, '', '2026-07-07 03:44:13', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'eQaxTfrZJs', 0, NULL, NULL),
(299, 'Domingo', 'Nicole', '', '', 9403990366, 'nicole.domingo@student.gjc.edu.ph', 1, '$2y$10$pVjeWXhLhxptgLEuu52KUObRCX4WEFBZqM1T5C4f/5ImME2vRBeZm', NULL, '', '2026-07-07 03:44:13', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2031', 0, NULL, NULL),
(300, 'Domingo', 'Priscilla', '', '', 9540089585, 'priscilladomingo34@outlook.com', 7, '$2y$10$cKOy2MOcYqS3YRPIOq0DBeEjCOkIjZ4XfqdAUweQpteGNP7sMSxTK', NULL, '', '2026-07-07 03:44:14', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'tCYuhsbUsP', 0, NULL, NULL),
(301, 'Marquez', 'Clarisse', '', '', 9388263207, 'clarisse.marquez@student.gjc.edu.ph', 1, '$2y$10$mLVP8uqiBl1/gal0BDh2WuZjXckduqsvftuLok.svmeDpxQ0.a99G', NULL, '', '2026-07-07 03:44:14', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2032', 0, NULL, NULL),
(302, 'Marquez', 'Rosario', '', '', 9882718828, 'rosariomarquez80@outlook.com', 7, '$2y$10$SeQ1ZM2CjOjXvm4z2WKuKu1Yc9lRcn4y5JpweeTbuVHprL0aJeJYS', NULL, '', '2026-07-07 03:44:14', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'KeP5awvjAt', 0, NULL, NULL),
(303, 'Soriano', 'Lorraine', '', '', 9306338733, 'lorraine.soriano@student.gjc.edu.ph', 1, '$2y$10$YHNl0gIoayjEQx8k42OtveEUX1gLeTnHTMTsNL9mx6a7Hx4dF3XHS', NULL, '', '2026-07-07 03:44:14', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2033', 0, NULL, NULL),
(304, 'Soriano', 'Ernesto', '', '', 9535270957, 'ernestosoriano69@yahoo.com', 7, '$2y$10$4IWgH2xc8TjGn9l0BOmkju/39XD1JW2vEcB5bFr6rAnvwSQ2wcETi', NULL, '', '2026-07-07 03:44:14', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'TwUcfQghvd', 0, NULL, NULL),
(305, 'Pascual', 'Christian', '', '', 9660923810, 'christian.pascual@student.gjc.edu.ph', 1, '$2y$10$kHN6sLKgmEJCuTgBt0Kcme5P5wRwa77qK6wk47gBmIM.mAYY8BKOq', NULL, '', '2026-07-07 03:44:14', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2034', 0, NULL, NULL),
(306, 'Pascual', 'Marilou', '', '', 9644249710, 'mariloupascual17@gmail.com', 7, '$2y$10$fo9LuSpmj7ELMzkGtx.brO2UE0LjWPusDrRrGubK/sjGJikIyUJIm', NULL, '', '2026-07-07 03:44:15', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'Ztgcru8pwJ', 0, NULL, NULL),
(307, 'Agustin', 'Colleen', '', '', 9728645911, 'colleen.agustin@student.gjc.edu.ph', 1, '$2y$10$.6BeWZ1lfHZb2YBrD7KIWul80ZNrbG.RC.ZsFjIh8ktF.4AIUiITK', NULL, '', '2026-07-07 03:44:15', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2035', 0, NULL, NULL),
(308, 'Agustin', 'Priscilla', '', '', 9748573316, 'priscillaagustin@gmail.com', 7, '$2y$10$FtXwXwAHIwHeHBrx8MSe0.Omqfcmvkd1fFP3Gcv5ZtLYEpp1yStHa', NULL, '', '2026-07-07 03:44:15', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'Ejf5DbSkt7', 0, NULL, NULL),
(309, 'Agustin', 'Jerome', '', 'III', 9959867585, 'jerome.agustin@student.gjc.edu.ph', 1, '$2y$10$W0YwGLpKRtnXVj.4vFqNmeOJhDq6VokWnOyneRbPg8fGr5zTS2Puy', NULL, '', '2026-07-07 03:44:15', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2036', 0, NULL, NULL),
(310, 'Alcantara', 'Mark Anthony', '', 'II', 9956873037, 'markanthony.alcantara@student.gjc.edu.ph', 1, '$2y$10$p5Qy9nstbKZdrGZrjObM6.pVBzzHPfweinZtD.VSZsl6RyMKahOja', NULL, '', '2026-07-07 03:44:15', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2037', 0, NULL, NULL),
(311, 'Alcantara', 'Fernando', '', '', 9131243408, 'fernandoalcantara34@gmail.com', 7, '$2y$10$eHWp7seueTjnkCGN6JxV..WLX.A5eTI0/ZOJK6Go8B9ZVjnqMzVEC', NULL, '', '2026-07-07 03:44:16', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'N9AkmkTDkb', 0, NULL, NULL),
(312, 'Lopez', 'Colleen', '', '', 9237378200, 'colleen.lopez@student.gjc.edu.ph', 1, '$2y$10$fJo/T7EkODb39.p7DGNgnu3U57EB8nPVM/44Ab3osqI0R9WQ0RIM2', NULL, '', '2026-07-07 03:44:16', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2038', 0, NULL, NULL),
(313, 'Lopez', 'Imelda', '', '', 9595604812, 'imeldalopez70@outlook.com', 7, '$2y$10$Jo/xwrygkpKxiXwePk6f0e3PRLpuAo5Ndfbe38Qm5IuG7kPVrlJhm', NULL, '', '2026-07-07 03:44:16', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'Djtj7McY6U', 0, NULL, NULL),
(314, 'Navarro', 'Ana Sofia', '', '', 9370661786, 'anasofia.navarro@student.gjc.edu.ph', 1, '$2y$10$up3B08aor5SnGt2r9kzXTeEwBpMRE8cSKmW.zJdyDG7AXY2Zl5nPe', NULL, '', '2026-07-07 03:44:16', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2039', 0, NULL, NULL),
(315, 'Navarro', 'Roberto', '', '', 9167189103, 'robertonavarro84@gmail.com', 7, '$2y$10$JMHjTnGWBbjFgLCDRraGdO6UrCmPqSBfxaIs.SbUSut.UHlseYrTi', NULL, '', '2026-07-07 03:44:16', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'k6nPbDgaXw', 0, NULL, NULL),
(316, 'Espino', 'Elijah', '', '', 9288082463, 'elijah.espino@student.gjc.edu.ph', 1, '$2y$10$30ra.k8sumFctCdBYsAaJuDz/naIqu5SZbiBQXUo.xj8eQTblJQoy', NULL, '', '2026-07-07 03:44:16', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2040', 0, NULL, NULL),
(317, 'Espino', 'Lourdes', '', '', 9115669748, 'lourdesespino69@yahoo.com', 7, '$2y$10$2uTHvhNBzabpuCij4YdxjuOWAWmeLyazMi9sPLJNC.HZuRya/e4Xi', NULL, '', '2026-07-07 03:44:17', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'iu2sAAD5Qj', 0, NULL, NULL),
(318, 'Torres', 'Katrina', '', '', 9354547372, 'katrina.torres@student.gjc.edu.ph', 1, '$2y$10$rVj5ImiFrZB8/HqQ641/suRs7imzG8wXrXB6sS3S8sidM3kXIdX9O', NULL, '', '2026-07-07 03:44:17', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2041', 0, NULL, NULL),
(319, 'Ramos', 'Lorenzo', '', '', 9102439281, 'lorenzo.ramos@student.gjc.edu.ph', 1, '$2y$10$gJZN/BNJf26JxoDHMHGlouKni6EFcYbzvxg5IbdnesUS2jCFLCUN.', NULL, '', '2026-07-07 03:44:17', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2042', 0, NULL, NULL),
(320, 'Ramos', 'Ramon', '', '', 9210529561, 'ramonramos16@outlook.com', 7, '$2y$10$nRi37SbWny2Fnusuycx2BO8EN6at/ZeuCKYU5ClsgapzEX8zccB/e', NULL, '', '2026-07-07 03:44:17', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'WZsmYiytex', 0, NULL, NULL),
(321, 'Villegas', 'Jasmine', '', 'Jr.', 9189025456, 'jasmine.villegas@student.gjc.edu.ph', 1, '$2y$10$nVPbiVCYimBpcc7bkPj77OCIiLdsC3Mr6E1Jjqucyo/QbjRVDgE3q', NULL, '', '2026-07-07 03:44:17', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2043', 0, NULL, NULL),
(322, 'Castro', 'Diana', '', 'II', 9976694569, 'diana.castro@student.gjc.edu.ph', 1, '$2y$10$Fi8wwGv7IDZGVpcpgfR7iega1FY0LAo.C.P7oJq.wJ4NQwInNMhoO', NULL, '', '2026-07-07 03:44:17', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2044', 0, NULL, NULL),
(323, 'Castro', 'Fernando', '', '', 9004522774, 'fernandocastro39@outlook.com', 7, '$2y$10$n7eDl0fFgWA8oZAQnf/vMOhFwBzgQip0ZJlr/Dwn27T40DNEZSCom', NULL, '', '2026-07-07 03:44:17', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'VqZFh3jUiw', 0, NULL, NULL),
(324, 'Mendoza', 'Andrea', '', '', 9589443425, 'andrea.mendoza@student.gjc.edu.ph', 1, '$2y$10$gbAslOA6g.WlUcepc4KkH.CcMiaSGXP/FAsXUU6imjGJ6VDpg7VMC', NULL, '', '2026-07-07 03:44:18', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2045', 0, NULL, NULL),
(325, 'Rosales', 'Isabella', '', 'II', 9760268507, 'isabella.rosales@student.gjc.edu.ph', 1, '$2y$10$pEoUFAZBfr4X8RLW3Phfx.TPt69OaGVldZNgewnMszmSVE7KTurqC', NULL, '', '2026-07-07 03:44:18', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2046', 0, NULL, NULL),
(326, 'Espino', 'Angelica Mae', '', '', 9469156403, 'angelicamae.espino@student.gjc.edu.ph', 1, '$2y$10$Csi3P8MZa1L2jMOJH6u6Qez/mHDCZbkq8IGjh7JgsIpGoCC096D7C', NULL, '', '2026-07-07 03:44:18', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2047', 0, NULL, NULL),
(327, 'Sarmiento', 'Christian', '', 'Jr.', 9362985433, 'christian.sarmiento@student.gjc.edu.ph', 1, '$2y$10$ucpbt81zl3G3gAgfwCZSau5Fdlih3.tJQELLKV97tbo2Q3QGAgsyK', NULL, '', '2026-07-07 03:44:18', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2048', 0, NULL, NULL),
(328, 'Soriano', 'Jasmine', '', 'II', 9994184752, 'jasmine.soriano@student.gjc.edu.ph', 1, '$2y$10$MpynJ07VUJXwrSP50A77wOSW5outUmtedE.QAlbW1pDsHNNI7OgJa', NULL, '', '2026-07-07 03:44:18', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2049', 0, NULL, NULL),
(329, 'Soriano', 'Maria', '', '', 9840677304, 'mariasoriano85@outlook.com', 7, '$2y$10$uFGX7IGXnwmds28S7mLbJu1TmSIb5eTgHwh7mas5Y8g2AXn/2Ivoe', NULL, '', '2026-07-07 03:44:19', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'DkfkLFeTRx', 0, NULL, NULL),
(330, 'Hernandez', 'Emmanuel', '', '', 9431652707, 'emmanuel.hernandez@student.gjc.edu.ph', 1, '$2y$10$U9RHTgJCWz2ItGNZ6.dr9.5TemQGUQc8tUaBqE0vZ0/Ce5pgRr1f.', NULL, '', '2026-07-07 03:44:19', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2050', 0, NULL, NULL),
(331, 'Hernandez', 'Marilou', '', '', 9785684437, 'marilouhernandez19@yahoo.com', 7, '$2y$10$3cHa2bjchWp9KmDwVSQqr.Fx5SdmnCNyYIZRagM97sOGMBsOgkmAq', NULL, '', '2026-07-07 03:44:19', 'parent', NULL, 1, NULL, 1, 0, 'Active', '7AubSkJBa3', 0, NULL, NULL),
(332, 'Castro', 'Ivan', '', '', 9337780069, 'ivan.castro@student.gjc.edu.ph', 1, '$2y$10$qfkk2MjrpwnfgvjIgHkCDOSa9cx7A1FUvsSpdYOc1Z1cB9mIQ2ePa', NULL, '', '2026-07-07 03:44:19', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2051', 0, NULL, NULL),
(333, 'Castro', 'Corazon', '', '', 9461493668, 'corazoncastro84@gmail.com', 7, '$2y$10$2eXfl9NS6kkC3cVj1j9kauUGPJ60kjG6aWX4i0sfGV37yl17GV5ca', NULL, '', '2026-07-07 03:44:19', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'LQxheHmx39', 0, NULL, NULL),
(334, 'Aquino', 'Trisha', '', '', 9468425099, 'trisha.aquino@student.gjc.edu.ph', 1, '$2y$10$8r0ZVpFeRgUvtPPwuyWupO6sqjL6EGvvDvaLet67RbR92uiYzDT7.', NULL, '', '2026-07-07 03:44:19', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2052', 0, NULL, NULL),
(335, 'Aquino', 'Grace', '', '', 9611636452, 'graceaquino98@outlook.com', 7, '$2y$10$PaecSUTahaRLz9yK.4bN2eCh95xGo5a9sJWGyKKECGn4UD7pmuTGy', NULL, '', '2026-07-07 03:44:20', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'T3mH73khwZ', 0, NULL, NULL),
(336, 'Diaz', 'Marco', '', '', 9212776106, 'marco.diaz@student.gjc.edu.ph', 1, '$2y$10$PtOI2zsemweKvF6TGuoWCeRFPjqS7Md0HYf9j9rvFEzm6dcugd3oO', NULL, '', '2026-07-07 03:44:20', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2053', 0, NULL, NULL),
(337, 'Diaz', 'Fernando', '', '', 9879978972, 'fernandodiaz39@gmail.com', 7, '$2y$10$3mpumPNC7..x.ByZdJ4JRusQk2VG1CWyQq6g.lDqfQlW.gMbCkLPW', NULL, '', '2026-07-07 03:44:20', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'eKTrjUYYS8', 0, NULL, NULL),
(338, 'Cruz', 'Bea', '', 'III', 9702711595, 'bea.cruz@student.gjc.edu.ph', 1, '$2y$10$cCcDE6zLO9l7v8nio65.L.Lj1vLCXjFAjQxXTFQJmsLgDp7EZjmsW', NULL, '', '2026-07-07 03:44:20', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2054', 0, NULL, NULL),
(339, 'Peñaflor', 'Benedict', '', '', 9879983705, 'benedict.penaflor@student.gjc.edu.ph', 1, '$2y$10$xQ6MzCyJZjGUOnxpoCthnOmLQ4zN7S1C1PwGLYQOCy7kVuepinWT.', NULL, '', '2026-07-07 03:44:20', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2055', 0, NULL, NULL),
(340, 'Peñaflor', 'Ernesto', '', '', 9199928840, 'ernestopenaflor@gmail.com', 7, '$2y$10$fvuvuxhEODR8/o8XbhUkkOOE72vdp5Md33Cf/.jYrZjSdIM0d47u.', NULL, '', '2026-07-07 03:44:20', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'Rn78piVw78', 0, NULL, NULL),
(341, 'Peñaflor', 'Samantha', '', '', 9589120859, 'samantha.penaflor@student.gjc.edu.ph', 1, '$2y$10$OBBCw80k.kuQdlchfUmWQ.Zgsp8ufE.7A.ztUovo/JPbMAaSmLIyO', NULL, '', '2026-07-07 03:44:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2056', 0, NULL, NULL),
(342, 'Mendoza', 'Lorraine', '', '', 9034980659, 'lorraine.mendoza@student.gjc.edu.ph', 1, '$2y$10$h8N3L498dBjBNwWQG8z6deqTqEdlE5xsqCqPA45XB9S/vyLoOiHye', NULL, '', '2026-07-07 03:44:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2057', 0, NULL, NULL),
(343, 'Mendoza', 'Fernando', '', '', 9655786869, 'fernandomendoza90@yahoo.com', 7, '$2y$10$/rYOiIWLtHVQugFVi3qwFO8ifpPGD9G92VjlAMBUxXFywYdiD/.eu', NULL, '', '2026-07-07 03:44:21', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'CHNDFycvGk', 0, NULL, NULL),
(344, 'Pascual', 'Ana Sofia', '', '', 9718137502, 'anasofia.pascual@student.gjc.edu.ph', 1, '$2y$10$89tG0IiahCads0j4E9.hv.jg6a1dc/sT84iwyeO8jbSpuClh93z0S', NULL, '', '2026-07-07 03:44:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2058', 0, NULL, NULL),
(345, 'Pascual', 'Grace', '', '', 9283190811, 'gracepascual59@gmail.com', 7, '$2y$10$WxzfgAcJQyQ5v6hOQ1O3Se52CvJG/N3AfJ9Di27D05Ltf9brlWMcO', NULL, '', '2026-07-07 03:44:21', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'inTKjS7v2u', 0, NULL, NULL),
(346, 'Lopez', 'Trisha', '', '', 9026539546, 'trisha.lopez@student.gjc.edu.ph', 1, '$2y$10$LAaesOSXIKbzzLiQqkyqNO2we7L.8aOqjaFkPsraTEXcFeu/Y1JP.', NULL, '', '2026-07-07 03:44:21', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2059', 0, NULL, NULL),
(347, 'Lopez', 'Imelda', '', '', 9098417483, 'imeldalopez9@yahoo.com', 7, '$2y$10$U1CS0yDnIMKLXa3dHgkGe.MHLpIv3CrTyETPEfes0cfu.HBx71Vd6', NULL, '', '2026-07-07 03:44:22', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'EyGaPfvbPk', 0, NULL, NULL),
(348, 'Legaspi', 'Elijah', '', '', 9724234404, 'elijah.legaspi@student.gjc.edu.ph', 1, '$2y$10$0QhZNhG6H8TiyY86AouiyepqJsUP07fOE10YTurxzJvn2mcZyO9rG', NULL, '', '2026-07-07 03:44:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2060', 0, NULL, NULL),
(349, 'Mercado', 'Rafael', '', 'Jr.', 9526218375, 'rafael.mercado@student.gjc.edu.ph', 1, '$2y$10$nEtFihsPzWwJ7kB1xrqZYu39Zfozo1j34kp44j.xp9Oo4PWUZA.8G', NULL, '', '2026-07-07 03:44:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2061', 0, NULL, NULL),
(350, 'Mercado', 'Corazon', '', '', 63948949023, 'corazonmercado61@gmail.com', 7, '$2y$10$z5GSrs.OGMbMpR5r4ViC7uLnHnzJZY6CB8VXqnDxV2IhwLCbr.ieG', NULL, '', '2026-07-07 03:44:22', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'AiU2TqCdaK', 0, NULL, NULL),
(351, 'Marquez', 'Erica', '', '', 9377500166, 'erica.marquez@student.gjc.edu.ph', 1, '$2y$10$Si0En1UjN2f6XjNe3YlSGOsavvieN3VG3wDB2Bz6zvhNQb8PznYBa', NULL, '', '2026-07-07 03:44:22', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2062', 0, NULL, NULL),
(352, 'Marquez', 'Ernesto', '', '', 9160790534, 'ernestomarquez80@outlook.com', 7, '$2y$10$K.JBPZ.adusnRUili2LM..g02xpz8l4UpRT4rwYjJOi5lylWSffSO', NULL, '', '2026-07-07 03:44:22', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'PqQHuJvVRv', 0, NULL, NULL),
(353, 'Sarmiento', 'Vincent', '', '', 9525633744, 'vincent.sarmiento@student.gjc.edu.ph', 1, '$2y$10$euCDdbGQeDII/nUgixPktObMzM9dr1hOKNHQOEEWWJ1Dy2e9U9R.S', NULL, '', '2026-07-07 03:44:23', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2063', 0, NULL, NULL),
(354, 'Sarmiento', 'Evangeline', '', '', 9032123071, 'evangelinesarmiento57@yahoo.com', 7, '$2y$10$jcRQIDjfwiXzpIdaGB6Nr.VUsmqAfeCN6ZnB61J0fe8Kxs.W12y.O', NULL, '', '2026-07-07 03:44:23', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'ehQHRqmN3W', 0, NULL, NULL),
(355, 'Torres', 'Veronica', '', '', 9593788211, 'veronica.torres@student.gjc.edu.ph', 1, '$2y$10$h.RL3BahGK0KmPYB1Mbcgesf4eRKT0LWCEZT5fWLxrZj88SiwYlKu', NULL, '', '2026-07-07 03:44:23', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2064', 0, NULL, NULL),
(356, 'Torres', 'Arturo', '', '', 9440872706, 'arturotorres52@outlook.com', 7, '$2y$10$g4fsmvZixgDcT.3MFciwne0GIkcahmIfIkhpcXq2tvGHAD491sNju', NULL, '', '2026-07-07 03:44:23', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'ewsLtER23c', 0, NULL, NULL),
(357, 'Gutierrez', 'Andrea', '', '', 9088654578, 'andrea.gutierrez@student.gjc.edu.ph', 1, '$2y$10$op5l0Y5gOiFzKy5SKon9OOhKA7/fWwc9CFRa3S4FP7fEbQFQ3bHqG', NULL, '', '2026-07-07 03:44:23', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2065', 0, NULL, NULL),
(358, 'Gutierrez', 'Roberto', '', '', 9043205340, 'robertogutierrez23@outlook.com', 7, '$2y$10$sJ94D/OOnzr4SGBe6evGYumySm2h/luzHgJt/dcnqJk.ujylr/vHu', NULL, '', '2026-07-07 03:44:24', 'parent', NULL, 1, NULL, 1, 0, 'Active', '5rW96L2xqp', 0, NULL, NULL),
(359, 'Espino', 'Ivan', '', '', 9006276983, 'ivan.espino@student.gjc.edu.ph', 1, '$2y$10$rby6MfVw8JPxSIhLIxVsO.p02oLemZSJ.KJHXJbtF2FiHL7ughVwe', NULL, '', '2026-07-07 03:44:24', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2066', 0, NULL, NULL),
(360, 'Espino', 'Ramon', '', '', 9563937606, 'ramonespino78@yahoo.com', 7, '$2y$10$jrsdg4T/F4.UO3IXmTHyHOE.ZiB5uzJGh9sRRUgZ4FJyLYD8I5E.u', NULL, '', '2026-07-07 03:44:24', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'm5epcALUR3', 0, NULL, NULL),
(361, 'Ocampo', 'Jerome', '', '', 9210817880, 'jerome.ocampo@student.gjc.edu.ph', 1, '$2y$10$P5yzxJCGCCN.3HLakU6zo.abU3iPe.dEf9b/HTuRhJPftiEnzjmAy', NULL, '', '2026-07-07 03:44:24', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2067', 0, NULL, NULL),
(362, 'Ocampo', 'Josefina', '', '', 9906503937, 'josefinaocampo84@outlook.com', 7, '$2y$10$4Da4ZTY9qrSmG8euwaeIJ.Jwya4EFA/tXQhQW2EQeDcBY8vszSKMi', NULL, '', '2026-07-07 03:44:24', 'parent', NULL, 1, NULL, 1, 0, 'Active', '9HjTfkXGaX', 0, NULL, NULL),
(363, 'Zamora', 'Aaron', '', '', 9423598812, 'aaron.zamora@student.gjc.edu.ph', 1, '$2y$10$bAviAcUdTN84WcPRrmfSiOqw.xNb3zctFURP9.I.lggFwUCXEMDnW', NULL, '', '2026-07-07 03:44:24', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2068', 0, NULL, NULL),
(364, 'Zamora', 'Evangeline', '', '', 9745384839, 'evangelinezamora49@gmail.com', 7, '$2y$10$TE289MbXmNJ7jYPLR2xi1O4rb6V4b9Kx6O7yJIro9B49uosQb/Y7y', NULL, '', '2026-07-07 03:44:25', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'ApgPThRJdh', 0, NULL, NULL),
(365, 'Mercado', 'Emmanuel', '', '', 9712861740, 'emmanuel.mercado@student.gjc.edu.ph', 1, '$2y$10$EJBwl2YzKgiEpTEiCMe/J.MW8jfIvdNuCnwl4ptT/2PZCF7toonr6', NULL, '', '2026-07-07 03:44:25', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2069', 0, NULL, NULL),
(366, 'Mercado', 'Grace', '', '', 9005293098, 'gracemercado92@outlook.com', 7, '$2y$10$nauJWPMcTeSI5fx9aXzjNeyAa1NFwOg0oKlpx4XZQCdIM9MX7JEeq', NULL, '', '2026-07-07 03:44:25', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'SY7guBbChp', 0, NULL, NULL),
(367, 'Trinidad', 'Maria Angelica', '', '', 9539575080, 'mariaangelica.trinidad@student.gjc.edu.ph', 1, '$2y$10$OP/qaEq2DFa6htQ1G8Us1uccpaPaB0kHtBZWSjclx8vrSTkVkMnti', NULL, '', '2026-07-07 03:44:25', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2070', 0, NULL, NULL),
(368, 'Trinidad', 'Elena', '', '', 9565720351, 'elenatrinidad71@outlook.com', 7, '$2y$10$mr5tNNSw35fsR5dfgRWAt.4D1vjEbKsZPMQlvinR5hGvpEbc0mS1S', NULL, '', '2026-07-07 03:44:25', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'zsuY84bg6u', 0, NULL, NULL),
(369, 'Trinidad', 'Samantha', '', '', 9333840628, 'samantha.trinidad@student.gjc.edu.ph', 1, '$2y$10$qJpDs4foAGdZsLWovJow2.Ta9XPRt4W5zm6QR02SyrIlKtuxBH316', NULL, '', '2026-07-07 03:44:25', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2071', 0, NULL, NULL),
(370, 'Trinidad', 'Lourdes', '', '', 9941179027, 'lourdestrinidad9@yahoo.com', 7, '$2y$10$KuMBS49OtmbvqrIkQZd47.SKwHNYbRMHN8XPcrAYmi/z2mWKE27V2', NULL, '', '2026-07-07 03:44:26', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'DcNCXu3JJf', 0, NULL, NULL),
(371, 'Buenaventura', 'Veronica', '', '', 9144796467, 'veronica.buenaventura@student.gjc.edu.ph', 1, '$2y$10$DEB5AgKceDXWzUwkQ2rLY.Na7qMeBeRu2ikkV0PtwQjoCuc6XbAdi', NULL, '', '2026-07-07 03:44:26', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2072', 0, NULL, NULL),
(372, 'Buenaventura', 'Grace', '', '', 9068530636, 'gracebuenaventura48@yahoo.com', 7, '$2y$10$RLUibsMr5lALtkFysxTIJenjPMnSqSiehxBEwoLFAgtRYqUuUebrm', NULL, '', '2026-07-07 03:44:26', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'yuMWCThQsm', 0, NULL, NULL),
(373, 'Sarmiento', 'Miguel', '', '', 9901591772, 'miguel.sarmiento@student.gjc.edu.ph', 1, '$2y$10$6h2DW1aS4GVfyGa5WN0.ru9aC4HxdkRp2D9TRCC0Z99yfupB9xDxS', NULL, '', '2026-07-07 03:44:26', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2073', 0, NULL, NULL),
(374, 'Sarmiento', 'Lourdes', '', '', 9669375536, 'lourdessarmiento66@outlook.com', 7, '$2y$10$K7SuGFEMSln1./uML.0Qwe0RrLbipFKmK9.U5KS1PJWS/lMP4iO1.', NULL, '', '2026-07-07 03:44:26', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'TcnuLHjJ7P', 0, NULL, NULL),
(375, 'Legaspi', 'Paolo', '', '', 9724354918, 'paolo.legaspi@student.gjc.edu.ph', 1, '$2y$10$SvY38Un/QNBkbQpQca78vOxbA.INkJWWQhhX1QfQseKDnoFC22q3W', NULL, '', '2026-07-07 03:44:27', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2074', 0, NULL, NULL),
(376, 'Legaspi', 'Cecilia', '', '', 9522177132, 'cecilialegaspi26@gmail.com', 7, '$2y$10$TbBtiDiswT3fcLMhCNq8yO634mJCIC2K4rLEeZ/1QgYSxy3fuZCLK', NULL, '', '2026-07-07 03:44:27', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'CZHqVkvmgD', 0, NULL, NULL),
(377, 'Pascual', 'Bea', '', '', 9398826513, 'bea.pascual@student.gjc.edu.ph', 1, '$2y$10$EAtVkIy3C1W.JbPc0wAlWeVBUtJni6N1Rn47egKV2JWroPd4dQVVO', NULL, '', '2026-07-07 03:44:27', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2075', 0, NULL, NULL),
(378, 'Pascual', 'Imelda', '', '', 9571588531, 'imeldapascual62@outlook.com', 7, '$2y$10$MlPDSWhkokUFcPaYUBNJyebKGsrZfDgOvTDjNxNj1ELaBCp3nvp.e', NULL, '', '2026-07-07 03:44:27', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'eyaHCZFP8C', 0, NULL, NULL),
(379, 'Soriano', 'Isabella', '', '', 9200442637, 'isabella.soriano@student.gjc.edu.ph', 1, '$2y$10$SYeHYOmcE2MTfPQFyqlJJu0uDziJ9dG4GYPeIIiR2iMFBxQtXS1D.', NULL, '', '2026-07-07 03:44:27', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2076', 0, NULL, NULL),
(380, 'Soriano', 'Arturo', '', '', 9256085632, 'arturosoriano75@gmail.com', 7, '$2y$10$HlqnLpFk6Q/1eVv.EK77EOo0xRIIGkTgGpQf2Ne8p9a/RSrrBTjqe', NULL, '', '2026-07-07 03:44:27', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'RwK2JUsVFf', 0, NULL, NULL),
(381, 'Navarro', 'Veronica', '', '', 9095923048, 'veronica.navarro@student.gjc.edu.ph', 1, '$2y$10$3okeJF/LnO0QMqEW6l..IugIK9ek8zULonI21Ns.rzNfEsvXEMysu', NULL, '', '2026-07-07 03:44:28', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2077', 0, NULL, NULL),
(382, 'Navarro', 'Elena', '', '', 9989711948, 'elenanavarro98@yahoo.com', 7, '$2y$10$crW0mgefTR2Nob8./HnGjOLBXtUQUkFGUegV6d7M44RCi3YaUAIJO', NULL, '', '2026-07-07 03:44:28', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'Y28tPaGYMF', 0, NULL, NULL),
(383, 'Hernandez', 'Hazel', '', '', 9715501344, 'hazel.hernandez@student.gjc.edu.ph', 1, '$2y$10$Y7t2NPffU7Lzgu6gRutwNuorwN1yj8IJKkfg8ymFdygCiGZXtfKhS', NULL, '', '2026-07-07 03:44:28', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2078', 0, NULL, NULL),
(384, 'Hernandez', 'Maria', '', '', 9586610395, 'mariahernandez14@yahoo.com', 7, '$2y$10$7rKT6zBgaUf/sEzcyEBsxudyuNwihoWubJVGCaHFTEB7REdYlOvZG', NULL, '', '2026-07-07 03:44:28', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'iLfJjL6dUp', 0, NULL, NULL),
(385, 'Soriano', 'Colleen', '', '', 9336855545, 'colleen.soriano@student.gjc.edu.ph', 1, '$2y$10$v1oRjdrMIeNoOg4/ZK7vR.hhoGKvyz9asoB0Wf9p7kzpVV8UUc27e', NULL, '', '2026-07-07 03:44:28', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2079', 0, NULL, NULL),
(386, 'Santos', 'Justin', '', '', 9144425648, 'justin.santos@student.gjc.edu.ph', 1, '$2y$10$N2jmfkwIueOb1aq5HLQNRegeLnGFocZqzEJjdTP3pxanHq1hfCY52', NULL, '', '2026-07-07 03:44:28', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2080', 0, NULL, NULL),
(387, 'Santos', 'Corazon', '', '', 9516681609, 'corazonsantos15@yahoo.com', 7, '$2y$10$psb.DueuwIISg0vc0Y9lxer.lj3RvBOzHCUJQMw/HFmJXKCr50G9G', NULL, '', '2026-07-07 03:44:29', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'Nt6EV7KB5Q', 0, NULL, NULL),
(388, 'Estrella', 'Ana Sofia', '', 'II', 9594394556, 'anasofia.estrella@student.gjc.edu.ph', 1, '$2y$10$4P2o8Ynr1E89OX1FO6jcWuRF9wO7JsgD8hAvKiaGEm6zwEU.ZgBIG', NULL, '', '2026-07-07 03:44:29', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2081', 0, NULL, NULL),
(389, 'Pineda', 'Nathaniel', '', 'III', 9227342425, 'nathaniel.pineda@student.gjc.edu.ph', 1, '$2y$10$n.L2RsgWsFPYlN07PPkxcOskxMBQ4eaArNz7xrvxBkQhraFTO.Hty', NULL, '', '2026-07-07 03:44:29', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2082', 0, NULL, NULL),
(390, 'Pineda', 'Danilo', '', '', 9597255833, 'danilopineda34@outlook.com', 7, '$2y$10$HcQz0N0AnwZCXvJJhcP1L.dAGUy84amI0qUp0daa82xf53DYNsz7G', NULL, '', '2026-07-07 03:44:29', 'parent', NULL, 1, NULL, 1, 0, 'Active', '9sXAF7AJEM', 0, NULL, NULL),
(391, 'Legaspi', 'Cristina', '', '', 9905579918, 'cristina.legaspi@student.gjc.edu.ph', 1, '$2y$10$AYf1fEGWyPMHCC9k1qV6we58L8dS0EG47LfssXBAxYKhbtfpzYD3y', NULL, '', '2026-07-07 03:44:29', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2083', 0, NULL, NULL),
(392, 'Legaspi', 'Grace', '', '', 9594984193, 'gracelegaspi79@outlook.com', 7, '$2y$10$0iiy2VAoiBg53O4m5b3L1evw3XNMkAk5NrCOIh5c/Pv.SxZ/VpM4.', NULL, '', '2026-07-07 03:44:29', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'PA4CctcvHJ', 0, NULL, NULL),
(393, 'Reyes', 'Pauline', '', '', 9032616644, 'pauline.reyes@student.gjc.edu.ph', 1, '$2y$10$Q2sj0ZADh36nbIer0S.AjeFcvkxkqwvOUC.eMkjoOTrf09Zqzmc/y', NULL, '', '2026-07-07 03:44:30', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2084', 0, NULL, NULL),
(394, 'Reyes', 'Eduardo', '', '', 9498411142, 'eduardoreyes64@gmail.com', 7, '$2y$10$kQN97j6Q0nARpPlVrhuE3.pzc/e9aBc4ddNexsR3tyMNF5PXjwO5e', NULL, '', '2026-07-07 03:44:30', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'GeGynNwVbS', 0, NULL, NULL),
(395, 'Pascual', 'Isabella', '', 'Jr.', 9329449379, 'isabella.pascual@student.gjc.edu.ph', 1, '$2y$10$2RKAe9c4LkWceZB0oMAPFeQe6grDXscO8xZuAuhQyLjI1E9OjEVQC', NULL, '', '2026-07-07 03:44:30', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2085', 0, NULL, NULL),
(396, 'Pascual', 'Antonio', '', '', 9013458731, 'antoniopascual18@yahoo.com', 7, '$2y$10$325G2SJOvhs1QPMZJp5Iu.cNwBFe9580BoFILZ3nUDp1FneyxULVy', NULL, '', '2026-07-07 03:44:30', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'TmSSxRtxUw', 0, NULL, NULL),
(397, 'Sarmiento', 'Ronald', '', '', 9145563020, 'ronald.sarmiento@student.gjc.edu.ph', 1, '$2y$10$LD0ahmy.K23TvsTjlKeSoetnB16bwOBR3wxAn0noMqxF41k3GrECG', NULL, '', '2026-07-07 03:44:30', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2086', 0, NULL, NULL),
(398, 'Sarmiento', 'Rogelio', '', '', 9135125058, 'rogeliosarmiento32@outlook.com', 7, '$2y$10$YdOfPlMziX.h5P9ScVBgj.rzETtDnnOJiJ7qEI8Qm2vL8Fc1F4Q8C', NULL, '', '2026-07-07 03:44:30', 'parent', NULL, 1, NULL, 1, 0, 'Active', '2vVtT4cj7b', 0, NULL, NULL),
(399, 'Mendoza', 'Regine', '', '', 9750894211, 'regine.mendoza@student.gjc.edu.ph', 1, '$2y$10$L8mz13NLJ41wDgyKISyJ4evKg6asB7j2XIuCNu3UKNv9yRJN5tmQm', NULL, '', '2026-07-07 03:44:31', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2087', 0, NULL, NULL),
(400, 'Buenaventura', 'Nathaniel', '', 'III', 9411870669, 'nathaniel.buenaventura@student.gjc.edu.ph', 1, '$2y$10$YJwjnPaNgnzGYFKF3BT5bOrkV/SapY7..FNTk6FpBstkRLR5BO/5a', NULL, '', '2026-07-07 03:44:31', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2088', 0, NULL, NULL),
(401, 'Buenaventura', 'Lourdes', '', '', 9720026558, 'lourdesbuenaventura49@yahoo.com', 7, '$2y$10$2qpuoroB3Mh81wi0/Z9DeOK/OEYlxxbYRh6QKO.d97pK3r9BA/V3K', NULL, '', '2026-07-07 03:44:31', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'Va7SNGWFWn', 0, NULL, NULL),
(402, 'Del Rosario', 'Maria Angelica', '', '', 9230989644, 'mariaangelica.delrosario@student.gjc.edu.ph', 1, '$2y$10$CmdkIDLRQxlMnL2rfo4QfOLYLtN.Zv57DOyzXn/93xiL53qmfEKLO', NULL, '', '2026-07-07 03:44:31', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2089', 0, NULL, NULL),
(403, 'Del Rosario', 'Grace', '', '', 9295578289, 'gracedelrosario77@gmail.com', 7, '$2y$10$RWdODfsf1ZeYqDgTykUCBuHCmoTOgHWa0F8Euc.lD..bKoUI1clYe', NULL, '', '2026-07-07 03:44:31', 'parent', NULL, 1, NULL, 1, 0, 'Active', '5MpjwUzJyh', 0, NULL, NULL),
(404, 'Sarmiento', 'Lorraine', '', '', 9036008562, 'lorraine.sarmiento@student.gjc.edu.ph', 1, '$2y$10$dBV0FXCfxMKW4AmGmYThJOXBbtyXBSFbXT.Oc/L/33aYKlgW14VEW', NULL, '', '2026-07-07 03:44:32', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2090', 0, NULL, NULL),
(405, 'Sarmiento', 'Rodolfo', '', '', 9951226980, 'rodolfosarmiento41@outlook.com', 7, '$2y$10$XBjkdYJDrj2ecduCWk6q2e..4YL9MSngTvAW5wC7HXQfgneIiHOSK', NULL, '', '2026-07-07 03:44:32', 'parent', NULL, 1, NULL, 1, 0, 'Active', '7tdhafTpCW', 0, NULL, NULL),
(406, 'Rivera', 'Veronica', '', '', 9934521295, 'veronica.rivera@student.gjc.edu.ph', 1, '$2y$10$h12eeF2HvIpCz8No3DhFdOD83IoHhJ.A1MDOQBpxXcVeijrNL4kNK', NULL, '', '2026-07-07 03:44:32', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2091', 0, NULL, NULL),
(407, 'Rivera', 'Dolores', '', '', 9353254094, 'doloresrivera2@gmail.com', 7, '$2y$10$.jeGTfFIJsnS51ca1UNstOlZElvCeCeiNTLbZqe6V7SmYXvHtJX66', NULL, '', '2026-07-07 03:44:32', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'qBUErNDVzB', 0, NULL, NULL),
(408, 'Ocampo', 'Paolo', '', '', 9580800160, 'paolo.ocampo@student.gjc.edu.ph', 1, '$2y$10$X3eZQTvyqtoh2e7YC2gHB.CiQQ001lXi7UMdsA2ZW69nL1LTTmG3G', NULL, '', '2026-07-07 03:44:32', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2092', 0, NULL, NULL),
(409, 'Domingo', 'Juan Carlos', '', 'II', 9823345926, 'juancarlos.domingo@student.gjc.edu.ph', 1, '$2y$10$OTaSN/fNKSKD6xKOQREz5OgsrGhslhKi7WAc36mE6kec4HRBSZ0EO', NULL, '', '2026-07-07 03:44:33', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2093', 0, NULL, NULL),
(410, 'Domingo', 'Lourdes', '', '', 9564919526, 'lourdesdomingo8@outlook.com', 7, '$2y$10$qgD0.iGQnhBVf7B4y2UeSOCJCxZOaDZI8AFQrPsUupvHllpag9xiC', NULL, '', '2026-07-07 03:44:33', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'U2DYWXVBXh', 0, NULL, NULL),
(411, 'Buenaventura', 'Isabella', '', 'II', 9625618021, 'isabella.buenaventura@student.gjc.edu.ph', 1, '$2y$10$us9UldPVPvKwFwaFZQ2yseOt8Qf/7ofumgXCo1izJNp4C1xsFfCfe', NULL, '', '2026-07-07 03:44:33', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2094', 0, NULL, NULL),
(412, 'Buenaventura', 'Nestor', '', '', 63905028840, 'nestorbuenaventura98@gmail.com', 7, '$2y$10$uv5OCXOQnnFWM6DHYeeK8O.gMKMsM3aYko7WV55DU2bVVFOd5NUNe', NULL, '', '2026-07-07 03:44:33', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'pEXHX4DpFv', 0, NULL, NULL),
(413, 'Pascual', 'Abigail', '', '', 9742531537, 'abigail.pascual@student.gjc.edu.ph', 1, '$2y$10$n5Lm2arF/UAPu/Pvzxq3.eahNAHPinasuLryNl7MLwdsk6BFZu4q2', NULL, '', '2026-07-07 03:44:33', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2095', 0, NULL, NULL),
(414, 'Pascual', 'Josefina', '', '', 9048379370, 'josefinapascual21@outlook.com', 7, '$2y$10$vf/jlGC86tsWTzSPqPu2iOgtrJd5dP09dO1YNda4gQZ9PYc3cwS9W', NULL, '', '2026-07-07 03:44:33', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'NUkQwNTDXY', 0, NULL, NULL),
(415, 'Del Rosario', 'Frances', '', '', 9230999513, 'frances.delrosario@student.gjc.edu.ph', 1, '$2y$10$LPuwmAE.EAvTLIFhdXED/.XhqZHjFk9kbqGhnrQPWwz75HdSL6Hi2', NULL, '', '2026-07-07 03:44:34', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2096', 0, NULL, NULL),
(416, 'Del Rosario', 'Roberto', '', '', 9455105578, 'robertodelrosario35@gmail.com', 7, '$2y$10$XeLLCCqdUriCL3gF9cDzFOVBT6sJKR4AwkR1zoQSlduEw2e.GHT/W', NULL, '', '2026-07-07 03:44:34', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'dE9wBdDE8f', 0, NULL, NULL),
(417, 'Fernandez', 'Angelica Mae', '', '', 9366413376, 'angelicamae.fernandez@student.gjc.edu.ph', 1, '$2y$10$cXHerKAhPfQKWaWahxkQRuDCgu8G17.ueLZagcNk3xFjHo6Pwebe2', NULL, '', '2026-07-07 03:44:34', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2097', 0, NULL, NULL),
(418, 'Fernandez', 'Elena', '', '', 9936833889, 'elenafernandez9@gmail.com', 7, '$2y$10$s82uOAtJSQOlKBdpXQ4AtuDcm.vV5doMdTguxqcGsK3cLfDZx6L4G', NULL, '', '2026-07-07 03:44:34', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'gTZyHwjyGr', 0, NULL, NULL),
(419, 'Del Rosario', 'Elijah', '', '', 9094459657, 'elijah.delrosario@student.gjc.edu.ph', 1, '$2y$10$csd4Lb3hmCBl4FB/fV63Yu0N8YkpNhM.olf/5EKqEPHfPS90au3qG', NULL, '', '2026-07-07 03:44:34', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2098', 0, NULL, NULL),
(420, 'Del Rosario', 'Ricardo', '', '', 9687446267, 'ricardodelrosario51@yahoo.com', 7, '$2y$10$royot56zbkIs0ffa8Gifu.XavlZB7b4Jafp7QKblSeyl6/yUMMTPe', NULL, '', '2026-07-07 03:44:34', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'HkTTWwniMB', 0, NULL, NULL),
(421, 'Valdez', 'Dennis', '', '', 9194290873, 'dennis.valdez@student.gjc.edu.ph', 1, '$2y$10$by18MaDdAsIYDSdt2mkkZ.J/yiRBAN2KnrCVcKC/6fddmwVx38wQy', NULL, '', '2026-07-07 03:44:35', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2099', 0, NULL, NULL),
(422, 'Valdez', 'Roberto', '', '', 9255554375, 'robertovaldez14@gmail.com', 7, '$2y$10$zShHm3197dOl5DKlX1UFlebDBufClJvQHDtki0.Sk.ZlwVKt2fD/m', NULL, '', '2026-07-07 03:44:35', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'eX3T6xac6N', 0, NULL, NULL),
(423, 'Domingo', 'Michelle', '', 'Jr.', 9378440227, 'michelle.domingo@student.gjc.edu.ph', 1, '$2y$10$LU4q7Q5MouktjEIL5B504.qUSRb3/DZASFn0iEi6puZuHvp.2oLZ.', NULL, '', '2026-07-07 03:44:35', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2100', 0, NULL, NULL),
(424, 'Domingo', 'Lourdes', '', '', 9211592416, 'lourdesdomingo61@gmail.com', 7, '$2y$10$ftROYtPhtC.FNiR4G93LHOy8UmRqgk363HWZ3jUla1B1.DfB3G4O.', NULL, '', '2026-07-07 03:44:35', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'pcJReMwHh2', 0, NULL, NULL),
(425, 'Mendoza', 'Dennis', '', '', 9653868726, 'dennis.mendoza@student.gjc.edu.ph', 1, '$2y$10$fzPRfPWJJF6pfwGkXTm7O.QVMRD.3Ub9LNur/uGOv8FBde1bwrEoe', NULL, '', '2026-07-07 03:44:35', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2101', 0, NULL, NULL),
(426, 'Mendoza', 'Arturo', '', '', 9181342856, 'arturomendoza@gmail.com', 7, '$2y$10$fMCo14htXsBhrbbS460NxewrqIQD2zxn57BlwuSgmos6LWfKsmCUa', NULL, '', '2026-07-07 03:44:35', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'FV5AgSTH4Z', 0, NULL, NULL),
(427, 'Mendoza', 'Hazel', '', '', 9321313554, 'hazel.mendoza@student.gjc.edu.ph', 1, '$2y$10$TlK15Re.FwqgGzXy.z/X3.J6oBRSMialBuzAS9cskld8XcvgHt8SW', NULL, '', '2026-07-07 03:44:35', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2102', 0, NULL, NULL),
(428, 'Valdez', 'Marco', '', 'Jr.', 9251477511, 'marco.valdez@student.gjc.edu.ph', 1, '$2y$10$N.6C8nJ6pR00Cje/51rCtO/Rh9QBNYmjQjYswn9fOFh.mptR.zKHu', NULL, '', '2026-07-07 03:44:36', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2103', 0, NULL, NULL),
(429, 'Valdez', 'Nestor', '', '', 9644741572, 'nestorvaldez23@outlook.com', 7, '$2y$10$NmYbAsW/V5jztxynZ1oKUOfK4OZX1bTCY7Yc8ph0H8FtzXWuDPQjC', NULL, '', '2026-07-07 03:44:36', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'MAzu8F4Vsp', 0, NULL, NULL),
(430, 'Mercado', 'Ronald', '', '', 9122032537, 'ronald.mercado@student.gjc.edu.ph', 1, '$2y$10$RVJ.mxbn2AwgM7167FTNwOHBrYmj5bqVKFLF6i311BxHf/U1uVMmG', NULL, '', '2026-07-07 03:44:36', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2104', 0, NULL, NULL),
(431, 'Mercado', 'Rogelio', '', '', 9818276864, 'rogeliomercado6@outlook.com', 7, '$2y$10$3IQOglQsF6ivDS4rM2EYAul5u71jkuBEsAaC3qvvBfM5tiMfy.Kou', NULL, '', '2026-07-07 03:44:36', 'parent', NULL, 1, NULL, 1, 0, 'Active', '8ebNvZQ6zS', 0, NULL, NULL),
(432, 'Peñaflor', 'Alvin', '', '', 9529393077, 'alvin.penaflor@student.gjc.edu.ph', 1, '$2y$10$5ExCxbwLzj9dfBc5ErUVG.DZpYDa7yB3RoyLDm75y07gd.xzKa5Nu', NULL, '', '2026-07-07 03:44:36', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2105', 0, NULL, NULL),
(433, 'Peñaflor', 'Rodolfo', '', '', 9831663077, 'rodolfopenaflor87@gmail.com', 7, '$2y$10$vTBdEwGsI1rBwXeaPb6hH.EqW9LFgFbHLyPaZGk9EE0.8D6yN6sea', NULL, '', '2026-07-07 03:44:36', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'DNayXztLGv', 0, NULL, NULL),
(434, 'Tolentino', 'Michelle', '', '', 9261619262, 'michelle.tolentino@student.gjc.edu.ph', 1, '$2y$10$jDtrmyNlpHxgi6aTZT9DZuDdpKSAY5STZ7jNmflWNZm1Wnqi.VXAy', NULL, '', '2026-07-07 03:44:36', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2106', 0, NULL, NULL),
(435, 'Tolentino', 'Lourdes', '', '', 9706809149, 'lourdestolentino9@outlook.com', 7, '$2y$10$okTjiCOPbhLl6Dd/jRx3DOStXKeJNcWDYZdQXKYJuiJAmKWNvaX6.', NULL, '', '2026-07-07 03:44:36', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'wPMrycx2Rp', 0, NULL, NULL),
(436, 'Buenaventura', 'Jerome', '', '', 9713817719, 'jerome.buenaventura@student.gjc.edu.ph', 1, '$2y$10$VAyC7WokA/gSH.v47/3ZruAg4LSqYdg9U6S3lwi7FN8sj66Bvk1MC', NULL, '', '2026-07-07 03:44:37', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2107', 0, NULL, NULL);
INSERT INTO `users` (`userID`, `last_name`, `first_name`, `middle_name`, `suffix`, `contact_number`, `email`, `roleID`, `password`, `mint_pin`, `profile_img`, `created_at`, `sub_role`, `position`, `force_password_change`, `merchant_owner_id`, `is_first_login`, `password_changed`, `status`, `temp_password`, `restricted_violation_count`, `restricted_violation_last_at`, `restricted_suspended_until`) VALUES
(437, 'Padilla', 'Miguel', '', '', 9863557208, 'miguel.padilla@student.gjc.edu.ph', 1, '$2y$10$JsWBze.Wwh1hO5dw7klxhOKIsgM4vphNs7GLkXrvfSHvEfbkdbgwi', NULL, '', '2026-07-07 03:44:37', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2108', 0, NULL, NULL),
(438, 'Padilla', 'Evangeline', '', '', 9393120566, 'evangelinepadilla84@gmail.com', 7, '$2y$10$hJf00JAhwE2wRQDbp7v1XOfmS0QOZCfHOtyCXBjRa3XuvdkygCNMi', NULL, '', '2026-07-07 03:44:37', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'hTE5YF6a3Y', 0, NULL, NULL),
(439, 'Castro', 'Katrina', '', '', 9608033384, 'katrina.castro@student.gjc.edu.ph', 1, '$2y$10$Ra6nE7xTv3YBrSK70SslNu5LWuXjsbcBtfEPIrkGc.pzLBqpTMZMa', NULL, '', '2026-07-07 03:44:37', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2109', 0, NULL, NULL),
(440, 'Manalo', 'Hazel', '', '', 9368606780, 'hazel.manalo@student.gjc.edu.ph', 1, '$2y$10$LhS7Twf.35akkBdgatFfM.FmVnKs3w9KtMHFMLrphIZ6oAGF/AfPO', NULL, '', '2026-07-07 03:44:37', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2110', 0, NULL, NULL),
(441, 'Manalo', 'Rogelio', '', '', 9465100991, 'rogeliomanalo10@gmail.com', 7, '$2y$10$bD/gmxbwdBC0pyXJt5b5p.OdO8SRgSd9IoDLFxgVaV/UwmbcJToNi', NULL, '', '2026-07-07 03:44:37', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'NWBt6P66eF', 0, NULL, NULL),
(442, 'Diaz', 'Pauline', '', '', 9637717719, 'pauline.diaz@student.gjc.edu.ph', 1, '$2y$10$CNbkcbaFK4vTeSWcKK5aYekCol531l49N5QDO9Mxzl1UOsGOZKb9m', NULL, '', '2026-07-07 03:44:37', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2111', 0, NULL, NULL),
(443, 'Roque', 'Diana', '', '', 9708003919, 'diana.roque@student.gjc.edu.ph', 1, '$2y$10$nvLwUNH8T9MNGYurm4d.AeGfbdDgYtXq1lmRmSt5.iL/pRDI93Bre', NULL, '', '2026-07-07 03:44:38', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2112', 0, NULL, NULL),
(444, 'Roque', 'Lourdes', '', '', 9434532157, 'lourdesroque17@outlook.com', 7, '$2y$10$a4O6SfaFqHS3ZnhvFh0wauoL.sgGRNvvSe8sco1AEr2DHMPP6BUta', NULL, '', '2026-07-07 03:44:38', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'REJ5zQ3CWe', 0, NULL, NULL),
(445, 'Estrella', 'Cristina', '', '', 9723997757, 'cristina.estrella@student.gjc.edu.ph', 1, '$2y$10$/xcIKmSR3rkMvcVPOTkrG.mVCWruKgNtzoVmeKK6qFIaM1DFS75CG', NULL, '', '2026-07-07 03:44:38', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2113', 0, NULL, NULL),
(446, 'Estrella', 'Rosario', '', '', 9488730432, 'rosarioestrella29@yahoo.com', 7, '$2y$10$UcuJ1RmFflksNOJzGsS7N.PlD7bwByHGX4TWJC0AogcwQ1UsVSyf2', NULL, '', '2026-07-07 03:44:38', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'X9Y2rmcT66', 0, NULL, NULL),
(447, 'Lopez', 'Juan Carlos', '', '', 9388906871, 'juancarlos.lopez@student.gjc.edu.ph', 1, '$2y$10$cqTn570UKHYPHagOxvWwvuDYaEE/EON142/K00vsRbM4wddqNZlnq', NULL, '', '2026-07-07 03:44:38', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2114', 0, NULL, NULL),
(448, 'Del Rosario', 'Samantha', '', '', 9900180549, 'samantha.delrosario@student.gjc.edu.ph', 1, '$2y$10$2FUQQRX1UjpR6aBryEEJYelEwbP5lEqTVzPXbp4rKfI471DybDB4S', NULL, '', '2026-07-07 03:44:38', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2115', 0, NULL, NULL),
(449, 'Del Rosario', 'Rosario', '', '', 9409709857, 'rosariodelrosario@gmail.com', 7, '$2y$10$jPb3IiRqBbMNRTD1/6qYmetfnO5SLNZUPaYhUIvC5KbXMqhOi4Oha', NULL, '', '2026-07-07 03:44:39', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'kmfERfZEge', 0, NULL, NULL),
(450, 'Del Rosario', 'Paolo', '', 'Jr.', 9893834291, 'paolo.delrosario@student.gjc.edu.ph', 1, '$2y$10$0kqNEAqFUMISdzg.SeqDJ.nOZuYuRzeRMTH5hQsfsSnFtQkH.s3.y', NULL, '', '2026-07-07 03:44:39', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2116', 0, NULL, NULL),
(451, 'Villanueva', 'Regine', '', '', 9562904472, 'regine.villanueva@student.gjc.edu.ph', 1, '$2y$10$LFTc/4o50Ef5oIUYZ1YLVuYAR/lz7sY4wDLfmR9r1zc7HGW38m7Vi', NULL, '', '2026-07-07 03:44:39', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2117', 0, NULL, NULL),
(452, 'Bautista', 'Gabriel', '', 'II', 9891836652, 'gabriel.bautista@student.gjc.edu.ph', 1, '$2y$10$MKEjOtRiUBj8zNk6fVs0gucdLGJBiktMIQx8fo9U97POwVGgg85UG', NULL, '', '2026-07-07 03:44:39', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2118', 0, NULL, NULL),
(453, 'Santos', 'Miguel', '', '', 9224502118, 'miguel.santos@student.gjc.edu.ph', 1, '$2y$10$7fHkkq1htHXzrtsyCvs8Z.GcJG4dcUjOBAbbghdRoOOyqZCdzvygS', NULL, '', '2026-07-07 03:44:39', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2119', 0, NULL, NULL),
(454, 'Santos', 'Carmen', '', '', 9434392416, 'carmensantos83@yahoo.com', 7, '$2y$10$718FW87YGYBugth9qMQSbesZgPhh6Yx2UA71JDSbAx77Yi71jS0fe', NULL, '', '2026-07-07 03:44:39', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'LXccdFXPWG', 0, NULL, NULL),
(455, 'Tolentino', 'Bianca', '', 'II', 9577795939, 'bianca.tolentino@student.gjc.edu.ph', 1, '$2y$10$pWeEbQKkeWY9W18Hl.HBtOLbsD3ASQX26PX5WTGoqvZ6FcffFmfg.', NULL, '', '2026-07-07 03:44:39', 'student', NULL, 1, NULL, 1, 0, 'Active', 'GJC2026-2120', 0, NULL, NULL),
(456, 'Tolentino', 'Marilou', '', '', 9709579916, 'mariloutolentino3@yahoo.com', 7, '$2y$10$pgSs33A3I.Zg4baR6hBhoulIpHLNBxoyZtciRb7EmHOygnu9bpWru', NULL, '', '2026-07-07 03:44:40', 'parent', NULL, 1, NULL, 1, 0, 'Active', 'wGrQ4aDaHq', 0, NULL, NULL),
(462, 'Gamboa', 'Chienna Mae', NULL, NULL, 9614708712, 'chiennagamboa321@gmail.com', 6, '$2y$10$TWKzQWZ6igXngoT9LxGt0eF1ir67Ph5V3kdKBetA24x1Hnd9N3fsG', NULL, '', '2026-07-12 15:18:29', 'merchant_staff', 'Cashier', 0, 29, 0, 1, 'Active', NULL, 0, NULL, NULL),
(464, 'Doe', 'John', '', '', 15550101, 'john.doe@example.com', 1, '$2y$10$yV5BQ9FdLD1A9eG20/jc/ux.EZOP.lRmOzvCkM9cYDkXFGMF6IT.e', NULL, '', '2026-07-14 01:48:29', 'student', NULL, 1, NULL, 1, 0, 'Active', '2026-0001', 0, NULL, NULL),
(465, 'Smith', 'Jane', '', '', 15550102, 'jane.smith@example.com', 1, '$2y$10$nudqqki4Z8jI/MD1nc2yfeBRfN09J9tVmVFnw/4ru.LI/pTh0nFM2', NULL, '', '2026-07-14 01:48:29', 'student', NULL, 1, NULL, 1, 0, 'Active', '2026-0002', 0, NULL, NULL),
(466, 'Johnson', 'Michael', '', '', 15550103, 'michael.j@example.com', 1, '$2y$10$a7YPxXqaGLV4FVYOpjUnNOFCmHwF4cUvX5wpp5Iy8fjEbmUYddXLG', NULL, '', '2026-07-14 01:48:29', 'student', NULL, 1, NULL, 1, 0, 'Active', '2026-0003', 0, NULL, NULL),
(467, 'Davis', 'Emily', '', '', 15550104, 'emily.davis@example.com', 1, '$2y$10$FFr0RnwBqz9UGfgsj1Xn4uqsygGBXhmcKw.Xht1XFpn9a6Pt58UBy', NULL, '', '2026-07-14 01:48:29', 'student', NULL, 1, NULL, 1, 0, 'Active', '2026-0004', 0, NULL, NULL),
(468, 'Martinez', 'David', '', '', 15550105, 'david.m@example.com', 1, '$2y$10$oRorvaZ2ldog.M/QG1HCJ.syIxHmPFVC47iimIjkTr8vq9.VWIm.u', NULL, '', '2026-07-14 01:48:30', 'student', NULL, 1, NULL, 1, 0, 'Active', '2026-0005', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vouchers`
--

CREATE TABLE `vouchers` (
  `id` int UNSIGNED NOT NULL,
  `qr_code_hash` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `voucher_code` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `issued_by` int UNSIGNED NOT NULL COMMENT 'FK -> users.id (cashier or admin who created it)',
  `is_refundable` tinyint(1) NOT NULL DEFAULT '0',
  `visitor_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `visitor_contact` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `initial_value` decimal(15,2) DEFAULT NULL,
  `original_amount` decimal(10,2) NOT NULL COMMENT 'Points pulled from the vault at creation time',
  `remaining_balance` decimal(10,2) NOT NULL COMMENT 'Unspent points -- stays in the economy, non-refundable',
  `status` enum('active','used','expired','void') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `is_non_refundable` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Always 1 -- architectural constant, never override',
  `expires_at` datetime NOT NULL,
  `expired_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `use_count` smallint UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vouchers`
--

INSERT INTO `vouchers` (`id`, `qr_code_hash`, `voucher_code`, `issued_by`, `is_refundable`, `visitor_name`, `visitor_contact`, `initial_value`, `original_amount`, `remaining_balance`, `status`, `is_non_refundable`, `expires_at`, `expired_at`, `cancelled_at`, `last_used_at`, `use_count`, `created_at`, `updated_at`) VALUES
(1, 'b18e113a3760541abe1ca05777f4faebf1c7241d575a17a62a5aeb56de97a014', 'VCH-9F948010', 7, 1, 'Ezekiel Clarence', '09610912764', 500.00, 0.00, 500.00, 'active', 1, '2026-05-15 03:38:25', NULL, NULL, NULL, 0, '2026-05-14 03:38:25', '2026-05-14 03:38:25'),
(2, '28f2b60aea6ba408da195d7d7e6104a0274845171c20c70b4db1457682801e7f', 'VCH-EC2381D1', 7, 1, 'Paolo Varon', '', 900.00, 0.00, 900.00, 'active', 1, '2026-06-09 19:16:19', NULL, NULL, NULL, 0, '2026-06-08 19:16:19', '2026-06-08 19:16:19');

-- --------------------------------------------------------

--
-- Table structure for table `voucher_payment_log`
--

CREATE TABLE `voucher_payment_log` (
  `id` int UNSIGNED NOT NULL,
  `voucher_id` int UNSIGNED NOT NULL,
  `merchant_wallet_id` int UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_before` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `scanned_by` int UNSIGNED DEFAULT NULL,
  `transaction_ref` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallet`
--

CREATE TABLE `wallet` (
  `wallet_id` int NOT NULL,
  `userID` int NOT NULL,
  `balance` int NOT NULL,
  `last_updated` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `withdrawal_requests`
--

CREATE TABLE `withdrawal_requests` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `student_wallet_id` int UNSIGNED DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `method` varchar(80) NOT NULL DEFAULT 'Cashier Release',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reference_no` varchar(40) DEFAULT NULL,
  `released_by` int UNSIGNED DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `rejected_by` int UNSIGNED DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `withdrawal_requests`
--

INSERT INTO `withdrawal_requests` (`id`, `user_id`, `student_wallet_id`, `amount`, `method`, `status`, `reference_no`, `released_by`, `released_at`, `rejected_by`, `rejected_at`, `created_at`) VALUES
(1, 1, 2, 265.90, 'Cashier Release', 'released', 'WTH-20260705-09FE17', 16, '2026-07-17 22:17:19', NULL, NULL, '2026-07-05 21:05:53'),
(3, 6, 10, 893.99, 'Cashier Release', 'released', 'WTH-20260717-7825BD', 16, '2026-07-17 22:17:25', NULL, NULL, '2026-07-17 21:51:18');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `archived_rejections`
--
ALTER TABLE `archived_rejections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ar_original` (`original_application_id`),
  ADD KEY `idx_ar_email` (`email`);

--
-- Indexes for table `auth_remember_tokens`
--
ALTER TABLE `auth_remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_art_selector` (`selector`),
  ADD KEY `idx_art_user` (`user_id`);

--
-- Indexes for table `cap_increase_log`
--
ALTER TABLE `cap_increase_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cart_orders`
--
ALTER TABLE `cart_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_cart_orders_student` (`student_user_id`,`status`),
  ADD KEY `idx_cart_orders_merchant` (`merchant_user_id`,`status`);

--
-- Indexes for table `course`
--
ALTER TABLE `course`
  ADD PRIMARY KEY (`courseID`);

--
-- Indexes for table `encashment_requests`
--
ALTER TABLE `encashment_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_encash_user` (`user_id`),
  ADD KEY `idx_encash_status` (`status`);

--
-- Indexes for table `fee_revenue_log`
--
ALTER TABLE `fee_revenue_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fee_ref` (`transaction_ref`),
  ADD KEY `idx_fee_source` (`top_up_source`),
  ADD KEY `idx_fee_date` (`created_at`);

--
-- Indexes for table `fee_waiver_credits`
--
ALTER TABLE `fee_waiver_credits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fee_waiver_student` (`student_user_id`);

--
-- Indexes for table `fee_waiver_credit_logs`
--
ALTER TABLE `fee_waiver_credit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fwcl_credit` (`fee_waiver_credit_id`);

--
-- Indexes for table `imported_student_registry`
--
ALTER TABLE `imported_student_registry`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_import_batch` (`import_batch_id`),
  ADD KEY `idx_import_student` (`student_id_number`),
  ADD KEY `idx_import_email` (`email`),
  ADD KEY `idx_import_status` (`import_status`);

--
-- Indexes for table `meeting_holidays`
--
ALTER TABLE `meeting_holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mh_date` (`holiday_date`);

--
-- Indexes for table `meeting_settings`
--
ALTER TABLE `meeting_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `merchant`
--
ALTER TABLE `merchant`
  ADD PRIMARY KEY (`merchantID`),
  ADD KEY `merchant.usersFK` (`userID`),
  ADD KEY `fk_merchant_stall` (`stall_id`);

--
-- Indexes for table `merchant_accounts`
--
ALTER TABLE `merchant_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ma_application` (`application_id`),
  ADD UNIQUE KEY `uq_ma_user` (`user_id`),
  ADD KEY `idx_ma_created_by` (`created_by`);

--
-- Indexes for table `merchant_applications`
--
ALTER TABLE `merchant_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_app_stage` (`stage`),
  ADD KEY `idx_app_email` (`owner_email`);

--
-- Indexes for table `merchant_application_product_types`
--
ALTER TABLE `merchant_application_product_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_map_application` (`merchant_application_id`);

--
-- Indexes for table `merchant_card_views`
--
ALTER TABLE `merchant_card_views`
  ADD PRIMARY KEY (`merchant_id`);

--
-- Indexes for table `merchant_inventory`
--
ALTER TABLE `merchant_inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_merchant_sku` (`merchant_user_id`,`sku`),
  ADD KEY `idx_inv_merchant` (`merchant_user_id`),
  ADD KEY `idx_inv_category` (`category`),
  ADD KEY `idx_inv_restricted` (`is_restricted`),
  ADD KEY `idx_inv_available` (`is_available`);

--
-- Indexes for table `merchant_leases`
--
ALTER TABLE `merchant_leases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lease_merchant` (`merchant_user_id`),
  ADD KEY `idx_lease_status` (`status`),
  ADD KEY `idx_lease_due` (`next_due_date`),
  ADD KEY `fk_lease_stall` (`stall_id`);

--
-- Indexes for table `merchant_qr_orders`
--
ALTER TABLE `merchant_qr_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD UNIQUE KEY `uq_mqo_short_code` (`short_code`),
  ADD KEY `idx_mqo_token` (`token`),
  ADD KEY `idx_mqo_status_expiry` (`status`,`expires_at`),
  ADD KEY `idx_mqo_merchant` (`merchant_user_id`);

--
-- Indexes for table `merchant_rent_payments`
--
ALTER TABLE `merchant_rent_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_rent_lease` (`lease_id`),
  ADD KEY `idx_rent_date` (`payment_date`);

--
-- Indexes for table `merchant_wallets`
--
ALTER TABLE `merchant_wallets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user` (`user_id`,`created_at`),
  ADD KEY `idx_notifications_user_unread` (`user_id`,`is_read`);

--
-- Indexes for table `p2p_transfers`
--
ALTER TABLE `p2p_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_p2p_from` (`from_wallet_id`),
  ADD KEY `idx_p2p_to` (`to_wallet_id`),
  ADD KEY `idx_p2p_from_user` (`from_user_id`),
  ADD KEY `idx_p2p_date` (`created_at`);

--
-- Indexes for table `parents`
--
ALTER TABLE `parents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `parent_alerts`
--
ALTER TABLE `parent_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_parent_unread` (`parent_id`,`is_read`);

--
-- Indexes for table `parent_student_links`
--
ALTER TABLE `parent_student_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_link` (`parent_id`,`student_user_id`),
  ADD KEY `idx_parent` (`parent_id`),
  ADD KEY `idx_student` (`student_user_id`);

--
-- Indexes for table `parent_topup_requests`
--
ALTER TABLE `parent_topup_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_parent_status` (`parent_id`,`status`);

--
-- Indexes for table `parent_wallets`
--
ALTER TABLE `parent_wallets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `parent_id` (`parent_id`);

--
-- Indexes for table `payment_verifications`
--
ALTER TABLE `payment_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pv_application` (`application_id`),
  ADD KEY `idx_pv_verified_by` (`verified_by`);

--
-- Indexes for table `qr_order_items`
--
ALTER TABLE `qr_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qoi_order` (`merchant_qr_order_id`),
  ADD KEY `idx_qoi_inventory` (`merchant_inventory_id`);

--
-- Indexes for table `qr_tokens`
--
ALTER TABLE `qr_tokens`
  ADD PRIMARY KEY (`qrID`),
  ADD KEY `qr.usersFK` (`userID`);

--
-- Indexes for table `restricted_products`
--
ALTER TABLE `restricted_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rp_name` (`product_name`),
  ADD KEY `idx_rp_active` (`is_active`);

--
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`roleID`);

--
-- Indexes for table `school_revenue_ledger`
--
ALTER TABLE `school_revenue_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rev_user` (`user_id`),
  ADD KEY `idx_rev_date` (`credited_at`);

--
-- Indexes for table `school_years`
--
ALTER TABLE `school_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sy_name` (`school_year_name`);

--
-- Indexes for table `school_year_balances`
--
ALTER TABLE `school_year_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_student_sy` (`student_user_id`,`school_year_id`),
  ADD KEY `idx_syb_year` (`school_year_id`);

--
-- Indexes for table `stalls`
--
ALTER TABLE `stalls`
  ADD PRIMARY KEY (`stall_id`),
  ADD KEY `idx_stall_status` (`status`),
  ADD KEY `idx_stall_merchant` (`merchant_id`);

--
-- Indexes for table `stall_applications`
--
ALTER TABLE `stall_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sa_stall` (`stall_id`),
  ADD KEY `idx_sa_status` (`status`),
  ADD KEY `idx_sa_email` (`email`),
  ADD KEY `idx_sa_created` (`created_at`);

--
-- Indexes for table `student_fees`
--
ALTER TABLE `student_fees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_student_fees_student` (`student_user_id`);

--
-- Indexes for table `student_info`
--
ALTER TABLE `student_info`
  ADD PRIMARY KEY (`stud_infoID`),
  ADD KEY `student.courseFK` (`courseID`),
  ADD KEY `student.usersFK` (`userID`);

--
-- Indexes for table `student_wallets`
--
ALTER TABLE `student_wallets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `systemic_audit_trail`
--
ALTER TABLE `systemic_audit_trail`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_audit_timestamp` (`timestamp`),
  ADD KEY `idx_audit_role_action` (`user_role`,`action_type`),
  ADD KEY `fk_systemic_audit_user` (`user_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `topup`
--
ALTER TABLE `topup`
  ADD PRIMARY KEY (`topupID`),
  ADD KEY `adminFK` (`adminID`),
  ADD KEY `toptup.usersFK` (`userID`);

--
-- Indexes for table `topup_requests`
--
ALTER TABLE `topup_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_topup_user` (`user_id`),
  ADD KEY `idx_topup_status` (`status`);

--
-- Indexes for table `transaction`
--
ALTER TABLE `transaction`
  ADD PRIMARY KEY (`transactionID`),
  ADD KEY `transaction.usersFK` (`wallet_id`),
  ADD KEY `transaction.merchantFK` (`merchantID`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_txn_type` (`transaction_type`),
  ADD KEY `idx_txn_student` (`student_wallet_id`),
  ADD KEY `idx_txn_merchant` (`merchant_wallet_id`),
  ADD KEY `idx_txn_voucher` (`voucher_id`),
  ADD KEY `idx_txn_created` (`created_at`),
  ADD KEY `idx_txn_sy` (`school_year_id`,`id`),
  ADD KEY `idx_txn_parent_wallet` (`parent_wallet_id`,`id`);

--
-- Indexes for table `tuition_credits`
--
ALTER TABLE `tuition_credits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tc_student` (`student_user_id`),
  ADD KEY `idx_tc_status` (`status`),
  ADD KEY `idx_tc_created` (`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`userID`),
  ADD KEY `users_roleFK` (`roleID`);

--
-- Indexes for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `voucher_code` (`voucher_code`),
  ADD UNIQUE KEY `qr_code_hash` (`qr_code_hash`),
  ADD KEY `idx_v_hash` (`qr_code_hash`),
  ADD KEY `idx_v_status` (`status`),
  ADD KEY `idx_v_expiry` (`expires_at`,`status`);

--
-- Indexes for table `voucher_payment_log`
--
ALTER TABLE `voucher_payment_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vpl_voucher` (`voucher_id`),
  ADD KEY `idx_vpl_merchant` (`merchant_wallet_id`);

--
-- Indexes for table `wallet`
--
ALTER TABLE `wallet`
  ADD PRIMARY KEY (`wallet_id`);

--
-- Indexes for table `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_withdraw_user` (`user_id`),
  ADD KEY `idx_withdraw_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `archived_rejections`
--
ALTER TABLE `archived_rejections`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `auth_remember_tokens`
--
ALTER TABLE `auth_remember_tokens`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `cap_increase_log`
--
ALTER TABLE `cap_increase_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `cart_orders`
--
ALTER TABLE `cart_orders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `course`
--
ALTER TABLE `course`
  MODIFY `courseID` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `encashment_requests`
--
ALTER TABLE `encashment_requests`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fee_revenue_log`
--
ALTER TABLE `fee_revenue_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `fee_waiver_credits`
--
ALTER TABLE `fee_waiver_credits`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=127;

--
-- AUTO_INCREMENT for table `fee_waiver_credit_logs`
--
ALTER TABLE `fee_waiver_credit_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `imported_student_registry`
--
ALTER TABLE `imported_student_registry`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=246;

--
-- AUTO_INCREMENT for table `meeting_holidays`
--
ALTER TABLE `meeting_holidays`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `merchant`
--
ALTER TABLE `merchant`
  MODIFY `merchantID` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `merchant_accounts`
--
ALTER TABLE `merchant_accounts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `merchant_applications`
--
ALTER TABLE `merchant_applications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `merchant_application_product_types`
--
ALTER TABLE `merchant_application_product_types`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `merchant_inventory`
--
ALTER TABLE `merchant_inventory`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `merchant_leases`
--
ALTER TABLE `merchant_leases`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `merchant_qr_orders`
--
ALTER TABLE `merchant_qr_orders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `merchant_rent_payments`
--
ALTER TABLE `merchant_rent_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `merchant_wallets`
--
ALTER TABLE `merchant_wallets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `p2p_transfers`
--
ALTER TABLE `p2p_transfers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `parents`
--
ALTER TABLE `parents`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=175;

--
-- AUTO_INCREMENT for table `parent_alerts`
--
ALTER TABLE `parent_alerts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `parent_student_links`
--
ALTER TABLE `parent_student_links`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=188;

--
-- AUTO_INCREMENT for table `parent_topup_requests`
--
ALTER TABLE `parent_topup_requests`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `parent_wallets`
--
ALTER TABLE `parent_wallets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payment_verifications`
--
ALTER TABLE `payment_verifications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `qr_order_items`
--
ALTER TABLE `qr_order_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `qr_tokens`
--
ALTER TABLE `qr_tokens`
  MODIFY `qrID` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `restricted_products`
--
ALTER TABLE `restricted_products`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `roleID` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `school_revenue_ledger`
--
ALTER TABLE `school_revenue_ledger`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `school_years`
--
ALTER TABLE `school_years`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `school_year_balances`
--
ALTER TABLE `school_year_balances`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=507;

--
-- AUTO_INCREMENT for table `stall_applications`
--
ALTER TABLE `stall_applications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `student_fees`
--
ALTER TABLE `student_fees`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=122;

--
-- AUTO_INCREMENT for table `student_info`
--
ALTER TABLE `student_info`
  MODIFY `stud_infoID` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=255;

--
-- AUTO_INCREMENT for table `student_wallets`
--
ALTER TABLE `student_wallets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=250;

--
-- AUTO_INCREMENT for table `systemic_audit_trail`
--
ALTER TABLE `systemic_audit_trail`
  MODIFY `log_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=652;

--
-- AUTO_INCREMENT for table `topup`
--
ALTER TABLE `topup`
  MODIFY `topupID` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `topup_requests`
--
ALTER TABLE `topup_requests`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `transaction`
--
ALTER TABLE `transaction`
  MODIFY `transactionID` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `tuition_credits`
--
ALTER TABLE `tuition_credits`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `userID` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=469;

--
-- AUTO_INCREMENT for table `vouchers`
--
ALTER TABLE `vouchers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `voucher_payment_log`
--
ALTER TABLE `voucher_payment_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wallet`
--
ALTER TABLE `wallet`
  MODIFY `wallet_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `merchant`
--
ALTER TABLE `merchant`
  ADD CONSTRAINT `fk_merchant_stall` FOREIGN KEY (`stall_id`) REFERENCES `stalls` (`stall_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `merchant.usersFK` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`);

--
-- Constraints for table `merchant_accounts`
--
ALTER TABLE `merchant_accounts`
  ADD CONSTRAINT `fk_ma_application` FOREIGN KEY (`application_id`) REFERENCES `stall_applications` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `merchant_application_product_types`
--
ALTER TABLE `merchant_application_product_types`
  ADD CONSTRAINT `fk_map_application` FOREIGN KEY (`merchant_application_id`) REFERENCES `merchant_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `merchant_leases`
--
ALTER TABLE `merchant_leases`
  ADD CONSTRAINT `fk_lease_stall` FOREIGN KEY (`stall_id`) REFERENCES `stalls` (`stall_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `payment_verifications`
--
ALTER TABLE `payment_verifications`
  ADD CONSTRAINT `fk_pv_application` FOREIGN KEY (`application_id`) REFERENCES `stall_applications` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `qr_order_items`
--
ALTER TABLE `qr_order_items`
  ADD CONSTRAINT `fk_qoi_inventory` FOREIGN KEY (`merchant_inventory_id`) REFERENCES `merchant_inventory` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_qoi_order` FOREIGN KEY (`merchant_qr_order_id`) REFERENCES `merchant_qr_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `qr_tokens`
--
ALTER TABLE `qr_tokens`
  ADD CONSTRAINT `qr.usersFK` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`);

--
-- Constraints for table `stall_applications`
--
ALTER TABLE `stall_applications`
  ADD CONSTRAINT `fk_stallapps_stall` FOREIGN KEY (`stall_id`) REFERENCES `stalls` (`stall_id`) ON UPDATE CASCADE;

--
-- Constraints for table `student_info`
--
ALTER TABLE `student_info`
  ADD CONSTRAINT `student.courseFK` FOREIGN KEY (`courseID`) REFERENCES `course` (`courseID`),
  ADD CONSTRAINT `student.usersFK` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`);

--
-- Constraints for table `systemic_audit_trail`
--
ALTER TABLE `systemic_audit_trail`
  ADD CONSTRAINT `fk_systemic_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`userID`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
