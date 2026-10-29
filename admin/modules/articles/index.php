<?php
/**
 * BAIT Admin - Article Management (Index)
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

$search  = getStr('search');
$status  = getStr('status');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where  = '1=1';
$params = [];
if ($search) { $where .= ' AND a.title LIKE ?'; $params[] = '%' . addcslashes($search, '%_\\') . '%'; }
if ($status) { $where .= ' AND a.status = ?';  $params[] = $status; }

$total = (int)($db->fetchOne("SELECT COUNT(*) as c FROM articles a WHERE $where", $params)['c'] ?? 0);
$pager = paginate($total, $perPage, $page, '?search=' . urlencode($search) . '&status=' . urlencode($status) . '&page={page}');

$articles = $db->fetchAll(
    "SELECT a.*, u.full_name FROM articles a
     JOIN users u ON u.user_id = a.user_id
     WHERE $where ORDER BY a.created_at DESC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
    $params
);

if (isset($_GET['deleted'])) $_SESSION['flash_success'] = 'ลบบทความเรียบร้อยแล้ว';

$pageTitle   = 'จัดการบทความ';
$activeMenu  = 'articles';
$breadcrumb  = [['label' => 'จัดการบทความ']];
$pageActions = ''; // ปุ่มเพิ่มบทความย้ายไปอยู่ใน topbar ที่ออกแบบใหม่ด้านล่างแทน
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

.ne-topbar {
  display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  margin-bottom: 22px; padding-bottom: 16px; border-bottom: 1px solid var(--ne-line);
}
.ne-topbar h1 {
  font-family: 'Kanit', sans-serif; font-size: 26px; font-weight: 700; margin: 0; color: var(--ne-ink);
  display: flex; align-items: baseline; gap: 10px;
}
.ne-topbar h1 .ne-count {
  font-family: 'Kanit', sans-serif; font-size: 14px; font-weight: 600; color: var(--ne-gold);
  background: var(--ne-gold-soft); border-radius: 999px; padding: 3px 11px;
}
.ne-btn {
  font-family: 'Kanit', sans-serif; font-weight: 600; font-size: 14.5px; border-radius: 9px; padding: 10px 18px;
  border: 1px solid transparent; display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
  text-decoration: none; transition: all .15s ease; line-height: 1;
}
.ne-btn-primary { background: var(--ne-blue); color: #fff; }
.ne-btn-primary:hover { background: var(--ne-blue-deep); color: #fff; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }

.ne-input, .ne-select {
  width: 100%; border: 1px solid var(--ne-line); border-radius: 8px; padding: 9px 12px;
  font-family: 'Kanit', sans-serif; font-size: 14px; color: var(--ne-ink); background: var(--ne-surface);
  outline: none; transition: border-color .15s ease;
}
.ne-input:focus, .ne-select:focus { border-color: var(--ne-blue); }
.ne-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%235b6480' stroke-width='1.6' fill='none' fill-rule='evenodd'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }
.ne-search-input-wrap { position: relative; }
.ne-search-input-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--ne-slate); font-size: 14px; pointer-events: none; }
.ne-search-input-wrap input { padding-left: 34px; }

.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; overflow: hidden; }
.ne-panel-head {
  display: flex; align-items: center; justify-content: space-between; padding: 14px 20px;
  border-bottom: 1px solid var(--ne-line); font-size: 13.5px; font-weight: 600; color: var(--ne-ink-soft);
}
.ne-panel-count {
  display: inline-flex; align-items: center; font-size: 12px; font-weight: 700; padding: 2px 9px;
  border-radius: 999px; background: var(--ne-gold-soft); color: #a06e00; margin-left: 4px;
}
.ne-table { width: 100%; border-collapse: collapse; }
.ne-table thead th {
  text-align: left; font-size: 11.5px; letter-spacing: .05em; text-transform: uppercase; color: var(--ne-slate);
  font-weight: 600; padding: 12px 20px; background: var(--ne-paper); border-bottom: 1px solid var(--ne-line); white-space: nowrap;
}
.ne-table tbody tr { border-bottom: 1px solid var(--ne-line); transition: background .12s ease; }
.ne-table tbody tr:last-child { border-bottom: none; }
.ne-table tbody tr:hover { background: var(--ne-paper); }
.ne-table td { padding: 12px 20px; vertical-align: middle; font-size: 14px; }
.ne-table td.ne-num { color: var(--ne-slate); font-size: 13px; }

.ne-thumb {
  width: 46px; height: 46px; border-radius: 9px; object-fit: cover; cursor: zoom-in;
  border: 1px solid var(--ne-line); display: block; transition: transform .15s ease;
}
.ne-thumb:hover { transform: scale(1.06); }
.ne-thumb-empty {
  width: 46px; height: 46px; border-radius: 9px; background: var(--ne-slate-soft); display: flex;
  align-items: center; justify-content: center; color: var(--ne-slate); font-size: 16px;
}

.ne-title-link { font-weight: 600; color: var(--ne-ink); text-decoration: none; font-family: 'Kanit', sans-serif; font-size: 15px; line-height: 1.4; }
.ne-title-link:hover { color: var(--ne-blue); }
.ne-byline { font-size: 12px; color: var(--ne-slate); margin-top: 2px; }

.ne-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; padding: 4px 11px; border-radius: 999px; white-space: nowrap; }
.ne-badge-published { background: var(--ne-green-soft); color: #1f6b47; }
.ne-badge-draft { background: var(--ne-slate-soft); color: var(--ne-ink-soft); }
.ne-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

.ne-views { display: inline-flex; align-items: center; gap: 5px; color: var(--ne-ink-soft); font-size: 13px; }

.ne-actions { display: flex; gap: 6px; }
.ne-icon-btn {
  width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--ne-line); background: var(--ne-surface);
  color: var(--ne-ink-soft); display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer; text-decoration: none; transition: all .15s ease; font-size: 14px;
}
.ne-icon-btn:hover { background: var(--ne-paper); color: var(--ne-ink); border-color: var(--ne-ink); }
.ne-icon-btn.ne-icon-view { color: #1f6b47 !important; border-color: #bfe2cd !important; background: var(--ne-green-soft) !important; }
.ne-icon-btn.ne-icon-view:hover { background: #1f6b47 !important; border-color: #1f6b47 !important; color: #fff !important; }
.ne-icon-btn.ne-icon-edit { color: var(--ne-blue) !important; border-color: #c3d9fb !important; background: var(--ne-blue-soft) !important; }
.ne-icon-btn.ne-icon-edit:hover { background: var(--ne-blue) !important; border-color: var(--ne-blue) !important; color: #fff !important; }
.ne-icon-btn.ne-icon-delete { color: var(--ne-danger) !important; border-color: #f0c9c6 !important; background: var(--ne-danger-soft) !important; }
.ne-icon-btn.ne-icon-delete:hover { background: var(--ne-danger) !important; border-color: var(--ne-danger) !important; color: #fff !important; }
.ne-icon-btn i { color: inherit !important; }

.ne-empty { text-align: center; padding: 64px 20px; color: var(--ne-slate); }
.ne-empty i { font-size: 34px; display: block; margin-bottom: 10px; color: var(--ne-line); }

.ne-footer {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
  padding: 14px 20px; border-top: 1px solid var(--ne-line); font-size: 13px; color: var(--ne-slate);
}
.ne-footer .pagination { margin: 0; }
.ne-footer .page-link { border: 1px solid var(--ne-line); color: var(--ne-ink-soft); font-size: 13px; border-radius: 7px !important; margin-left: 4px; padding: 5px 11px; }
.ne-footer .page-item.active .page-link { background: var(--ne-blue); border-color: var(--ne-blue); color: #fff; }
.ne-footer .page-item.disabled .page-link { color: var(--ne-ink-soft); background: var(--ne-paper); }

.ne-lightbox {
  position: fixed; inset: 0; background: rgba(15,20,34,.82); display: none; align-items: center;
  justify-content: center; z-index: 2000; padding: 40px;
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
      <h1>บทความทั้งหมด <span class="ne-count"><?= number_format($total) ?> รายการ</span></h1>
    </div>
    <a href="<?= BASE_URL ?>/admin/modules/articles/create.php" class="ne-btn ne-btn-primary" style="margin-left:auto;">
      <i class="bi bi-plus-lg"></i>เพิ่มบทความ
    </a>
  </div>

  <!-- Filters -->
  <form class="ne-filters" method="GET">
    <div class="ne-filters-field ne-search">
      <label for="fSearch">ค้นหา</label>
      <div class="ne-search-input-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="fSearch" class="ne-input" name="search" placeholder="ค้นหาบทความ..." value="<?= e($search) ?>">
      </div>
    </div>
    <div class="ne-filters-field">
      <label for="fStatus">สถานะ</label>
      <select id="fStatus" class="ne-select" name="status">
        <option value="">-- ทุกสถานะ --</option>
        <option value="เผยแพร่" <?= $status === 'เผยแพร่' ? 'selected' : '' ?>>เผยแพร่</option>
        <option value="แบบร่าง" <?= $status === 'แบบร่าง' ? 'selected' : '' ?>>แบบร่าง</option>
      </select>
    </div>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-funnel"></i>ค้นหา</button>
      <a href="?" class="ne-btn ne-btn-ghost">ล้าง</a>
    </div>
  </form>

  <!-- Table -->
  <div class="ne-panel">
    <div class="ne-panel-head">
      <span>บทความทั้งหมด</span>
      <span>หน้า <?= $pager['current_page'] ?> / <?= max(1, $pager['total_pages']) ?></span>
    </div>

    <?php if (!empty($articles)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th style="width:64px">ภาพ</th>
            <th>หัวข้อบทความ</th>
            <th style="width:110px">สถานะ</th>
            <th style="width:90px">เข้าชม</th>
            <th style="width:120px">วันที่</th>
            <th style="width:130px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($articles as $i => $a): ?>
          <?php
            $viewUrl = !empty($a['slug'])
                ? BASE_URL . '/articles.php?slug=' . urlencode($a['slug'])
                : BASE_URL . '/articles.php?id=' . $a['article_id'];
          ?>
          <tr>
            <td class="ne-num"><?= $pager['offset'] + $i + 1 ?></td>
            <td>
              <?php if ($a['image']): ?>
              <img src="<?= UPLOAD_URL . '/' . e($a['image']) ?>" alt="" class="ne-thumb"
                   onclick="neOpenLightbox('<?= UPLOAD_URL . '/' . e($a['image']) ?>')">
              <?php else: ?>
              <div class="ne-thumb-empty"><i class="bi bi-file-earmark-text"></i></div>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= BASE_URL ?>/admin/modules/articles/edit.php?id=<?= $a['article_id'] ?>" class="ne-title-link">
                <?= e(mb_strimwidth($a['title'], 0, 60, '...', 'UTF-8')) ?>
              </a>
              <div class="ne-byline">โดย <?= e($a['full_name']) ?></div>
            </td>
            <td>
              <?php if ($a['status'] === 'เผยแพร่'): ?>
                <span class="ne-badge ne-badge-published">เผยแพร่</span>
              <?php else: ?>
                <span class="ne-badge ne-badge-draft">แบบร่าง</span>
              <?php endif; ?>
            </td>
            <td><span class="ne-views"><i class="bi bi-eye"></i><?= number_format($a['view_count']) ?></span></td>
            <td><small class="text-muted"><?= thaiDate($a['created_at']) ?></small></td>
            <td>
              <div class="ne-actions">
                <a href="<?= e($viewUrl) ?>" target="_blank" rel="noopener" class="ne-icon-btn ne-icon-view" title="ดูบทความ">
                  <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <a href="<?= BASE_URL ?>/admin/modules/articles/edit.php?id=<?= $a['article_id'] ?>" class="ne-icon-btn ne-icon-edit" title="แก้ไข">
                  <i class="bi bi-pencil"></i>
                </a>
                <button class="ne-icon-btn ne-icon-delete" title="ลบ"
                  onclick="confirmDelete('<?= BASE_URL ?>/admin/modules/articles/delete.php?id=<?= $a['article_id'] ?>',
                  '<?= e(addslashes($a['title'])) ?>')">
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
      <i class="bi bi-file-earmark-text"></i>
      ไม่พบบทความ<?= $search ? ' ที่ตรงกับ "' . e($search) . '"' : '' ?>
      <div class="mt-2">
        <a href="<?= BASE_URL ?>/admin/modules/articles/create.php" class="ne-btn ne-btn-primary" style="display:inline-flex;margin-top:10px;">
          <i class="bi bi-plus-lg"></i>เพิ่มบทความแรกของคุณ
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
  }
});
</script>

<?php include BASE_PATH . '/admin/layout/footer.php'; ?>