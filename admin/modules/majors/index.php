<?php
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
$auth=new Auth();$auth->requireLogin();$db=Database::getInstance();

if ($_SERVER['REQUEST_METHOD']==='POST'){
    verifyCsrf();$a=$_POST['action']??'';
    if($a==='save'){
        $id=(int)($_POST['major_id']??0);$name=postStr('major_name');$abbr=postStr('major_abbr');
        if(!$name)jsonError('กรุณากรอกชื่อสาขาวิชา');if(nameTaken($db,'majors','major_name','major_id',$name,$id))jsonError(dupMsg('สาขาวิชา', $name));
        $d=['major_name'=>$name,'major_abbr'=>$abbr?:null];
        if (!empty($_FILES['image']['name'])) {
            $up = uploadImage($_FILES['image'], 'majors');
            if ($up) {
                fileTrackNew($up);
                if ($id) {
                    $old = $db->fetchOne('SELECT image FROM majors WHERE major_id=?', [$id]);
                    if ($old) fileQueueDelete($old['image']);   // the old picture goes only once the change is saved
                }
                $d['image'] = $up;
            } else jsonError('อัปโหลดภาพไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        } elseif ($id && !empty($_POST['remove_image'])) {
            // No new file chosen, but the admin explicitly asked to clear
            // the existing one (not just "leave it alone", the default).
            $old = $db->fetchOne('SELECT image FROM majors WHERE major_id=?', [$id]);
            if ($old) fileQueueDelete($old['image']);
            $d['image'] = null;
        }
        if($id){$db->update('majors',$d,'major_id=?',[$id]);fileCommitChanges();jsonSuccess([],'แก้ไขสาขาวิชาแล้ว');}
        else{$db->insert('majors',$d);fileCommitChanges();jsonSuccess([],'เพิ่มสาขาวิชาแล้ว');}
    }
    if($a==='delete'){
        $id=(int)($_POST['major_id']??0);
        $c=$db->count('programs','major_id=?',[$id])+$db->count('staff','major_id=?',[$id]);
        if($c>0)jsonError("ไม่สามารถลบได้ มีหลักสูตรหรือบุคลากรที่ใช้สาขานี้ $c รายการ (นับรวมหลักสูตรที่ปิดรับสมัครและผู้ที่ลาออกแล้ว)");
        $row=$db->fetchOne('SELECT image FROM majors WHERE major_id=?',[$id]);
        $db->delete('majors','major_id=?',[$id]);
        if ($row) { fileQueueDelete($row['image']); fileCommitChanges(); }   // the picture goes only once the row is really gone
        jsonSuccess([],'ลบแล้ว');
    }
}
$rows=$db->fetchAll("SELECT m.*,(SELECT COUNT(*) FROM programs WHERE major_id=m.major_id) pc,(SELECT COUNT(*) FROM staff WHERE major_id=m.major_id AND " . staffCurrentSql('') . ") sc FROM majors m ORDER BY m.major_name");
foreach ($rows as &$r) { $r['image_url'] = !empty($r['image']) ? UPLOAD_URL.'/'.$r['image'] : null; } unset($r);
$pageTitle='จัดการสาขาวิชา';$activeMenu='majors';$breadcrumb=[['label'=>'สาขาวิชา']];
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
.ne-abbr-code { font-family: monospace; font-size: 12.5px; color: var(--ne-ink-soft); background: var(--ne-slate-soft); padding: 3px 8px; border-radius: 6px; }
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
    <h1>สาขาวิชาทั้งหมด <span class="ne-count"><?=count($rows)?> สาขา</span></h1>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openModal(0)">
      <i class="bi bi-plus-lg"></i>เพิ่มสาขาวิชา
    </button>
  </div>

  <div class="ne-grid">
    <div class="ne-main">
      <div class="ne-panel">
        <div class="ne-panel-head"><span>สาขาวิชาทั้งหมด</span></div>
        <?php if (!empty($rows)): ?>
        <div class="table-responsive">
          <table class="ne-table">
            <thead>
              <tr>
                <th style="width:44px">#</th>
                <th style="width:56px">ภาพ</th>
                <th>ชื่อสาขาวิชา</th>
                <th style="width:110px">ชื่อย่อ</th>
                <th style="width:100px" class="text-center">หลักสูตร</th>
                <th style="width:100px" class="text-center">บุคลากร</th>
                <th style="width:100px">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $i=>$r):?>
              <tr>
                <td class="ne-num"><?=$i+1?></td>
                <td>
                  <?php if ($r['image_url']): ?>
                  <img src="<?=e($r['image_url'])?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid var(--ne-line);">
                  <?php else: ?>
                  <div style="width:40px;height:40px;border-radius:8px;background:var(--ne-slate-soft);color:var(--ne-slate);display:flex;align-items:center;justify-content:center;"><i class="bi bi-image"></i></div>
                  <?php endif; ?>
                </td>
                <td class="fw-500"><?=e($r['major_name'])?></td>
                <td><span class="ne-abbr-code"><?=e($r['major_abbr']??'—')?></span></td>
                <td class="text-center"><span class="ne-badge-count"><?=$r['pc']?></span></td>
                <td class="text-center"><span class="ne-badge-count"><?=$r['sc']?></span></td>
                <td>
                  <div class="ne-actions">
                    <button class="ne-icon-btn ne-icon-edit" onclick="openModal(<?=$r['major_id']?>)" title="แก้ไข"><i class="bi bi-pencil"></i></button>
                    <button class="ne-icon-btn ne-icon-delete" onclick="del(<?=$r['major_id']?>,'<?=e(addslashes($r['major_name']))?>')" title="ลบ"><i class="bi bi-trash"></i></button>
                  </div>
                </td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="ne-empty"><i class="bi bi-diagram-3"></i>ยังไม่มีข้อมูลสาขาวิชา</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content ne-modal-content ne">
  <div class="modal-header ne-modal-header"><h5 class="modal-title" id="mt" style="font-family:'Kanit',serif;">สาขาวิชา</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" style="padding:20px;">
    <input type="hidden" id="fId">
    <div class="ne-field">
      <label for="fName">ชื่อสาขาวิชา <span style="color:var(--ne-danger)">*</span></label>
      <input type="text" class="ne-input" id="fName">
    </div>
    <div class="ne-field">
      <label for="fAbbr">ชื่อย่อ</label>
      <input type="text" class="ne-input" id="fAbbr">
    </div>
    <div class="ne-field" style="margin-bottom:0;">
      <label for="fImage">ภาพประกอบ</label>
      <div id="fImagePreviewWrap" style="display:none;position:relative;margin-bottom:8px;">
        <img id="fImagePreview" src="" alt="" style="width:100%;max-height:140px;object-fit:cover;border-radius:8px;border:1px solid var(--ne-line);display:block;">
        <button type="button" id="fImageRemoveBtn" title="ลบภาพนี้"
                style="position:absolute;top:6px;right:6px;width:28px;height:28px;border-radius:50%;border:none;background:rgba(17,35,110,.75);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;">
          <i class="bi bi-trash"></i>
        </button>
      </div>
      <input type="hidden" id="fRemoveImage" value="0">
      <input type="file" data-has-preview class="ne-input" id="fImage" accept="image/*">
    </div>
  </div>
  <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
    <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
    <button type="button" class="ne-btn ne-btn-primary" onclick="save()">บันทึก</button>
  </div>
</div></div></div>

<script id="dd" type="application/json"><?=json_encode($rows,JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
<?php $extraJs='<script>
const dd=JSON.parse(document.getElementById("dd").textContent);const csrf="'.csrfToken().'";let mi;
function openModal(id){
  const d=dd.find(x=>x.major_id==id);
  document.getElementById("fId").value=id;
  document.getElementById("fName").value=d?d.major_name:"";
  document.getElementById("fAbbr").value=d?d.major_abbr??"":"";
  document.getElementById("fImage").value="";
  document.getElementById("fRemoveImage").value="0";
  const prev=document.getElementById("fImagePreview");
  const prevWrap=document.getElementById("fImagePreviewWrap");
  if(d&&d.image_url){prev.src=d.image_url;prevWrap.style.display="block";}else{prev.src="";prevWrap.style.display="none";}
  document.getElementById("mt").textContent=id?"แก้ไขสาขาวิชา":"เพิ่มสาขาวิชา";
  mi=new bootstrap.Modal(document.getElementById("modal"));mi.show();
}
document.getElementById("fImageRemoveBtn").addEventListener("click",function(){
  document.getElementById("fImagePreviewWrap").style.display="none";
  document.getElementById("fImagePreview").src="";
  document.getElementById("fRemoveImage").value="1";
  document.getElementById("fImage").value="";
});
document.getElementById("fImage").addEventListener("change",function(){
  const file=this.files[0];
  if(!file)return;
  document.getElementById("fRemoveImage").value="0";
  const reader=new FileReader();
  reader.onload=e=>{document.getElementById("fImagePreview").src=e.target.result;document.getElementById("fImagePreviewWrap").style.display="block";};
  reader.readAsDataURL(file);
});
async function save(){const fd=new FormData();fd.append("action","save");fd.append("major_id",document.getElementById("fId").value);fd.append("major_name",document.getElementById("fName").value);fd.append("major_abbr",document.getElementById("fAbbr").value);const imgFile=document.getElementById("fImage").files[0];if(imgFile)fd.append("image",imgFile);fd.append("remove_image",document.getElementById("fRemoveImage").value);fd.append("csrf_token",csrf);const r=await axios.post("",fd);if(r.data.status==="success"){mi.hide();showSuccess(r.data.message);setTimeout(()=>location.reload(),1300);}else showError(r.data.message);}
async function del(id,name){
  const d=dd.find(x=>x.major_id==id);const pc=d?Number(d.pc)||0:0,sc=d?Number(d.sc)||0:0;
  if(pc>0||sc>0){
    const parts=[];if(pc>0)parts.push(`หลักสูตร ${pc} รายการ`);if(sc>0)parts.push(`บุคลากร ${sc} คน`);
    await Swal.fire({title:"ไม่สามารถลบได้",html:`สาขาวิชา <b>${escapeHtml(name)}</b> ยังมี${parts.join(" และ ")}ที่ผูกอยู่ กรุณาย้ายหรือลบข้อมูลที่เกี่ยวข้องก่อน`,icon:"warning",confirmButtonText:"เข้าใจแล้ว"});
    return;
  }
  const r=await Swal.fire({title:"ลบสาขาวิชา?",html:`ลบ <b>${escapeHtml(name)}</b>?`,icon:"warning",showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});if(!r.isConfirmed)return;const fd=new FormData();fd.append("action","delete");fd.append("major_id",id);fd.append("csrf_token",csrf);const res=await axios.post("",fd);if(res.data.status==="success"){showSuccess(res.data.message);setTimeout(()=>location.reload(),1300);}else showError(res.data.message);}
</script>';
include BASE_PATH.'/admin/layout/footer.php';?>