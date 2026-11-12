-- BAIT: second "ระเบียบการคัดเลือก" link on the tuition page (ป.โท – ป.เอก), next to the existing one (ปวช. – ป.ตรี).
-- Run once in phpMyAdmin (SQL tab) on the bait database. Safe to run again: it only adds the column when it is missing.
-- Until it is run the site keeps working and shows the one link it already had.

ALTER TABLE tuition_years
  ADD COLUMN IF NOT EXISTS admission_url_grad varchar(500) NOT NULL DEFAULT '' AFTER admission_url;
