<?php
/**
 * BAIT Admin - ข่าวไอที (RSS): the headlines read from the Thai IT-news feeds for the "IT NEWS วันนี้" band on the news
 * * page. New headlines arrive by themselves (every hour and at the first visit of a new day, when someone opens the news page); here an admin can fetch
 * right away, see how each feed answered, and hide a headline that should not be shown. Needs migration step 17.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
$auth = new Auth(); $auth->requireLogin(); $db = Database::getInstance();
$ready = hasItNews();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = postStr('action');
    if ($action === 'fetch') {
        $r = itNewsFetch(true);
        // say the real reason: each of these used to show as the same vague message
        if (!$r && !function_exists('curl_init')) jsonError('ดึงข่าวไม่ได้: เซิร์ฟเวอร์นี้ไม่มี cURL (ส่วนเสริมของ PHP ที่ใช้อ่านเว็บอื่น) กรุณาแจ้งผู้ดูแลโฮสต์ให้เปิดใช้งาน');
        if (!$r && !is_writable(BASE_PATH . '/cache')) jsonError('ดึงข่าวไม่ได้: โฟลเดอร์ cache/ ของเว็บไซต์เขียนไฟล์ไม่ได้ กรุณาตั้งสิทธิ์โฟลเดอร์ cache ให้เขียนได้ (เช่น 755 หรือ 775) แล้วลองใหม่');
        if (!$r) jsonError('ดึงข่าวไม่ได้: มีคนกำลังดึงอยู่ กรุณาลองใหม่อีกครั้ง');
        jsonSuccess(['result' => $r], implode(' · ', array_map(fn($k, $v) => "$k: $v", array_keys($r), $r)));
    }
    if ($action === 'hide' || $action === 'show') {
        $id = (int)postStr('item_id');
        if (!$db->fetchOne('SELECT 1 FROM it_news WHERE item_id = ?', [$id])) jsonError('ไม่พบข่าวนี้ อาจถูกลบไปแล้ว กรุณาโหลดหน้าใหม่');
        $db->query('UPDATE it_news SET is_hidden = ? WHERE item_id = ?', [$action === 'hide' ? 1 : 0, $id]);
        jsonSuccess([], $action === 'hide' ? 'ซ่อนข่าวนี้แล้ว (ไม่แสดงบนเว็บ)' : 'แสดงข่าวนี้แล้ว');
    }
    jsonError('คำสั่งไม่ถูกต้อง');
}

$show = getStr('show') === 'hidden' ? 'hidden' : 'all';
$page = max(1, (int)getStr('page'));
$rows = []; $total = 0; $hiddenCount = 0; $pager = paginate(0, 30, 1);
if ($ready) {
    $since = itNewsSince();   // the same 7 days as the website
    $where = 'published_at >= ?' . ($show === 'hidden' ? ' AND is_hidden = 1' : '');
    $total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM it_news WHERE $where", [$since])['c'] ?? 0);
    $hiddenCount = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM it_news WHERE published_at >= ? AND is_hidden = 1', [$since])['c'] ?? 0);
    $pager = paginate($total, 30, $page, '?show=' . $show . '&page={page}');
    $rows = $db->fetchAll("SELECT * FROM it_news WHERE $where ORDER BY published_at DESC, item_id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}", [$since]);
}
$state = is_file(BASE_PATH . '/cache/it_news_fetch.json') ? (json_decode((string)@file_get_contents(BASE_PATH . '/cache/it_news_fetch.json'), true) ?: []) : [];
$onBand = array_column($ready ? itNewsPick(6) : [], 'item_id');

$pageTitle = 'ข่าวไอที (RSS)'; $activeMenu = 'it-news';
$breadcrumb = [['label' => 'ข่าวไอที (RSS)']]; $pageActions = ''; $hidePageHeader = true;
include BASE_PATH . '/admin/layout/header.php';
?>
<style>
.itn-a-status { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0 0; }
.itn-a-status span { font-size: 12.5px; border: 1px solid var(--ne-line); border-radius: 999px; padding: 3px 10px; }
.itn-a-row { display: flex; gap: 12px; align-items: flex-start; padding: 12px 0; border-top: 1px solid var(--ne-line); }
.itn-a-row:first-child { border-top: 0; }
.itn-a-main { min-width: 0; flex: 1 1 auto; }
.itn-a-main a { font-weight: 600; color: var(--ne-ink); text-decoration: none; }
.itn-a-main a:hover { text-decoration: underline; }
.itn-a-main p { margin: 2px 0 0; font-size: 13px; color: var(--ne-slate); }
.itn-a-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; font-size: 12px; color: var(--ne-slate); }
.itn-a-meta b { font-weight: 600; border-radius: 4px; padding: 0 6px; background: var(--ne-blue-soft); color: var(--ne-blue-deep); }
.itn-a-meta .is-on { background: #fff4e5; color: #9a5b00; }
.itn-a-row.is-hidden .itn-a-main { opacity: .55; }
/* the hide / show button: its own size at the right of the row (never stretched), coloured so it is easy to see */
.itn-a-btn { flex: 0 0 auto; width: auto; align-self: center; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
  font: inherit; font-size: 13.5px; font-weight: 600; padding: 7px 14px; border-radius: 999px; cursor: pointer; transition: background .15s, color .15s; }
.itn-a-btn.is-hide { background: #fdecec; color: #b42318; border: 1px solid #f5c2c0; }
.itn-a-btn.is-hide:hover { background: #b42318; color: #fff; }
.itn-a-btn.is-show { background: #e8f6ee; color: #1b7a45; border: 1px solid #b8e2c8; }
.itn-a-btn.is-show:hover { background: #1b7a45; color: #fff; }
.itn-a-btn:focus-visible { outline: 2px solid var(--ne-blue, #1F56D6); outline-offset: 2px; }
</style>
<div class="ne">
  <div class="ne-topbar">
    <h1>ข่าวไอที (RSS) <span class="ne-count"><?= number_format($total) ?> ข่าว</span></h1>
    <?php if ($ready): ?><button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" id="itnFetch"><i class="bi bi-arrow-repeat"></i>ดึงข่าวตอนนี้</button><?php endif; ?>
  </div>
  <?php if (!$ready): ?>
  <div class="ne-panel"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังใช้งานไม่ได้: ต้องรันไฟล์ <code>sql/1_bait_migrations_13_to_16.sql</code> (ข้อ 17) ใน phpMyAdmin หนึ่งครั้งก่อน</p></div></div>
  <?php else: ?>
  <?php if (!function_exists('curl_init') || !is_writable(BASE_PATH . '/cache')): ?>
  <div class="ne-panel" style="margin-bottom:14px;border-left:4px solid #c1443d;"><div class="ne-panel-body"><p class="ne-hint" style="margin:0;color:#b42318;font-weight:600;">
    ระบบดึงข่าวอัตโนมัติไม่ทำงานบนเซิร์ฟเวอร์นี้: <?= !function_exists('curl_init') ? 'ไม่มี cURL (ส่วนเสริมของ PHP ที่ใช้อ่านเว็บอื่น) กรุณาแจ้งผู้ดูแลโฮสต์ให้เปิดใช้งาน' : 'โฟลเดอร์ cache/ ของเว็บไซต์เขียนไฟล์ไม่ได้ กรุณาตั้งสิทธิ์โฟลเดอร์ cache ให้เขียนได้ (เช่น 755 หรือ 775)' ?> — ข่าวไอทีจะไม่อัปเดตจนกว่าจะแก้</p></div></div>
  <?php endif; ?>
  <div class="ne-panel" style="margin-bottom:14px;">
    <div class="ne-panel-body">
      <p class="ne-hint" style="margin:0;">แสดงบนเว็บที่ หน้าข่าวสาร › แถบ "IT NEWS วันนี้" 6 ข่าวล่าสุด (ไม่เกิน 3 ข่าวต่อเว็บ) และหน้า "ข่าวไอทีย้อนหลัง" · ระบบดึงข่าวใหม่เองทุก 1 ชั่วโมง และทันทีที่ขึ้นวันใหม่ เมื่อมีคนเปิดหน้าข่าวสารหรือข่าวไอทีย้อนหลัง · แสดงและเก็บ 7 วันล่าสุด (วันนี้ + 6 วันก่อนหน้า) ขึ้นวันใหม่ วันที่เก่าที่สุดหายไปเอง · เก็บเฉพาะหัวข้อ สรุปสั้น และลิงก์ (ข่าวเต็มอยู่ที่เว็บต้นทาง)</p>
      <div class="itn-a-status">
        <span>ดึงล่าสุด: <?= !empty($state['at']) ? thaiDate(date('Y-m-d H:i:s', (int)$state['at']), true) : 'ยังไม่เคย' ?></span>
        <?php foreach (itNewsFeeds() as $src => $url): ?><span><?= e($src) ?>: <?= e($state['result'][$src] ?? '-') ?></span><?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="tab-pills" style="display:flex;gap:8px;margin-bottom:12px;">
    <a class="ne-btn <?= $show === 'all' ? 'ne-btn-primary' : 'ne-btn-ghost' ?>" href="?show=all">ทั้งหมด</a>
    <a class="ne-btn <?= $show === 'hidden' ? 'ne-btn-primary' : 'ne-btn-ghost' ?>" href="?show=hidden">ที่ซ่อนไว้ (<?= $hiddenCount ?>)</a>
  </div>
  <div class="ne-panel">
    <div class="ne-panel-body">
      <?php if (!$rows): ?><div class="ne-empty"><i class="bi bi-rss"></i><?= $show === 'hidden' ? 'ไม่มีข่าวที่ซ่อนไว้' : 'ยังไม่มีข่าว — กด "ดึงข่าวตอนนี้"' ?></div><?php endif; ?>
      <?php foreach ($rows as $r): ?>
      <div class="itn-a-row<?= $r['is_hidden'] ? ' is-hidden' : '' ?>">
        <div class="itn-a-main">
          <a href="<?= e($r['url']) ?>" target="_blank" rel="noopener nofollow"><?= e($r['title']) ?></a>
          <?php if ($r['summary'] !== ''): ?><p><?= e($r['summary']) ?></p><?php endif; ?>
          <div class="itn-a-meta"><b><?= e($r['tag']) ?></b><span><?= e($r['source']) ?></span><span><?= thaiDate($r['published_at'], true) ?></span>
            <?php if (in_array($r['item_id'], $onBand)): ?><b class="is-on">อยู่ในแถบหน้าข่าวสารตอนนี้</b><?php endif; ?>
            <?php if ($r['is_hidden']): ?><b class="is-on">ซ่อนอยู่</b><?php endif; ?></div>
        </div>
        <button type="button" class="itn-a-btn <?= $r['is_hidden'] ? 'is-show' : 'is-hide' ?>" data-itn="<?= (int)$r['item_id'] ?>" data-act="<?= $r['is_hidden'] ? 'show' : 'hide' ?>">
          <i class="bi <?= $r['is_hidden'] ? 'bi-eye' : 'bi-eye-slash' ?>"></i><?= $r['is_hidden'] ? 'แสดง' : 'ซ่อน' ?></button>
      </div>
      <?php endforeach; ?>
      <?php if ($pager['total_pages'] > 1): ?><div class="ne-footer" style="margin-top:12px;"><span>แสดง <?= $pager['offset'] + 1 ?>–<?= min($pager['offset'] + 30, $total) ?> จาก <?= number_format($total) ?> ข่าว</span><?= paginationHtml($pager) ?></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
$extraJs = '<script>
const csrf = ' . json_encode(csrfToken()) . ';
async function itnPost(data) {
  const fd = new FormData(); fd.append("csrf_token", csrf); Object.keys(data).forEach(k => fd.append(k, data[k]));
  const r = await axios.post("", fd);
  if (r.data.status === "success") { showSuccess(r.data.message); setTimeout(() => location.reload(), 1200); } else showError(r.data.message);
}
const fb = document.getElementById("itnFetch");
if (fb) fb.addEventListener("click", function () { fb.disabled = true; fb.innerHTML = "<i class=\"bi bi-hourglass-split\"></i>กำลังดึงข่าว…"; itnPost({ action: "fetch" }).finally(() => { fb.disabled = false; }); });
document.querySelectorAll("[data-itn]").forEach(b => b.addEventListener("click", () => itnPost({ action: b.dataset.act, item_id: b.dataset.itn })));
</script>';
include BASE_PATH . '/admin/layout/footer.php';
