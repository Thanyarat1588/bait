<?php
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if(!defined('BASE_PATH'))define('BASE_PATH',dirname(dirname(dirname(__DIR__))));
$auth=new Auth();$auth->requireLogin();$db=Database::getInstance();
if($_SERVER['REQUEST_METHOD']==='POST'){verifyCsrf();$a=$_POST['action']??'';
  if($a==='save'){$id=(int)($_POST['department_id']??0);$name=postStr('department_name');if(!$name)jsonError('กรุณากรอกชื่อหน่วยงาน');if(nameTaken($db,'departments','department_name','department_id',$name,$id))jsonError(dupMsg('หน่วยงาน', $name));if($id){$db->update('departments',['department_name'=>$name],'department_id=?',[$id]);jsonSuccess([],'แก้ไขแล้ว');}else{$db->insert('departments',['department_name'=>$name]);jsonSuccess([],'เพิ่มแล้ว');}}
  if($a==='delete'){$id=(int)($_POST['department_id']??0);$c=$db->count('staff','department_id=?',[$id]);if($c)jsonError("ลบไม่ได้ มีบุคลากร $c คนอยู่ในหน่วยงานนี้ (นับรวมผู้ที่ลาออกแล้ว)");$db->delete('departments','department_id=?',[$id]);jsonSuccess([],'ลบแล้ว');}
}
$rows=$db->fetchAll("SELECT d.*,(SELECT COUNT(*) FROM staff WHERE department_id=d.department_id AND " . staffCurrentSql('') . ") sc FROM departments d ORDER BY d.department_id");
$pageTitle='จัดการหน่วยงาน';$activeMenu='departments';$breadcrumb=[['label'=>'หน่วยงาน']];
$pageActions=''; // ปุ่มเพิ่มย้ายไปอยู่ใน topbar แบบใหม่แทน
include BASE_PATH.'/admin/layout/header.php';?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
.ne {
  --ne-ink: #1e2a45; --ne-ink-soft: #5b6480; --ne-paper: #f5f6fa; --ne-surface: #ffffff; --ne-line: #e3e6ee;
  --ne-gold: #FBC02D; --ne-blue: #162E93; --ne-blue-deep: #11236E; --ne-blue-soft: #ECEEF6; --ne-gold-soft: #f7f1e3;
  --ne-green: #3e8564; --ne-green-soft: #e5f2eb; --ne-slate: #8b93a7; --ne-slate-soft: #eef0f5;
  --ne-danger: #c1443d; --ne-danger-soft: #fbeae9;
  font-family: 'Kanit', sans-serif; color: var(--ne-ink);
}
.ne * { box-sizing: border-box; }
.ne-topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; padding-bottom: 16px; border-bottom: 1px solid var(--ne-line); }
.ne-topbar h1 { font-family: 'Kanit', sans-serif; font-size: 26px; font-weight: 700; margin: 0; color: var(--ne-ink); display: flex; align-items: baseline; gap: 10px; }
.ne-topbar h1 .ne-count { font-family: 'Kanit', sans-serif; font-size: 14px; font-weight: 600; color: var(--ne-gold); background: var(--ne-gold-soft); border-radius: 999px; padding: 3px 11px; }
.ne-btn { font-family: 'Kanit', sans-serif; font-weight: 600; font-size: 14.5px; border-radius: 9px; padding: 10px 18px; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 7px; cursor: pointer; text-decoration: none; transition: all .15s ease; line-height: 1; }
.ne-btn-primary { background: var(--ne-blue) !important; color: #fff !important; }
.ne-btn-primary:hover { background: var(--ne-blue-deep) !important; color: #fff !important; }
.ne-btn-ghost { background: transparent; color: var(--ne-ink-soft); border-color: var(--ne-line); }
.ne-btn-ghost:hover { background: var(--ne-paper); color: var(--ne-ink); }
.ne-btn-block { width: 100%; justify-content: center; }
.ne-grid { display: flex; flex-direction: column; gap: 24px; align-items: stretch; }
.ne-main { flex: 1 1 0; min-width: 0; }
.ne-side { width: 100%; flex-shrink: 0; }
@media (min-width: 1024px) { .ne-grid { flex-direction: row; align-items: flex-start; } .ne-side { width: 320px; } }
.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; overflow: hidden; }
.ne-panel-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--ne-line); font-size: 13.5px; font-weight: 600; color: var(--ne-ink-soft); }
.ne-panel-body { padding: 18px 20px 20px; }
.ne-panel-title { font-size: 12.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--ne-ink-soft); display: flex; align-items: center; gap: 7px; margin: 0; }
.ne-panel-title i { color: var(--ne-blue); font-size: 14px; }
.ne-table { width: 100%; border-collapse: collapse; }
.ne-table thead th { text-align: left; font-size: 11.5px; letter-spacing: .05em; text-transform: uppercase; color: var(--ne-slate); font-weight: 600; padding: 12px 20px; background: var(--ne-paper); border-bottom: 1px solid var(--ne-line); white-space: nowrap; }
.ne-table tbody tr { border-bottom: 1px solid var(--ne-line); transition: background .12s ease; }
.ne-table tbody tr:last-child { border-bottom: none; }
.ne-table tbody tr:hover { background: var(--ne-paper); }
.ne-table td { padding: 12px 20px; vertical-align: middle; font-size: 14px; }
.ne-table td.ne-num { color: var(--ne-slate); font-size: 13px; }
.ne-badge-count { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; font-size: 12.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: var(--ne-slate-soft); color: var(--ne-ink-soft); }
.ne-actions { display: flex; gap: 6px; }
.ne-icon-btn { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--ne-line); background: var(--ne-surface); color: var(--ne-ink-soft); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; transition: all .15s ease; font-size: 14px; }
.ne-icon-btn i { color: inherit !important; }
.ne-icon-btn.ne-icon-edit { color: var(--ne-blue) !important; border-color: #c3d9fb !important; background: var(--ne-blue-soft) !important; }
.ne-icon-btn.ne-icon-edit:hover { background: var(--ne-blue) !important; border-color: var(--ne-blue) !important; color: #fff !important; }
.ne-icon-btn.ne-icon-delete { color: var(--ne-danger) !important; border-color: #f0c9c6 !important; background: var(--ne-danger-soft) !important; }
.ne-icon-btn.ne-icon-delete:hover { background: var(--ne-danger) !important; border-color: var(--ne-danger) !important; color: #fff !important; }
.ne-empty { text-align: center; padding: 56px 20px; color: var(--ne-slate); }
.ne-empty i { font-size: 32px; display: block; margin-bottom: 10px; color: var(--ne-line); }
.ne-field { margin-bottom: 16px; }
.ne-field label { font-size: 11.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--ne-slate); display: block; margin-bottom: 7px; }
.ne-input { width: 100%; border: 1px solid var(--ne-line); border-radius: 8px; padding: 9px 12px; font-family: 'Kanit', sans-serif; font-size: 14px; color: var(--ne-ink); background: var(--ne-surface); outline: none; transition: border-color .15s ease; }
.ne-input:focus { border-color: var(--ne-blue); }
.ne-modal-header { background: var(--ne-blue-deep); color: #fff; border: none; }
.ne-modal-header .btn-close { filter: brightness(0) invert(1); }
.ne-modal-content { border: none; border-radius: 14px; overflow: hidden; }
</style>

<div class="ne">
  <div class="ne-topbar">
    <h1>หน่วยงานทั้งหมด <span class="ne-count"><?=count($rows)?> หน่วยงาน</span></h1>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openModal(0)">
      <i class="bi bi-plus-lg"></i>เพิ่มหน่วยงาน
    </button>
  </div>

  <div class="ne-grid">
    <div class="ne-main">
      <div class="ne-panel">
        <div class="ne-panel-head"><span>หน่วยงานทั้งหมด</span></div>
        <?php if (!empty($rows)): ?>
        <div class="table-responsive">
          <table class="ne-table">
            <thead><tr><th style="width:44px">#</th><th>ชื่อหน่วยงาน</th><th style="width:110px" class="text-center">บุคลากร</th><th style="width:100px">จัดการ</th></tr></thead>
            <tbody>
              <?php foreach($rows as $i=>$r):?>
              <tr>
                <td class="ne-num"><?=$i+1?></td>
                <td class="fw-500"><?=e($r['department_name'])?></td>
                <td class="text-center"><span class="ne-badge-count"><?=$r['sc']?></span></td>
                <td>
                  <div class="ne-actions">
                    <button class="ne-icon-btn ne-icon-edit" onclick="openModal(<?=$r['department_id']?>)" title="แก้ไข"><i class="bi bi-pencil"></i></button>
                    <button class="ne-icon-btn ne-icon-delete" onclick="del(<?=$r['department_id']?>,'<?=e(addslashes($r['department_name']))?>')" title="ลบ"><i class="bi bi-trash"></i></button>
                  </div>
                </td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="ne-empty"><i class="bi bi-diagram-3"></i>ยังไม่มีหน่วยงาน</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content ne-modal-content ne">
  <div class="modal-header ne-modal-header"><h5 class="modal-title" id="mt" style="font-family:'Kanit',serif;">หน่วยงาน</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" style="padding:20px;">
    <input type="hidden" id="fId">
    <label style="font-size:11.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--ne-slate);display:block;margin-bottom:7px;">ชื่อหน่วยงาน <span style="color:var(--ne-danger)">*</span></label>
    <input type="text" class="ne-input" id="fName">
  </div>
  <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
    <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
    <button type="button" class="ne-btn ne-btn-primary" onclick="save()">บันทึก</button>
  </div>
</div></div></div>

<script id="dd" type="application/json"><?=json_encode($rows,JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
<?php $extraJs='<script>
const dd=JSON.parse(document.getElementById("dd").textContent);const csrf="'.csrfToken().'";let mi;
function openModal(id){const d=dd.find(x=>x.department_id==id);document.getElementById("fId").value=id;document.getElementById("fName").value=d?d.department_name:"";document.getElementById("mt").textContent=id?"แก้ไขหน่วยงาน":"เพิ่มหน่วยงาน";mi=new bootstrap.Modal(document.getElementById("modal"));mi.show();}
async function save(){const fd=new FormData();fd.append("action","save");fd.append("department_id",document.getElementById("fId").value);fd.append("department_name",document.getElementById("fName").value);fd.append("csrf_token",csrf);const r=await axios.post("",fd);if(r.data.status==="success"){mi.hide();showSuccess(r.data.message);setTimeout(()=>location.reload(),1300);}else showError(r.data.message);}
async function del(id,name){
  const d=dd.find(x=>x.department_id==id);const sc=d?Number(d.sc)||0:0;
  if(sc>0){await Swal.fire({title:"ไม่สามารถลบได้",html:`หน่วยงาน <b>${escapeHtml(name)}</b> ยังมีบุคลากร ${sc} คนที่ผูกอยู่ กรุณาย้ายหรือลบข้อมูลที่เกี่ยวข้องก่อน`,icon:"warning",confirmButtonText:"เข้าใจแล้ว"});return;}
  const r=await Swal.fire({title:"ลบ?",html:`ลบ <b>${escapeHtml(name)}</b>?`,icon:"warning",showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});if(!r.isConfirmed)return;const fd=new FormData();fd.append("action","delete");fd.append("department_id",id);fd.append("csrf_token",csrf);const res=await axios.post("",fd);if(res.data.status==="success"){showSuccess(res.data.message);setTimeout(()=>location.reload(),1300);}else showError(res.data.message);}
</script>';
include BASE_PATH.'/admin/layout/footer.php';?>