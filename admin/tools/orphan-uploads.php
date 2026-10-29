<?php
/**
 * BAIT Admin - Unused upload files (READ-ONLY report).
 *
 * Lists the files in /uploads that no database value mentions any more (left behind by older versions that did not
 * delete a replaced picture). It NEVER deletes anything: it is a dry run, so the list can be checked by a person first.
 * Super Admin only. Address: /admin/tools/orphan-uploads.php (not in the menu).
 */
require_once dirname(dirname(__DIR__)) . '/includes/Database.php';
require_once dirname(dirname(__DIR__)) . '/includes/Auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireSuperAdmin();
$db = Database::getInstance();
$user = $auth->currentUser();
@set_time_limit(120);

// ---- 1. every file name any text column of any table mentions ----
$mentioned = [];   // lower-case file name => true
$fileRe = '/[A-Za-z0-9_.\-%]+\.(?:jpe?g|png|gif|webp|svg|bmp|ico|avif|mp4|webm|pdf|docx?|xlsx?|pptx?|zip)/iu';
$cols = $db->fetchAll(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('varchar','char','text','tinytext','mediumtext','longtext','json')"
);
foreach ($cols as $col) {
    if (in_array($col['t'], ['translation_cache', 'page_views'], true)) continue;   // copies of text / visit log (can be 100k+ rows): no file references there, and reading them ran out of memory
    $rows = $db->fetchAll("SELECT `{$col['c']}` AS v FROM `{$col['t']}` WHERE `{$col['c']}` IS NOT NULL AND `{$col['c']}` <> ''");
    foreach ($rows as $r) {
        if (preg_match_all($fileRe, rawurldecode((string)$r['v']), $m)) foreach ($m[0] as $name) $mentioned[strtolower(basename($name))] = true;
    }
}

// ---- 2. every file on disk ----
$orphans = []; $total = 0; $bytes = 0; $orphanBytes = 0;
$root = realpath(UPLOAD_PATH);
if ($root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if (!$fi->isFile()) continue;
        $name = $fi->getFilename();
        if ($name === '.htaccess' || $name === 'index.html') continue;
        $total++; $bytes += $fi->getSize();
        if (!isset($mentioned[strtolower($name)])) {
            $rel = ltrim(str_replace('\\', '/', substr($fi->getPathname(), strlen($root))), '/');
            $orphans[] = ['rel' => $rel, 'size' => $fi->getSize(), 'time' => $fi->getMTime()];
            $orphanBytes += $fi->getSize();
        }
    }
}
usort($orphans, fn($a, $b) => $b['size'] <=> $a['size']);
$byFolder = [];
foreach ($orphans as $o) { $f = strstr($o['rel'], '/', true) ?: '(root)'; $byFolder[$f]['n'] = ($byFolder[$f]['n'] ?? 0) + 1; $byFolder[$f]['b'] = ($byFolder[$f]['b'] ?? 0) + $o['size']; }
$mb = fn(int $b) => number_format($b / 1048576, 2) . ' MB';

$pageTitle = 'ไฟล์อัปโหลดที่ไม่ได้ใช้งาน'; $activeMenu = '';
$breadcrumb = [['label' => $pageTitle]];
include BASE_PATH . '/admin/layout/header.php';
?>
<div class="ne">
  <div class="ne-topbar"><div><h1>ไฟล์อัปโหลดที่ไม่ได้ใช้งาน <span class="ne-count"><?= count($orphans) ?> ไฟล์</span></h1>
    <p class="ne-hint" style="margin:2px 0 0">รายงานเท่านั้น — หน้านี้ไม่ลบอะไรทั้งสิ้น ตรวจรายการให้แน่ใจก่อน แล้วค่อยแจ้งผู้พัฒนาหากต้องการล้างไฟล์</p></div></div>

  <div class="ne-panel" style="margin-bottom:20px"><div class="ne-panel-body">
    ไฟล์ทั้งหมดในโฟลเดอร์ uploads <strong><?= number_format($total) ?></strong> ไฟล์ (<?= $mb($bytes) ?>) ·
    ไม่มีค่าใดในฐานข้อมูลอ้างถึง <strong><?= number_format(count($orphans)) ?></strong> ไฟล์ (<?= $mb($orphanBytes) ?>)
    <?php if ($byFolder): ?><div style="margin-top:8px;font-size:.88rem;color:#5b6480">
      <?php foreach ($byFolder as $f => $v): ?><span style="margin-right:14px"><?= e($f) ?>: <?= $v['n'] ?> ไฟล์ (<?= $mb($v['b']) ?>)</span><?php endforeach; ?></div><?php endif; ?>
    <div style="margin-top:8px;font-size:.82rem;color:#5b6480">วิธีตรวจ: ค้นชื่อไฟล์ในทุกคอลัมน์ข้อความของทุกตาราง (รวมรูปที่ฝังในเนื้อหาข่าว) โดยเทียบเฉพาะชื่อไฟล์ ไม่เทียบโฟลเดอร์ ผลจึงเอนไปทาง "ถือว่ายังใช้อยู่" มากกว่าจะระบุว่าไม่ใช้ผิด</div>
  </div></div>

  <?php if ($orphans): ?>
  <div class="ne-panel"><div class="table-responsive">
    <table class="ne-table">
      <thead><tr><th>ไฟล์</th><th class="text-end" style="width:120px">ขนาด</th><th style="width:150px">แก้ไขล่าสุด</th></tr></thead>
      <tbody>
        <?php foreach ($orphans as $o): ?>
        <tr><td><a href="<?= e(UPLOAD_URL . '/' . $o['rel']) ?>" target="_blank" rel="noopener"><?= e($o['rel']) ?></a></td>
            <td class="text-end"><?= $mb($o['size']) ?></td><td><?= e(date('d/m/', $o['time']) . (date('Y', $o['time']) + 543)) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php else: ?>
  <div class="ne-panel"><div class="ne-panel-body">ไม่พบไฟล์ที่ไม่ได้ใช้งาน</div></div>
  <?php endif; ?>
</div>
<?php include BASE_PATH . '/admin/layout/footer.php'; ?>
