-- install/schema_template.sql
-- Schema base per le tabelle, %%DB_TABLE_PREFIX%% verrà sostituito dinamicamente.

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `%%DB_TABLE_PREFIX%%files`;
CREATE TABLE `%%DB_TABLE_PREFIX%%files` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `folder_id` int DEFAULT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size_bytes` bigint UNSIGNED NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `upload_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_download_date` datetime DEFAULT NULL,
  `download_count` int UNSIGNED DEFAULT '0',
  `public_link_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_link_expires_at` datetime DEFAULT NULL,
  `expiry_date` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by_user_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `%%DB_TABLE_PREFIX%%folder_permissions`;
DROP TABLE IF EXISTS `%%DB_TABLE_PREFIX%%folders`;
CREATE TABLE `%%DB_TABLE_PREFIX%%folders` (
  `id` int NOT NULL,
  `parent_folder_id` int DEFAULT NULL,
  `owner_user_id` int NOT NULL,
  `folder_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by_user_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%%DB_TABLE_PREFIX%%folder_permissions` (
  `id` int NOT NULL,
  `folder_id` int NOT NULL,
  `user_id` int NOT NULL,
  `can_view` tinyint(1) DEFAULT '0',
  `can_upload_files` tinyint(1) DEFAULT '0',
  `can_delete_files` tinyint(1) DEFAULT '0',
  `can_share_files` tinyint(1) DEFAULT '0'
  -- Ho rimosso can_upload e can_download per allinearmi con le funzioni folder_php recenti
  -- Se sono necessarie nel tuo schema esatto, assicurati che functions_folder.php le gestisca
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `%%DB_TABLE_PREFIX%%site_settings`;
CREATE TABLE `%%DB_TABLE_PREFIX%%site_settings` (
  `setting_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `%%DB_TABLE_PREFIX%%users`;
CREATE TABLE `%%DB_TABLE_PREFIX%%users` (
  `id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('Admin','User') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User',
  `quota_bytes` bigint UNSIGNED DEFAULT '1073741824',
  `used_space_bytes` bigint UNSIGNED DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `requires_admin_validation` tinyint(1) NOT NULL DEFAULT '1',
  `requires_password_change` tinyint(1) NOT NULL DEFAULT '0',
  `is_email_validated` tinyint(1) NOT NULL DEFAULT '0',
  `validation_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `validation_token_expires_at` datetime DEFAULT NULL,
  `initial_password_setup_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `initial_password_setup_token_expires_at` datetime DEFAULT NULL,
  `password_reset_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_reset_token_expires_at` datetime DEFAULT NULL,
  `email_change_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_email_pending_validation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_change_token_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `%%DB_TABLE_PREFIX%%files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_stored_filename_in_path` (`file_path`(255),`stored_filename`(191)), /* Chiave più specifica */
  ADD KEY `idx_public_link_token` (`public_link_token`), /* Modificato da UNIQUE a KEY */
  ADD KEY `user_id` (`user_id`),
  ADD KEY `folder_id` (`folder_id`),
  ADD KEY `idx_deleted_by_user_id` (`deleted_by_user_id`); /* Nome indice più specifico */

ALTER TABLE `%%DB_TABLE_PREFIX%%folders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_folder_in_parent` (`parent_folder_id`,`owner_user_id`,`folder_name`,`is_deleted`),
  ADD KEY `owner_user_id` (`owner_user_id`),
  ADD KEY `idx_deleted_by_user_id_folders` (`deleted_by_user_id`); /* Nome indice più specifico */

ALTER TABLE `%%DB_TABLE_PREFIX%%folder_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_permission` (`folder_id`,`user_id`),
  ADD KEY `user_id_permissions` (`user_id`); /* Nome indice più specifico */

ALTER TABLE `%%DB_TABLE_PREFIX%%site_settings`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `%%DB_TABLE_PREFIX%%users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_validation_token` (`validation_token`),
  ADD KEY `idx_initial_password_setup_token` (`initial_password_setup_token`),
  ADD KEY `idx_password_reset_token` (`password_reset_token`); /* Aggiunto indice mancante */

ALTER TABLE `%%DB_TABLE_PREFIX%%files` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `%%DB_TABLE_PREFIX%%folders` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `%%DB_TABLE_PREFIX%%folder_permissions` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `%%DB_TABLE_PREFIX%%users` MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `%%DB_TABLE_PREFIX%%files`
  ADD CONSTRAINT `%%DB_TABLE_PREFIX%%files_fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `%%DB_TABLE_PREFIX%%users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `%%DB_TABLE_PREFIX%%files_fk_folder_id` FOREIGN KEY (`folder_id`) REFERENCES `%%DB_TABLE_PREFIX%%folders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `%%DB_TABLE_PREFIX%%files_fk_deleted_by` FOREIGN KEY (`deleted_by_user_id`) REFERENCES `%%DB_TABLE_PREFIX%%users` (`id`) ON DELETE SET NULL;
ALTER TABLE `%%DB_TABLE_PREFIX%%folders`
  ADD CONSTRAINT `%%DB_TABLE_PREFIX%%folders_fk_parent_id` FOREIGN KEY (`parent_folder_id`) REFERENCES `%%DB_TABLE_PREFIX%%folders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `%%DB_TABLE_PREFIX%%folders_fk_owner_id` FOREIGN KEY (`owner_user_id`) REFERENCES `%%DB_TABLE_PREFIX%%users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `%%DB_TABLE_PREFIX%%folders_fk_deleted_by` FOREIGN KEY (`deleted_by_user_id`) REFERENCES `%%DB_TABLE_PREFIX%%users` (`id`) ON DELETE SET NULL;
ALTER TABLE `%%DB_TABLE_PREFIX%%folder_permissions`
  ADD CONSTRAINT `%%DB_TABLE_PREFIX%%folder_permissions_fk_folder_id` FOREIGN KEY (`folder_id`) REFERENCES `%%DB_TABLE_PREFIX%%folders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `%%DB_TABLE_PREFIX%%folder_permissions_fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `%%DB_TABLE_PREFIX%%users` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS=1;