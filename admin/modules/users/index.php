<?php
/**
 * BAIT Admin - User Management (Superadmin only)
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireSuperAdmin();
$db   = Database::getInstance();
$me   = $auth->currentUser();

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $username = postStr('username');
        $fullName = postStr('full_name');
        $email    = postStr('email');
        $role     = $_POST['role'] ?? 'subadmin';
        $password = postRaw('password');
        $isActive = (int)($_POST['is_active'] ?? 1);

        if (!$username || !$fullName || !$email) jsonError('กรุณากรอกข้อมูลให้ครบ');
        if (!in_array($role, ['superadmin','subadmin'])) jsonError('สิทธิ์การใช้งานไม่ถูกต้อง');
        // ระบบมี Super Admin ได้บัญชีเดียว: เพิ่มผู้ใช้ใหม่เป็น Super Admin หรือเลื่อนผู้ใช้เดิมขึ้นเป็น Super Admin ไม่ได้
        // (บัญชี Super Admin ที่มีอยู่แล้วแก้ไขข้อมูลอื่นได้ตามปกติ)
        if ($role === 'superadmin') {
            $wasSuper = $uid && ($db->fetchOne('SELECT role FROM users WHERE user_id = ?', [$uid])['role'] ?? '') === 'superadmin';
            if (!$wasSuper) jsonError('ระบบอนุญาตให้มี Super Admin ได้เพียงบัญชีเดียว ไม่สามารถเพิ่มหรือเลื่อนผู้ใช้เป็น Super Admin ได้ กรุณาเลือก Sub Admin');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) jsonError('ชื่อผู้ใช้ต้องเป็นตัวอักษรอังกฤษ ตัวเลข _ . - ความยาว 3-50 ตัว');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('รูปแบบอีเมลไม่ถูกต้อง');
        if ($password !== '' && strlen($password) < 8) jsonError('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
        // The username must be unique (a second one used to crash the save with a database error).
        $taken = $db->fetchOne('SELECT user_id FROM users WHERE username = ? AND user_id != ?', [$username, $uid]);
        if ($taken) jsonError('ชื่อผู้ใช้ "' . $username . '" มีอยู่ในระบบแล้ว กรุณาใช้ชื่ออื่น');
        if (rowExists($db, 'users', ['email' => $email], 'user_id', $uid)) jsonError('อีเมล "' . $email . '" ถูกใช้โดยผู้ใช้อื่นแล้ว กรุณาใช้อีเมลอื่น');

        $data = [
            'username'  => $username,
            'full_name' => $fullName,
            'email'     => $email,
            'role'      => $role,
            'is_active' => $isActive,
        ];

        if ($uid) {
            if ($password) $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            // ห้ามแก้ตัวเองที่นี่ (กันเปลี่ยน role/ปิดใช้งานตัวเองพลาด) — แก้ไขบัญชีตัวเอง
            // รวมถึงเปลี่ยนรหัสผ่าน ให้ไปที่หน้า "โปรไฟล์ของฉัน" แทน
            if ($uid === $me['user_id']) jsonError('ไม่สามารถแก้ไขบัญชีของตัวเองได้ที่นี่ กรุณาไปที่หน้า "โปรไฟล์ของฉัน" (มุมขวาบน) เพื่อแก้ไขข้อมูลหรือเปลี่ยนรหัสผ่าน');
            $db->update('users', $data, 'user_id = ?', [$uid]);
            if ($password) $auth->endOtherSessions($uid);   // a password set by the super admin signs that user out everywhere
            jsonSuccess([], 'แก้ไขผู้ใช้เรียบร้อยแล้ว');
        } else {
            if (!$password) jsonError('กรุณากรอกรหัสผ่าน');
            $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $newId = $db->insert('users', $data);
            jsonSuccess(['user_id' => $newId], 'เพิ่มผู้ใช้เรียบร้อยแล้ว');
        }
    }

    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $me['user_id']) jsonError('ไม่สามารถลบบัญชีของตัวเองได้');
        $db->update('users', ['is_active' => 0], 'user_id = ?', [$uid]);
        jsonSuccess([], 'ปิดการใช้งานผู้ใช้แล้ว');
    }
}

$users = $db->fetchAll(
    "SELECT user_id, username, full_name, email, role, is_active, last_login, created_at
     FROM users ORDER BY role DESC, created_at ASC"
);

$pageTitle  = 'จัดการผู้ใช้งาน';
$activeMenu = 'users';
$breadcrumb = [['label' => 'จัดการผู้ใช้งาน']];
$pageActions= '';
include BASE_PATH . '/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <h1>ผู้ใช้งานทั้งหมด <span class="ne-count"><?= count($users) ?> คน</span></h1>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openModal(0)">
      <i class="bi bi-plus-lg"></i>เพิ่มผู้ใช้
    </button>
  </div>

  <div class="ne-panel">
    <div class="ne-panel-head"><span>ผู้ใช้งานทั้งหมด</span></div>
    <?php if (!empty($users)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th>ชื่อผู้ใช้</th>
            <th>ชื่อ-นามสกุล</th>
            <th>อีเมล</th>
            <th>สิทธิ์</th>
            <th>สถานะ</th>
            <th>เข้าใช้ล่าสุด</th>
            <th style="width:100px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $i => $u): ?>
          <tr>
            <td class="ne-num"><?= $i + 1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:32px;height:32px;background:var(--ne-blue);border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-size:0.8rem;font-weight:700;flex-shrink:0">
                  <?= e(mb_substr($u['full_name'], 0, 1, 'UTF-8')) ?>
                </div>
                <span class="fw-500"><?= e($u['username']) ?></span>
              </div>
            </td>
            <td><?= e($u['full_name']) ?></td>
            <td class="ne-num"><?= e($u['email']) ?></td>
            <td>
              <?php if ($u['role'] === 'superadmin'): ?>
                <span class="ne-chip" style="background:var(--ne-blue-soft);color:var(--ne-blue-deep);">Super Admin</span>
              <?php else: ?>
                <span class="ne-chip" style="background:var(--ne-slate-soft);color:var(--ne-ink-soft);">Sub Admin</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['is_active']): ?>
                <span class="ne-badge ne-badge-published">ใช้งาน</span>
              <?php else: ?>
                <span class="ne-badge ne-badge-closed">ปิดใช้งาน</span>
              <?php endif; ?>
            </td>
            <td class="ne-num"><?= $u['last_login'] ? thaiDate($u['last_login'], true) : '-' ?></td>
            <td>
              <div class="ne-actions">
                <?php if ($u['user_id'] === $me['user_id']): ?>
                <a href="<?= BASE_URL ?>/admin/modules/users/profile.php" class="ne-icon-btn ne-icon-edit" title="แก้ไขบัญชีของฉัน (รวมเปลี่ยนรหัสผ่าน) ที่หน้าโปรไฟล์">
                  <i class="bi bi-person-gear"></i>
                </a>
                <?php else: ?>
                <button class="ne-icon-btn ne-icon-edit"
                        onclick="openModal(<?= $u['user_id'] ?>)" title="แก้ไข">
                  <i class="bi bi-pencil"></i>
                </button>
                <button class="ne-icon-btn ne-icon-delete"
                        onclick="deleteUser(<?= $u['user_id'] ?>, '<?= e(addslashes($u['full_name'])) ?>')" title="ปิดใช้งาน">
                  <i class="bi bi-person-x"></i>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="ne-empty"><i class="bi bi-people"></i>ยังไม่มีผู้ใช้งาน</div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content ne-modal-content ne">
      <div class="modal-header ne-modal-header">
        <h5 class="modal-title" id="modalTitle" style="font-family:'Kanit',serif;">เพิ่มผู้ใช้งาน</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:20px;">
        <input type="hidden" id="editUserId" value="0">
        <div class="ne-meta-row" style="margin-top:0;padding-top:0;border-top:none;">
          <div class="ne-meta-field"><label for="fUsername">ชื่อผู้ใช้ <span style="color:var(--ne-danger)">*</span></label>
            <input type="text" class="ne-input" id="fUsername" placeholder="ชื่อผู้ใช้งาน"></div>
          <div class="ne-meta-field"><label for="fRole">สิทธิ์</label>
            <select class="ne-select" id="fRole">
              <option value="subadmin">Sub Admin</option>
              <option value="superadmin" id="optSuper" disabled hidden>Super Admin</option>
            </select>
            <small class="ne-hint">ระบบมี Super Admin ได้เพียงบัญชีเดียว</small></div>
        </div>
        <div class="ne-field"><label for="fFullName">ชื่อ-นามสกุล <span style="color:var(--ne-danger)">*</span></label>
          <input type="text" class="ne-input" id="fFullName" placeholder="ชื่อ-นามสกุล"></div>
        <div class="ne-field"><label for="fEmail">อีเมล <span style="color:var(--ne-danger)">*</span></label>
          <input type="email" class="ne-input" id="fEmail" placeholder="อีเมล"></div>
        <div class="ne-field"><label for="fPassword">รหัสผ่าน <span id="pwdRequired" style="color:var(--ne-danger)">*</span></label>
          <input type="password" class="ne-input" id="fPassword" placeholder="รหัสผ่าน (ปล่อยว่างเพื่อไม่เปลี่ยน)"></div>
        <div class="ne-field" style="margin-bottom:0;"><label for="fIsActive">สถานะ</label>
          <select class="ne-select" id="fIsActive">
            <option value="1">ใช้งาน</option>
            <option value="0">ปิดใช้งาน</option>
          </select></div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
        <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="ne-btn ne-btn-primary" onclick="saveUser()">
          <i class="bi bi-check-lg"></i>บันทึก
        </button>
      </div>
    </div>
  </div>
</div>

<script id="usersData" type="application/json">
<?= json_encode($users, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
</script>

<?php
$extraJs = '
<script>
const usersData  = JSON.parse(document.getElementById("usersData").textContent);
const csrfToken  = ' . json_encode(csrfToken()) . ';
let   modalInst  = null;

function openModal(id) {
  document.getElementById("editUserId").value = id;
  const u = usersData.find(u => u.user_id == id);

  if (u) {
    document.getElementById("modalTitle").textContent = "แก้ไขผู้ใช้งาน";
    document.getElementById("fUsername").value  = u.username;
    document.getElementById("fFullName").value  = u.full_name;
    document.getElementById("fEmail").value     = u.email;
    const optSuper = document.getElementById("optSuper");
    optSuper.disabled = optSuper.hidden = (u.role !== "superadmin");   // เลือก Super Admin ได้เฉพาะบัญชีที่เป็นอยู่แล้ว
    document.getElementById("fRole").value      = u.role;
    document.getElementById("fIsActive").value  = u.is_active;
    document.getElementById("fPassword").value  = "";
    document.getElementById("pwdRequired").style.display = "none";
  } else {
    document.getElementById("modalTitle").textContent = "เพิ่มผู้ใช้งาน";
    ["fUsername","fFullName","fEmail","fPassword"].forEach(id => document.getElementById(id).value = "");
    const optSuperNew = document.getElementById("optSuper");
    optSuperNew.disabled = optSuperNew.hidden = true;   // ผู้ใช้ใหม่เป็น Super Admin ไม่ได้
    document.getElementById("fRole").value     = "subadmin";
    document.getElementById("fIsActive").value = "1";
    document.getElementById("pwdRequired").style.display = "inline";
  }
  modalInst = new bootstrap.Modal(document.getElementById("userModal"));
  modalInst.show();
}

async function saveUser() {
  const fd = new FormData();
  fd.append("action",    "save");
  fd.append("user_id",   document.getElementById("editUserId").value);
  fd.append("username",  document.getElementById("fUsername").value);
  fd.append("full_name", document.getElementById("fFullName").value);
  fd.append("email",     document.getElementById("fEmail").value);
  fd.append("role",      document.getElementById("fRole").value);
  fd.append("password",  document.getElementById("fPassword").value);
  fd.append("is_active", document.getElementById("fIsActive").value);
  fd.append("csrf_token", csrfToken);

  try {
    const res = await axios.post("", fd);
    if (res.data.status === "success") {
      modalInst.hide();
      showSuccess(res.data.message);
      setTimeout(() => location.reload(), 1500);
    } else showError(res.data.message);
  } catch(e) { showError(e.response?.data?.message || "เกิดข้อผิดพลาด"); }
}

async function deleteUser(id, name) {
  const result = await Swal.fire({
    title: "ปิดการใช้งาน?",
    html: `ต้องการปิดการใช้งาน <strong>${escapeHtml(name)}</strong> ใช่หรือไม่?`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#c1443d",
    cancelButtonColor: "#6c757d",
    confirmButtonText: "ปิดใช้งาน",
    cancelButtonText: "ยกเลิก",
  });
  if (!result.isConfirmed) return;

  const fd = new FormData();
  fd.append("action", "delete");
  fd.append("user_id", id);
  fd.append("csrf_token", csrfToken);

  try {
    const res = await axios.post("", fd);
    if (res.data.status === "success") {
      showSuccess(res.data.message);
      setTimeout(() => location.reload(), 1500);
    } else showError(res.data.message);
  } catch(e) { showError("เกิดข้อผิดพลาด"); }
}
</script>';

include BASE_PATH . '/admin/layout/footer.php';
?>
