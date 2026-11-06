<?php
/**
 * BAIT Admin - E-Bidding announcements (ประกาศประกวดราคาด้วยวิธีประกวดราคาอิเล็กทรอนิกส์).
 * Each announcement is a title, its announce date and a link to the documents on Google Drive (nothing is uploaded here);
 * shown on the public ebidding.php newest first. Add / edit in a modal, show-hide and delete inline (same flow as info-links).
 * Table `ebidding` comes from migration 20 — until it is run, the page says so instead of failing.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->currentUser();
$db   = Database::getInstance();
$ready = hasEbidding();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$ready) { verifyCsrf(); jsonError('ยังไม่ได้สร้างตาราง E-Bidding ในฐานข้อมูล (migration 20)'); }

// Show / hide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verifyCsrf();
    $id = (int)($_POST['bid_id'] ?? 0);
    $b  = $db->fetchOne("SELECT is_active FROM ebidding WHERE bid_id = ?", [$id]);
    if ($b) { $db->update('ebidding', ['is_active' => $b['is_active'] ? 0 : 1], 'bid_id = ?', [$id]); jsonSuccess([], $b['is_active'] ? 'ซ่อนประกาศแล้ว' : 'แสดงประกาศแล้ว'); }
    jsonError('ไม่พบประกาศ');
}

// Delete (nothing on disk: the documents live on Google Drive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int)($_POST['bid_id'] ?? 0);
    if ($db->fetchOne("SELECT bid_id FROM ebidding WHERE bid_id = ?", [$id])) { $db->delete('ebidding', 'bid_id = ?', [$id]); jsonSuccess([], 'ลบประกาศแล้ว'); }
    jsonError('ไม่พบประกาศ');
}

// Add / edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $id      = (int)($_POST['bid_id'] ?? 0);
    $title   = preg_replace('/\s+/u', ' ', postStr('title'));   // pasted titles often carry line breaks
    $linkUrl = postStr('link_url');
    $date    = postStr('announce_date');
    $isDraft = !empty($_POST['is_draft']) ? 1 : 0;

    if ($title === '') jsonError('กรุณากรอกหัวข้อประกาศ');
    if (mb_strlen($title, 'UTF-8') > 500) jsonError('หัวข้อประกาศยาวเกิน 500 ตัวอักษร');
    if ($linkUrl === '') jsonError('กรุณากรอกลิงก์เอกสาร');
    if (!isHttpUrl($linkUrl) || !filter_var($linkUrl, FILTER_VALIDATE_URL)) jsonError('ลิงก์ไม่ถูกต้อง กรุณาใส่ URL ให้ครบ ขึ้นต้นด้วย https://');
    if (strlen($linkUrl) > 500) jsonError('ลิงก์ยาวเกิน 500 ตัวอักษร');
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) jsonError('กรุณาเลือกวันที่ประกาศ');
    if (rowExists($db, 'ebidding', ['title' => $title, 'announce_date' => $date], 'bid_id', $id)) jsonError('มีประกาศหัวข้อนี้ในวันที่เดียวกันอยู่แล้ว กรุณาตรวจสอบรายการเดิม');

    $data = ['title' => $title, 'link_url' => $linkUrl, 'announce_date' => $date, 'is_draft' => $isDraft];
    if ($id) {
        if (!$db->fetchOne("SELECT bid_id FROM ebidding WHERE bid_id = ?", [$id])) jsonError('ไม่พบประกาศ');
        $db->update('ebidding', $data, 'bid_id = ?', [$id]);
        jsonSuccess([], 'แก้ไขประกาศเรียบร้อยแล้ว');
    }
    $db->insert('ebidding', $data + ['is_active' => 1, 'created_by' => (int)($user['user_id'] ?? 0) ?: null]);
    jsonSuccess([], 'เพิ่มประกาศเรียบร้อยแล้ว');
}

$search  = getStr('search');
$page    = max(1, (int)($_GET['page'] ?? 1));
$rows = []; $total = 0;
$pager = paginate(0, 20, 1);
if ($ready) {
    $where = '1=1'; $params = [];
    if ($search !== '') { $where .= ' AND title LIKE ?'; $params[] = '%' . addcslashes($search, '%_\\') . '%'; }
    $total = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM ebidding WHERE $where", $params)['c'] ?? 0);
    $pager = paginate($total, 20, $page, '?search=' . urlencode($search) . '&page={page}');
    $rows  = $db->fetchAll("SELECT * FROM ebidding WHERE $where ORDER BY announce_date DESC, bid_id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}", $params);
}

$pageTitle   = 'E-Bidding';
$activeMenu  = 'ebidding';
$breadcrumb  = [['label' => 'E-Bidding']];
$pageActions = ''; $hidePageHeader = true;   // the topbar below carries the title + count
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <h1>E-Bidding <span class="ne-count"><?= $total ?> ประกาศ</span></h1>
    <?php if ($ready): ?>
    <button type="button" class="ne-btn ne-btn-primary" onclick="openBid(0)"><i class="bi bi-plus-lg"></i>เพิ่มประกาศ</button>
    <?php endif; ?>
  </div>

  <?php if (!$ready): ?>
  <div class="ne-alert ne-alert-warn"><i class="bi bi-exclamation-triangle"></i> ยังไม่มีตาราง E-Bidding ในฐานข้อมูล ให้รันไฟล์ SQL <b>bait_migration_20_ebidding.sql</b> ใน phpMyAdmin (เลือกฐานข้อมูลของเว็บก่อน) แล้วเปิดหน้านี้ใหม่</div>
  <?php else: ?>

  <div class="ne-alert ne-alert-info" style="margin-bottom:16px;"><i class="bi bi-info-circle"></i> ประกาศจะแสดงในหน้า <b>E-Bidding</b> ของเว็บไซต์ เรียงตามวันที่ประกาศ (ใหม่ไปเก่า) เอกสารให้เก็บไว้ใน Google Drive แล้วนำลิงก์มาใส่ อย่าลืมตั้งค่าการแชร์เป็น "ทุกคนที่มีลิงก์"</div>

  <form class="ne-filters" method="GET">
    <div class="ne-filters-field ne-search">
      <label for="fSearch">ค้นหา</label>
      <div class="ne-search-input-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="fSearch" class="ne-input" name="search" placeholder="ค้นหาจากหัวข้อประกาศ..." value="<?= e($search) ?>">
      </div>
    </div>
    <div class="ne-filters-actions">
      <button type="submit" class="ne-btn ne-btn-primary"><i class="bi bi-funnel"></i>ค้นหา</button>
      <a href="?" class="ne-btn ne-btn-ghost">ล้าง</a>
    </div>
  </form>

  <div class="ne-panel">
    <div class="ne-panel-head">
      <span>ประกาศทั้งหมด</span>
      <span>หน้า <?= $pager['current_page'] ?> / <?= max(1, $pager['total_pages']) ?></span>
    </div>
    <?php if ($rows): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th>หัวข้อประกาศ</th>
            <th style="width:130px">วันที่ประกาศ</th>
            <th style="width:120px">ประเภท</th>
            <th style="width:150px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $r): ?>
          <tr<?= $r['is_active'] ? '' : ' style="opacity:.6"' ?>>
            <td class="ne-num"><?= $pager['offset'] + $i + 1 ?></td>
            <td style="min-width:280px;white-space:normal">
              <?php /* each space-separated phrase stays whole (the browser's Thai dictionary splits place names like ท่าวาสุกรี) */ ?>
              <div style="line-height:1.55"><?= implode(' ', array_map(fn($w) => '<span style="display:inline-block">' . e($w) . '</span>', explode(' ', $r['title']))) ?></div>
              <a href="<?= e($r['link_url']) ?>" target="_blank" rel="noopener" class="ne-hint" style="display:inline-flex;gap:4px;margin-top:4px;word-break:break-all"><i class="bi bi-box-arrow-up-right"></i><?= e(mb_strimwidth($r['link_url'], 0, 70, '...', 'UTF-8')) ?></a>
              <?php if (!$r['is_active']): ?><div class="ne-hint" style="margin-top:2px"><i class="bi bi-eye-slash"></i> ซ่อนอยู่ ไม่แสดงบนเว็บ</div><?php endif; ?>
            </td>
            <td class="ne-num"><?= e(thaiDate($r['announce_date'])) ?></td>
            <td><span class="ne-badge <?= $r['is_draft'] ? 'ne-badge-pending' : 'ne-badge-published' ?>"><?= $r['is_draft'] ? 'ร่างประกาศ' : 'ประกาศ' ?></span></td>
            <td>
              <div class="ne-actions">
                <button type="button" class="ne-icon-btn ne-icon-edit" onclick="openBid(<?= (int)$r['bid_id'] ?>)" title="แก้ไข" aria-label="แก้ไข"><i class="bi bi-pencil"></i></button>
                <button type="button" class="ne-icon-btn <?= $r['is_active'] ? 'ne-icon-edit' : '' ?>" onclick="toggleBid(<?= (int)$r['bid_id'] ?>)" title="<?= $r['is_active'] ? 'ซ่อน' : 'แสดง' ?>" aria-label="<?= $r['is_active'] ? 'ซ่อน' : 'แสดง' ?>"><i class="bi bi-<?= $r['is_active'] ? 'eye' : 'eye-slash' ?>"></i></button>
                <button type="button" class="ne-icon-btn ne-icon-delete" onclick="deleteBid(<?= (int)$r['bid_id'] ?>)" title="ลบ" aria-label="ลบ"><i class="bi bi-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="ne-empty"><i class="bi bi-hammer"></i><?= $search !== '' ? 'ไม่พบประกาศที่ค้นหา' : 'ยังไม่มีประกาศ กด "เพิ่มประกาศ" เพื่อเริ่ม' ?></div>
    <?php endif; ?>
    <?php if ($pager['total_pages'] > 1): ?><div style="padding:12px 20px;border-top:1px solid var(--ne-line);"><?= paginationHtml($pager) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($ready): ?>
<div class="modal fade" id="bidModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content ne-modal-content ne">
      <div class="modal-header ne-modal-header">
        <h5 class="modal-title" id="bidModalTitle" style="font-family:'Kanit',serif;">เพิ่มประกาศ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body ne-modal-body">
        <input type="hidden" id="bidId" value="0">
        <div class="ne-field">
          <label for="bidTitle">หัวข้อประกาศ <span style="color:var(--ne-danger)">*</span></label>
          <textarea class="ne-input" id="bidTitle" rows="3" maxlength="500" placeholder="เช่น ประกวดราคาซื้อชุดครุภัณฑ์... ด้วยวิธีประกวดราคาอิเล็กทรอนิกส์ (e-bidding)"></textarea>
          <p class="ne-hint">ใส่ชื่อเรื่องเต็มตามประกาศ ไม่ต้องพิมพ์เลขลำดับหรือคำว่า "(ร่าง)" ระบบใส่ให้เอง</p>
        </div>
        <div class="ne-field">
          <label for="bidUrl">ลิงก์เอกสาร (Google Drive) <span style="color:var(--ne-danger)">*</span></label>
          <input type="url" class="ne-input" id="bidUrl" maxlength="500" placeholder="https://drive.google.com/...">
          <p class="ne-hint">ลิงก์โฟลเดอร์หรือไฟล์ก็ได้ ตั้งค่าการแชร์เป็น "ทุกคนที่มีลิงก์" เพื่อให้บริษัทเปิดดูได้</p>
        </div>
        <div class="ne-field">
          <label for="bidDate">วันที่ประกาศ <span style="color:var(--ne-danger)">*</span></label>
          <input type="date" class="ne-input" id="bidDate" style="max-width:220px">
        </div>
        <div class="ne-field" style="margin-bottom:0;">
          <label>ประเภท</label>
          <div role="radiogroup" aria-label="ประเภทประกาศ" style="display:flex;gap:16px;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:6px;font-weight:400"><input type="radio" name="bidType" value="0" checked> ประกาศ</label>
            <label style="display:flex;align-items:center;gap:6px;font-weight:400"><input type="radio" name="bidType" value="1"> ร่างประกาศ (ประชาพิจารณ์)</label>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
        <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="ne-btn ne-btn-primary" id="bidSaveBtn" onclick="saveBid()"><i class="bi bi-plus-lg"></i>เพิ่มประกาศ</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = !$ready ? '' : '
<script>
const csrf = ' . json_encode(csrfToken()) . ';
const bids = ' . json_encode(array_map(fn($r) => [
    'id'    => (int)$r['bid_id'],
    'title' => $r['title'],
    'url'   => $r['link_url'],
    'date'  => $r['announce_date'],
    'draft' => (int)$r['is_draft'],
], $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';
const today = ' . json_encode(date('Y-m-d')) . ';
let bidModal;

function openBid(id) {
  const b = id ? bids.find(x => x.id === id) : null;
  document.getElementById("bidId").value = b ? b.id : 0;
  document.getElementById("bidTitle").value = b ? b.title : "";
  document.getElementById("bidUrl").value = b ? b.url : "";
  document.getElementById("bidDate").value = b ? b.date : today;
  document.querySelector("input[name=bidType][value=\"" + (b && b.draft ? 1 : 0) + "\"]").checked = true;
  document.getElementById("bidModalTitle").textContent = b ? "แก้ไขประกาศ" : "เพิ่มประกาศ";
  document.getElementById("bidSaveBtn").innerHTML = b ? "<i class=\"bi bi-save\"></i>บันทึกการแก้ไข" : "<i class=\"bi bi-plus-lg\"></i>เพิ่มประกาศ";
  bidModal = bootstrap.Modal.getOrCreateInstance(document.getElementById("bidModal"));
  bidModal.show();
}

async function saveBid() {
  const title = document.getElementById("bidTitle").value.trim();
  const url   = document.getElementById("bidUrl").value.trim();
  const date  = document.getElementById("bidDate").value;
  if (!title) { showError("กรุณากรอกหัวข้อประกาศ"); return; }
  if (!url) { showError("กรุณากรอกลิงก์เอกสาร"); return; }
  if (!/^https?:\/\//i.test(url)) { showError("ลิงก์ต้องขึ้นต้นด้วย https://"); return; }
  if (!date) { showError("กรุณาเลือกวันที่ประกาศ"); return; }
  const fd = new FormData();
  fd.append("action", "save");
  fd.append("bid_id", document.getElementById("bidId").value);
  fd.append("title", title);
  fd.append("link_url", url);
  fd.append("announce_date", date);
  if (document.querySelector("input[name=bidType]:checked").value === "1") fd.append("is_draft", "1");
  fd.append("csrf_token", csrf);
  const btn = document.getElementById("bidSaveBtn"); btn.disabled = true;
  try {
    const r = await axios.post("", fd);
    if (r.data.status === "success") { bidModal.hide(); showSuccess(r.data.message); setTimeout(() => location.reload(), 1200); }
    else showError(r.data.message);
  } catch (e) { showError(e.response?.data?.message || "บันทึกไม่สำเร็จ ลองอีกครั้ง"); }
  finally { btn.disabled = false; }
}

async function toggleBid(id) {
  const fd = new FormData();
  fd.append("action", "toggle"); fd.append("bid_id", id); fd.append("csrf_token", csrf);
  try {
    const r = await axios.post("", fd);
    if (r.data.status === "success") { showSuccess(r.data.message); setTimeout(() => location.reload(), 1000); } else showError(r.data.message);
  } catch (e) { showError(e.response?.data?.message || "ทำรายการไม่สำเร็จ"); }
}

async function deleteBid(id) {
  const c = await Swal.fire({ title: "ลบประกาศนี้?", text: "ไฟล์ใน Google Drive ไม่ถูกลบ ลบเฉพาะรายการบนเว็บ", icon: "warning", showCancelButton: true,
    confirmButtonColor: "#c1443d", confirmButtonText: "ลบ", cancelButtonText: "ยกเลิก" });
  if (!c.isConfirmed) return;
  const fd = new FormData();
  fd.append("action", "delete"); fd.append("bid_id", id); fd.append("csrf_token", csrf);
  try {
    const r = await axios.post("", fd);
    if (r.data.status === "success") { showSuccess(r.data.message); setTimeout(() => location.reload(), 1000); } else showError(r.data.message);
  } catch (e) { showError(e.response?.data?.message || "ลบไม่สำเร็จ"); }
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>
