-- BAIT website: database structure (all tables of the current system, every migration up to step 19 included).
-- No real data and no user accounts. Create the first Super Admin as README.md (3.1) explains.
-- Import into an empty database created with CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `about_section_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `about_section_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `icon` varchar(50) DEFAULT 'lightbulb',
  `image` varchar(255) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `idx_section` (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `about_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `about_sections` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `section_type` enum('text','grid','highlights') NOT NULL DEFAULT 'text',
  `slug` varchar(50) DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'target',
  `image` varchar(255) DEFAULT NULL,
  `color` varchar(20) DEFAULT '#1E2A78',
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `content_format` enum('paragraph','list','bullet','html') NOT NULL DEFAULT 'html',
  `display_options` text DEFAULT NULL,
  `width` enum('full','half') NOT NULL DEFAULT 'full',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `articles` (
  `article_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('เผยแพร่','แบบร่าง') NOT NULL DEFAULT 'แบบร่าง',
  `tags` varchar(255) DEFAULT NULL,
  `view_count` int(11) NOT NULL DEFAULT 0,
  `slug` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`article_id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `fk_article_user` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_article_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `banners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `banners` (
  `banner_id` int(11) NOT NULL AUTO_INCREMENT,
  `image` varchar(255) NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`banner_id`),
  KEY `idx_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `campus_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `campus_contacts` (
  `contact_id` int(11) NOT NULL AUTO_INCREMENT,
  `campus_id` int(11) NOT NULL,
  `contact_type` enum('phone','email','facebook','line','tiktok','website','youtube','x','instagram') NOT NULL,
  `contact_value` varchar(255) NOT NULL,
  `display_label` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`contact_id`),
  KEY `fk_contact_campus` (`campus_id`),
  CONSTRAINT `fk_contact_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`campus_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `campuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `campuses` (
  `campus_id` int(11) NOT NULL AUTO_INCREMENT,
  `campus_name` varchar(255) NOT NULL,
  `head_staff_id` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `map_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`campus_id`),
  KEY `fk_campus_head` (`head_staff_id`),
  CONSTRAINT `fk_campus_head` FOREIGN KEY (`head_staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `color` varchar(7) DEFAULT '#1E2A78',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `committee_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `committee_members` (
  `member_id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `name_en` varchar(255) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `image` varchar(255) DEFAULT NULL,
  `role` varchar(12) NOT NULL DEFAULT 'member',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`member_id`),
  KEY `idx_role` (`role`,`sort_order`),
  KEY `idx_staff` (`staff_id`),
  CONSTRAINT `fk_committee_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(50) NOT NULL,
  PRIMARY KEY (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `education_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `education_levels` (
  `level_id` int(11) NOT NULL AUTO_INCREMENT,
  `level_name` varchar(255) NOT NULL,
  `level_abbr` varchar(10) DEFAULT NULL,
  `level_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `faculty`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `faculty` (
  `faculty_id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_name_th` varchar(255) NOT NULL,
  `faculty_name_en` varchar(255) NOT NULL DEFAULT 'Faculty of Business Administration and Information Technology',
  `faculty_abbr` varchar(30) NOT NULL DEFAULT 'BAIT',
  `logo` varchar(255) DEFAULT NULL,
  `about` longtext DEFAULT NULL,
  `vision` text DEFAULT NULL,
  `mission` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`faculty_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `form_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `form_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `color` varchar(7) DEFAULT '#162E93',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `image` varchar(255) DEFAULT NULL,
  `icon` varchar(50) NOT NULL DEFAULT 'download',
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `forms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `forms` (
  `form_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `form_name` varchar(255) NOT NULL,
  `audience` enum('student','staff') NOT NULL DEFAULT 'student',
  `file` varchar(255) DEFAULT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`form_id`),
  KEY `fk_form_category` (`category_id`),
  CONSTRAINT `fk_form_category` FOREIGN KEY (`category_id`) REFERENCES `form_categories` (`category_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `highlights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `highlights` (
  `highlight_id` int(11) NOT NULL AUTO_INCREMENT,
  `icon` varchar(50) NOT NULL DEFAULT 'lightbulb',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `media_type` enum('none','image','video') NOT NULL DEFAULT 'none',
  `media_path` varchar(255) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `crop_scale_x` decimal(4,3) DEFAULT NULL,
  `crop_scale_y` decimal(4,3) DEFAULT NULL,
  PRIMARY KEY (`highlight_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `home_promo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `home_promo` (
  `promo_id` int(11) NOT NULL AUTO_INCREMENT,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `video_source` varchar(10) NOT NULL DEFAULT 'url',
  `video_url` varchar(500) DEFAULT NULL,
  `video_file` varchar(255) DEFAULT NULL,
  `poster` varchar(255) DEFAULT NULL,
  `logo_mode` varchar(6) NOT NULL DEFAULT 'none',
  `logo` varchar(255) DEFAULT NULL,
  `logo_size` smallint(6) NOT NULL DEFAULT 150,
  `autoplay` tinyint(1) NOT NULL DEFAULT 0,
  `video_side` varchar(5) NOT NULL DEFAULT 'left',
  `heading` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `heading_size` smallint(6) NOT NULL DEFAULT 32,
  `body_size` smallint(6) NOT NULL DEFAULT 18,
  `text_align` varchar(8) NOT NULL DEFAULT 'left',
  `v_align` varchar(8) NOT NULL DEFAULT 'center',
  `text_color` varchar(7) DEFAULT NULL,
  `bg_color` varchar(7) DEFAULT NULL,
  `btn_label` varchar(100) DEFAULT NULL,
  `btn_url` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`promo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `info_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `info_links` (
  `link_id` int(11) NOT NULL AUTO_INCREMENT,
  `audience` enum('student','staff') NOT NULL DEFAULT 'student',
  `icon` varchar(50) NOT NULL DEFAULT 'link',
  `icon_image` varchar(255) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `link_url` varchar(500) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `it_news`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `it_news` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `guid_hash` char(40) NOT NULL,
  `source` varchar(60) NOT NULL DEFAULT '',
  `title` varchar(300) NOT NULL,
  `summary` varchar(400) NOT NULL DEFAULT '',
  `url` varchar(500) NOT NULL,
  `tag` varchar(30) NOT NULL DEFAULT '',
  `published_at` datetime NOT NULL,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `uq_guid` (`guid_hash`),
  KEY `idx_pub` (`is_hidden`,`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `major_campus_heads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `major_campus_heads` (
  `campus_id` int(11) NOT NULL,
  `major_id` int(11) NOT NULL,
  `head_staff_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`campus_id`,`major_id`),
  KEY `major_id` (`major_id`),
  KEY `head_staff_id` (`head_staff_id`),
  CONSTRAINT `major_campus_heads_ibfk_1` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`campus_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `major_campus_heads_ibfk_2` FOREIGN KEY (`major_id`) REFERENCES `majors` (`major_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `major_campus_heads_ibfk_3` FOREIGN KEY (`head_staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `majors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `majors` (
  `major_id` int(11) NOT NULL AUTO_INCREMENT,
  `major_name` varchar(255) NOT NULL,
  `major_abbr` varchar(20) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`major_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `news`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `news` (
  `news_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `category_id` int(11) NOT NULL,
  `content` longtext NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `gallery_url` varchar(255) DEFAULT NULL,
  `status` enum('เผยแพร่','แบบร่าง') NOT NULL DEFAULT 'แบบร่าง',
  `tags` varchar(255) DEFAULT NULL,
  `view_count` int(11) NOT NULL DEFAULT 0,
  `slug` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`news_id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `fk_news_category` (`category_id`),
  KEY `fk_news_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_news_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_news_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `news_sdgs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `news_sdgs` (
  `news_id` int(11) NOT NULL,
  `sdg` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`news_id`,`sdg`),
  KEY `idx_sdg` (`sdg`,`news_id`),
  CONSTRAINT `fk_news_sdgs_news` FOREIGN KEY (`news_id`) REFERENCES `news` (`news_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `page_referrers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `page_referrers` (
  `ref_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `page_url` varchar(255) NOT NULL,
  `page_title` varchar(255) NOT NULL DEFAULT '',
  `page_type` varchar(20) NOT NULL DEFAULT 'other',
  `ref_url` varchar(500) NOT NULL,
  `ref_domain` varchar(190) NOT NULL,
  `visitor` char(16) NOT NULL DEFAULT '',
  PRIMARY KEY (`ref_id`),
  KEY `idx_viewed` (`viewed_at`),
  KEY `idx_domain` (`ref_domain`,`viewed_at`),
  KEY `idx_type` (`page_type`,`viewed_at`),
  KEY `idx_visitor` (`visitor`,`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `page_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `page_views` (
  `view_id` int(11) NOT NULL AUTO_INCREMENT,
  `page_url` varchar(255) NOT NULL,
  `page_title` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `new_visit` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`view_id`),
  KEY `idx_url` (`page_url`),
  KEY `idx_date` (`viewed_at`),
  KEY `idx_ip_date` (`ip_address`,`viewed_at`),
  KEY `idx_visit` (`new_visit`,`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `partners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `partners` (
  `partner_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `logo` varchar(255) NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `popup_ads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `popup_ads` (
  `popup_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `image` varchar(255) NOT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`popup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `positions` (
  `position_id` int(11) NOT NULL AUTO_INCREMENT,
  `position_name` varchar(255) NOT NULL,
  `position_level` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`position_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `program_campuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `program_campuses` (
  `program_id` int(11) NOT NULL,
  `campus_id` int(11) NOT NULL,
  PRIMARY KEY (`program_id`,`campus_id`),
  KEY `idx_campus` (`campus_id`),
  CONSTRAINT `fk_pc_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`campus_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pc_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `programs` (
  `program_id` int(11) NOT NULL AUTO_INCREMENT,
  `program_name` varchar(255) NOT NULL,
  `program_abbr` varchar(10) DEFAULT NULL,
  `about` text DEFAULT NULL,
  `overview` longtext DEFAULT NULL,
  `file` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `major_id` int(11) DEFAULT NULL,
  `level_id` int(11) NOT NULL,
  `campus_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`program_id`),
  KEY `fk_program_major` (`major_id`),
  KEY `fk_program_level` (`level_id`),
  KEY `fk_program_campus` (`campus_id`),
  CONSTRAINT `fk_program_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`campus_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_program_level` FOREIGN KEY (`level_id`) REFERENCES `education_levels` (`level_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_program_major` FOREIGN KEY (`major_id`) REFERENCES `majors` (`major_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `logo` varchar(255) DEFAULT NULL,
  `site_name` varchar(255) NOT NULL DEFAULT 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ',
  `site_abbr` varchar(30) NOT NULL DEFAULT 'BAIT',
  `meta_keywords` text DEFAULT NULL,
  `meta_desc` text DEFAULT NULL,
  `favicon` varchar(255) DEFAULT NULL,
  `footer_text` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `marquee_options` text DEFAULT NULL,
  `mourning_options` text DEFAULT NULL,
  `footer_bg` text DEFAULT NULL,
  `pageviews_removed` bigint(20) unsigned NOT NULL DEFAULT 0,
  `about_bg` text DEFAULT NULL,
  `visits_removed` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`setting_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff` (
  `staff_id` int(11) NOT NULL AUTO_INCREMENT,
  `prefix` varchar(20) NOT NULL DEFAULT 'นาย',
  `full_name` varchar(255) NOT NULL,
  `full_name_en` varchar(255) DEFAULT NULL,
  `name_en_verified` tinyint(1) NOT NULL DEFAULT 0,
  `position_id` int(11) NOT NULL,
  `responsibility` varchar(255) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `campus_id` int(11) NOT NULL,
  `major_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `staff_type_id` int(11) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `e_profile_url` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `work_status` varchar(10) NOT NULL DEFAULT 'active',
  `resigned_on` date DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `is_unit_head` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`staff_id`),
  KEY `fk_staff_position` (`position_id`),
  KEY `fk_staff_campus` (`campus_id`),
  KEY `fk_staff_major` (`major_id`),
  KEY `fk_staff_department` (`department_id`),
  KEY `fk_staff_type` (`staff_type_id`),
  KEY `idx_display_order` (`display_order`),
  KEY `fk_staff_supervisor` (`supervisor_id`),
  KEY `idx_work_status` (`work_status`),
  KEY `idx_unit` (`unit_id`),
  CONSTRAINT `fk_staff_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`campus_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_major` FOREIGN KEY (`major_id`) REFERENCES `majors` (`major_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `staff` (`staff_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_type` FOREIGN KEY (`staff_type_id`) REFERENCES `staff_types` (`staff_type_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_unit` FOREIGN KEY (`unit_id`) REFERENCES `staff_units` (`unit_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_counts` (
  `count_id` int(11) NOT NULL AUTO_INCREMENT,
  `campus_id` int(11) NOT NULL,
  `major_id` int(11) DEFAULT NULL,
  `department_id` int(11) NOT NULL,
  `staff_type_id` int(11) NOT NULL,
  `total` int(11) NOT NULL DEFAULT 0,
  `prev_total` int(11) DEFAULT NULL,
  `year` year(4) NOT NULL DEFAULT year(curdate()),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`count_id`),
  KEY `fk_stc_campus` (`campus_id`),
  KEY `fk_stc_major` (`major_id`),
  KEY `fk_stc_department` (`department_id`),
  KEY `fk_stc_type` (`staff_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_types` (
  `staff_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_type_name` varchar(255) NOT NULL,
  PRIMARY KEY (`staff_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_units` (
  `unit_id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_name` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`unit_id`),
  UNIQUE KEY `uq_unit_name` (`unit_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `student_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_counts` (
  `count_id` int(11) NOT NULL AUTO_INCREMENT,
  `campus_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `total` int(11) NOT NULL DEFAULT 0,
  `prev_total` int(11) DEFAULT NULL,
  `year` year(4) NOT NULL DEFAULT year(curdate()),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`count_id`),
  UNIQUE KEY `unique_count` (`campus_id`,`program_id`,`year`),
  KEY `fk_sc_campus` (`campus_id`),
  KEY `fk_sc_program` (`program_id`),
  CONSTRAINT `fk_sc_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`campus_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tags` (
  `tag_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`tag_id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `translation_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `translation_cache` (
  `cache_id` int(11) NOT NULL AUTO_INCREMENT,
  `source_hash` varchar(64) NOT NULL,
  `source_text` mediumtext NOT NULL,
  `target_lang` varchar(5) NOT NULL DEFAULT 'en',
  `translated` mediumtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cache_id`),
  UNIQUE KEY `unique_cache` (`source_hash`,`target_lang`),
  KEY `idx_lang` (`target_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tuition_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tuition_fees` (
  `fee_id` int(11) NOT NULL AUTO_INCREMENT,
  `program_id` int(11) NOT NULL,
  `campus_id` int(11) DEFAULT NULL,
  `schedule` varchar(120) NOT NULL DEFAULT '',
  `duration_years` decimal(3,1) DEFAULT NULL,
  `fee_type` varchar(10) NOT NULL DEFAULT 'lump',
  `term1` int(10) unsigned DEFAULT NULL,
  `term2` int(10) unsigned DEFAULT NULL,
  `summer` int(10) unsigned DEFAULT NULL,
  `total` int(10) unsigned DEFAULT NULL,
  `loan_fund` varchar(60) NOT NULL DEFAULT '',
  `note` varchar(255) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fee_id`),
  KEY `idx_program` (`program_id`,`sort_order`),
  KEY `idx_campus` (`campus_id`),
  CONSTRAINT `fk_tuition_campus` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`campus_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tuition_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tuition_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tuition_options` (
  `option_id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` varchar(10) NOT NULL,
  `value` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `uq_kind_value` (`kind`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tuition_page`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tuition_page` (
  `setting_id` tinyint(3) unsigned NOT NULL,
  `academic_year` varchar(20) NOT NULL DEFAULT '',
  `data_date` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(50) NOT NULL,
  `role` enum('superadmin','subadmin') NOT NULL DEFAULT 'subadmin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `session_version` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


SET FOREIGN_KEY_CHECKS = 1;

-- the single settings rows every page reads (edit them in the admin: ตั้งค่าเว็บไซต์ / ข้อมูลคณะ)
INSERT INTO site_settings (site_name, site_abbr) VALUES ('คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ', 'BAIT');
INSERT INTO faculty (faculty_name_th, faculty_name_en, faculty_abbr) VALUES ('คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ', 'Faculty of Business Administration and Information Technology', 'BAIT');
