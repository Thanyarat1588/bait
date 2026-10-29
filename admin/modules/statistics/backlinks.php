<?php
/**
 * BAIT Admin - สถิติเว็บไซต์ > Backlink: which other websites visitors clicked through from (table page_referrers,
 * written by recordReferrer() in includes/helpers.php). Filters: date range, kind of page landed on, source domain.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

$ready = false;
try { $ready = (bool)$db->fetchOne("SHOW TABLES LIKE 'page_referrers'"); } catch (Throwable $e) {}

// ── filters ──
$date = fn(string $v) => (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) ? $v : '';
$from   = $date(getStr('from'));
$to     = $date(getStr('to'));
if ($from !== '' && $to !== '' && $from > $to) [$from, $to] = [$to, $from];
$types  = pageTypeLabels();
$type   = getStr('type');
if (!isset($types[$type])) $type = '';
$domain = mb_substr(strtolower(getStr('domain')), 0, 100);

$where = '1=1'; $params = [];
if ($from !== '')   { $where .= ' AND viewed_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '')     { $where .= ' AND viewed_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to; }
if ($type !== '')   { $where .= ' AND page_type = ?'; $params[] = $type; }
if ($domain !== '') { $where .= ' AND ref_domain LIKE ?'; $params[] = '%' . addcslashes($domain, '%_\\') . '%'; }

$keep = array_filter(['from' => $from, 'to' => $to, 'type' => $type, 'domain' => $domain], fn($v) => $v !== '');
$qs   = fn(array $extra = []) => '?' . http_build_query(array_merge($keep, $extra));
$pageNo = fn(string $k) => max(1, (int)getStr($k, '1'));
$pat    = fn(string $k) => $qs() . ($keep ? '&' : '') . $k . '={page}';   // pager address: {page} must stay literal (http_build_query would encode it)

$stats = ['domains' => 0, 'pages' => 0, 'visits' => 0, 'landed' => 0, 'updated' => null];
$domains = $refPages = $landed = [];
$pd = $pr = $pl = paginate(0, 20, 1);
if ($ready) {
    $stats = $db->fetchOne(
        "SELECT COUNT(DISTINCT ref_domain) AS domains, COUNT(DISTINCT ref_url) AS pages, COUNT(*) AS visits,
                COUNT(DISTINCT page_url) AS landed, MAX(viewed_at) AS updated
         FROM page_referrers WHERE $where", $params) ?: $stats;

    // websites that link to us, most visits first
    $pd = paginate((int)$stats['domains'], 20, $pageNo('dp'), $pat('dp'));
    $domains = $db->fetchAll(
        "SELECT ref_domain, COUNT(DISTINCT ref_url) AS pages, COUNT(*) AS visits, MAX(viewed_at) AS last_seen
         FROM page_referrers WHERE $where GROUP BY ref_domain ORDER BY visits DESC, ref_domain
         LIMIT {$pd['per_page']} OFFSET {$pd['offset']}", $params);

    // the pages on those websites, and where they lead on ours
    $refTotal = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM (SELECT 1 FROM page_referrers WHERE $where GROUP BY ref_url, page_url) t", $params)['c'] ?? 0);
    $pr = paginate($refTotal, 20, $pageNo('rp'), $pat('rp'));
    $refPages = $db->fetchAll(
        "SELECT ref_url, ref_domain, page_url, MAX(page_title) AS page_title, COUNT(*) AS visits, MAX(viewed_at) AS last_seen
         FROM page_referrers WHERE $where GROUP BY ref_url, ref_domain, page_url ORDER BY visits DESC, last_seen DESC
         LIMIT {$pr['per_page']} OFFSET {$pr['offset']}", $params);

    // our pages that other websites send visitors to
    $pl = paginate((int)$stats['landed'], 20, $pageNo('lp'), $pat('lp'));
    $landed = $db->fetchAll(
        "SELECT page_url, MAX(page_title) AS page_title, MAX(page_type) AS page_type, COUNT(DISTINCT ref_domain) AS domains, COUNT(*) AS visits
         FROM page_referrers WHERE $where GROUP BY page_url ORDER BY visits DESC
         LIMIT {$pl['per_page']} OFFSET {$pl['offset']}", $params);
}
$dt = fn(?string $v) => $v ? date('d/m/', strtotime($v)) . (date('Y', strtotime($v)) + 543) . ' ' . date('H:i', strtotime($v)) : '-';
$host = fn(string $u) => (string)parse_url($u, PHP_URL_HOST);

// ── Excel export (?export=xlsx + the same filters): the whole report, not just the page shown. A sheet holds at most
//    20,000 rows; when there are more, the sheet ends with a note giving the real total and the summary sheet says so too ──
if ($ready && getStr('export') === 'xlsx') {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';
    $cap = 20000;
    $be  = fn(?string $v) => $v ? date('d/m/', strtotime($v)) . (date('Y', strtotime($v)) + 543) . ' ' . date('H:i', strtotime($v)) : '';
    $day = fn(string $v) => $v !== '' ? date('d/m/', strtotime($v)) . (date('Y', strtotime($v)) + 543) : 'ทั้งหมด';
    $cut = [];   // sheet name => real number of rows, for the sheets that were cut
    // a grouped query asking for one row more than the cap, so a cut is never silent
    $grab = function (string $sheet, string $select, string $group, string $order) use ($db, $where, $params, $cap, &$cut): array {
        $got = $db->fetchAll("SELECT $select FROM page_referrers WHERE $where GROUP BY $group ORDER BY $order LIMIT " . ($cap + 1), $params);
        if (count($got) > $cap) {
            $cut[$sheet] = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM (SELECT 1 FROM page_referrers WHERE $where GROUP BY $group) t", $params)['c'] ?? 0);
            $got = array_slice($got, 0, $cap);
        }
        return $got;
    };
    $note = function (array &$rows, string $sheet, int $cols) use (&$cut, $cap): void {
        if (isset($cut[$sheet])) $rows[] = array_pad(['⚠ แสดงเพียง ' . number_format($cap) . ' แถวแรก จากทั้งหมด ' . number_format($cut[$sheet])
            . ' แถว — เลือกช่วงวันที่ / ประเภท / โดเมนให้แคบลง แล้วส่งออกอีกครั้งเพื่อดูส่วนที่เหลือ'], $cols, '');
    };

    $sheets = [];
    $rows = [['อันดับ', 'โดเมน', 'จำนวนหน้าที่ลิงก์มา', 'ผู้เข้าชมผ่านลิงก์', 'พบล่าสุด']];
    foreach ($grab('เว็บไซต์ที่อ้างอิง', 'ref_domain, COUNT(DISTINCT ref_url) AS pages, COUNT(*) AS visits, MAX(viewed_at) AS last_seen',
                   'ref_domain', 'visits DESC, ref_domain') as $i => $r)
        $rows[] = [$i + 1, (string)$r['ref_domain'], (int)$r['pages'], (int)$r['visits'], $be($r['last_seen'])];
    $note($rows, 'เว็บไซต์ที่อ้างอิง', 5);
    $sheets[] = ['เว็บไซต์ที่อ้างอิง', $rows, [8, 36, 20, 18, 18]];
    $rows = [['ลิงก์จากหน้า', 'โดเมน', 'เข้ามาที่หน้า', 'URL หน้าของเรา', 'ผู้เข้าชม', 'พบล่าสุด']];
    foreach ($grab('หน้าเว็บต้นทาง', 'ref_url, ref_domain, page_url, MAX(page_title) AS page_title, COUNT(*) AS visits, MAX(viewed_at) AS last_seen',
                   'ref_url, ref_domain, page_url', 'visits DESC, last_seen DESC') as $r)
        $rows[] = [(string)$r['ref_url'], (string)$r['ref_domain'], (string)$r['page_title'], (string)$r['page_url'], (int)$r['visits'], $be($r['last_seen'])];
    $note($rows, 'หน้าเว็บต้นทาง', 6);
    $sheets[] = ['หน้าเว็บต้นทาง', $rows, [60, 28, 50, 46, 12, 18]];
    $rows = [['อันดับ', 'หน้า', 'URL', 'ประเภทเนื้อหา', 'จำนวนเว็บไซต์', 'ผู้เข้าชมผ่านลิงก์']];
    foreach ($grab('หน้าที่ถูกอ้างอิง', 'page_url, MAX(page_title) AS page_title, MAX(page_type) AS page_type, COUNT(DISTINCT ref_domain) AS domains, COUNT(*) AS visits',
                   'page_url', 'visits DESC, page_url') as $i => $r)
        $rows[] = [$i + 1, (string)$r['page_title'], (string)$r['page_url'], $types[$r['page_type']] ?? $types['other'], (int)$r['domains'], (int)$r['visits']];
    $note($rows, 'หน้าที่ถูกอ้างอิง', 6);
    $sheets[] = ['หน้าที่ถูกอ้างอิง', $rows, [8, 50, 46, 22, 16, 18]];

    $summary = [
        ['หัวข้อ', 'ค่า'],
        ['ช่วงวันที่', $day($from) . ' ถึง ' . $day($to)],
        ['ประเภทเนื้อหา', $type !== '' ? $types[$type] : 'ทั้งหมด'],
        ['โดเมนต้นทาง', $domain !== '' ? $domain : 'ทั้งหมด'],
        ['เว็บไซต์ที่อ้างอิงถึงเรา', (int)$stats['domains']],
        ['หน้าเว็บต้นทาง', (int)$stats['pages']],
        ['ผู้เข้าชมผ่านลิงก์', (int)$stats['visits']],
        ['หน้าของเราที่ถูกอ้างอิง', (int)$stats['landed']],
        ['ส่งออกเมื่อ', $be(date('Y-m-d H:i:s'))],
    ];
    foreach ($cut as $sheet => $total)
        $summary[] = ['⚠ แผ่นงาน "' . $sheet . '" ไม่ครบ', 'แสดง ' . number_format($cap) . ' จาก ' . number_format($total) . ' แถว — กรองให้แคบลงแล้วส่งออกอีกครั้ง'];
    $x = new SimpleXlsx();
    $x->addSheet('สรุป', $summary, [30, 40]);
    foreach ($sheets as [$name, $rows, $w]) $x->addSheet($name, $rows, $w);
    $x->download('bait-backlinks-' . date('Ymd') . '.xlsx');
}

$pageTitle   = 'การอ้างอิงจากเว็บไซต์ภายนอก (Backlink)';
$activeMenu  = 'statistics-backlinks';
$breadcrumb  = [['label' => 'สถิติเว็บไซต์', 'url' => BASE_URL . '/admin/modules/statistics/index.php'], ['label' => 'Backlink']];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>
<style>
.bl-info { display: flex; gap: 10px; align-items: flex-start; padding: 12px 16px; border-radius: 10px; background: #E8F4FB; border: 1px solid #BFE0F2; color: #10425E; margin-bottom: 16px; font-size: 14px; }
.bl-url { word-break: break-word; overflow-wrap: anywhere; font-size: 13px; min-width: 160px; max-width: 360px; }
.bl-tablewrap { overflow-x: auto; }
.bl-tablewrap td, .bl-tablewrap .ne-title-link { white-space: normal; }
.ne-table th.bl-num, .ne-table td.bl-num { text-align: center; white-space: nowrap; }   /* numbers centred under their heading */
</style>
<div class="ne">
  <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin:-6px 0 14px;">
    <p class="ne-hint" style="margin:0;">อัปเดตล่าสุด <?= e($dt($stats['updated'] ?? null)) ?></p>
    <?php if ($ready): ?>
    <a class="ne-btn ne-btn-excel" href="<?= e($qs(['export' => 'xlsx'])) ?>" title="ส่งออกรายงาน Backlink ตามตัวกรองที่เลือกเป็นไฟล์ Excel"><i class="bi bi-file-earmark-excel"></i> ส่งออกรายงาน Backlink (Excel)</a>
    <?php endif; ?>
  </div>

  <div class="bl-info"><i class="bi bi-info-circle-fill" aria-hidden="true"></i>
    <span>รายงานนี้นับจากผู้เข้าชมที่ <strong>คลิกลิงก์จากเว็บไซต์ภายนอกเข้ามา</strong> จึงเห็นเฉพาะลิงก์ที่มีคนใช้งานจริง ลิงก์ที่มีอยู่แต่ยังไม่มีผู้คลิกจะยังไม่ปรากฏ
    และเบราว์เซอร์หรือเว็บบางแห่งส่งมาเฉพาะชื่อโดเมน หรือไม่ส่งข้อมูลต้นทางมาให้ · เริ่มเก็บข้อมูลตั้งแต่ติดตั้งระบบนี้ และเก็บย้อนหลัง 400 วันเหมือนสถิติผู้เข้าชม</span>
  </div>

  <?php if (!$ready): ?>
  <div class="ne-panel"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังไม่เริ่มเก็บข้อมูล: ต้องรันไฟล์ <code>sql/bait_migrations_safe_to_rerun.sql</code> (ข้อ 10) ใน phpMyAdmin หนึ่งครั้งก่อน</p></div></div>
  <?php else: ?>

  <form class="ne-filters" method="GET">
    <div class="ne-filters-field"><label for="fFrom">วันที่เริ่มต้น</label><input type="date" id="fFrom" class="ne-input" name="from" value="<?= e($from) ?>"></div>
    <div class="ne-filters-field"><label for="fTo">วันที่สิ้นสุด</label><input type="date" id="fTo" class="ne-input" name="to" value="<?= e($to) ?>"></div>
    <div class="ne-filters-field">
      <label for="fType">ประเภทเนื้อหา</label>
      <select id="fType" class="ne-select" name="type">
        <option value="">ทั้งหมด</option>
        <?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="ne-filters-field ne-search"><label for="fDomain">โดเมนต้นทาง</label><input type="text" id="fDomain" class="ne-input" name="domain" placeholder="เช่น facebook.com" value="<?= e($domain) ?>"></div>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-funnel"></i>แสดงรายงาน</button>
      <a href="?" class="ne-btn ne-btn-ghost">ล้างตัวกรอง</a>
    </div>
  </form>

  <div class="ne-stat-grid">
    <div class="ne-stat-card"><div class="ne-stat-icon is-blue"><i class="bi bi-globe2"></i></div><div><div class="ne-stat-num"><?= number_format((int)$stats['domains']) ?></div><div class="ne-stat-label">เว็บไซต์ที่อ้างอิงถึงเรา</div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-green"><i class="bi bi-link-45deg"></i></div><div><div class="ne-stat-num"><?= number_format((int)$stats['pages']) ?></div><div class="ne-stat-label">หน้าเว็บต้นทาง</div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-gold"><i class="bi bi-cursor"></i></div><div><div class="ne-stat-num"><?= number_format((int)$stats['visits']) ?></div><div class="ne-stat-label">ผู้เข้าชมผ่านลิงก์</div></div></div>
    <div class="ne-stat-card"><div class="ne-stat-icon is-danger"><i class="bi bi-file-earmark-text"></i></div><div><div class="ne-stat-num"><?= number_format((int)$stats['landed']) ?></div><div class="ne-stat-label">หน้าของเราที่ถูกอ้างอิง</div></div></div>
  </div>

  <div class="ne-panel" id="domains" style="margin-bottom:20px;">
    <div class="ne-panel-head"><span><i class="bi bi-globe2"></i>เว็บไซต์ที่อ้างอิงถึงเรามากที่สุด</span><span><?= number_format((int)$stats['domains']) ?> เว็บไซต์</span></div>
    <?php if ($domains): ?>
    <div class="bl-tablewrap"><table class="ne-table">
      <thead><tr><th style="width:60px">อันดับ</th><th>โดเมน</th><th class="bl-num">จำนวนหน้าที่ลิงก์มา</th><th class="bl-num">ผู้เข้าชมผ่านลิงก์</th><th style="width:150px">พบล่าสุด</th></tr></thead>
      <tbody>
      <?php foreach ($domains as $i => $d): ?>
        <tr>
          <td class="ne-num"><?= $pd['offset'] + $i + 1 ?></td>
          <td><a href="<?= e($qs(['domain' => $d['ref_domain']])) ?>#sources" class="ne-title-link" title="ดูหน้าเว็บต้นทางของโดเมนนี้"><?= e($d['ref_domain']) ?></a></td>
          <td class="bl-num"><?= number_format((int)$d['pages']) ?></td>
          <td class="bl-num" style="font-weight:700;color:var(--ne-blue)"><?= number_format((int)$d['visits']) ?></td>
          <td><small class="text-muted"><?= e($dt($d['last_seen'])) ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($pd['total_pages'] > 1): ?><div class="ne-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;"><?= paginationHtml($pd) ?></div><?php endif; ?>
    <?php else: ?><div class="ne-panel-body"><p class="ne-hint" style="margin:0;">ยังไม่มีผู้เข้าชมที่คลิกลิงก์จากเว็บไซต์ภายนอกเข้ามา<?= $keep ? ' ในช่วง/ตัวกรองที่เลือก' : '' ?></p></div><?php endif; ?>
  </div>

  <div class="ne-panel" id="sources" style="margin-bottom:20px;">
    <div class="ne-panel-head"><span><i class="bi bi-link-45deg"></i>หน้าเว็บต้นทาง<?= $domain !== '' ? ' (โดเมน "' . e($domain) . '")' : '' ?></span><span><?= number_format($pr['total']) ?> รายการ</span></div>
    <?php if ($refPages): ?>
    <div class="bl-tablewrap"><table class="ne-table">
      <thead><tr><th>ลิงก์จากหน้า</th><th>เข้ามาที่หน้า</th><th class="bl-num">ผู้เข้าชม</th><th style="width:150px">พบล่าสุด</th></tr></thead>
      <tbody>
      <?php foreach ($refPages as $r): ?>
        <tr>
          <td class="bl-url"><?php if (preg_match('#^https?://#', $r['ref_url'])): ?><a href="<?= e($r['ref_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['ref_url']) ?></a><?php else: ?><?= e($r['ref_url']) ?> <span class="ne-byline">(เปิดจากแอป)</span><?php endif; ?></td>
          <td class="bl-url"><a href="<?= e($r['page_url']) ?>" target="_blank" rel="noopener"><?= e($r['page_title'] !== '' ? $r['page_title'] : $r['page_url']) ?></a></td>
          <td class="bl-num" style="font-weight:700;color:var(--ne-blue)"><?= number_format((int)$r['visits']) ?></td>
          <td><small class="text-muted"><?= e($dt($r['last_seen'])) ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($pr['total_pages'] > 1): ?><div class="ne-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;"><?= paginationHtml($pr) ?></div><?php endif; ?>
    <?php else: ?><div class="ne-panel-body"><p class="ne-hint" style="margin:0;">ยังไม่มีข้อมูล</p></div><?php endif; ?>
  </div>

  <div class="ne-panel" id="landed">
    <div class="ne-panel-head"><span><i class="bi bi-file-earmark-text"></i>หน้าของเราที่ถูกอ้างอิงมากที่สุด</span><span><?= number_format((int)$stats['landed']) ?> หน้า</span></div>
    <?php if ($landed): ?>
    <div class="bl-tablewrap"><table class="ne-table">
      <thead><tr><th style="width:60px">อันดับ</th><th>หน้า</th><th style="width:170px">ประเภทเนื้อหา</th><th class="bl-num">จำนวนเว็บไซต์</th><th class="bl-num">ผู้เข้าชมผ่านลิงก์</th></tr></thead>
      <tbody>
      <?php foreach ($landed as $i => $l): ?>
        <tr>
          <td class="ne-num"><?= $pl['offset'] + $i + 1 ?></td>
          <td><a href="<?= e($l['page_url']) ?>" target="_blank" rel="noopener" class="ne-title-link"><?= e($l['page_title'] !== '' ? $l['page_title'] : $l['page_url']) ?></a><div class="ne-byline bl-url"><?= e($l['page_url']) ?></div></td>
          <td><?= e($types[$l['page_type']] ?? $types['other']) ?></td>
          <td class="bl-num"><?= number_format((int)$l['domains']) ?></td>
          <td class="bl-num" style="font-weight:700;color:var(--ne-blue)"><?= number_format((int)$l['visits']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($pl['total_pages'] > 1): ?><div class="ne-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;"><?= paginationHtml($pl) ?></div><?php endif; ?>
    <?php else: ?><div class="ne-panel-body"><p class="ne-hint" style="margin:0;">ยังไม่มีข้อมูล</p></div><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>
