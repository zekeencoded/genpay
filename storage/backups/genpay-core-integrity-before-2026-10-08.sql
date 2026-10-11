-- GenPay Issue 4: pre-migration definitions only.
-- Restore column definitions/constraints selectively; do not drop populated tables.
USE `ewallet`;

CREATE TABLE `auth_remember_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `selector` char(24) COLLATE utf8mb4_general_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_general_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_art_selector` (`selector`),
  KEY `idx_art_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cart_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(30) COLLATE utf8mb4_general_ci NOT NULL,
  `student_user_id` int unsigned DEFAULT NULL,
  `student_wallet_id` int unsigned DEFAULT NULL,
  `merchant_user_id` int unsigned NOT NULL,
  `merchant_wallet_id` int unsigned NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `items_json` text COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` datetime DEFAULT NULL,
  `paid_ref` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `buyer_role` varchar(10) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'student',
  `parent_user_id` int unsigned DEFAULT NULL,
  `parent_wallet_id` int unsigned DEFAULT NULL,
  `served_at` datetime DEFAULT NULL,
  `served_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_cart_orders_student` (`student_user_id`,`status`),
  KEY `idx_cart_orders_merchant` (`merchant_user_id`,`status`),
  KEY `idx_cart_orders_parent` (`parent_user_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `encashment_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `merchant_wallet_id` int unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `method` varchar(80) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Cashier Release',
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `reference_no` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `released_by` int unsigned DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `rejected_by` int unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_encash_user` (`user_id`),
  KEY `idx_encash_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `merchant_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int unsigned NOT NULL COMMENT 'FK -> stall_applications.id',
  `user_id` int unsigned NOT NULL COMMENT 'FK -> users.userID (the new merchant account)',
  `temp_password_plain` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Plaintext temp password - cleared after merchant changes password',
  `created_by` int unsigned NOT NULL COMMENT 'FK -> users.userID (admin who executed Final Approval)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ma_application` (`application_id`),
  UNIQUE KEY `uq_ma_user` (`user_id`),
  KEY `idx_ma_created_by` (`created_by`),
  CONSTRAINT `fk_ma_application` FOREIGN KEY (`application_id`) REFERENCES `stall_applications` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit bridge: stall application -> users account created on Final Approval';

CREATE TABLE `merchant_qr_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `short_code` varchar(12) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `merchant_user_id` int unsigned NOT NULL,
  `merchant_wallet_id` int unsigned NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `items_json` text COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `paid_by` int unsigned DEFAULT NULL,
  `paid_ref` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  UNIQUE KEY `uq_mqo_short_code` (`short_code`),
  KEY `idx_mqo_token` (`token`),
  KEY `idx_mqo_status_expiry` (`status`,`expires_at`),
  KEY `idx_mqo_merchant` (`merchant_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `p2p_transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `from_wallet_id` int unsigned NOT NULL COMMENT 'FK -> student_wallets.id',
  `to_wallet_id` int unsigned NOT NULL COMMENT 'FK -> student_wallets.id',
  `from_user_id` int unsigned NOT NULL,
  `to_user_id` int unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('completed','failed','reversed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'completed',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_p2p_from` (`from_wallet_id`),
  KEY `idx_p2p_to` (`to_wallet_id`),
  KEY `idx_p2p_from_user` (`from_user_id`),
  KEY `idx_p2p_date` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Peer-to-peer token transfers between students (atomic, transactionally safe)';

CREATE TABLE `parent_student_links` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned NOT NULL,
  `student_user_id` int unsigned NOT NULL,
  `linked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_link` (`parent_id`,`student_user_id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_student` (`student_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=188 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `parent_wallets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `parents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `low_balance_threshold` decimal(10,2) NOT NULL DEFAULT '50.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `student_wallets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL COMMENT 'FK -> users.id (student role)',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Current spendable balance in PHP points',
  `daily_spend_limit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_frozen` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=256 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `topup_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `student_wallet_id` int unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(80) COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `reference_no` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `approved_by` int unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `top_up_source` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `fee_amount` decimal(15,2) DEFAULT NULL,
  `credited_amount` decimal(15,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_topup_user` (`user_id`),
  KEY `idx_topup_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transaction_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint unsigned NOT NULL,
  `item_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `line_no` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_item_line` (`transaction_id`,`line_no`),
  KEY `idx_txn_items_transaction` (`transaction_id`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(30) COLLATE utf8mb4_general_ci NOT NULL,
  `txn_token` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_type` enum('cash_in','payment','voucher_payment','merchant_settle','voucher_create','voucher_expire','cap_increase','p2p_transfer','service_fee','tuition_credit','student_withdraw','refund','allowance','fee_waiver_claim') COLLATE utf8mb4_general_ci NOT NULL,
  `initiated_by` int unsigned NOT NULL COMMENT 'FK -> users.id -- who triggered this transaction',
  `student_wallet_id` int unsigned DEFAULT NULL,
  `merchant_wallet_id` int unsigned DEFAULT NULL,
  `voucher_id` int unsigned DEFAULT NULL,
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
  `parent_wallet_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  UNIQUE KEY `txn_token` (`txn_token`),
  KEY `idx_txn_type` (`transaction_type`),
  KEY `idx_txn_student` (`student_wallet_id`),
  KEY `idx_txn_merchant` (`merchant_wallet_id`),
  KEY `idx_txn_voucher` (`voucher_id`),
  KEY `idx_txn_created` (`created_at`),
  KEY `idx_txn_sy` (`school_year_id`,`id`),
  KEY `idx_txn_parent_wallet` (`parent_wallet_id`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `userID` int NOT NULL AUTO_INCREMENT,
  `view_token` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
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
  `staff_removed_at` datetime DEFAULT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = merchant must change password on next login',
  `merchant_owner_id` int unsigned DEFAULT NULL COMMENT 'FK -> users.userID â€” links Merchant Staff to their Merchant Admin',
  `is_first_login` tinyint(1) NOT NULL DEFAULT '0',
  `password_changed` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('Active','Inactive','Suspended','Banned') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Active',
  `temp_password` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `restricted_violation_count` int unsigned NOT NULL DEFAULT '0',
  `restricted_violation_last_at` datetime DEFAULT NULL,
  `restricted_suspended_until` datetime DEFAULT NULL,
  `suspended_until` datetime DEFAULT NULL,
  `suspension_reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `suspended_at` datetime DEFAULT NULL,
  `suspended_by` int DEFAULT NULL,
  `suspension_prev_status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `suspension_froze_wallet` tinyint(1) NOT NULL DEFAULT '0',
  `lockout_kind` varchar(12) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sex` enum('Male','Female','Prefer not to say') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address_barangay` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address_city` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address_province` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`userID`),
  UNIQUE KEY `view_token` (`view_token`),
  KEY `users_roleFK` (`roleID`)
) ENGINE=InnoDB AUTO_INCREMENT=490 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `voucher_payment_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` int unsigned NOT NULL,
  `merchant_wallet_id` int unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_before` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `scanned_by` int unsigned DEFAULT NULL,
  `transaction_ref` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vpl_voucher` (`voucher_id`),
  KEY `idx_vpl_merchant` (`merchant_wallet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `vouchers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `qr_code_hash` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `voucher_code` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `issued_by` int unsigned NOT NULL COMMENT 'FK -> users.id (cashier or admin who created it)',
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
  `use_count` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_code` (`voucher_code`),
  UNIQUE KEY `qr_code_hash` (`qr_code_hash`),
  KEY `idx_v_hash` (`qr_code_hash`),
  KEY `idx_v_status` (`status`),
  KEY `idx_v_expiry` (`expires_at`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `withdrawal_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `student_wallet_id` int unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `method` varchar(80) NOT NULL DEFAULT 'Cashier Release',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reference_no` varchar(40) DEFAULT NULL,
  `released_by` int unsigned DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `rejected_by` int unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_withdraw_user` (`user_id`),
  KEY `idx_withdraw_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

