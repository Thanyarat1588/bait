<?php
/**
 * BAIT Admin - News Management (Index)
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// Filter params
$search   = getStr('search');
$catId    = (int)($_GET['cat']    ?? 0);
$status   = getStr('status');
// SDGs, several can be ticked (?sdg[]=4&sdg[]=8&sdg[]=none): a news item matches when it has ANY of the ticked goals;
// 'none' = news without SDGs (fine: not every news item needs one). An old single ?sdg=4 link still works. Only once news_sdgs exists.
$sdgSel = [];
if (hasNewsSdgs()) {
    foreach ((array)($_GET['sdg'] ?? []) as $v) {
        if (!is_string($v)) continue;   // a crafted sdg[0][]=4 is ignored, not an "Array to string" warning
        $v = trim($v);
        if ($v === 'none' || (ctype_digit($v) && isset(sdgList()[(int)$v]))) $sdgSel[$v === 'none' ? 'none' : (int)$v] = true;   // "4abc" is not goal 4
    }
    $sdgSel = array_keys($sdgSel);
    usort($sdgSel, fn($a, $b) => ($a === 'none' ? 99 : $a) <=> ($b === 'none' ? 99 : $b));
}
$sdgGoals = array_values(array_filter($sdgSel, 'is_int'));
// ช่วงเวลา: the news date (created_at), both ends included, picked on a calendar; a reversed range is swapped
$validDay = fn(string $d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && $m[1] >= 2000 && $m[1] <= 2100 ? $d : '';
$dateFrom = $validDay(getStr('from'));
$dateTo   = $validDay(getStr('to'));
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
// the current filters as a query string (paging links, Excel export)
$filterQs = function (array $extra = []) use (&$search, &$catId, &$status, &$sdgSel, &$dateFrom, &$dateTo): string {
    return http_build_query(array_filter(['search' => $search, 'cat' => $catId ?: '', 'status' => $status, 'sdg' => $sdgSel ?: '', 'from' => $dateFrom, 'to' => $dateTo], fn($v) => $v !== '') + $extra);
};
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;

// Build WHERE
$where  = '1=1';
$params = [];
if ($search) {
    $where   .= ' AND n.title LIKE ?';
    $params[] = "%$search%";
}
if ($catId) {
    $where   .= ' AND n.category_id = ?';
    $params[] = $catId;
}
if ($status) {
    $where   .= ' AND n.status = ?';
    $params[] = $status;
}
if ($sdgSel) {
    $or = [];
    if (in_array('none', $sdgSel, true)) $or[] = 'NOT EXISTS (SELECT 1 FROM news_sdgs s WHERE s.news_id = n.news_id)';
    if ($sdgGoals) {
        $or[] = 'EXISTS (SELECT 1 FROM news_sdgs s WHERE s.news_id = n.news_id AND s.sdg IN (' . implode(',', array_fill(0, count($sdgGoals), '?')) . '))';
        array_push($params, ...$sdgGoals);
    }
    $where .= ' AND (' . implode(' OR ', $or) . ')';
}
if ($dateFrom !== '') { $where .= ' AND n.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '')   { $where .= ' AND n.created_at < ?';  $params[] = date('Y-m-d', strtotime($dateTo . ' +1 day')) . ' 00:00:00'; }

$total = (int)($db->fetchOne(
    "SELECT COUNT(*) as c FROM news n WHERE $where", $params
)['c'] ?? 0);

$pager = paginate($total, $perPage, $page, '?' . $filterQs() . '&page={page}');

$newsList = $db->fetchAll(
    "SELECT n.news_id, n.title, n.slug, n.status, n.view_count, n.created_at, n.image,
            c.category_name, c.color AS category_color, u.full_name
     FROM news n
     JOIN categories c ON c.category_id = n.category_id
     JOIN users u ON u.user_id = n.user_id
     WHERE $where
     ORDER BY n.created_at DESC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
    $params
);

$categories  = $db->fetchAll("SELECT * FROM categories ORDER BY category_name");
$newsList    = attachNewsSdgs($newsList);
// how many news have no goal ticked yet / how many per goal (for the SDG filter)
$sdgNone = 0; $sdgCount = [];
if (hasNewsSdgs()) {
    $sdgNone = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM news n WHERE NOT EXISTS (SELECT 1 FROM news_sdgs s WHERE s.news_id = n.news_id)')['c'] ?? 0);
    foreach ($db->fetchAll('SELECT sdg, COUNT(*) AS c FROM news_sdgs GROUP BY sdg') as $r) $sdgCount[(int)$r['sdg']] = (int)$r['c'];
}

// Flash delete
if (isset($_GET['deleted'])) $_SESSION['flash_success'] = 'ลบข่าวสารเรียบร้อยแล้ว';

// ── Excel export (?export=xlsx + the same filters): the news list with their SDGs, and how many news each goal has ──
if (getStr('export') === 'xlsx') {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';
    $be   = fn(?string $v) => $v ? date('d/m/', strtotime($v)) . (date('Y', strtotime($v)) + 543) : '';
    $list = sdgList();
    $cap  = 20000;
    $all  = attachNewsSdgs($db->fetchAll(
        "SELECT n.news_id, n.title, n.status, n.view_count, n.created_at, c.category_name
         FROM news n JOIN categories c ON c.category_id = n.category_id WHERE $where ORDER BY n.created_at DESC LIMIT " . ($cap + 1), $params));
    $total = count($all);   // more than the cap: the file says so (summary + both lists) instead of silently stopping
    if ($total > $cap) {
        $total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM news n JOIN categories c ON c.category_id = n.category_id WHERE $where", $params)['c'] ?? 0);
        $all = array_slice($all, 0, $cap);
    }
    $cutMsg = $total > count($all) ? '⚠ แสดงเพียง ' . number_format(count($all)) . ' ข่าวล่าสุด จากทั้งหมด ' . number_format($total) . ' ข่าว — ตัวเลขในไฟล์นี้นับจากข่าวที่แสดงเท่านั้น เลือกตัวกรองให้แคบลงแล้วส่งออกอีกครั้ง' : '';
    $catName = '';
    foreach ($categories as $cat) if ((int)$cat['category_id'] === $catId) $catName = $cat['category_name'];
    $sdgLabel = $sdgSel ? implode(', ', array_map(fn($g) => $g === 'none' ? 'ไม่มี SDGs' : 'SDGs ' . $g . ' ' . $list[$g][1], $sdgSel)) : 'ทุกข่าว';
    $rangeLabel = $dateFrom === '' && $dateTo === '' ? 'ทุกช่วงเวลา'
        : ($dateFrom !== '' ? thaiDate($dateFrom) : 'ข่าวแรกสุด') . ' – ' . ($dateTo !== '' ? thaiDate($dateTo) : 'ปัจจุบัน');
    $x = new SimpleXlsx();

    $perGoal = array_fill_keys(array_keys($list), ['all' => 0, 'pub' => 0]);
    $withSdg = 0;
    foreach ($all as $r) {
        if ($r['sdgs']) $withSdg++;
        foreach ($r['sdgs'] as $g) { $perGoal[$g]['all']++; if ($r['status'] === 'เผยแพร่') $perGoal[$g]['pub']++; }
    }
    // the period on top, so a printed / forwarded report always says which time it covers
    $rows = [['รายงานข่าวตาม SDGs ช่วงเวลา: ' . $rangeLabel, '', '', '', ''], ['', '', '', '', ''],
             ['SDGs', 'ชื่อเป้าหมาย', 'เป้าหมาย (ฉบับเต็ม)', 'จำนวนข่าว', 'เผยแพร่แล้ว']];
    // when goals are ticked, only those goals are listed (the other goals' counts would only be "also tagged")
    foreach ($list as $no => $g) if (!$sdgGoals || in_array($no, $sdgGoals, true)) $rows[] = ['SDGs ' . $no, $g[1], $g[3], $perGoal[$no]['all'], $perGoal[$no]['pub']];
    $rows[] = ['', '', '', '', ''];
    $rows[] = ['ข่าวทั้งหมดตามตัวกรอง', '', '', $total, ''];
    if ($cutMsg !== '') $rows[] = [$cutMsg, '', '', count($all), ''];
    $rows[] = ['ข่าวที่มี SDGs', '', '', $withSdg, ''];
    $rows[] = ['ข่าวที่ไม่มี SDGs', '', '', count($all) - $withSdg, ''];
    $rows[] = ['ตัวกรอง', 'ค้นหา: ' . ($search !== '' ? $search : '-') . ' · ประเภท: ' . ($catName !== '' ? $catName : 'ทุกประเภท') . ' · สถานะ: ' . ($status !== '' ? $status : 'ทุกสถานะ') . ' · SDGs: ' . $sdgLabel . ' · ช่วงเวลา: ' . $rangeLabel, '', '', ''];
    $rows[] = ['ส่งออกเมื่อ', $be(date('Y-m-d')) . ' ' . date('H:i'), '', '', ''];
    $x->addSheet('สรุปตาม SDGs', $rows, [26, 40, 90, 14, 14]);

    $rows = [['#', 'หัวข้อข่าว', 'ประเภท', 'สถานะ', 'วันที่', 'เข้าชม', 'SDGs', 'ชื่อเป้าหมาย SDGs', 'ลิงก์']];
    foreach ($all as $i => $r) $rows[] = [
        $i + 1, (string)$r['title'], (string)$r['category_name'], (string)$r['status'], $be($r['created_at']), (int)$r['view_count'],
        $r['sdgs'] ? implode(', ', array_map(fn($g) => 'SDGs ' . $g, $r['sdgs'])) : '-',
        implode(' / ', array_map(fn($g) => $list[$g][1], $r['sdgs'])),
        BASE_URL_ABSOLUTE . '/news-detail.php?id=' . (int)$r['news_id'],
    ];
    if ($cutMsg !== '') $rows[] = ['', $cutMsg, '', '', '', '', '', '', ''];
    $x->addSheet('รายการข่าว', $rows, [6, 60, 22, 12, 14, 10, 24, 60, 50]);

    // one row per news + goal: easy to filter / pivot in Excel
    $rows = [['SDGs', 'ชื่อเป้าหมาย', 'หัวข้อข่าว', 'ประเภท', 'สถานะ', 'วันที่']];
    foreach ($list as $no => $g) if (!$sdgGoals || in_array($no, $sdgGoals, true)) foreach ($all as $r) if (in_array($no, $r['sdgs'], true))
        $rows[] = ['SDGs ' . $no, $g[1], (string)$r['title'], (string)$r['category_name'], (string)$r['status'], $be($r['created_at'])];
    if ($cutMsg !== '') $rows[] = ['', '', $cutMsg, '', '', ''];
    $x->addSheet('ข่าวแยกตาม SDGs', $rows, [12, 40, 60, 22, 12, 14]);

    $x->download('bait-news-sdgs-' . ($dateFrom !== '' || $dateTo !== '' ? str_replace('-', '', $dateFrom ?: 'start') . '-' . str_replace('-', '', $dateTo ?: 'now') : date('Ymd')) . '.xlsx');
}

// แปลงสี hex ของประเภทข่าวเป็นสไตล์ chip (พื้นอ่อน 12% + ตัวอักษรเป็นสีเข้ม)
function categoryChipStyle(?string $hex): string {
    $hex = $hex ?: '#162E93';
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = '3e5fa3';
    return "background:#{$hex}1f;color:#{$hex};box-shadow:inset 0 0 0 1px #{$hex}33;";
}

$pageTitle   = 'จัดการข่าวสาร';
$activeMenu  = 'news';
$breadcrumb  = [['label' => 'จัดการข่าวสาร']];
$pageActions = ''; // ปุ่มเพิ่มข่าวย้ายไปอยู่ใน topbar ที่ออกแบบใหม่ด้านล่างแทน
include BASE_PATH . '/admin/layout/header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
.ne {
  --ne-ink: #1e2a45;
  --ne-ink-soft: #5b6480;
  --ne-paper: #f5f6fa;
  --ne-surface: #ffffff;
  --ne-line: #e3e6ee;
  --ne-gold: #FBC02D;
  --ne-blue: #162E93;
  --ne-blue-deep: #11236E;
  --ne-blue-soft: #ECEEF6;
  --ne-gold-soft: #f7f1e3;
  --ne-green: #3e8564;
  --ne-green-soft: #e5f2eb;
  --ne-slate: #8b93a7;
  --ne-slate-soft: #eef0f5;
  --ne-danger: #c1443d;
  --ne-danger-soft: #fbeae9;
  font-family: 'Kanit', sans-serif;
  color: var(--ne-ink);
}
.ne * { box-sizing: border-box; }

/* ── Top bar ───────────────────────────────── */
.ne-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 22px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--ne-line);
}
.ne-topbar-eyebrow {
  font-size: 12.5px;
  letter-spacing: .08em;
  color: var(--ne-slate);
  text-transform: uppercase;
  margin: 0 0 4px;
  font-weight: 600;
}
.ne-topbar h1 {
  font-family: 'Kanit', sans-serif;
  font-size: 26px;
  font-weight: 700;
  margin: 0;
  color: var(--ne-ink);
  display: flex;
  align-items: baseline;
  gap: 10px;
}
.ne-topbar h1 .ne-count {
  font-family: 'Kanit', sans-serif;
  font-size: 14px;
  font-weight: 600;
  color: var(--ne-gold);
  background: var(--ne-gold-soft);
  border-radius: 999px;
  padding: 3px 11px;
}
.ne-btn {
  font-family: 'Kanit', sans-serif;
  font-weight: 600;
  font-size: 14.5px;
  border-radius: 9px;
  padding: 10px 18px;
  border: 1px solid transparent;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  cursor: pointer;
  text-decoration: none;
  transition: all .15s ease;
  line-height: 1;
}
.ne-btn-primary { background: var(--ne-blue); color: #fff; }
.ne-btn-primary:hover { background: var(--ne-blue-deep); color: #fff; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }

.ne-input, .ne-select {
  width: 100%;
  border: 1px solid var(--ne-line);
  border-radius: 8px;
  padding: 9px 12px;
  font-family: 'Kanit', sans-serif;
  font-size: 14px;
  color: var(--ne-ink);
  background: var(--ne-surface);
  outline: none;
  transition: border-color .15s ease;
}
.ne-input:focus, .ne-select:focus { border-color: var(--ne-blue); }
.ne-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%235b6480' stroke-width='1.6' fill='none' fill-rule='evenodd'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }
.ne-search-input-wrap { position: relative; }
.ne-search-input-wrap i {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  color: var(--ne-slate); font-size: 14px; pointer-events: none;
}
.ne-search-input-wrap input { padding-left: 34px; }
/* SDGs multi-select: a dropdown-looking summary that opens a list of checkboxes */
.ne-ms { position: relative; }
.ne-ms > summary { list-style: none; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ne-ms > summary::-webkit-details-marker { display: none; }
.ne-ms-panel {
  position: absolute; z-index: 30; left: 0; top: calc(100% + 6px); width: min(360px, calc(100vw - 48px));
  max-height: 60vh; overflow-y: auto; background: var(--ne-surface); border: 1px solid var(--ne-line);
  border-radius: 12px; box-shadow: 0 12px 32px rgba(15, 23, 42, .16); padding: 6px;
}
.ne-ms-panel.is-right { left: auto; right: 0; }
.ne-ms-note { margin: 2px 6px 6px; font-size: 11.5px; color: var(--ne-slate); line-height: 1.5; }
.ne-filters-field .ne-ms-opt { display: flex; align-items: center; gap: 8px; padding: 7px 8px; border-radius: 8px; cursor: pointer; font-size: 13.5px; font-weight: 400; letter-spacing: 0; text-transform: none; color: var(--ne-ink); margin: 0; }
.ne-ms-opt:hover { background: var(--ne-paper); }
.ne-ms-opt span:nth-of-type(2) { flex: 1 1 auto; min-width: 0; }
.ne-ms-opt b { font-weight: 600; font-size: 12px; color: var(--ne-slate); }
.ne-ms-dot { flex: 0 0 12px; height: 12px; border-radius: 3px; }
.ne-ms-foot { position: sticky; bottom: -6px; display: flex; gap: 8px; justify-content: flex-end; background: var(--ne-surface); padding: 8px 4px 4px; border-top: 1px solid var(--ne-line); margin-top: 4px; }
/* ช่วงเวลา */
.ne-range-inputs { display: flex; align-items: center; gap: 6px; }
.ne-range-inputs .ne-input { flex: 1 1 0; min-width: 0; }
.ne-range-quick { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; }
.ne-filters-field .ne-fy { width: auto; padding: 3px 28px 3px 10px; font-size: 12px; border-radius: 999px; background-position: right 10px center; }
.ne-chip { font: inherit; font-size: 12px; border: 1px solid var(--ne-line); background: var(--ne-surface); color: var(--ne-ink-soft); border-radius: 999px; padding: 3px 10px; cursor: pointer; white-space: nowrap; }
.ne-chip:hover { border-color: var(--ne-blue); color: var(--ne-blue); }
.ne-chip.is-on { background: var(--ne-blue); border-color: var(--ne-blue); color: #fff; }
.ne-range-now { margin: 6px 0 0; font-size: 12.5px; color: var(--ne-blue-deep); }

/* ── Table panel ──────────────────────────── */
.ne-panel {
  background: var(--ne-surface);
  border: 1px solid var(--ne-line);
  border-radius: 14px;
  overflow: hidden;
}
.ne-panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  border-bottom: 1px solid var(--ne-line);
  font-size: 13.5px;
  font-weight: 600;
  color: var(--ne-ink-soft);
}
.ne-panel-count {
  display: inline-flex;
  align-items: center;
  font-size: 12px;
  font-weight: 700;
  padding: 2px 9px;
  border-radius: 999px;
  background: var(--ne-gold-soft);
  color: #8a611f;
  margin-left: 4px;
}
.ne-table { width: 100%; border-collapse: collapse; }
.ne-table thead th {
  text-align: left;
  font-size: 11.5px;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--ne-slate);
  font-weight: 600;
  padding: 12px 20px;
  background: var(--ne-paper);
  border-bottom: 1px solid var(--ne-line);
  white-space: nowrap;
}
.ne-table tbody tr {
  border-bottom: 1px solid var(--ne-line);
  transition: background .12s ease;
}
.ne-table tbody tr:last-child { border-bottom: none; }
.ne-table tbody tr:hover { background: var(--ne-paper); }
.ne-table td { padding: 12px 20px; vertical-align: middle; font-size: 14px; }
.ne-table td.ne-num { color: var(--ne-slate); font-size: 13px; }
.ne-sdgs { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
.ne-sdg { display: inline-block; font-size: 11px; font-weight: 700; line-height: 1; padding: 4px 7px; border-radius: 5px; }

.ne-thumb {
  width: 46px; height: 46px; border-radius: 9px; object-fit: cover;
  cursor: zoom-in; border: 1px solid var(--ne-line); display: block;
  transition: transform .15s ease;
}
.ne-thumb:hover { transform: scale(1.06); }
.ne-thumb-empty {
  width: 46px; height: 46px; border-radius: 9px; background: var(--ne-slate-soft);
  display: flex; align-items: center; justify-content: center; color: var(--ne-slate); font-size: 16px;
}

.ne-title-link {
  font-weight: 600; color: var(--ne-ink); text-decoration: none;
  font-family: 'Kanit', sans-serif; font-size: 15px; line-height: 1.4;
}
.ne-title-link:hover { color: var(--ne-blue); }
.ne-byline { font-size: 12px; color: var(--ne-slate); margin-top: 2px; }

.ne-chip {
  display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 4px 10px;
  border-radius: 999px; white-space: nowrap;
}
.ne-chip::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.ne-badge {
  display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600;
  padding: 4px 11px; border-radius: 999px; white-space: nowrap;
}
.ne-badge-published { background: var(--ne-green-soft); color: #1f6b47; }
.ne-badge-draft { background: var(--ne-slate-soft); color: var(--ne-ink-soft); }
.ne-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

.ne-views { display: inline-flex; align-items: center; gap: 5px; color: var(--ne-ink-soft); font-size: 13px; }

.ne-actions { display: flex; gap: 6px; }
.ne-icon-btn {
  width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--ne-line);
  background: var(--ne-surface); color: var(--ne-ink-soft);
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer; text-decoration: none; transition: all .15s ease; font-size: 14px;
}
.ne-icon-btn:hover { background: var(--ne-paper) !important; color: var(--ne-ink) !important; border-color: var(--ne-ink) !important; }
.ne-icon-btn.ne-icon-view { color: #1f6b47 !important; border-color: #bfe2cd !important; background: var(--ne-green-soft) !important; }
.ne-icon-btn.ne-icon-view:hover { background: #1f6b47 !important; border-color: #1f6b47 !important; color: #fff !important; }
.ne-icon-btn.ne-icon-edit { color: var(--ne-blue) !important; border-color: #c3d9fb !important; background: var(--ne-blue-soft) !important; }
.ne-icon-btn.ne-icon-edit:hover { background: var(--ne-blue) !important; border-color: var(--ne-blue) !important; color: #fff !important; }
.ne-icon-btn.ne-icon-delete { color: var(--ne-danger) !important; border-color: #f0c9c6 !important; background: var(--ne-danger-soft) !important; }
.ne-icon-btn.ne-icon-delete:hover { background: var(--ne-danger) !important; border-color: var(--ne-danger) !important; color: #fff !important; }
.ne-icon-btn i { color: inherit !important; }

.ne-empty { text-align: center; padding: 64px 20px; color: var(--ne-slate); }
.ne-empty i { font-size: 34px; display: block; margin-bottom: 10px; color: var(--ne-slate-soft); }
.ne-empty i.bi { color: var(--ne-line); }

.ne-footer {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
  padding: 14px 20px; border-top: 1px solid var(--ne-line); font-size: 13px; color: var(--ne-slate);
}
.ne-footer .pagination { margin: 0; }
.ne-footer .page-link {
  border: 1px solid var(--ne-line); color: var(--ne-ink-soft); font-size: 13px;
  border-radius: 7px !important; margin-left: 4px; padding: 5px 11px;
}
.ne-footer .page-item.active .page-link { background: var(--ne-blue); border-color: var(--ne-blue); color: #fff; }
.ne-footer .page-item.disabled .page-link { color: var(--ne-ink-soft); background: var(--ne-paper); }

/* ── Lightbox ─────────────────────────────── */
.ne-lightbox {
  position: fixed; inset: 0; background: rgba(15,20,34,.82);
  display: none; align-items: center; justify-content: center; z-index: 2000; padding: 40px;
}
.ne-lightbox.active { display: flex; }
.ne-lightbox img { max-width: min(720px, 92vw); max-height: 84vh; border-radius: 10px; box-shadow: 0 20px 60px rgba(0,0,0,.4); }
.ne-lightbox-close {
  position: absolute; top: 22px; right: 28px; width: 38px; height: 38px; border-radius: 50%;
  background: rgba(255,255,255,.12); color: #fff; border: none; font-size: 18px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
}
.ne-lightbox-close:hover { background: rgba(255,255,255,.22); }
</style>

<div class="ne">
  <div class="ne-topbar">
    <div style="min-width:0;flex:1 1 auto;">
      <h1>ข่าวสารทั้งหมด <span class="ne-count"><?= number_format($total) ?> รายการ</span></h1>
    </div>
    <div style="margin-left:auto;display:flex;flex-wrap:wrap;gap:8px;">
      <a href="?<?= e($filterQs(['export' => 'xlsx'])) ?>" class="ne-btn ne-btn-excel" title="ส่งออกรายงาน SDGs ของข่าวตามตัวกรองและช่วงเวลาที่เลือกเป็นไฟล์ Excel">
        <i class="bi bi-file-earmark-excel"></i>ส่งออกรายงาน SDGs (Excel)
      </a>
      <a href="<?= BASE_URL ?>/admin/modules/news/create.php" class="ne-btn ne-btn-primary">
        <i class="bi bi-plus-lg"></i>เพิ่มข่าวสาร
      </a>
    </div>
  </div>

  <!-- Filters -->
  <form class="ne-filters" method="GET">
    <div class="ne-filters-field ne-search">
      <label for="fSearch">ค้นหา</label>
      <div class="ne-search-input-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="fSearch" class="ne-input" name="search"
               placeholder="ค้นหาหัวข้อข่าว..." value="<?= e($search) ?>">
      </div>
    </div>
    <div class="ne-filters-field">
      <label for="fCat">ประเภท</label>
      <select id="fCat" class="ne-select" name="cat">
        <option value="">-- ทุกประเภท --</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['category_id'] ?>" <?= $catId == $cat['category_id'] ? 'selected' : '' ?>>
          <?= e($cat['category_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field">
      <label for="fStatus">สถานะ</label>
      <select id="fStatus" class="ne-select" name="status">
        <option value="">-- ทุกสถานะ --</option>
        <option value="เผยแพร่" <?= $status === 'เผยแพร่' ? 'selected' : '' ?>>เผยแพร่</option>
        <option value="แบบร่าง" <?= $status === 'แบบร่าง' ? 'selected' : '' ?>>แบบร่าง</option>
      </select>
    </div>
    <?php if (hasNewsSdgs()): ?>
    <div class="ne-filters-field">
      <span class="ne-flabel" id="fSdgLabel">SDGs <small>(เลือกได้หลายข้อ)</small></span>
      <details class="ne-ms" id="fSdg">
        <summary class="ne-select" aria-labelledby="fSdgLabel"><span data-ms-text><?= $sdgSel ? 'เลือกแล้ว ' . count($sdgSel) . ' รายการ' : 'ทุกข่าว' ?></span></summary>
        <div class="ne-ms-panel">
          <p class="ne-ms-note">ตัวเลขด้านขวา = จำนวนข่าวทั้งหมดของแต่ละข้อ (ไม่ได้นับตามช่วงเวลา/ตัวกรองอื่น)</p>
          <label class="ne-ms-opt"><input type="checkbox" name="sdg[]" value="none" <?= in_array('none', $sdgSel, true) ? 'checked' : '' ?>><span class="ne-ms-dot" style="background:#e5e7eb"></span><span>ไม่มี SDGs</span><b><?= $sdgNone ?></b></label>
          <?php foreach (sdgList() as $no => $g): ?>
          <label class="ne-ms-opt"><input type="checkbox" name="sdg[]" value="<?= $no ?>" <?= in_array($no, $sdgSel, true) ? 'checked' : '' ?>><span class="ne-ms-dot" style="background:<?= e($g[0]) ?>"></span><span>SDGs <?= $no ?> <?= e($g[1]) ?></span><b><?= $sdgCount[$no] ?? 0 ?></b></label>
          <?php endforeach; ?>
          <div class="ne-ms-foot">
            <button type="button" class="ne-btn ne-btn-ghost" data-ms-clear>ล้างที่เลือก</button>
            <button type="submit" class="ne-btn ne-btn-primary">ใช้ตัวกรอง</button>
          </div>
        </div>
      </details>
    </div>
    <?php endif; ?>
    <div class="ne-filters-field ne-range">
      <span class="ne-flabel">ช่วงเวลา (วันที่ข่าว)</span>
      <div class="ne-range-inputs">
        <input type="date" class="ne-input" name="from" id="fFrom" value="<?= e($dateFrom) ?>" aria-label="ตั้งแต่วันที่" max="<?= e($dateTo) ?>">
        <span aria-hidden="true">–</span>
        <input type="date" class="ne-input" name="to" id="fTo" value="<?= e($dateTo) ?>" aria-label="ถึงวันที่" min="<?= e($dateFrom) ?>">
      </div>
      <div class="ne-range-quick">
        <?php
        // ปีงบประมาณ = 1 Oct – 30 Sep, named by the year it ends in (พ.ศ.): every year from the oldest news up to the
        // current one, newest first, so a new year shows up by itself on 1 October
        $fyOf  = fn(string $ymd) => (int)substr($ymd, 5, 2) >= 10 ? (int)substr($ymd, 0, 4) + 1 : (int)substr($ymd, 0, 4);
        $fyNow = $fyOf(date('Y-m-d'));
        $oldest = (string)($db->fetchOne('SELECT MIN(created_at) AS d FROM news')['d'] ?? '');
        $fyOld = $oldest !== '' ? min($fyOf(substr($oldest, 0, 10)), $fyNow) : $fyNow;
        ?>
        <select class="ne-select ne-fy" id="fFy" aria-label="ปีงบประมาณ">
          <option value="">ปีงบประมาณ…</option>
          <?php for ($y = $fyNow; $y >= $fyOld; $y--): $a = ($y - 1) . '-10-01'; $b = $y . '-09-30'; ?>
          <option value="<?= $a ?>|<?= $b ?>" <?= $dateFrom === $a && $dateTo === $b ? 'selected' : '' ?>>ปีงบ <?= $y + 543 ?></option>
          <?php endfor; ?>
        </select>
        <?php
        $quick = [
            'ปี ' . ((int)date('Y') + 543) => [date('Y') . '-01-01', date('Y') . '-12-31'],
            '30 วันล่าสุด'                  => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
        ];
        foreach ($quick as $lab => [$a, $b]): ?>
        <button type="button" class="ne-chip<?= $dateFrom === $a && $dateTo === $b ? ' is-on' : '' ?>" data-range="<?= $a ?>|<?= $b ?>"><?= e($lab) ?></button>
        <?php endforeach; ?>
      </div>
      <?php if ($dateFrom !== '' || $dateTo !== ''): ?>
      <p class="ne-range-now"><i class="bi bi-calendar-range"></i> <?= $dateFrom !== '' ? thaiDate($dateFrom) : 'ข่าวแรกสุด' ?> – <?= $dateTo !== '' ? thaiDate($dateTo) : 'ปัจจุบัน' ?></p>
      <?php endif; ?>
    </div>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-funnel"></i>ค้นหา</button>
      <a href="?" class="ne-btn ne-btn-ghost">ล้าง</a>
    </div>
  </form>

  <!-- Table -->
  <div class="ne-panel">
    <div class="ne-panel-head">
      <span>ข่าวสารทั้งหมด</span>
      <span>หน้า <?= $pager['current_page'] ?> / <?= max(1, $pager['total_pages']) ?></span>
    </div>

    <?php if (!empty($newsList)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th style="width:64px">ภาพ</th>
            <th>หัวข้อข่าว</th>
            <th style="width:150px">ประเภท</th>
            <th style="width:110px">สถานะ</th>
            <th style="width:90px">เข้าชม</th>
            <th style="width:120px">วันที่</th>
            <th style="width:130px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($newsList as $i => $n): ?>
          <?php
            $viewUrl = !empty($n['slug'])
                ? BASE_URL . '/news.php?slug=' . urlencode($n['slug'])
                : BASE_URL . '/news.php?id=' . $n['news_id'];
          ?>
          <tr>
            <td class="ne-num"><?= $pager['offset'] + $i + 1 ?></td>
            <td>
              <?php if ($n['image']): ?>
              <img src="<?= UPLOAD_URL . '/' . e($n['image']) ?>" alt="" class="ne-thumb"
                   onclick="neOpenLightbox('<?= UPLOAD_URL . '/' . e($n['image']) ?>')">
              <?php else: ?>
              <div class="ne-thumb-empty"><i class="bi bi-image"></i></div>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= BASE_URL ?>/admin/modules/news/edit.php?id=<?= $n['news_id'] ?>" class="ne-title-link">
                <?= e(mb_strimwidth($n['title'], 0, 60, '...', 'UTF-8')) ?>
              </a>
              <div class="ne-byline">โดย <?= e($n['full_name']) ?></div>
              <?php // not every news item needs SDGs: those without show nothing here (no "not chosen yet" warning)
              if (hasNewsSdgs() && $n['sdgs']): ?>
              <div class="ne-sdgs">
                <?php foreach ($n['sdgs'] as $no): $g = sdgList()[$no]; ?>
                <span class="ne-sdg" style="background:<?= $g[0] ?>;color:<?= inkOn($g[0]) ?>" title="SDGs <?= $no ?>: <?= e($g[1]) ?>">SDGs <?= $no ?></span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </td>
            <td><span class="ne-chip" style="<?= categoryChipStyle($n['category_color'] ?? null) ?>"><?= e($n['category_name']) ?></span></td>
            <td>
              <?php if ($n['status'] === 'เผยแพร่'): ?>
                <span class="ne-badge ne-badge-published">เผยแพร่</span>
              <?php else: ?>
                <span class="ne-badge ne-badge-draft">แบบร่าง</span>
              <?php endif; ?>
            </td>
            <td><span class="ne-views"><i class="bi bi-eye"></i><?= number_format($n['view_count']) ?></span></td>
            <td><small class="text-muted"><?= thaiDate($n['created_at']) ?></small></td>
            <td>
              <div class="ne-actions">
                <a href="<?= e($viewUrl) ?>" target="_blank" rel="noopener" class="ne-icon-btn ne-icon-view" title="ดูข่าว">
                  <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <a href="<?= BASE_URL ?>/admin/modules/news/edit.php?id=<?= $n['news_id'] ?>" class="ne-icon-btn ne-icon-edit" title="แก้ไข">
                  <i class="bi bi-pencil"></i>
                </a>
                <button class="ne-icon-btn ne-icon-delete" title="ลบ"
                  onclick="confirmDelete('<?= BASE_URL ?>/admin/modules/news/delete.php?id=<?= $n['news_id'] ?>',
                  '<?= e(addslashes($n['title'])) ?>')">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pager['total_pages'] > 1): ?>
    <div class="ne-footer">
      <span>แสดง <?= $pager['offset'] + 1 ?>–<?= min($pager['offset'] + $perPage, $total) ?> จาก <?= number_format($total) ?> รายการ</span>
      <?= paginationHtml($pager) ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="ne-empty">
      <i class="bi bi-newspaper"></i>
      ไม่พบข่าวสาร<?= $search ? ' ที่ตรงกับ "' . e($search) . '"' : '' ?>
      <div class="mt-2">
        <a href="<?= BASE_URL ?>/admin/modules/news/create.php" class="ne-btn ne-btn-primary" style="display:inline-flex;margin-top:10px;">
          <i class="bi bi-plus-lg"></i>เพิ่มข่าวสารแรกของคุณ
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Lightbox -->
<div class="ne-lightbox" id="neLightbox" onclick="neCloseLightbox(event)">
  <button class="ne-lightbox-close" onclick="neCloseLightbox(event)"><i class="bi bi-x-lg"></i></button>
  <img id="neLightboxImg" src="" alt="">
</div>

<script>
function neOpenLightbox(src) {
  document.getElementById('neLightboxImg').src = src;
  document.getElementById('neLightbox').classList.add('active');
  document.body.style.overflow = 'hidden';
}
function neCloseLightbox(e) {
  if (e.target.id !== 'neLightbox' && !e.target.closest('.ne-lightbox-close')) return;
  document.getElementById('neLightbox').classList.remove('active');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.getElementById('neLightbox').classList.remove('active');
    document.body.style.overflow = '';
    var ms = document.getElementById('fSdg'); if (ms) ms.open = false;
  }
});

// SDGs multi-select: live "เลือกแล้ว N รายการ", clear, close when clicking elsewhere
(function () {
  var ms = document.getElementById('fSdg');
  if (!ms) return;
  var boxes = ms.querySelectorAll('input[type=checkbox]'), text = ms.querySelector('[data-ms-text]');
  function sync() {
    var on = Array.prototype.filter.call(boxes, function (b) { return b.checked; });
    text.textContent = !on.length ? 'ทุกข่าว'
      : on.length === 1 ? on[0].parentNode.querySelector('span:nth-of-type(2)').textContent
      : 'เลือกแล้ว ' + on.length + ' รายการ';
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  ms.querySelector('[data-ms-clear]').addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); sync(); });
  document.addEventListener('click', function (e) { if (ms.open && !ms.contains(e.target)) ms.open = false; });
  // open towards the left when there is no room on the right
  var panel = ms.querySelector('.ne-ms-panel');
  ms.addEventListener('toggle', function () {
    if (!ms.open) return;
    panel.classList.remove('is-right');
    if (panel.getBoundingClientRect().right > document.documentElement.clientWidth - 8) panel.classList.add('is-right');
  });
  sync();
})();

// ช่วงเวลา: the end can't be before the start; the quick buttons fill both dates and search right away
(function () {
  var from = document.getElementById('fFrom'), to = document.getElementById('fTo');
  if (!from) return;
  from.addEventListener('change', function () { to.min = from.value; });
  to.addEventListener('change', function () { from.max = to.value; });
  function setRange(v) {
    var r = v.split('|');
    from.max = ''; to.min = '';
    from.value = r[0]; to.value = r[1];
    from.form.submit();
  }
  document.querySelectorAll('[data-range]').forEach(function (b) {
    b.addEventListener('click', function () { setRange(b.dataset.range); });
  });
  // ปีงบประมาณ: has no name of its own (it only fills the two dates), so it is skipped by the page-wide auto-filter
  var fy = document.getElementById('fFy');
  if (fy) fy.addEventListener('change', function () { if (fy.value) setRange(fy.value); });
})();
</script>

<?php include BASE_PATH . '/admin/layout/footer.php'; ?>