<?php
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
$auth = new Auth(); $auth->requireLogin();
$db   = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id=(int)($_POST['contact_id']??0); $campusId=(int)($_POST['campus_id']??0);
        $type=postStr('contact_type'); $value=postStr('contact_value');
        $label=postStr('display_label');
        if(!$campusId||!$type||!$value) jsonError('กรุณากรอกข้อมูลให้ครบ');
        if(!array_key_exists($type,contactTypeLabels($db))) jsonError('ประเภทช่องทางติดต่อไม่ถูกต้อง');
        if(!$db->fetchOne('SELECT 1 FROM campuses WHERE campus_id=?',[$campusId])) jsonError('ไม่พบศูนย์พื้นที่ที่เลือก');
        if(rowExists($db,'campus_contacts',['campus_id'=>$campusId,'contact_type'=>$type,'contact_value'=>$value],'contact_id',$id)) jsonError('ช่องทางติดต่อนี้ ("'.$value.'") มีอยู่แล้วในศูนย์นี้ กรุณาแก้ไขรายการเดิมแทนการเพิ่มใหม่');
        $data=['campus_id'=>$campusId,'contact_type'=>$type,'contact_value'=>$value,'display_label'=>$label?:null];
        if($id){$db->update('campus_contacts',$data,'contact_id=?',[$id]);jsonSuccess([],'แก้ไขข้อมูลติดต่อแล้ว');}
        else{$db->insert('campus_contacts',$data);jsonSuccess([],'เพิ่มข้อมูลติดต่อแล้ว');}
    }
    if ($action === 'delete') {
        $id=(int)($_POST['contact_id']??0);
        $db->delete('campus_contacts','contact_id=?',[$id]); jsonSuccess([],'ลบแล้ว');
    }
}

$campuses = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$filterCampus = (int)($_GET['campus'] ?? 0);
$where='1=1'; $params=[];
if($filterCampus){$where.=' AND cc.campus_id=?';$params[]=$filterCampus;}
$contacts = $db->fetchAll(
    "SELECT cc.*,c.campus_name FROM campus_contacts cc JOIN campuses c ON c.campus_id=cc.campus_id WHERE $where ORDER BY c.campus_id,cc.contact_type",
    $params
);
$typeLabels=contactTypeLabels($db);
$typeIcons=['phone'=>'bi-telephone-fill text-success','email'=>'bi-envelope-fill text-primary','facebook'=>'bi-facebook text-primary','line'=>'bi-chat-fill text-success','tiktok'=>'bi-tiktok','website'=>'bi-globe2 text-secondary','youtube'=>'bi-youtube text-danger','x'=>'bi-twitter-x','instagram'=>'bi-instagram'];

$pageTitle='ข้อมูลติดต่อศูนย์พื้นที่'; $activeMenu='campus';   // a page under ศูนย์พื้นที่: that menu stays highlighted
$breadcrumb=[['label'=>'ศูนย์พื้นที่','url'=>BASE_URL.'/admin/modules/campus/index.php'],['label'=>'ข้อมูลติดต่อ']];
$pageActions='';
include BASE_PATH.'/admin/layout/header.php';
?>

<div class="ne">
  <div class="ne-topbar">
    <h1>ข้อมูลติดต่อศูนย์พื้นที่ <span class="ne-count"><?=count($contacts)?> ช่องทาง</span></h1>
    <button type="button" class="ne-btn ne-btn-primary" style="margin-left:auto;" onclick="openModal(0)">
      <i class="bi bi-plus-lg"></i>เพิ่มช่องทางติดต่อ
    </button>
  </div>

  <form class="ne-filters" method="GET">
    <div class="ne-filters-field">
      <label for="fCampusFilter">ศูนย์พื้นที่</label>
      <select id="fCampusFilter" class="ne-select" name="campus" onchange="this.form.submit()">
        <option value="">— ทั้งหมด —</option>
        <?php foreach($campuses as $c):?><option value="<?=$c['campus_id']?>" <?=$filterCampus==$c['campus_id']?'selected':''?>><?=e($c['campus_name'])?></option><?php endforeach;?>
      </select>
    </div>
    <?php if($filterCampus):?>
    <div class="ne-filters-actions">
      <a href="?" class="ne-btn ne-btn-ghost">ล้างตัวกรอง</a>
    </div>
    <?php endif;?>
  </form>

  <div class="ne-panel">
    <div class="ne-panel-head"><span>ช่องทางติดต่อทั้งหมด</span></div>
    <?php if (!empty($contacts)): ?>
    <div class="table-responsive">
      <table class="ne-table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th>ศูนย์พื้นที่</th>
            <th style="width:150px">ประเภท</th>
            <th>ข้อมูลติดต่อ</th>
            <th>ชื่อแสดง</th>
            <th style="width:100px">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($contacts as $i=>$ct):
            $icon=$typeIcons[$ct['contact_type']]??'bi-link';
            $lbl=$typeLabels[$ct['contact_type']]??$ct['contact_type'];
          ?>
          <tr>
            <td class="ne-num"><?=$i+1?></td>
            <td class="fw-500"><?=e($ct['campus_name'])?></td>
            <td><span class="ne-chip" style="background:var(--ne-slate-soft);color:var(--ne-ink-soft);"><i class="bi <?=$icon?>"></i><?=$lbl?></span></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis"><?=e($ct['contact_value'])?></td>
            <td class="ne-num"><?=e($ct['display_label']??'—')?></td>
            <td>
              <div class="ne-actions">
                <button class="ne-icon-btn ne-icon-edit" onclick="openModal(<?=$ct['contact_id']?>)" title="แก้ไข"><i class="bi bi-pencil"></i></button>
                <button class="ne-icon-btn ne-icon-delete" onclick="del(<?=$ct['contact_id']?>)" title="ลบ"><i class="bi bi-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="ne-empty"><i class="bi bi-telephone-x"></i>ยังไม่มีข้อมูลติดต่อ</div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content ne-modal-content ne">
  <div class="modal-header ne-modal-header">
    <h5 class="modal-title" id="mt" style="font-family:'Kanit',serif;">ช่องทางติดต่อ</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body" style="padding:20px;">
    <input type="hidden" id="fId">
    <div class="ne-field"><label for="fCampus">ศูนย์พื้นที่ <span style="color:var(--ne-danger)">*</span></label>
      <select class="ne-select" id="fCampus"><option value="">— เลือกศูนย์ —</option><?php foreach($campuses as $c):?><option value="<?=$c['campus_id']?>"><?=e($c['campus_name'])?></option><?php endforeach;?></select></div>
    <div class="ne-meta-row" style="margin-top:0;padding-top:0;border-top:none;">
      <div class="ne-meta-field"><label for="fType">ประเภท <span style="color:var(--ne-danger)">*</span></label>
        <select class="ne-select" id="fType"><?php foreach($typeLabels as $v=>$l):?><option value="<?=$v?>"><?=$l?></option><?php endforeach;?></select></div>
      <div class="ne-meta-field"><label for="fLabel">ชื่อแสดง</label><input type="text" class="ne-input" id="fLabel" placeholder="เช่น โทร.วาสุกรี"></div>
    </div>
    <div class="ne-field" style="margin-bottom:0;"><label for="fValue">ข้อมูลติดต่อ <span style="color:var(--ne-danger)">*</span></label><input type="text" class="ne-input" id="fValue" placeholder="เบอร์โทร / URL / @username"><p class="ne-hint">กรณี Facebook/Line ให้ใส่ URL เต็ม หรือ @username</p></div>
  </div>
  <div class="modal-footer" style="border-top:1px solid var(--ne-line);">
    <button type="button" class="ne-btn ne-btn-ghost" data-bs-dismiss="modal">ยกเลิก</button>
    <button type="button" class="ne-btn ne-btn-primary" onclick="save()"><i class="bi bi-check-lg"></i>บันทึก</button>
  </div>
</div></div></div>

<script id="dd" type="application/json"><?=json_encode($contacts,JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
<?php $extraJs='<script>
const dd=JSON.parse(document.getElementById("dd").textContent);
const csrf='.json_encode(csrfToken()).';let mi;
function openModal(id){const d=dd.find(x=>x.contact_id==id);document.getElementById("fId").value=id;document.getElementById("fCampus").value=d?d.campus_id:"";document.getElementById("fType").value=d?d.contact_type:"phone";document.getElementById("fValue").value=d?d.contact_value:"";document.getElementById("fLabel").value=d?d.display_label??"":"";document.getElementById("mt").textContent=id?"แก้ไขช่องทางติดต่อ":"เพิ่มช่องทางติดต่อ";mi=new bootstrap.Modal(document.getElementById("modal"));mi.show();}
async function save(){const fd=new FormData();fd.append("action","save");fd.append("contact_id",document.getElementById("fId").value);fd.append("campus_id",document.getElementById("fCampus").value);fd.append("contact_type",document.getElementById("fType").value);fd.append("contact_value",document.getElementById("fValue").value);fd.append("display_label",document.getElementById("fLabel").value);fd.append("csrf_token",csrf);const r=await axios.post("",fd);if(r.data.status==="success"){mi.hide();showSuccess(r.data.message);setTimeout(()=>location.reload(),1300);}else showError(r.data.message);}
async function del(id){const r=await Swal.fire({title:"ลบช่องทางติดต่อ?",icon:"warning",showCancelButton:true,confirmButtonColor:"#c1443d",confirmButtonText:"ลบ",cancelButtonText:"ยกเลิก"});if(!r.isConfirmed)return;const fd=new FormData();fd.append("action","delete");fd.append("contact_id",id);fd.append("csrf_token",csrf);const res=await axios.post("",fd);if(res.data.status==="success"){showSuccess(res.data.message);setTimeout(()=>location.reload(),1300);}else showError(res.data.message);}
</script>';
include BASE_PATH.'/admin/layout/footer.php';?>
