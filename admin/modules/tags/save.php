<?php
/**
 * BAIT Admin - Tags (AJAX only, no list page)
 * Shared "word bank" behind the tag picker on news/articles create+edit
 * forms (admin/assets/js/tag-picker.js). Not a CRUD module with its own
 * index.php — just one endpoint: create a tag if it doesn't already exist,
 * or return the existing one. `name` is case-insensitive-unique (the
 * `tags` table uses utf8mb4_unicode_ci) so "AI" and "ai" resolve to the
 * same row instead of silently multiplying near-duplicates.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
verifyCsrf();

$name = postStr('name');
if ($name === '') jsonError('กรุณากรอกชื่อแท็ก');
if (mb_strlen($name, 'UTF-8') > 100) jsonError('ชื่อแท็กยาวเกินไป (ไม่เกิน 100 ตัวอักษร)');
// news/articles keep their tags as "a, b, c": a comma inside one tag would split it into two tags there
if (preg_match('/[,，]/u', $name)) jsonError('ชื่อแท็กมีเครื่องหมายจุลภาค (,) ไม่ได้ เพราะระบบใช้ , คั่นแต่ละแท็ก หากต้องการ 2 แท็กให้เพิ่มทีละแท็ก');

$existing = $db->fetchOne("SELECT tag_id, name FROM tags WHERE name = ?", [$name]);
if ($existing) {
    jsonSuccess(['tag_id' => (int)$existing['tag_id'], 'name' => $existing['name']], 'มีแท็กนี้อยู่แล้ว');
}

$id = $db->insert('tags', ['name' => $name]);
jsonSuccess(['tag_id' => $id, 'name' => $name], 'เพิ่มแท็กใหม่แล้ว');
