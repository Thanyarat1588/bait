-- BAIT: slim banner slideshow at the top of the E-Bidding page (admin: E-Bidding > แบนเนอร์ด้านบน), 5:1 pictures.
-- Separate from ebidding_banners (the small "เว็บไซต์ที่เกี่ยวข้อง" link tiles). Same columns.
-- Run once in phpMyAdmin (SQL tab); safe to run again. Until it is run the page simply shows no top banner.

CREATE TABLE IF NOT EXISTS ebidding_top_banners (
  banner_id     int(11) NOT NULL AUTO_INCREMENT,
  image         varchar(255) NOT NULL,
  title         varchar(255) DEFAULT NULL,
  link_url      varchar(500) DEFAULT NULL,
  display_order int(11) NOT NULL DEFAULT 0,
  is_active     tinyint(1) NOT NULL DEFAULT 1,
  created_at    timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (banner_id),
  KEY idx_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
