-- GenPay legacy table definitions; schema only, no data.
-- Captured 2026-10-08 (Asia/Manila) from local development ewallet.
-- Recovery: select ewallet and execute these CREATE TABLE statements.
USE `ewallet`;

CREATE TABLE `topup` (
  `topupID` int NOT NULL AUTO_INCREMENT,
  `adminID` int NOT NULL,
  `userID` int NOT NULL,
  `wallet_id` int NOT NULL,
  `amount` int NOT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `date_time` datetime NOT NULL,
  PRIMARY KEY (`topupID`),
  KEY `adminFK` (`adminID`),
  KEY `toptup.usersFK` (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transaction` (
  `transactionID` int NOT NULL AUTO_INCREMENT,
  `wallet_id` int NOT NULL,
  `merchantID` int NOT NULL,
  `amount` bigint NOT NULL,
  `date_time` datetime NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`transactionID`),
  KEY `transaction.usersFK` (`wallet_id`),
  KEY `transaction.merchantFK` (`merchantID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wallet` (
  `wallet_id` int NOT NULL AUTO_INCREMENT,
  `userID` int NOT NULL,
  `balance` int NOT NULL,
  `last_updated` datetime NOT NULL,
  PRIMARY KEY (`wallet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

