<?php
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if(!defined('BASE_PATH'))define('BASE_PATH',dirname(dirname(dirname(__DIR__))));
$auth=new Auth();$auth->requireLogin();$db=Database::getInstance();
if($_SERVER['REQUEST_METHOD']==='POST'){verifyCsrf();$a=$_POST['action']??'';
  if($a==='save'){$id=(int)($_POST['position_id']??0);$name=postStr('position_name');$level=(int)($_POST['position_level']??5);if(!$name)jsonError('กรุณากรอกชื่อตำแหน่ง');if(nameTaken($db,'positions','position_name','position_id',$name,$id))jsonError(dupMsg('ตำแหน่ง', $name));$d=['position_name'=>$name,'position_level'=>$level];if($id){$db->update('positions',$d,'position_id=?',[$id]);jsonSuccess([],'แก้ไขแล้ว');}else{$db->insert('positions',$d);jsonSuccess([],'เพิ่มแล้ว');}}
  if($a==='delete'){$id=(int)($_POST['position_id']??0);$c=$db->count('staff','position_id=?',[$id]);if($c)jsonError("ลบไม่ได้ มีบุคลากร $c คนใช้ตำแหน่งนี้ (นับรวมผู้ที่ลาออกแล้ว)");$db->delete('positions','position_id=?',[$id]);jsonSuccess([],'ลบแล้ว');}
}
$rows=$db->fetchAll("SELECT p.*,(SELECT COUNT(*) FROM staff WHERE position_id=p.position_id AND " . staffCurrentSql('') . ") sc FROM positions p ORDER BY p.position_level DESC,p.position_name");
$pageTitle='จัดการตำแหน่ง';$activeMenu='positions';$breadcrumb=[['label'=>'ตำแหน่ง']];
$pageActions='';
include BASE_PATH.'/admin/layout/header.php';?>

<div class="ne">
  <div class="ne-topbar">
    <h1>ตำแหน่งบุคลากรทั้งหมด <span class="ne-count"><?=count($rows)?> ตำแหน่ง</span></h1>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openModal(0)">
      <i class="bi bi-plus-lg"></i>เพิ่มตำแหน่ง
    </button>
  </div>

  <div class="ne-panel">
    <div class="ne-panel-head"><span>ตำแหน่งทั้งหมด</span></div>
    <?php if (!empty($rows)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th>ชื่อตำแหน่ง</th>
            <th style="width:150px" class="text-center">ระดับ (สูง=สำคัญ)</th>
            <th style="width:100px" class="text-center">บุคลากร</th>
            <th style="width:100px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $i=>$r):?>
          <tr>
            <td class="ne-num"><?=$i+1?></td>
            <td class="fw-500"><?=e($r['position_name'])?></td>
            <td class="text-center"><span class="ne-badge-count"><?=$r['position_level']?></span></td>
            <td class="text-center"><span class="ne-badge-count"><?=$r['sc']?></span></td>
            <td>
              <div class="ne-actions">
                <button class="ne-icon-btn ne-icon-edit" onclick="openModal(<?=$r['position_id']?>)" title="แก้ไข"><i class="bi bi-pencil"></i></button>
                <button class="ne-icon-btn ne-icon-delete" onclick="del(<?=$r['position_id']?>,'<?=e(addslashes($r['position_name']))?>')" title="ลบ"><i class="bi bi-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="ne-empty"><i class="bi bi-person-badge"></i>ยังไม่มีข้อมูล</div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content ne-modal-content ne">
  <div class="modal-header ne-modal-header"><h5 class="modal-title" id="mt" style="font-family:'Kanit',serif;">ตำแหน่ง</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" style="padding:20px;">
    <input type="hidden" id="fId">
    <div class="ne-field"><label for="fName">ชื่อตำแหน่ง <span style="color:var(--ne-danger)">*</span></label><input type="text" class="ne-input" id="fName"></div>
    <div class="ne-field" style="margin-bottom:0;"><label for="fLevel">ระดับ (1-10, ยิ่งสูงยิ่งสำคัญ)</label><input type="number" class="ne-input" id="fLevel" value="5" min="1" max="10"></div>
  </div>
  <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
    <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
    <button type="button" class="ne-btn ne-btn-primary" onclick="save()">บันทึก</button>
  </div>
</div></div></div>
<script id="dd" type="application/json"><?=json_encode($rows,JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
<?php $extraJs='<script>
const dd=JSON.parse(document.getElementById("dd").textContent);const csrf="'.csrfToken().'";let mi;
function openModal(id){const d=dd.find(x=>x.position_id==id);document.getElementById("fId").value=id;document.getElementById("fName").value=d?d.position_name:"";document.getElementById("fLevel").value=d?d.position_level:5;document.getElementById("mt").textContent=id?"แก้ไขตำแหน่ง":"เพิ่มตำแหน่ง";mi=new bootstrap.Modal(document.getElementById("modal"));mi.show();}
async function save(){const fd=new FormData();fd.append("action","save");fd.append("position_id",document.getElementById("fId").value);fd.append("position_name",document.getElementById("fName").value);fd.append("position_level",document.getElementById("fLevel").value);fd.append("csrf_token",csrf);const r=await axios.post("",fd);if(r.data.status==="success"){mi.hide();showSuccess(r.data.message);setTimeout(()=>location.reload(),1300);}else showError(r.data.message);}
async function del(id,name){
  const d=dd.find(x=>x.position_id==id);const sc=d?Number(d.sc)||0:0;
  if(sc>0){await Swal.fire({title:"ไม่สามารถลบได้",html:`ตำแหน่ง <b>${escapeHtml(name)}</b> ยังมีบุคลากร ${sc} คนที่ใช้ตำแหน่งนี้อยู่ กรุณาย้ายหรือลบข้อมูลที่เกี่ยวข้องก่อน`,icon:"warning",confirmButtonText:"เข้าใจแล้ว"});return;}
  const r=await Swal.fire({title:"ลบ?",html:`ลบ <b>${escapeHtml(name)}</b>?`,icon:"warning",showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});if(!r.isConfirmed)return;const fd=new FormData();fd.append("action","delete");fd.append("position_id",id);fd.append("csrf_token",csrf);const res=await axios.post("",fd);if(res.data.status==="success"){showSuccess(res.data.message);setTimeout(()=>location.reload(),1300);}else showError(res.data.message);}
</script>';
include BASE_PATH.'/admin/layout/footer.php';?>
