-- BAIT → BA&IT: the faculty's short name as shown on the website (page titles "… | BA&IT RMUTSB", logos' alt text,
-- the "เกี่ยวกับคณะ" sub-heading). Run once in phpMyAdmin (SQL tab) on the bait database; running it again changes nothing.
-- Not touched: news hashtags (#BAIT cannot carry "&") and the "BAIT RUS" channel name.

UPDATE site_settings  SET site_abbr    = 'BA&IT' WHERE BINARY site_abbr    = 'BAIT';
UPDATE faculty        SET faculty_abbr = 'BA&IT' WHERE BINARY faculty_abbr = 'BAIT';
UPDATE about_sections SET subtitle = REPLACE(subtitle, 'การเรียนที่ BAIT ', 'การเรียนที่ BA&IT ') WHERE BINARY subtitle LIKE '%การเรียนที่ BAIT %';
-- keep "BAIT" in the search keywords too (people still search for it), just add the new spelling in front
UPDATE site_settings  SET meta_keywords = CONCAT('BA&IT,', meta_keywords) WHERE meta_keywords NOT LIKE '%BA&IT%';
