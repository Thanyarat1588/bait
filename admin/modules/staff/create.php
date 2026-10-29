<?php
/**
 * BAIT Admin - Staff Create / Edit
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

$isEdit  = isset($_GET['id']);
$staffId = $isEdit ? (int)$_GET['id'] : 0;
$staff   = [];

if ($isEdit) {
    $staff = $db->fetchOne("SELECT * FROM staff WHERE staff_id = ?", [$staffId]);
    if (!$staff) { header('Location: index.php'); exit; }
}

$positions  = $db->fetchAll("SELECT * FROM positions ORDER BY position_level DESC, position_name");
$campuses   = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$majors     = $db->fetchAll("SELECT * FROM majors ORDER BY major_name");
$departments= $db->fetchAll("SELECT * FROM departments ORDER BY department_id");
$staffTypes = $db->fetchAll("SELECT * FROM staff_types ORDER BY staff_type_id");
// หัวหน้างาน = the boss this person hangs under on the ผังผู้บริหาร, so only people already on that chart can be picked
// (anyone else would become a new top of their own instead). Worked out without this person, so nobody under them
// (a loop) and nobody who is only on the chart because of them is offered. While the chart is still empty anyone can be
// picked, to start it.
$chart = execChart($isEdit ? $staffId : null);
// the chart is empty without this person when they are its only top (e.g. the Dean): then anyone not under them may be
// picked, and that person becomes the new top above them
$myUnder = $isEdit ? staffUnder($staffId) : [];
$myReports = $isEdit ?(int)($db->fetchOne('SELECT COUNT(*) AS c FROM staff s WHERE s.supervisor_id = ? AND ' . staffCurrentSql('s'), [$staffId])['c'] ?? 0) : 0;
// the only top of the chart (the Dean): nobody is above them, so the list is closed; it opens only when the admin ticks
// "เปลี่ยนยอดผัง" on purpose (a new Dean: the person picked becomes the top above this one)
$isSoleTop = $isEdit && !$chart['members'] && $myReports > 0;
$supervisorOptions = array_values(array_filter($db->fetchAll(
    "SELECT s.staff_id, s.prefix, s.full_name, p.position_name
     FROM staff s JOIN positions p ON p.position_id = s.position_id
     WHERE " . staffCurrentSql('s') . ($isEdit ? ' AND s.staff_id != ?' : '') . "
     ORDER BY p.position_level DESC, s.display_order ASC, s.full_name ASC",
    $isEdit ? [$staffId] : []
), fn($o) => $chart['members'] ? isset($chart['members'][(int)$o['staff_id']]) : !in_array((int)$o['staff_id'], $myUnder, true)));

// Keyed by form field name so each message can both (a) highlight the
// exact input it belongs to and (b) show up as its own line in the
// SweetAlert summary — a flat list of strings can't drive the highlight.
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $prefix       = postStr('prefix', 'นาย');
    $fullName     = postStr('full_name');
    $fullNameEn   = trim(preg_replace('/\s+/', ' ', postStr('full_name_en')));
    // The English name counts as confirmed (and so shows on the English pages) when the admin ticked the box,
    // OR typed / accepted a name that differs from what was stored — they saw it in the field when they saved.
    // Only an untouched, auto-guessed name (the bulk-filled ones) stays "waiting for review".
    $prevNameEn   = trim($staff['full_name_en'] ?? '');
    $nameEnOk     = ($fullNameEn !== '' && (!empty($_POST['name_en_verified']) || $fullNameEn !== $prevNameEn)) ? 1 : 0;
    $eProfileUrl  = postStr('e_profile_url');
    $positionId   = (int)($_POST['position_id']  ?? 0);
    $responsibility = postStr('responsibility');
    $campusId     = (int)($_POST['campus_id']     ?? 0) ?: null;
    $supervisorId = (int)($_POST['supervisor_id'] ?? 0) ?: null;
    $majorId      = (int)($_POST['major_id']      ?? 0) ?: null;
    $departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
    $staffTypeId  = (int)($_POST['staff_type_id'] ?? 0);
    $displayOrder = (int)($_POST['display_order'] ?? 0);
    // สถานะบุคลากร (after migration step 14): ลาออก = hidden from the website and the statistics, kept here; the date is optional
    $workStatus   = postStr('work_status') === 'resigned' ? 'resigned' : 'active';
    $resignedOn   = $workStatus === 'resigned' ? postStr('resigned_on') : '';
    if ($resignedOn !== '' && !(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $resignedOn, $rm) && checkdate((int)$rm[2], (int)$rm[3], (int)$rm[1]) && $rm[1] >= 1950 && $rm[1] <= 2100))
        $errors['resigned_on'] = 'วันที่ลาออกไม่ถูกต้อง กรุณาเลือกจากปฏิทิน หรือเว้นว่าง';
    $deptIsSupport = false;
    // ผังสายสนับสนุน (after migration step 15): งาน, เป็นหัวหน้างาน, ตำแหน่งงาน — only kept for support staff
    $unitId     = (int)postStr('unit_id') ?: null;
    $isUnitHead = postStr('is_unit_head') === '1' ? 1 : 0;
    $jobTitle   = mb_substr(postStr('job_title'), 0, 255);
    foreach ($departments as $dep) if ((int)$dep['department_id'] === (int)$departmentId) $deptIsSupport = isSupportDepartmentName($dep['department_name']);

    if (!$fullName)     $errors['full_name']     = 'กรุณากรอกชื่อ-นามสกุล';
    if ($fullNameEn !== '' && !preg_match('/^[A-Za-z][A-Za-z .\'-]*$/', $fullNameEn)) $errors['full_name_en'] = 'ชื่อภาษาอังกฤษใช้ได้เฉพาะตัวอักษรอังกฤษ เว้นวรรค จุด และขีด';
    if (!$positionId)   $errors['position_id']   = 'กรุณาเลือกตำแหน่ง';
    if (!$staffTypeId)  $errors['staff_type_id'] = 'กรุณาเลือกประเภทบุคลากร';
    if (!$campusId)     $errors['campus_id']     = 'กรุณาเลือกศูนย์พื้นที่ (สังกัดได้เพียงศูนย์เดียว)';
    if ($isEdit && $supervisorId === $staffId) $errors['supervisor_id'] = 'ไม่สามารถเลือกตัวเองเป็นหัวหน้างานได้';
    // ผังผู้บริหาร must stay one connected chart: the boss has to be on it already, and someone with people under them
    // can neither drop their boss (their team would become a second, separate top) nor leave (their team would vanish)
    elseif ($supervisorId && in_array($supervisorId, $myUnder, true)) $errors['supervisor_id'] = 'เลือกไม่ได้: คนที่เลือกอยู่ใต้คนนี้ในผังผู้บริหาร (จะวนเป็นวงกลม)';
    elseif ($supervisorId) {
        // the boss must exist and still be working here, whatever the chart looks like (also for the top of the chart)
        $supOk = $db->fetchOne('SELECT s.supervisor_id, ' . staffCurrentSql('s') . ' AS cur FROM staff s WHERE s.staff_id = ?', [$supervisorId]);
        if (!$supOk) $errors['supervisor_id'] = 'ไม่พบหัวหน้างานที่เลือก อาจถูกลบไปแล้ว กรุณาเลือกใหม่';
        elseif (!$supOk['cur']) $errors['supervisor_id'] = 'หัวหน้างานที่เลือกลาออกหรือถูกปิดใช้งานแล้ว กรุณาเลือกคนอื่น';
        elseif ($isSoleTop && postStr('change_top') !== '1')
            $errors['supervisor_id'] = 'คนนี้เป็นยอดผังผู้บริหาร จึงไม่มีหัวหน้างาน — ถ้าต้องการให้คนอื่นขึ้นเป็นยอดผังแทน (เช่น คณบดีคนใหม่) ให้ติ๊ก "เปลี่ยนยอดผัง" ก่อนเลือก';
        elseif ($chart['members'] && !isset($chart['members'][$supervisorId]))
            $errors['supervisor_id'] = 'คนที่เลือกไม่ได้อยู่ในผังผู้บริหาร จึงแสดงคนนี้ไว้ใต้เขาไม่ได้ กรุณาเลือกคนที่อยู่ในผัง หรือจัดคนที่เลือกเข้าผังก่อน (ตั้งหัวหน้างานให้เขา)';
    }
    if ($isEdit && $myReports) {
        if ($workStatus === 'resigned')
            $errors['work_status'] = "เปลี่ยนเป็นลาออกไม่ได้ เพราะมีบุคลากร $myReports คนขึ้นตรงกับคนนี้ในผังผู้บริหาร กรุณาย้ายพวกเขาไปหัวหน้าคนอื่นก่อน";
        elseif (!$supervisorId && $chart['tops'])
            $errors['supervisor_id'] = "ต้องเลือกหัวหน้างาน: คนนี้มีบุคลากร $myReports คนขึ้นตรงอยู่ ถ้าไม่มีหัวหน้าจะกลายเป็นยอดผังอีกสายแยกออกจากผังผู้บริหาร";
    }
    if ($eProfileUrl && !isHttpUrl($eProfileUrl)) $errors['e_profile_url'] = 'ลิงก์ E-Profile ไม่ถูกต้อง กรุณากรอกเป็น URL เช่น https://...';
    // สาขาวิชา / หน่วยงาน เป็นข้อมูลทางเลือก เนื่องจากบุคลากรบางคน
    // ไม่ได้สังกัดอันใดอันหนึ่ง (หรือมากกว่าหนึ่งอย่าง) โดยตรง
    // แต่ต้องมี "สาขาวิชา" หรือ "หัวหน้างาน" อย่างน้อยหนึ่งอย่าง: หน้าบุคลากรของเว็บไซต์แสดงคนตามสาขา/ศูนย์
    // หรือตามสายบังคับบัญชาเท่านั้น คนที่ไม่มีทั้งสองอย่างจะไม่ปรากฏในหน้านั้นเลย (เจอได้แค่จากช่องค้นหา)
    if (hasStaffUnits() && $deptIsSupport && $unitId && !isset(staffUnits()[$unitId])) $errors['unit_id'] = 'ไม่พบงานที่เลือก กรุณาเลือกใหม่';
    if (!$deptIsSupport) { $unitId = null; $isUnitHead = 0; $jobTitle = ''; }
    if (!$majorId && !$supervisorId && !$deptIsSupport) $errors['major_id'] = 'กรุณาเลือกสาขาวิชา หรือเลือกหัวหน้างาน (อย่างน้อยหนึ่งอย่าง) — บุคลากรที่ไม่มีทั้งสองอย่างจะไม่แสดงในหน้าบุคลากรของเว็บไซต์';

    // ชื่อ-นามสกุลห้ามซ้ำกับบุคลากรคนอื่นที่มีอยู่แล้ว (ไม่ตรวจซ้ำกับตัวเองตอนแก้ไข)
    if ($fullName && !isset($errors['full_name'])) {
        $dupWhere = 'full_name = ? AND is_active = 1'; $dupParams = [$fullName];
        if ($isEdit) { $dupWhere .= ' AND staff_id != ?'; $dupParams[] = $staffId; }
        if ($db->fetchOne("SELECT staff_id FROM staff WHERE $dupWhere", $dupParams)) {
            $errors['full_name'] = 'มีบุคลากรชื่อ "' . $fullName . '" ที่ยังเปิดใช้งานอยู่ในระบบแล้ว กรุณาตรวจสอบ (หากเป็นคนเดิม ให้แก้ไขรายการเดิมแทนการเพิ่มใหม่)';
        }
    }

    $imagePath = $staff['image'] ?? null;
    $imageData = postStr('image_data');
    if ($imageData !== '') {
        // A photo was cropped/positioned client-side — this is the normal
        // path whenever a file was chosen, since the cropper always
        // produces this before the form submits (see image-cropper.js).
        $up = uploadImageFromDataUrl($imageData, 'staff');
        if ($up) {
            fileQueueDelete($imagePath);
            fileTrackNew($up);
            $imagePath = $up;
        } else $errors['image'] = 'บันทึกรูปที่ครอปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
    } elseif (!empty($_FILES['image']['name'])) {
        // No-JS fallback — the cropper never ran, so the raw file goes
        // through unmodified exactly like before this feature existed.
        $up = uploadImage($_FILES['image'], 'staff');
        if ($up) {
            fileQueueDelete($imagePath);
            fileTrackNew($up);
            $imagePath = $up;
        } else $errors['image'] = 'อัปโหลดภาพไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
    }

    if (empty($errors)) {
        $data = [
            'prefix'        => $prefix,
            'full_name'     => $fullName,
            'full_name_en'  => $fullNameEn !== '' ? $fullNameEn : null,
            'name_en_verified' => $nameEnOk,
            'e_profile_url' => $eProfileUrl ?: null,
            'position_id'   => $positionId,
            'responsibility'=> $responsibility ?: null,
            'supervisor_id' => $supervisorId,
            'campus_id'     => $campusId,
            'major_id'      => $majorId,
            'department_id' => $departmentId,
            'staff_type_id' => $staffTypeId,
            'image'         => $imagePath,
            'display_order' => $displayOrder,
        ];
        if (hasStaffUnits()) { $data['unit_id'] = $unitId; $data['is_unit_head'] = $isUnitHead; $data['job_title'] = $jobTitle !== '' ? $jobTitle : null; }
        if (hasStaffStatus()) { $data['work_status'] = $workStatus; $data['resigned_on'] = $resignedOn !== '' ? $resignedOn : null; }

        try {
            if ($isEdit) {
                $db->update('staff', $data, 'staff_id = ?', [$staffId]);
                $savedId = $staffId;
                $_SESSION['flash_success'] = 'แก้ไขข้อมูลบุคลากรเรียบร้อยแล้ว';
            } else {
                $savedId = $db->insert('staff', $data);
                $_SESSION['flash_success'] = 'เพิ่มบุคลากรเรียบร้อยแล้ว';
            }
            // ตำแหน่ง "หัวหน้าสาขาวิชา" + มีศูนย์และสาขา → ตั้งเป็นหัวหน้าสาขาในผังโครงสร้างให้อัตโนมัติ
            // (หน้าเว็บบุคลากรอ่านหัวหน้าสาขาจากผังนี้ ไม่ได้อ่านจากชื่อตำแหน่ง) — เฉพาะเมื่อช่องนั้นยังว่าง
            // หรือหัวหน้าเดิมเลิกใช้งานแล้ว จะไม่ไปทับคนที่แอดมินตั้งไว้
            if ($majorId && $campusId && $savedId) {
                $posName = (string)($db->fetchOne('SELECT position_name FROM positions WHERE position_id = ?', [$positionId])['position_name'] ?? '');
                if ($posName === 'หัวหน้าสาขาวิชา') {
                    $slot = $db->fetchOne(
                        'SELECT h.head_staff_id, (' . staffCurrentSql('s') . ') AS cur FROM major_campus_heads h LEFT JOIN staff s ON s.staff_id = h.head_staff_id WHERE h.campus_id = ? AND h.major_id = ?',
                        [$campusId, $majorId]
                    );
                    $slotFree = !$slot || !$slot['head_staff_id'] || !$slot['cur'];   // a head who resigned no longer holds the slot
                    $names = $db->fetchOne('SELECT m.major_name, c.campus_name FROM majors m, campuses c WHERE m.major_id = ? AND c.campus_id = ?', [$majorId, $campusId]) ?: [];
                    $where = ' สาขา' . ($names['major_name'] ?? '') . ' ' . ($names['campus_name'] ?? '');
                    if ($slotFree) {
                        $db->query(
                            'INSERT INTO major_campus_heads (campus_id, major_id, head_staff_id) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE head_staff_id = VALUES(head_staff_id)',
                            [$campusId, $majorId, $savedId]
                        );
                        $_SESSION['flash_success'] .= ' และตั้งเป็นหัวหน้า' . $where . ' ในผังโครงสร้างให้แล้ว';
                    } elseif ((int)$slot['head_staff_id'] !== (int)$savedId) {
                        $_SESSION['flash_success'] .= ' (หมายเหตุ:' . $where . ' มีหัวหน้าสาขาในผังโครงสร้างอยู่แล้ว จึงไม่เปลี่ยน — หากต้องการเปลี่ยน ให้ไปตั้งที่หน้าผังโครงสร้าง)';
                    }
                }
            }
            fileCommitChanges();
            header('Location: index.php'); exit;
        } catch (\Throwable $e) {
            // Technical detail (SQL error, etc.) goes to the server log only — the admin only ever
            // sees plain Thai, never a raw error message.
            error_log('[staff/create.php] save failed: ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หากยังไม่สำเร็จ กรุณาแจ้งผู้ดูแลระบบ';
        }
    }

    if (!empty($errors)) {
        fileRollbackChanges();   // rejected: keep the current photo, drop the new upload
        $imagePath = $staff['image'] ?? null;
        // Re-render with exactly what was just typed/selected — not the
        // stale pre-edit DB row — so nothing the user entered gets lost
        // just because one other field failed validation.
        $staff = array_merge($staff, [
            'prefix' => $prefix, 'full_name' => $fullName, 'full_name_en' => $fullNameEn, 'name_en_verified' => $nameEnOk, 'e_profile_url' => $eProfileUrl,
            'position_id' => $positionId, 'responsibility' => $responsibility,
            'campus_id' => $campusId, 'supervisor_id' => $supervisorId,
            'major_id' => $majorId, 'department_id' => $departmentId,
            'staff_type_id' => $staffTypeId, 'image' => $imagePath,
            'display_order' => $displayOrder,
            'work_status' => $workStatus, 'resigned_on' => $resignedOn,
            'unit_id' => $unitId, 'is_unit_head' => $isUnitHead, 'job_title' => $jobTitle,
        ]);
    }
}

// for the ผังผู้บริหาร section: someone is in that chart when they have a หัวหน้างาน or when others report to them
$supportDeptIds = array_values(array_map(fn($d) => (int)$d['department_id'], array_filter($departments, fn($d) => isSupportDepartmentName($d['department_name']))));
$isSupportNow = in_array((int)($staff['department_id'] ?? 0), $supportDeptIds, true);

$pageTitle  = $isEdit ? 'แก้ไขบุคลากร' : 'เพิ่มบุคลากร';
$activeMenu = 'staff';
$breadcrumb = [
    ['label' => 'จัดการบุคลากร', 'url' => BASE_URL.'/admin/modules/staff/index.php'],
    ['label' => $pageTitle],
];
$pageActions = '';
include BASE_PATH . '/admin/layout/header.php';
?>

<?php
// Whether a field should get a red outline, and — since the same file is
// included once and errors don't change mid-render — computed once here
// rather than re-checking isset($errors[...]) inline at every field.
$invalid = fn(string $key) => isset($errors[$key]) ? 'is-invalid' : '';
?>
<style>
.ne-input.is-invalid, .ne-select.is-invalid { border-color: var(--ne-danger); background: color-mix(in srgb, var(--ne-danger) 6%, var(--ne-surface)); }
.ne-input.is-invalid:focus, .ne-select.is-invalid:focus { border-color: var(--ne-danger); }

.ne-cropper { margin-bottom: 12px; }
/* Deliberately simple: width always fills the circle exactly (no zoom,
   no horizontal drag) — the only control is a single slider that moves
   the image up/down, since that's the one adjustment actually asked for. */
.ne-cropper-viewport {
  width: 220px; height: 220px; margin: 0 auto; border-radius: 50%;
  overflow: hidden; position: relative; background: #eee;
  border: 4px solid var(--ne-line);
}
.ne-cropper-viewport img { position: absolute; left: 0; top: 0; width: 100%; height: auto; }
.ne-cropper-slider-row { max-width: 220px; margin: 10px auto 0; }
.ne-cropper-slider-row input[type="range"] { width: 100%; }

/* ข้อมูลตำแหน่ง: one titled block per topic, one column on phones, two from 720px */
.sf-sec { padding: 18px 0; border-top: 1px solid var(--ne-line); }
.sf-sec:first-child { padding-top: 0; border-top: 0; }
.sf-sec:last-child { padding-bottom: 0; }
.sf-title { display: flex; align-items: center; gap: 8px; margin: 0 0 4px; font-size: 14.5px; font-weight: 700; color: var(--ne-blue-deep); }
.sf-title i { color: var(--ne-blue); }
.sf-lead { margin: 0 0 12px; font-size: 12.5px; color: var(--ne-slate); line-height: 1.6; }
.sf-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px 16px; }
.sf-grid .ne-field { margin: 0; }
.sf-grid .ne-hint { margin: 6px 0 0; }
.sf-inline { display: flex; gap: 8px; }
.sf-inline .ne-select { flex: 1 1 0; width: auto; min-width: 0; }
.sf-inline .ne-btn { flex: 0 0 auto; width: auto; white-space: nowrap; }
.sf-check { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1px solid var(--ne-line); border-radius: 10px; cursor: pointer; font-weight: 500; }
.sf-check input { margin-top: 4px; }
.sf-radios { display: flex; flex-wrap: wrap; gap: 8px; }
.sf-radios label { display: flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1px solid var(--ne-line); border-radius: 999px; cursor: pointer; font-weight: 500; }
.sf-state { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border-radius: 10px; background: var(--ne-blue-soft); color: var(--ne-blue-deep); font-size: 13px; line-height: 1.6; }
.sf-state.is-warn { background: color-mix(in srgb, #d97706 12%, transparent); color: #92400e; }
.sf-state.is-off { background: color-mix(in srgb, var(--ne-slate) 10%, transparent); color: var(--ne-slate); }
/* two columns only when the panel itself is wide enough (the side column takes room on mid-size screens) */
.sf-wrap { container-type: inline-size; }
@container (min-width: 520px) {
  .sf-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .sf-span { grid-column: 1 / -1; }
}
</style>

<div class="ne">
  <form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrfField() ?>
    <div class="ne-grid">

      <!-- Left: Main info -->
      <div class="ne-main">
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-person" style="color:var(--ne-blue);margin-right:6px;"></i>ข้อมูลส่วนตัว</span></div>
          <div class="ne-panel-body">
            <div class="ne-meta-row" style="margin-top:0;padding-top:0;border-top:none;">
              <div class="ne-meta-field" style="flex:0 0 130px;">
                <label for="fPrefix">คำนำหน้า</label>
                <select name="prefix" id="fPrefix" class="ne-select">
                  <?php foreach (['นาย','นาง','นางสาว','อาจารย์','คุณ','ดร.','ผศ.','ผศ.ดร.','รศ.','รศ.ดร.','ศ.','ศ.ดร.'] as $p): ?>
                  <option value="<?=$p?>" <?=($staff['prefix']??'นาย')===$p?'selected':''?>><?=$p?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ne-meta-field" style="flex:2 1 260px;">
                <label for="fFullName">ชื่อ-นามสกุล <span style="color:var(--ne-danger)">*</span></label>
                <input type="text" class="ne-input <?=$invalid('full_name')?>" id="fFullName" name="full_name"
                       value="<?=e($staff['full_name']??'')?>" placeholder="ชื่อ-นามสกุล" required>
              </div>
            </div>
            <div class="ne-field">
              <label for="fFullNameEn">ชื่อ-นามสกุล (ภาษาอังกฤษ) <span class="ne-hint" style="display:inline;margin:0;">แสดงในหน้าเว็บ EN</span></label>
              <input type="text" class="ne-input <?=$invalid('full_name_en')?>" id="fFullNameEn" name="full_name_en" maxlength="255"
                     value="<?=e($staff['full_name_en']??'')?>" placeholder="คลิกที่ช่องนี้ ระบบจะเดาชื่อภาษาอังกฤษให้จากชื่อไทย" autocomplete="off">
              <label style="display:flex;gap:8px;align-items:center;margin:8px 0 0;font-weight:400;cursor:pointer;">
                <input type="checkbox" name="name_en_verified" value="1" id="fNameEnOk" <?=!empty($staff['name_en_verified'])?'checked':''?>>
                <span>ตรวจสอบแล้วว่าสะกดถูกต้อง (จึงแสดงชื่อนี้ในหน้าเว็บ EN) — หากพิมพ์หรือแก้ชื่อในช่องนี้แล้วกดบันทึก ระบบถือว่ายืนยันให้อัตโนมัติ</span>
              </label>
              <p class="ne-hint" id="nameEnHint" style="margin-top:6px;">ระบบเดาให้จากชื่อไทย <b>อาจไม่ถูกต้อง</b> ตรวจแล้วแก้ไขได้ในช่อง แล้วติ๊กยืนยัน ถ้ายังไม่ติ๊ก หน้าเว็บ EN จะแสดงชื่อภาษาไทยไปก่อน</p>
            </div>
            <div class="ne-field" style="margin-bottom:0;">
              <label for="fEProfile">ลิงก์ E-Profile <span class="ne-hint" style="display:inline;margin:0;">(ถ้ามี)</span></label>
              <input type="url" class="ne-input <?=$invalid('e_profile_url')?>" id="fEProfile" name="e_profile_url"
                     value="<?=e($staff['e_profile_url']??'')?>" placeholder="https://...">
            </div>
          </div>
        </div>

        <div class="ne-panel">
          <div class="ne-panel-head"><span><i class="bi bi-briefcase" style="color:var(--ne-blue);margin-right:6px;"></i>ข้อมูลตำแหน่ง</span></div>
          <div class="ne-panel-body sf-wrap">

            <section class="sf-sec">
              <h3 class="sf-title"><i class="bi bi-person-badge"></i>ตำแหน่ง</h3>
              <div class="sf-grid">
                <div class="ne-field"><label for="fPosition">ตำแหน่ง <span style="color:var(--ne-danger)">*</span></label>
                  <select name="position_id" id="fPosition" class="ne-select <?=$invalid('position_id')?>" required>
                    <option value="">-- เลือกตำแหน่ง --</option>
                    <?php foreach ($positions as $pos): ?>
                    <option value="<?=$pos['position_id']?>" <?=($staff['position_id']??0)==$pos['position_id']?'selected':''?>><?=e($pos['position_name'])?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div class="ne-field"><label for="fStaffType">ประเภทบุคลากร <span style="color:var(--ne-danger)">*</span></label>
                  <select name="staff_type_id" id="fStaffType" class="ne-select <?=$invalid('staff_type_id')?>" required>
                    <option value="">-- เลือกประเภท --</option>
                    <?php foreach ($staffTypes as $st): ?>
                    <option value="<?=$st['staff_type_id']?>" <?=($staff['staff_type_id']??0)==$st['staff_type_id']?'selected':''?>><?=e($st['staff_type_name'])?></option>
                    <?php endforeach; ?>
                  </select></div>
                <?php
                // ฝ่าย/ด้านที่รับผิดชอบ: picked from what is already in use (plus the งาน names) so the same ฝ่าย is
                // always spelled the same; "พิมพ์ใหม่" opens the text box for one not in the list yet. The text box is
                // what gets saved, so without JS it is simply shown.
                $respNow = trim((string)($staff['responsibility'] ?? ''));
                $respList = array_column($db->fetchAll("SELECT DISTINCT TRIM(s.responsibility) AS r FROM staff s WHERE " . staffCurrentSql('s') . " AND TRIM(COALESCE(s.responsibility, '')) <> ''"), 'r');
                if (hasStaffUnits()) $respList = array_merge($respList, array_values(staffUnits()));
                if ($respNow !== '') $respList[] = $respNow;
                $respList = array_values(array_unique($respList));
                usort($respList, fn($a, $b) => strcmp($a, $b));
                ?>
                <div class="ne-field sf-span"><label for="fRespPick">ฝ่าย/ด้านที่รับผิดชอบ <span class="ne-hint" style="display:inline;margin:0;">(ถ้ามี)</span></label>
                  <select class="ne-select" id="fRespPick" hidden>
                    <option value="">-- ไม่มี --</option>
                    <?php foreach ($respList as $r): ?>
                    <option value="<?= e($r) ?>" <?= $r === $respNow ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                    <option value="__new">+ พิมพ์ฝ่าย/ด้านใหม่…</option>
                  </select>
                  <input type="text" class="ne-input" id="fResponsibility" name="responsibility" maxlength="255" style="margin-top:8px;"
                         value="<?=e($respNow)?>" placeholder="พิมพ์ชื่อฝ่าย/ด้าน เช่น งานบริหารและพัฒนาระบบ">
                  <script>
                  (function () {
                    var pick = document.getElementById('fRespPick'), box = document.getElementById('fResponsibility');
                    pick.hidden = false; box.hidden = true;
                    pick.addEventListener('change', function () {
                      var isNew = this.value === '__new';
                      box.hidden = !isNew;
                      box.value = isNew ? '' : this.value;
                      if (isNew) box.focus();
                    });
                  })();
                  </script></div>
              </div>
            </section>

            <section class="sf-sec">
              <h3 class="sf-title"><i class="bi bi-building"></i>สังกัด</h3>
              <p class="sf-lead">หน่วยงานเป็นตัวแยกแท็บบนเว็บ: สายวิชาการ / สายสนับสนุน</p>
              <div class="sf-grid">
                <div class="ne-field"><label for="campusSelect">ศูนย์พื้นที่ <span style="color:var(--ne-danger)">*</span> <span class="ne-hint" style="display:inline;margin:0;">(ได้ศูนย์เดียว)</span></label>
                  <select name="campus_id" id="campusSelect" class="ne-select <?=$invalid('campus_id')?>" required>
                    <option value="">-- เลือกศูนย์พื้นที่ --</option>
                    <?php foreach ($campuses as $c): ?>
                    <option value="<?=$c['campus_id']?>" <?=($staff['campus_id']??0)==$c['campus_id']?'selected':''?>><?=e($c['campus_name'])?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div class="ne-field"><label for="fDept">หน่วยงาน</label>
                  <select name="department_id" id="fDept" class="ne-select" data-support-depts="<?= e(json_encode($supportDeptIds)) ?>">
                    <option value="">-- ไม่ระบุ --</option>
                    <?php foreach ($departments as $dep): ?>
                    <option value="<?=$dep['department_id']?>" <?=($staff['department_id']??0)==$dep['department_id']?'selected':''?>><?=e($dep['department_name'])?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div class="ne-field sf-span" id="fMajorWrap" <?= $isSupportNow && empty($staff['major_id']) ? 'hidden' : '' ?>><label for="fMajor">สาขาวิชา <span style="color:var(--ne-danger)">*</span>
                    <span class="ne-hint" style="display:inline;margin:0;">(สายวิชาการ: เลือกสาขา หรือเลือกหัวหน้างานในผังผู้บริหารแทน)</span></label>
                  <select name="major_id" id="fMajor" class="ne-select <?=$invalid('major_id')?>">
                    <option value="">-- ไม่ระบุ --</option>
                    <?php foreach ($majors as $m): ?>
                    <option value="<?=$m['major_id']?>" <?=($staff['major_id']??0)==$m['major_id']?'selected':''?>><?=e($m['major_name'])?></option>
                    <?php endforeach; ?>
                  </select></div>
              </div>
            </section>

            <?php if (hasStaffUnits()): ?>
            <section class="sf-sec" id="fSupportBox" <?= $isSupportNow ? '' : 'hidden' ?>>
              <h3 class="sf-title"><i class="bi bi-diagram-2"></i>ผังบุคลากรสายสนับสนุน</h3>
              <p class="sf-lead">ศูนย์พื้นที่ → งาน → หัวหน้างานอยู่บนสุด ตามด้วยคนอื่นในงาน</p>
              <div class="sf-grid">
                <div class="ne-field"><label for="fUnit">งาน</label>
                  <select name="unit_id" id="fUnit" class="ne-select <?=$invalid('unit_id')?>">
                    <option value="">-- ไม่ระบุ --</option>
                    <?php foreach (staffUnits() as $uid => $uname): ?>
                    <option value="<?= (int)$uid ?>" <?= (int)($staff['unit_id'] ?? 0) === (int)$uid ? 'selected' : '' ?>><?= e($uname) ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div class="ne-field"><label for="fJobTitle">ตำแหน่งที่แสดงในผังนี้ <span class="ne-hint" style="display:inline;margin:0;">(ถ้าต่างจากตำแหน่ง)</span></label>
                  <input type="text" class="ne-input" id="fJobTitle" name="job_title" maxlength="255" value="<?= e((string)($staff['job_title'] ?? '')) ?>" placeholder="เว้นว่าง = ใช้ตำแหน่งด้านบน"></div>
                <label class="sf-check sf-span"><input type="checkbox" name="is_unit_head" value="1" <?= !empty($staff['is_unit_head']) ? 'checked' : '' ?>>
                  <span>เป็นหัวหน้างาน <span class="ne-hint" style="display:block;margin:0;">อยู่บนสุดของงานนี้ในผัง พร้อมป้าย "หัวหน้างาน"</span></span></label>
              </div>
            </section>
            <?php endif; ?>

            <section class="sf-sec">
              <h3 class="sf-title"><i class="bi bi-diagram-3"></i>ผังผู้บริหาร</h3>
              <p class="sf-lead">เลือกหัวหน้างาน = แสดงในผังผู้บริหารใต้คนนั้น · เลือกได้เฉพาะคนที่อยู่ในผังผู้บริหารแล้ว · ไม่เลือก = ไม่อยู่ในผังผู้บริหาร</p>
              <?php
              // the current boss is kept in the list even when they are no longer on the chart (left, or the chart
              // changed), marked, so the admin sees why saving asks for another choice
              $curSup = (int)($staff['supervisor_id'] ?? 0);
              $curSupRow = ($curSup && !in_array($curSup, array_map(fn($o) => (int)$o['staff_id'], $supervisorOptions), true))
                  ? $db->fetchOne('SELECT s.staff_id, s.prefix, s.full_name FROM staff s WHERE s.staff_id = ?', [$curSup]) : null;
              $mustHaveBoss = $myReports && $chart['tops'];
              ?>
              <div class="sf-grid">
                <div class="ne-field"><label for="fSupervisor">หัวหน้างาน (ขึ้นตรงกับ)</label>
                  <div class="sf-inline">
                    <select name="supervisor_id" id="fSupervisor" class="ne-select <?=$invalid('supervisor_id')?>">
                      <option value="">-- ไม่มี --</option>
                      <?php if ($curSupRow): ?>
                      <option value="<?= (int)$curSupRow['staff_id'] ?>" selected data-off="1"><?= e($curSupRow['prefix'] . $curSupRow['full_name']) ?> (ไม่อยู่ในผังผู้บริหารแล้ว)</option>
                      <?php endif; ?>
                      <?php $topOpen = $isSoleTop && (postStr('change_top') === '1' || $curSup); ?>
                      <?php foreach ($supervisorOptions as $sup): $hideTop = $isSoleTop && !$topOpen; ?>
                      <option value="<?=$sup['staff_id']?>" <?=$curSup===(int)$sup['staff_id']?'selected':''?><?= $isSoleTop ? ' data-top="1"' : '' ?><?= $hideTop ? ' hidden disabled' : '' ?>><?=e($sup['prefix'].$sup['full_name'])?> (<?=e($sup['position_name'])?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <button type="button" class="ne-btn ne-btn-ghost" id="fSupClear" title="เอาออกจากผังผู้บริหาร"><i class="bi bi-x-lg"></i><span>เอาออก</span></button>
                  </div>
                  <?php if ($isSoleTop): ?>
                  <p class="ne-hint">คนนี้เป็นยอดผังผู้บริหาร จึงไม่มีหัวหน้างาน</p>
                  <label class="sf-check" style="margin-top:8px;"><input type="checkbox" name="change_top" value="1" id="fChangeTop" <?= $topOpen ? 'checked' : '' ?>>
                    <span>เปลี่ยนยอดผัง <span class="ne-hint" style="display:block;margin:0;">เช่น ตั้งคณบดีคนใหม่: เลือกคนใหม่ที่ช่องด้านบน แล้วคนนั้นจะขึ้นเป็นยอดผังเหนือคนนี้ (จากนั้นย้ายรองคณบดีไปไว้ใต้คนใหม่ได้)</span></span></label>
                  <?php elseif (!$chart['members']): ?><p class="ne-hint">ยังไม่มีผังผู้บริหาร จึงเลือกได้ทุกคน (คนที่เลือกจะเป็นยอดผัง)</p><?php endif; ?>
                </div>
                <div class="ne-field"><label for="fOrder">ลำดับแสดง <span class="ne-hint" style="display:inline;margin:0;">(ในตำแหน่งเดียวกัน)</span></label>
                  <input type="number" class="ne-input" id="fOrder" name="display_order" value="<?=$staff['display_order']??0?>" min="0"></div>
                <div class="sf-state sf-span" id="fExecState" data-reports="<?= $myReports ?>" data-must-boss="<?= $mustHaveBoss ? 1 : 0 ?>" aria-live="polite"></div>
              </div>
            </section>

            <?php if (hasStaffStatus()): $ws = ($staff['work_status'] ?? 'active') === 'resigned' ? 'resigned' : 'active'; ?>
            <section class="sf-sec">
              <h3 class="sf-title"><i class="bi bi-person-check"></i>สถานะบุคลากร</h3>
              <p class="sf-lead">ลาออก = ไม่แสดงบนเว็บไซต์ (ทุกแท็บ ทุกผัง) และไม่นับในสถิติ แต่ข้อมูลยังอยู่ในหลังบ้าน เปลี่ยนกลับได้</p>
              <div class="sf-grid">
                <div class="sf-radios">
                  <label><input type="radio" name="work_status" value="active" <?= $ws === 'active' ? 'checked' : '' ?> onchange="document.getElementById('fResignedWrap').hidden=true"> คงอยู่</label>
                  <label><input type="radio" name="work_status" value="resigned" <?= $ws === 'resigned' ? 'checked' : '' ?> onchange="document.getElementById('fResignedWrap').hidden=false"> ลาออก</label>
                </div>
                <div class="ne-field" id="fResignedWrap" <?= $ws === 'resigned' ? '' : 'hidden' ?>>
                  <label for="fResignedOn">วันที่ลาออก <span class="ne-hint" style="display:inline;margin:0;">(ไม่บังคับ)</span></label>
                  <input type="date" class="ne-input <?=$invalid('resigned_on')?>" id="fResignedOn" name="resigned_on" value="<?= e((string)($staff['resigned_on'] ?? '')) ?>">
                </div>
              </div>
            </section>
            <?php endif; ?>

            <script>
            (function () {
              var dept = document.getElementById('fDept'), box = document.getElementById('fSupportBox'), major = document.getElementById('fMajorWrap');
              var sup = document.getElementById('fSupervisor'), state = document.getElementById('fExecState'), reports = +state.dataset.reports;
              var supportIds = JSON.parse(dept.dataset.supportDepts);
              dept.addEventListener('change', function () {
                var isSupport = supportIds.indexOf(+this.value) >= 0;
                if (box) box.hidden = !isSupport;
                major.hidden = isSupport && !document.getElementById('fMajor').value;
              });
              function showState() {
                var opt = sup.options[sup.selectedIndex], name = sup.value ? opt.text : '', off = sup.value && opt.dataset.off;
                var mustBoss = +state.dataset.mustBoss && !sup.value;
                var warn = off || mustBoss, on = sup.value || reports;
                state.className = 'sf-state sf-span' + (warn ? ' is-warn' : on ? '' : ' is-off');
                state.innerHTML = '<i class="bi bi-' + (warn ? 'exclamation-triangle' : on ? 'check-circle' : 'dash-circle') + '"></i><span></span>';
                state.lastChild.textContent = off ? 'หัวหน้างานคนนี้ไม่อยู่ในผังผู้บริหารแล้ว กรุณาเลือกคนใหม่'
                  : mustBoss ? 'ต้องเลือกหัวหน้างาน: มี ' + reports + ' คนขึ้นตรงกับคนนี้ ถ้าไม่มีหัวหน้าจะกลายเป็นยอดผังอีกสายแยกออกไป'
                  : sup.value ? 'แสดงในผังผู้บริหาร ใต้ ' + name
                  : reports ? 'แสดงในผังผู้บริหารเป็นยอดผัง (มี ' + reports + ' คนขึ้นตรงกับคนนี้)'
                  : 'ไม่แสดงในผังผู้บริหาร';
                document.getElementById('fSupClear').hidden = !sup.value;
              }
              // ลาออก while people still report to this person would leave them cut off: say so before saving
              document.querySelectorAll('input[name="work_status"]').forEach(function (r) {
                r.addEventListener('change', function () {
                  if (this.value === 'resigned' && reports) showError('เปลี่ยนเป็นลาออกไม่ได้ เพราะมี ' + reports + ' คนขึ้นตรงกับคนนี้ในผังผู้บริหาร กรุณาย้ายพวกเขาไปหัวหน้าคนอื่นก่อน');
                });
              });
              // the top of the chart: the list stays closed until "เปลี่ยนยอดผัง" is ticked, and saving asks once more
              var changeTop = document.getElementById('fChangeTop');
              if (changeTop) {
                changeTop.addEventListener('change', function () {
                  sup.querySelectorAll('option[data-top]').forEach(function (o) { o.hidden = o.disabled = !changeTop.checked; });
                  if (!changeTop.checked) sup.value = '';
                  showState();
                });
                sup.form.addEventListener('submit', function (e) {
                  if (changeTop.checked && sup.value && !confirm('ยืนยันเปลี่ยนยอดผัง: ' + sup.options[sup.selectedIndex].text + ' จะขึ้นเป็นยอดผังผู้บริหารเหนือคนนี้')) e.preventDefault();
                });
              }
              sup.addEventListener('change', showState);
              document.getElementById('fSupClear').addEventListener('click', function () { sup.value = ''; showState(); });
              showState();
            })();
            </script>
          </div>
        </div>
      </div>

      <!-- Right: Photo + Submit -->
      <div class="ne-side">
        <div class="ne-panel" style="margin-bottom:14px;">
          <div class="ne-panel-head"><span><i class="bi bi-person-bounding-box" style="color:var(--ne-blue);margin-right:6px;"></i>รูปภาพ</span></div>
          <div class="ne-panel-body text-center">
            <div id="previewWrap">
              <?php if (!empty($staff['image'])): ?>
              <img src="<?=UPLOAD_URL.'/'.e($staff['image'])?>" alt=""
                   id="previewImg"
                   class="rounded-circle mb-3 d-block mx-auto"
                   style="width:140px;height:140px;object-fit:cover;border:4px solid var(--ne-line)">
              <?php else: ?>
              <div id="previewImg" class="rounded-circle mb-3 mx-auto d-flex align-items-center justify-content-center"
                   style="width:140px;height:140px;background:var(--ne-blue);color:white;font-size:2.8rem;font-weight:800">
                <?=mb_substr(($staff['prefix']??'').' '.($staff['full_name']??'?'),0,1,'UTF-8')?>
              </div>
              <?php endif; ?>
            </div>

            <?php if (!empty($staff['image'])): ?>
            <!-- Edit page: lets the admin re-frame the photo already saved, without picking the file again. -->
            <button type="button" class="ne-btn ne-btn-ghost" id="repositionBtn" style="margin-bottom:10px;" data-src="<?=UPLOAD_URL.'/'.e($staff['image'])?>"><i class="bi bi-arrows-move"></i>ปรับตำแหน่งรูปเดิม</button>
            <p class="ne-hint" id="repositionNote" style="display:none;margin-top:0;">รูปนี้เป็นสี่เหลี่ยมจัตุรัสอยู่แล้ว จึงเลื่อนขึ้น-ลงไม่ได้ — ถ้าต้องการจัดกรอบใหม่ให้เลือกไฟล์รูปต้นฉบับอีกครั้ง</p>
            <?php endif; ?>

            <!-- Shown only once a new file is chosen — drag to reposition,
                 slider/buttons to zoom and nudge, so the saved photo is
                 framed exactly how the admin wants before it's uploaded. -->
            <div class="ne-cropper" id="cropperWrap" style="display:none;">
              <div class="ne-cropper-viewport" id="cropperViewport">
                <img id="cropperImg" alt="" draggable="false">
              </div>
              <div class="ne-cropper-slider-row">
                <input type="range" id="cropperSlider" min="0" max="0" value="0">
              </div>
            </div>

            <input type="file" data-has-preview id="imageFileInput" class="form-control form-control-sm <?=$invalid('image')?>" name="image"
                   accept="image/*">
            <input type="hidden" name="image_data" id="imageDataInput">
            <p class="ne-hint">แนะนำรูปสี่เหลี่ยมจัตุรัส ขนาด 400×400 พิกเซลขึ้นไป (JPG/PNG ไม่เกิน 2MB) — เลือกไฟล์แล้วเลื่อนแถบด้านล่างเพื่อขยับรูปขึ้น-ลงได้</p>
          </div>
        </div>

        <div class="ne-panel">
          <div class="ne-panel-head"><span><i class="bi bi-send" style="color:var(--ne-blue);margin-right:6px;"></i>บันทึก</span></div>
          <div class="ne-panel-body">
            <div class="d-grid gap-2">
              <button type="submit" class="ne-btn ne-btn-primary" style="justify-content:center;">
                <i class="bi bi-check-lg"></i><?=$isEdit?'บันทึกการแก้ไข':'เพิ่มบุคลากร'?>
              </button>
              <a href="index.php" class="ne-btn ne-btn-ghost" style="justify-content:center;">ยกเลิก</a>
            </div>
          </div>
        </div>
      </div>

    </div>
  </form>
</div>

<?php
$errorListHtml = !empty($errors)
    ? '<ul style="text-align:left;margin:0;padding-left:20px;">' . implode('', array_map(fn($m) => '<li>' . e($m) . '</li>', $errors)) . '</ul>'
    : '';
$cropperJs = <<<'JS'
(function () {
  var fileInput = document.getElementById('imageFileInput');
  var previewWrap = document.getElementById('previewWrap');
  var wrap = document.getElementById('cropperWrap');
  var img = document.getElementById('cropperImg');
  var slider = document.getElementById('cropperSlider');
  var dataInput = document.getElementById('imageDataInput');
  var form = fileInput.closest('form');

  var VIEW = 220;   // matches .ne-cropper-viewport width/height
  var OUTPUT = 500; // exported square photo size, px

  // Width always fills the circle exactly (height scales proportionally,
  // no zoom) — the only thing to choose is which vertical slice of the
  // now-220px-wide image shows through the circle, via one slider.
  var scaledHeight = 0;

  function apply() {
    img.style.transform = 'translateY(-' + slider.value + 'px)';
  }

  function renderCrop() {
    var canvas = document.createElement('canvas');
    canvas.width = OUTPUT;
    canvas.height = OUTPUT;
    var ctx = canvas.getContext('2d');
    var outScale = OUTPUT / VIEW;
    var w = OUTPUT;
    var h = scaledHeight * outScale;
    var x = 0;
    var y = -parseFloat(slider.value) * outScale;
    ctx.drawImage(img, x, y, w, h);
    dataInput.value = canvas.toDataURL('image/jpeg', 0.9);
  }

  function loadSrc(src, renderNow) {
      img.onload = function () {
        var scale = VIEW / img.naturalWidth;
        scaledHeight = img.naturalHeight * scale;
        var maxSlide = Math.max(0, scaledHeight - VIEW);
        slider.min = 0;
        slider.max = maxSlide;
        slider.value = maxSlide / 2;
        apply();
        previewWrap.style.display = 'none';
        wrap.style.display = '';
        // The saved photo is only re-encoded once the slider is actually moved.
        if (renderNow) renderCrop(); else dataInput.value = '';
      };
      img.src = src;
  }

  function loadFile(file) {
    var reader = new FileReader();
    reader.onload = function (e) { loadSrc(e.target.result, true); };
    reader.readAsDataURL(file);
  }

  var repoBtn = document.getElementById('repositionBtn');
  if (repoBtn) {
    repoBtn.addEventListener('click', function () {
      var note = document.getElementById('repositionNote');
      img.onload = null;
      var probe = new Image();
      probe.onload = function () {
        // A square photo has nothing left to slide (it was already cropped when saved).
        if (probe.naturalHeight <= probe.naturalWidth * 1.01) { note.style.display = ''; return; }
        note.style.display = 'none';
        fileInput.value = '';
        repoBtn.style.display = 'none';
        loadSrc(repoBtn.dataset.src + (repoBtn.dataset.src.indexOf('?') < 0 ? '?t=' + Date.now() : ''), false);
      };
      probe.src = repoBtn.dataset.src;
    });
  }

  fileInput.addEventListener('change', function () {
    if (fileInput.files[0]) loadFile(fileInput.files[0]);
  });

  slider.addEventListener('input', function () {
    apply();
    renderCrop();
  });

  // ---- English name helper: suggests a romanization of the Thai name; the admin can edit it ----
  var nameTh = document.getElementById('fFullName');
  var nameEn = document.getElementById('fFullNameEn');
  var nameOk = document.getElementById('fNameEnOk');
  if (nameTh && nameEn && window.ThaiRomanize) {
    // A verified (or hand-typed) English name must never be overwritten by a new guess.
    var enTouched = nameEn.value.trim() !== '' && nameOk && nameOk.checked;
    var timer = null;
    function titleCase(s) { return s.replace(/(^|[\s-])([a-z])/g, function (m, a, b) { return a + b.toUpperCase(); }); }
    function suggest() {
      var th = nameTh.value.trim();
      if (!th) { return ''; }
      try { return titleCase(window.ThaiRomanize.romanizeSentence(th).replace(/\s+/g, ' ').trim()); } catch (e) { return ''; }
    }
    nameTh.addEventListener('input', function () {
      if (enTouched) return;
      clearTimeout(timer);
      timer = setTimeout(function () { nameEn.value = suggest(); if (nameOk) nameOk.checked = false; }, 250);
    });
    nameEn.addEventListener('input', function () { enTouched = true; if (nameOk && nameEn.value.trim() !== '') nameOk.checked = true; });
    window.__nameEnReset = function () { enTouched = false; };
    window.__nameEnSuggest = suggest;
    // New record with the Thai name pre-filled (e.g. after a validation error): offer a guess straight away.
    if (nameEn.value.trim() === '' && nameTh.value.trim() !== '') { nameEn.value = suggest(); }
  }

  // Clicking (focusing) the empty English-name box fills in a guess from the Thai name, and says so
  // under the box. A box that already has a name is never overwritten by focusing it.
  if (nameEn && nameTh) {
    var hint = document.getElementById('nameEnHint');
    var hintDefault = hint ? hint.innerHTML : '';
    function say(html, ok) {
      if (!hint) return;
      hint.innerHTML = html;
      hint.style.color = ok ? '#0b8a4b' : '#b3261e';
      clearTimeout(say.t);
      say.t = setTimeout(function () { hint.innerHTML = hintDefault; hint.style.color = ''; }, 6000);
    }
    nameEn.addEventListener('focus', function () {
      if (nameEn.value.trim() !== '') return;
      if (!nameTh.value.trim()) { say('กรุณากรอกชื่อ-นามสกุลภาษาไทยก่อน ระบบจะเดาชื่ออังกฤษให้', false); return; }
      if (!window.ThaiRomanize || !window.__nameEnSuggest) { say('ตัวช่วยเดายังไม่พร้อมใช้งาน (ไม่พบไฟล์ thai-romanize.js) กรุณาอัปโหลดไฟล์นี้ หรือพิมพ์ชื่ออังกฤษเอง', false); return; }
      window.__nameEnReset();
      nameEn.value = window.__nameEnSuggest();
      if (nameOk) nameOk.checked = false;
      nameEn.select();
      say('เดาให้แล้ว: <b>' + nameEn.value + '</b> — ตรวจสอบ แก้ไขได้ แล้วติ๊กยืนยันด้านล่าง', true);
    });
  }
  // A cropped photo already carries its own byte payload in image_data —
  // submitting the original file too would just double the upload for no
  // reason, so it's cleared right before the form actually posts.
  form.addEventListener('submit', function () {
    if (dataInput.value) fileInput.value = '';
  });
})();
JS;
$extraJs = '
<script src="' . BASE_URL . '/admin/assets/js/thai-romanize.js?v=' . (@filemtime(BASE_PATH . '/admin/assets/js/thai-romanize.js') ?: time()) . '"></script>
<script>
' . $cropperJs . '
' . (!empty($errors) ? 'Swal.fire({ icon: "error", title: "กรุณาตรวจสอบข้อมูล", html: ' . json_encode($errorListHtml) . ' });' : '') . '
</script>';
include BASE_PATH . '/admin/layout/footer.php';
?>
