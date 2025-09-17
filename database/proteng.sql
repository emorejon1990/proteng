/*
 Navicat Premium Data Transfer

 Source Server         : MySql
 Source Server Type    : MySQL
 Source Server Version : 100432 (10.4.32-MariaDB)
 Source Host           : localhost:3306
 Source Schema         : proteng

 Target Server Type    : MySQL
 Target Server Version : 100432 (10.4.32-MariaDB)
 File Encoding         : 65001

 Date: 16/09/2025 17:34:43
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for asset
-- ----------------------------
DROP TABLE IF EXISTS `asset`;
CREATE TABLE `asset`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` double NOT NULL,
  `weight_tolerance` double NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of asset
-- ----------------------------
INSERT INTO `asset` VALUES (1, 'THIA-S30', 163, 0.5, '2025-09-10 13:47:13', '2025-09-10 13:47:13');
INSERT INTO `asset` VALUES (2, 'THIA-S50', 188, 0.5, '2025-09-10 13:47:35', '2025-09-10 13:47:35');
INSERT INTO `asset` VALUES (3, 'THIA-S100', 250, 0.5, '2025-09-10 13:47:49', '2025-09-10 13:47:49');

-- ----------------------------
-- Table structure for cache
-- ----------------------------
DROP TABLE IF EXISTS `cache`;
CREATE TABLE `cache`  (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of cache
-- ----------------------------
INSERT INTO `cache` VALUES ('proteng_cache_livewire-rate-limiter:a17961fa74e9275d529f489537f179c05d50c2f3', 'i:1;', 1757955653);
INSERT INTO `cache` VALUES ('proteng_cache_livewire-rate-limiter:a17961fa74e9275d529f489537f179c05d50c2f3:timer', 'i:1757955653;', 1757955653);

-- ----------------------------
-- Table structure for cache_locks
-- ----------------------------
DROP TABLE IF EXISTS `cache_locks`;
CREATE TABLE `cache_locks`  (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of cache_locks
-- ----------------------------

-- ----------------------------
-- Table structure for capacities
-- ----------------------------
DROP TABLE IF EXISTS `capacities`;
CREATE TABLE `capacities`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `wc` bigint UNSIGNED NULL DEFAULT NULL,
  `asset_id` bigint UNSIGNED NULL DEFAULT NULL,
  `quant` int NOT NULL,
  `time` time NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `capacities_wc_foreign`(`wc` ASC) USING BTREE,
  INDEX `capacities_asset_id_foreign`(`asset_id` ASC) USING BTREE,
  CONSTRAINT `capacities_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `capacities_wc_foreign` FOREIGN KEY (`wc`) REFERENCES `work_centers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of capacities
-- ----------------------------

-- ----------------------------
-- Table structure for failed_jobs
-- ----------------------------
DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `failed_jobs_uuid_unique`(`uuid` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of failed_jobs
-- ----------------------------

-- ----------------------------
-- Table structure for inventories
-- ----------------------------
DROP TABLE IF EXISTS `inventories`;
CREATE TABLE `inventories`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `location` bigint UNSIGNED NULL DEFAULT NULL,
  `asset_id` bigint UNSIGNED NULL DEFAULT NULL,
  `quant` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `inventories_location_foreign`(`location` ASC) USING BTREE,
  INDEX `inventories_asset_id_foreign`(`asset_id` ASC) USING BTREE,
  CONSTRAINT `inventories_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `inventories_location_foreign` FOREIGN KEY (`location`) REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of inventories
-- ----------------------------

-- ----------------------------
-- Table structure for job_batches
-- ----------------------------
DROP TABLE IF EXISTS `job_batches`;
CREATE TABLE `job_batches`  (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `cancelled_at` int NULL DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of job_batches
-- ----------------------------

-- ----------------------------
-- Table structure for jobs
-- ----------------------------
DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED NULL DEFAULT NULL,
  `available_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `jobs_queue_index`(`queue` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of jobs
-- ----------------------------

-- ----------------------------
-- Table structure for locations
-- ----------------------------
DROP TABLE IF EXISTS `locations`;
CREATE TABLE `locations`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `wh` bigint UNSIGNED NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `locations_wh_foreign`(`wh` ASC) USING BTREE,
  CONSTRAINT `locations_wh_foreign` FOREIGN KEY (`wh`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of locations
-- ----------------------------
INSERT INTO `locations` VALUES (1, 'Production', 1, '2025-09-10 13:37:16', '2025-09-10 13:37:16');
INSERT INTO `locations` VALUES (2, 'Waiting', 1, '2025-09-10 13:37:50', '2025-09-10 13:37:50');
INSERT INTO `locations` VALUES (3, 'Quality', 1, '2025-09-10 13:38:23', '2025-09-10 13:38:23');
INSERT INTO `locations` VALUES (4, 'Stock', 1, '2025-09-10 13:38:39', '2025-09-10 13:38:39');
INSERT INTO `locations` VALUES (5, 'Recycle', 1, '2025-09-10 13:19:27', '2025-09-10 13:19:32');

-- ----------------------------
-- Table structure for migrations
-- ----------------------------
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 23 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of migrations
-- ----------------------------
INSERT INTO `migrations` VALUES (1, '0001_01_01_000000_create_users_table', 1);
INSERT INTO `migrations` VALUES (2, '0001_01_01_000001_create_cache_table', 1);
INSERT INTO `migrations` VALUES (3, '0001_01_01_000002_create_jobs_table', 1);
INSERT INTO `migrations` VALUES (4, '2025_05_13_151035_create_asset_table', 1);
INSERT INTO `migrations` VALUES (5, '2025_05_13_151138_create_statuses_table', 1);
INSERT INTO `migrations` VALUES (6, '2025_05_13_151146_create_products_table', 1);
INSERT INTO `migrations` VALUES (7, '2025_05_13_192238_create_work_orders_table', 1);
INSERT INTO `migrations` VALUES (8, '2025_05_13_192332_create_work_centers_table', 1);
INSERT INTO `migrations` VALUES (9, '2025_05_13_192620_create_capacities_table', 1);
INSERT INTO `migrations` VALUES (10, '2025_05_13_192630_create_werehouses_table', 1);
INSERT INTO `migrations` VALUES (11, '2025_05_13_192635_create_locations_table', 1);
INSERT INTO `migrations` VALUES (12, '2025_05_13_192640_create_inventories_table', 1);
INSERT INTO `migrations` VALUES (13, '2025_05_14_182236_add_field_to_products_table', 1);
INSERT INTO `migrations` VALUES (14, '2025_05_16_134456_add_field_to_products_table', 1);
INSERT INTO `migrations` VALUES (15, '2025_05_18_145022_create_wo_statu_table', 1);
INSERT INTO `migrations` VALUES (16, '2025_05_18_145033_create_wo_type_table', 1);
INSERT INTO `migrations` VALUES (17, '2025_05_18_182212_add_field_to_work_orders_table', 1);
INSERT INTO `migrations` VALUES (18, '2025_05_19_134831_add_field_to_products_table', 1);
INSERT INTO `migrations` VALUES (19, '2025_05_19_162005_add_field_to_work_orders_table', 1);
INSERT INTO `migrations` VALUES (20, '2025_05_27_150423_add_field_to_work_orders_table', 1);
INSERT INTO `migrations` VALUES (21, '2025_06_02_152008_create_wo_ass_table', 1);

-- ----------------------------
-- Table structure for password_reset_tokens
-- ----------------------------
DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens`  (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of password_reset_tokens
-- ----------------------------

-- ----------------------------
-- Table structure for products
-- ----------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `serial` char(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `assambly_by` bigint UNSIGNED NULL DEFAULT NULL,
  `assambly_date` datetime NULL DEFAULT NULL,
  `assambled` tinyint(1) NOT NULL DEFAULT 0,
  `fill_by` bigint UNSIGNED NULL DEFAULT NULL,
  `fill_date` datetime NULL DEFAULT NULL,
  `filled` tinyint(1) NOT NULL DEFAULT 0,
  `weight` double NULL DEFAULT NULL,
  `quality_by` bigint UNSIGNED NULL DEFAULT NULL,
  `quality_date` datetime NULL DEFAULT NULL,
  `qualified` tinyint(1) NOT NULL DEFAULT 0,
  `f_weight` double NULL DEFAULT NULL,
  `status_id` bigint UNSIGNED NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `asset_id` bigint UNSIGNED NULL DEFAULT NULL,
  `location_id` bigint UNSIGNED NULL DEFAULT NULL,
  `work_order_id` bigint UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `products_assambly_by_foreign`(`assambly_by` ASC) USING BTREE,
  INDEX `products_fill_by_foreign`(`fill_by` ASC) USING BTREE,
  INDEX `products_quality_by_foreign`(`quality_by` ASC) USING BTREE,
  INDEX `products_status_id_foreign`(`status_id` ASC) USING BTREE,
  INDEX `products_asset_id_foreign`(`asset_id` ASC) USING BTREE,
  INDEX `products_location_id_foreign`(`location_id` ASC) USING BTREE,
  INDEX `products_work_order_id_foreign`(`work_order_id` ASC) USING BTREE,
  CONSTRAINT `products_assambly_by_foreign` FOREIGN KEY (`assambly_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `products_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `products_fill_by_foreign` FOREIGN KEY (`fill_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `products_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `products_quality_by_foreign` FOREIGN KEY (`quality_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `products_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `products_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 108 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of products
-- ----------------------------
INSERT INTO `products` VALUES (81, NULL, 1, '2025-09-10 15:57:42', 1, 1, '2025-09-10 12:09:17', 1, 163.5, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:45', 1, 4, NULL);
INSERT INTO `products` VALUES (82, NULL, 1, '2025-09-10 15:57:44', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:45', 1, 4, NULL);
INSERT INTO `products` VALUES (83, NULL, 1, '2025-09-10 15:57:46', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:45', 1, 4, NULL);
INSERT INTO `products` VALUES (84, NULL, 1, '2025-09-10 15:57:48', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (85, NULL, 1, '2025-09-10 15:57:50', 1, 1, '2025-09-10 12:09:17', 1, 163.5, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (86, NULL, 1, '2025-09-10 15:57:52', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (87, NULL, 1, '2025-09-10 15:57:53', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (88, NULL, 1, '2025-09-10 15:57:55', 1, 1, '2025-09-10 12:09:17', 1, 163.5, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (89, NULL, 1, '2025-09-10 15:57:57', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (90, NULL, 1, '2025-09-10 15:57:58', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (91, NULL, 1, '2025-09-10 15:58:00', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (92, NULL, 1, '2025-09-10 15:58:02', 1, 1, '2025-09-10 12:09:17', 1, 163.5, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (93, NULL, 1, '2025-09-10 15:58:03', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (94, NULL, 1, '2025-09-10 15:58:05', 1, 1, '2025-09-10 12:09:17', 1, 163.5, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (95, NULL, 1, '2025-09-10 15:58:07', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (96, NULL, 1, '2025-09-10 15:58:09', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (97, NULL, 1, '2025-09-10 15:58:10', 1, 1, '2025-09-10 12:09:17', 1, 163.5, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (98, NULL, 1, '2025-09-10 15:58:12', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (99, NULL, 1, '2025-09-10 15:58:14', 1, 1, '2025-09-10 12:09:17', 1, 163, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (100, NULL, 1, '2025-09-10 15:58:16', 1, 1, '2025-09-10 12:09:17', 1, 163.5, 1, '2025-09-12 12:21:16', 1, 163, 6, '2025-09-10 15:55:34', '2025-09-10 17:12:46', 1, 4, NULL);
INSERT INTO `products` VALUES (101, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, 0, NULL, 3, '2025-09-15 18:20:50', '2025-09-15 18:20:50', 3, 1, 8);
INSERT INTO `products` VALUES (102, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, 0, NULL, 3, '2025-09-15 18:20:50', '2025-09-15 18:20:50', 3, 1, 8);
INSERT INTO `products` VALUES (103, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, 0, NULL, 3, '2025-09-15 18:20:50', '2025-09-15 18:20:50', 3, 1, 8);
INSERT INTO `products` VALUES (104, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, 0, NULL, 3, '2025-09-15 18:20:50', '2025-09-15 18:20:50', 3, 1, 8);
INSERT INTO `products` VALUES (105, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, 0, NULL, 3, '2025-09-15 18:20:50', '2025-09-15 18:20:50', 3, 1, 8);
INSERT INTO `products` VALUES (106, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, 0, NULL, 3, '2025-09-15 18:23:04', '2025-09-15 18:23:04', 2, 1, 9);
INSERT INTO `products` VALUES (107, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, 0, NULL, 3, '2025-09-15 18:23:04', '2025-09-15 18:23:04', 2, 1, 9);

-- ----------------------------
-- Table structure for sessions
-- ----------------------------
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions`  (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint UNSIGNED NULL DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `sessions_user_id_index`(`user_id` ASC) USING BTREE,
  INDEX `sessions_last_activity_index`(`last_activity` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of sessions
-- ----------------------------
INSERT INTO `sessions` VALUES ('4okELyoQeguNNyzcYv6tl34sRCBjh5peDfWszWu4', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'YTo0OntzOjY6Il90b2tlbiI7czo0MDoibnRuSkd3QUhma09IQmx1SGpaWW4wTTRVdjdMcmRMQjhQUXN4ZmJzbiI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czozMzoiaHR0cDovLzEyNy4wLjAuMTo4MDAwL2ludmVudG9yaWVzIjt9czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6Mjc6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMC9sb2dpbiI7fX0=', 1758055464);
INSERT INTO `sessions` VALUES ('6cU3YICeppmEX2dpAtz3q7pZe2FkK5VM4mJYVcB4', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'YTo4OntzOjY6Il90b2tlbiI7czo0MDoiVnBwWkRqaDJsQU5tUlYxMmFhZ1lNbXRvcFJVSEZjRmtTcHFON2dyZyI7czozOiJ1cmwiO2E6MDp7fXM6OToiX3ByZXZpb3VzIjthOjE6e3M6MzoidXJsIjtzOjMzOiJodHRwOi8vMTI3LjAuMC4xOjgwMDAvaW52ZW50b3JpZXMiO31zOjY6Il9mbGFzaCI7YToyOntzOjM6Im9sZCI7YTowOnt9czozOiJuZXciO2E6MDp7fX1zOjUwOiJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI7aToxO3M6MTc6InBhc3N3b3JkX2hhc2hfd2ViIjtzOjYwOiIkMnkkMTIkV0RRekZNSVlNNU1PWXFoR3VoMUdpZXNZdjFybldGcHIudWVKcnJUUDRuTFlJenZMR3Y2TWkiO3M6ODoiZmlsYW1lbnQiO2E6MDp7fXM6NjoidGFibGVzIjthOjE6e3M6MjE6Ikxpc3RQcm9kdWN0c19wZXJfcGFnZSI7czozOiJhbGwiO319', 1757961809);

-- ----------------------------
-- Table structure for status
-- ----------------------------
DROP TABLE IF EXISTS `status`;
CREATE TABLE `status`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of status
-- ----------------------------
INSERT INTO `status` VALUES (1, 'New', '2025-09-10 13:49:41', '2025-09-10 13:49:41');
INSERT INTO `status` VALUES (2, 'Approved', '2025-09-10 13:50:57', '2025-09-10 13:50:57');
INSERT INTO `status` VALUES (3, 'inProcess', '2025-09-10 13:51:06', '2025-09-10 13:51:06');
INSERT INTO `status` VALUES (4, 'Waiting', '2025-09-10 13:51:13', '2025-09-10 13:51:13');
INSERT INTO `status` VALUES (5, 'Recycled', '2025-09-10 13:51:21', '2025-09-10 13:51:21');
INSERT INTO `status` VALUES (6, 'Ready', '2025-09-10 13:51:32', '2025-09-10 13:51:32');

-- ----------------------------
-- Table structure for users
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `users_email_unique`(`email` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of users
-- ----------------------------
INSERT INTO `users` VALUES (1, 'admin', 'admin@proteng.com', '2025-09-01 14:14:22', '$2y$12$WDQzFMIYM5MOYqhGuh1GiesYv1rnWFpr.ueJrrTP4nLYIzvLGv6Mi', NULL, NULL, NULL);

-- ----------------------------
-- Table structure for warehouses
-- ----------------------------
DROP TABLE IF EXISTS `warehouses`;
CREATE TABLE `warehouses`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` char(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of warehouses
-- ----------------------------
INSERT INTO `warehouses` VALUES (1, 'Jupiter', '1234 NW', '2025-09-10 13:31:33', '2025-09-10 13:31:33');

-- ----------------------------
-- Table structure for wo_ass
-- ----------------------------
DROP TABLE IF EXISTS `wo_ass`;
CREATE TABLE `wo_ass`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint UNSIGNED NOT NULL,
  `asset_id` bigint UNSIGNED NOT NULL,
  `quantity` int NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of wo_ass
-- ----------------------------
INSERT INTO `wo_ass` VALUES (1, 2, 1, 5, '2025-09-12 17:17:34', '2025-09-12 17:17:34');
INSERT INTO `wo_ass` VALUES (2, 2, 2, 2, '2025-09-12 17:17:45', '2025-09-12 17:17:45');

-- ----------------------------
-- Table structure for wo_status
-- ----------------------------
DROP TABLE IF EXISTS `wo_status`;
CREATE TABLE `wo_status`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of wo_status
-- ----------------------------
INSERT INTO `wo_status` VALUES (1, 'New', NULL, NULL);
INSERT INTO `wo_status` VALUES (2, 'Approved', NULL, NULL);
INSERT INTO `wo_status` VALUES (3, 'inProcess', NULL, NULL);
INSERT INTO `wo_status` VALUES (4, 'Waiting', NULL, NULL);
INSERT INTO `wo_status` VALUES (5, 'Paused', NULL, NULL);
INSERT INTO `wo_status` VALUES (6, 'Ready', NULL, NULL);

-- ----------------------------
-- Table structure for wo_type
-- ----------------------------
DROP TABLE IF EXISTS `wo_type`;
CREATE TABLE `wo_type`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of wo_type
-- ----------------------------
INSERT INTO `wo_type` VALUES (1, 'Producction', NULL, NULL);
INSERT INTO `wo_type` VALUES (2, 'Distribution', NULL, NULL);
INSERT INTO `wo_type` VALUES (3, 'Installation', NULL, NULL);

-- ----------------------------
-- Table structure for work_centers
-- ----------------------------
DROP TABLE IF EXISTS `work_centers`;
CREATE TABLE `work_centers`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of work_centers
-- ----------------------------
INSERT INTO `work_centers` VALUES (1, 'Assemble', NULL, '2025-09-10 15:48:59');
INSERT INTO `work_centers` VALUES (2, 'Fill', '2025-09-10 16:02:25', '2025-09-10 16:02:25');
INSERT INTO `work_centers` VALUES (3, 'Waiting', '2025-09-10 16:05:34', '2025-09-10 16:05:34');
INSERT INTO `work_centers` VALUES (4, 'Quality', NULL, NULL);

-- ----------------------------
-- Table structure for work_orders
-- ----------------------------
DROP TABLE IF EXISTS `work_orders`;
CREATE TABLE `work_orders`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_id` bigint UNSIGNED NULL DEFAULT NULL,
  `date` datetime NOT NULL,
  `quant` int NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status_id` bigint UNSIGNED NULL DEFAULT NULL,
  `type_id` bigint UNSIGNED NULL DEFAULT NULL,
  `wc_id` bigint UNSIGNED NULL DEFAULT NULL,
  `wc_changed_at` date NULL DEFAULT NULL,
  `for` int NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `work_orders_asset_id_foreign`(`asset_id` ASC) USING BTREE,
  INDEX `work_orders_status_id_foreign`(`status_id` ASC) USING BTREE,
  INDEX `work_orders_type_id_foreign`(`type_id` ASC) USING BTREE,
  INDEX `work_orders_wc_id_foreign`(`wc_id` ASC) USING BTREE,
  CONSTRAINT `work_orders_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `work_orders_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `wo_status` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `work_orders_type_id_foreign` FOREIGN KEY (`type_id`) REFERENCES `wo_type` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `work_orders_wc_id_foreign` FOREIGN KEY (`wc_id`) REFERENCES `work_centers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 10 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of work_orders
-- ----------------------------
INSERT INTO `work_orders` VALUES (1, 'WO1', 1, '2025-09-11 00:00:00', 20, '2025-09-10 14:02:28', '2025-09-10 17:12:46', 6, 1, NULL, '2025-09-12', NULL);
INSERT INTO `work_orders` VALUES (2, 'WD1', NULL, '2025-09-13 00:00:00', NULL, '2025-09-12 17:14:10', '2025-09-15 18:22:39', 5, 2, NULL, NULL, NULL);
INSERT INTO `work_orders` VALUES (8, 'WO3', 3, '2025-09-16 00:00:00', 5, '2025-09-15 18:20:01', '2025-09-15 18:20:50', 2, 1, 1, NULL, NULL);
INSERT INTO `work_orders` VALUES (9, 'WO4', 2, '2025-09-16 00:00:00', 2, '2025-09-15 18:22:39', '2025-09-15 18:23:04', 2, 1, 1, NULL, 2);

SET FOREIGN_KEY_CHECKS = 1;
