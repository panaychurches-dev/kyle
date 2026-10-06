-- Run once in the existing `doctrack` database to replace the documents
-- table's unique tracking_number column with the QR code payload column.
-- Existing values are preserved and remain valid QR payloads.

ALTER TABLE `documents`
  CHANGE COLUMN `tracking_number` `qr_code` varchar(64) NOT NULL,
  DROP INDEX `tracking_number`,
  ADD UNIQUE KEY `qr_code` (`qr_code`);

-- The application creates this table automatically; run this only if you want
-- the tracking history table provisioned directly through phpMyAdmin.
CREATE TABLE IF NOT EXISTS `document_tracking_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `event_type` varchar(20) NOT NULL,
  `office` varchar(150) NOT NULL,
  `received_by_user_id` int(11) DEFAULT NULL,
  `received_by_name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `document_event` (`document_id`, `id`),
  KEY `receiver_user` (`received_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
