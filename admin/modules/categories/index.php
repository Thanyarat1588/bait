<?php
/**
 * BAIT Admin - Category Management
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id   = (int)($_POST['category_id'] ?? 0);
        $name = postStr('category_name');
        $color= postStr('color', '#162E93');

        if (!$name) jsonError('กรุณากรอกชื่อประเภท');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) jsonError('สีไม่ถูกต้อง กรุณาเลือกสีจากช่องเลือกสี');
        if (nameTaken($db, 'categories', 'category_name', 'category_id', $name, $id)) jsonError(dupMsg('ประเภทข่าว', $name));

        $data = ['category_name' => $name, 'color' => $color];

        if ($id) {
            $db->update('categories', $data, 'category_id = ?', [$id]);
            jsonSuccess([], 'แก้ไขประเภทเรียบร้อยแล้ว');
        } else {
            $data['slug'] = createSlug($name);
            $newId = $db->insert('categories', $data);
            jsonSuccess(['category_id' => $newId], 'เพิ่มประเภทเรียบร้อยแล้ว');
        }
    }

    if ($action === 'delete') {
        $id    = (int)($_POST['category_id'] ?? 0);
        $count = $db->count('news', 'category_id = ?', [$id]);
        if ($count > 0) jsonError("ไม่สามารถลบได้ มีข่าวสาร $count รายการที่ใช้ประเภทนี้อยู่");
        $db->delete('categories', 'category_id = ?', [$id]);
        jsonSuccess([], 'ลบประเภทเรียบร้อยแล้ว');
    }
}

$categories = $db->fetchAll(
    "SELECT c.*, COUNT(n.news_id) as news_count
     FROM categories c LEFT JOIN news n ON n.category_id = c.category_id
     GROUP BY c.category_id ORDER BY c.category_id ASC"
);

// แปลงสี hex ของประเภทข่าวเป็นสไตล์ chip (พื้นอ่อน 12% + ตัวอักษรเป็นสีเข้ม)
function categoryChipStyle(?string $hex): string {
    $hex = $hex ?: '#162E93';
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = '3e5fa3';
    return "background:#{$hex}1f;color:#{$hex};box-shadow:inset 0 0 0 1px #{$hex}33;";
}

$pageTitle   = 'ประเภทข่าวสาร';
$activeMenu  = 'categories';
$breadcrumb  = [['label' => 'ประเภทข่าวสาร']];
$pageActions = ''; // ปุ่มเพิ่มประเภทย้ายไปอยู่ใน topbar ที่ออกแบบใหม่ด้านล่างแทน
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
.ne-btn-primary { background: var(--ne-blue) !important; color: #fff !important; }
.ne-btn-primary:hover { background: var(--ne-blue-deep) !important; color: #fff !important; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }
.ne-btn-block { width: 100%; justify-content: center; }

/* ── Layout grid ──────────────────────────── */
.ne-grid { display: flex; flex-direction: column; gap: 24px; align-items: stretch; }
.ne-main { flex: 1 1 0; min-width: 0; }
.ne-side { width: 100%; flex-shrink: 0; }
@media (min-width: 1024px) { .ne-grid { flex-direction: row; align-items: flex-start; } .ne-side { width: 320px; } }

/* ── Panel ────────────────────────────────── */
.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; overflow: hidden; }
.ne-panel-head {
  display: flex; align-items: center; justify-content: space-between; padding: 14px 20px;
  border-bottom: 1px solid var(--ne-line); font-size: 13.5px; font-weight: 600; color: var(--ne-ink-soft);
}
.ne-panel-count {
  display: inline-flex; align-items: center; font-size: 12px; font-weight: 700; padding: 2px 9px;
  border-radius: 999px; background: var(--ne-gold-soft); color: #75603a; margin-left: 4px;
}
.ne-panel-body { padding: 18px 20px 20px; }
.ne-panel-title {
  font-size: 12.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  color: var(--ne-ink-soft); display: flex; align-items: center; gap: 7px; margin: 0;
}
.ne-panel-title i { color: var(--ne-blue); font-size: 14px; }

/* ── Table ────────────────────────────────── */
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

.ne-chip {
  display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; padding: 5px 12px;
  border-radius: 999px; white-space: nowrap;
}
.ne-chip::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

.ne-slug { font-size: 12.5px; color: var(--ne-slate); background: var(--ne-paper); border-radius: 6px; padding: 3px 8px; font-family: monospace; }

.ne-badge-count {
  display: inline-flex; align-items: center; justify-content: center; min-width: 26px;
  font-size: 12.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px;
  background: var(--ne-slate-soft); color: var(--ne-ink-soft);
}

.ne-actions { display: flex; gap: 6px; }
.ne-icon-btn {
  width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--ne-line);
  background: var(--ne-surface); color: var(--ne-ink-soft);
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer; text-decoration: none; transition: all .15s ease; font-size: 14px;
}
.ne-icon-btn i { color: inherit !important; }
.ne-icon-btn.ne-icon-edit { color: var(--ne-blue) !important; border-color: #c3d9fb !important; background: var(--ne-blue-soft) !important; }
.ne-icon-btn.ne-icon-edit:hover { background: var(--ne-blue) !important; border-color: var(--ne-blue) !important; color: #fff !important; }
.ne-icon-btn.ne-icon-delete { color: var(--ne-danger) !important; border-color: #f0c9c6 !important; background: var(--ne-danger-soft) !important; }
.ne-icon-btn.ne-icon-delete:hover { background: var(--ne-danger) !important; border-color: var(--ne-danger) !important; color: #fff !important; }

.ne-empty { text-align: center; padding: 56px 20px; color: var(--ne-slate); }
.ne-empty i { font-size: 32px; display: block; margin-bottom: 10px; color: var(--ne-line); }

/* ── Quick add form ───────────────────────── */
.ne-field { margin-bottom: 16px; }
.ne-field label {
  font-size: 11.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
  color: var(--ne-slate); display: block; margin-bottom: 7px;
}
.ne-input {
  width: 100%; border: 1px solid var(--ne-line); border-radius: 8px; padding: 9px 12px;
  font-family: 'Kanit', sans-serif; font-size: 14px; color: var(--ne-ink);
  background: var(--ne-surface); outline: none; transition: border-color .15s ease;
}
.ne-input:focus { border-color: var(--ne-blue); }
.ne-color-row { display: flex; gap: 10px; align-items: center; }
.ne-color-input {
  width: 44px; height: 38px; border: 1px solid var(--ne-line); border-radius: 8px;
  padding: 3px; cursor: pointer; background: var(--ne-surface); flex-shrink: 0;
}
.ne-swatches { display: flex; gap: 6px; flex-wrap: wrap; }
.ne-swatch {
  width: 24px; height: 24px; border-radius: 7px; border: 2px solid var(--ne-surface);
  box-shadow: 0 0 0 1px var(--ne-line); cursor: pointer; transition: transform .12s ease;
}
.ne-swatch:hover { transform: scale(1.12); }

/* ── Modal restyle ────────────────────────── */
.ne-modal-header {
  background: var(--ne-blue-deep);
  color: #fff;
  border: none;
}
.ne-modal-header .btn-close { filter: brightness(0) invert(1); }
.ne-modal-content { border: none; border-radius: 14px; overflow: hidden; }
.ne-modal-body label {
  font-size: 11.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
  color: var(--ne-slate); display: block; margin-bottom: 7px;
}
.ne-modal-body .ne-input { margin-bottom: 14px; }
</style>

<div class="ne">
  <div class="ne-topbar">
    <h1>ประเภทข่าวสาร <span class="ne-count"><?= count($categories) ?> ประเภท</span></h1>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openModal(0)">
      <i class="bi bi-plus-lg"></i>เพิ่มประเภท
    </button>
  </div>

  <div class="ne-grid">

    <!-- Main: table -->
    <div class="ne-main">
      <div class="ne-panel">
        <div class="ne-panel-head">
          <span>ประเภทข่าวสารทั้งหมด </span>
        </div>

        <?php if (!empty($categories)): ?>
        <div class="table-responsive">
          <table class="ne-table">
            <thead>
              <tr>
                <th style="width:44px">#</th>
                <th>ชื่อประเภท</th>
                <th>Slug</th>
                <th style="width:110px" class="text-center">จำนวนข่าว</th>
                <th style="width:100px">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($categories as $i => $cat): ?>
              <tr>
                <td class="ne-num"><?= $i + 1 ?></td>
                <td>
                  <span class="ne-chip" style="<?= categoryChipStyle($cat['color']) ?>"><?= e($cat['category_name']) ?></span>
                </td>
                <td><span class="ne-slug"><?= e($cat['slug']) ?></span></td>
                <td class="text-center"><span class="ne-badge-count"><?= $cat['news_count'] ?></span></td>
                <td>
                  <div class="ne-actions">
                    <button class="ne-icon-btn ne-icon-edit" onclick="openModal(<?= $cat['category_id'] ?>)" title="แก้ไข">
                      <i class="bi bi-pencil"></i>
                    </button>
                    <button class="ne-icon-btn ne-icon-delete"
                            onclick="deleteCategory(<?= $cat['category_id'] ?>, '<?= e(addslashes($cat['category_name'])) ?>')"
                            title="ลบ">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="ne-empty">
          <i class="bi bi-tags"></i>
          ยังไม่มีประเภทข่าวสาร
        </div>
        <?php endif; ?>
      </div>
    </div>


  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="catModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content ne-modal-content ne">
      <div class="modal-header ne-modal-header">
        <h5 class="modal-title" style="font-family:'Kanit',serif;">แก้ไขประเภท</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body ne-modal-body">
        <input type="hidden" id="editCatId">
        <label for="editCatName">ชื่อประเภท</label>
        <input type="text" class="ne-input" id="editCatName">
        <label for="editCatColor">สี</label>
        <input type="color" class="ne-color-input" style="width:100%;height:42px;" id="editCatColor">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
        <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="ne-btn ne-btn-primary" onclick="saveCategory()">บันทึก</button>
      </div>
    </div>
  </div>
</div>

<script id="catsData" type="application/json"><?= json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php
$extraJs = '
<script>
const cats = JSON.parse(document.getElementById("catsData").textContent);
const csrf = ' . json_encode(csrfToken()) . ';
let modal;

function openModal(id) {
  const c = cats.find(x => x.category_id == id);
  document.getElementById("editCatId").value    = id;
  document.getElementById("editCatName").value  = c ? c.category_name : "";
  document.getElementById("editCatColor").value = c ? c.color : "#162E93";
  document.querySelector("#catModal .modal-title").textContent = id ? "แก้ไขประเภท" : "เพิ่มประเภทใหม่";
  modal = new bootstrap.Modal(document.getElementById("catModal"));
  modal.show();
}

async function saveCategory() {
  const fd = new FormData();
  fd.append("action","save");
  fd.append("category_id",   document.getElementById("editCatId").value);
  fd.append("category_name", document.getElementById("editCatName").value);
  fd.append("color",         document.getElementById("editCatColor").value);
  fd.append("csrf_token", csrf);
  const res = await axios.post("", fd);
  if (res.data.status === "success") { modal.hide(); showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
  else showError(res.data.message);
}

async function deleteCategory(id, name) {
  const c = cats.find(x => x.category_id == id);
  const nc = c ? Number(c.news_count) || 0 : 0;
  if (nc > 0) {
    await Swal.fire({title:"ไม่สามารถลบได้",html:`ประเภท <strong>${escapeHtml(name)}</strong> ยังมีข่าวสาร ${nc} รายการที่ใช้ประเภทนี้อยู่ กรุณาย้ายหรือลบข่าวที่เกี่ยวข้องก่อน`,icon:"warning",confirmButtonText:"เข้าใจแล้ว"});
    return;
  }
  const r = await Swal.fire({title:"ลบประเภท?",html:`ลบ <strong>${escapeHtml(name)}</strong> ?`,icon:"warning",
    showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});
  if (!r.isConfirmed) return;
  const fd = new FormData();
  fd.append("action","delete"); fd.append("category_id",id); fd.append("csrf_token",csrf);
  const res = await axios.post("",fd);
  if (res.data.status==="success"){ showSuccess(res.data.message); setTimeout(()=>location.reload(),1200); }
  else showError(res.data.message);
}
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>