<?php
/**
 * BAIT Admin – ผังโครงสร้างบุคลากร (Org Chart)
 * - ผังต้นไม้แบบผสม: ผู้บริหารสูงสุดอยู่บนสุด เชื่อมเส้นลงมาหาลูกตรงแต่ละคน (สายบังคับบัญชาจริง
 *   จาก supervisor_id) — ลูกตรงทุกคนของหัวหน้าคนเดียวกัน อยู่ในระดับเดียวกัน เรียงหน้ากระดาน
 *   เป็นแถวเดียวกันเสมอ (ตรงกับหน้าเว็บสาธารณะ) ใครมีลูกน้องของตัวเอง จะได้กิ่งเพิ่มเป็น "การ์ด
 *   หัวหน้า + กริดผู้ใต้บังคับบัญชาทั้งหมดของสายนั้น" (รวมทุกชั้นมาไว้กริดเดียว ไม่แตกกิ่งซ้อน)
 * - ใครไม่มีหัวหน้า (supervisor_id ว่าง หรือหัวหน้าไม่อยู่ในกลุ่มที่กรองอยู่) จะขึ้นเป็นรากของผัง
 * - แต่ละกิ่งจัดกริดผู้ใต้บังคับบัญชาให้เป็นรูปสี่เหลี่ยมจัตุรัสคร่าวๆ (4 คน = 2x2, 9 คน = 3x3, ...)
 * - จัดกึ่งกลางด้วย justify-content:safe center ที่แถวนอกสุด — กึ่งกลางเมื่อพื้นที่พอ แต่ถอยไปชิดซ้าย
 *   พร้อมเลื่อนดูได้เมื่อผังกว้างเกินพื้นที่ (safe center กันไม่ให้เนื้อหาส่วนเกินตกไปอยู่ในโซนที่
 *   scrollbar เลื่อนไปไม่ถึง ซึ่งจะเกิดขึ้นถ้าใช้ center เฉยๆ)
 * - Drag & Drop เรียงลำดับด้วย SortableJS: ลากกิ่งเพื่อสลับลำดับ, ลากการ์ดในกริด/แถวเพื่อสลับ
 *   ลำดับคนภายในกิ่งเดียวกัน
 * - กำหนดหัวหน้าศูนย์พื้นที่ (แยกจากสายบังคับบัญชา ใช้แสดงป้าย "หัวหน้าศูนย์" เท่านั้น)
 * - การ์ดแสดงเฉพาะ ชื่อ / ตำแหน่ง / ด้านที่รับผิดชอบ (ถ้ามี) / ลิงก์โปรไฟล์ (ถ้ามี)
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/components.php';
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
$auth=new Auth(); $auth->requireLogin(); $db=Database::getInstance();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $action=$_POST['action']??'';

    if ($action==='reorder') {
        $orders=json_decode(postRaw('orders','[]'),true); if(!is_array($orders)) $orders=[];
        foreach($orders as $item) {
            if(!is_array($item)||!isset($item['id'],$item['order'])) continue;
            $db->update('staff',['display_order'=>(int)$item['order']],'staff_id=?',[(int)$item['id']]);
        }
        jsonSuccess([],'บันทึกลำดับเรียบร้อยแล้ว');
    }

    if ($action==='set_head') {
        $campusId=(int)($_POST['campus_id']??0);
        $staffId=(int)($_POST['staff_id']??0)?:null;
        if(!$db->fetchOne('SELECT 1 FROM campuses WHERE campus_id=?',[$campusId])) jsonError('ไม่พบศูนย์พื้นที่ที่เลือก');
        // the same people the dropdown offers: current staff (not resigned) of this campus
        if($staffId&&!$db->fetchOne('SELECT 1 FROM staff s WHERE s.staff_id=? AND s.campus_id=? AND '.staffCurrentSql('s'),[$staffId,$campusId])) jsonError('เลือกได้เฉพาะบุคลากรที่ยังปฏิบัติงานอยู่ในศูนย์พื้นที่นี้ กรุณาโหลดหน้าใหม่แล้วเลือกอีกครั้ง');
        $db->update('campuses',['head_staff_id'=>$staffId],'campus_id=?',[$campusId]);
        jsonSuccess([],'กำหนดหัวหน้าเรียบร้อยแล้ว');
    }

    if ($action==='set_major_head') {
        // Keyed by (campus_id, major_id) together, not major_id alone — the
        // same สาขา taught at two different ศูนย์ can have two different
        // heads, and a person picked while looking at one campus must never
        // leak into the dropdown for another campus's slice of that major.
        $campusId=(int)($_POST['campus_id']??0);
        $majorId=(int)($_POST['major_id']??0);
        $staffId=(int)($_POST['staff_id']??0)?:null;
        if(!$db->fetchOne('SELECT 1 FROM campuses WHERE campus_id=?',[$campusId])) jsonError('ไม่พบศูนย์พื้นที่ที่เลือก');
        if(!$db->fetchOne('SELECT 1 FROM majors WHERE major_id=?',[$majorId])) jsonError('ไม่พบสาขาวิชาที่เลือก');
        // the same people the dropdown offers: current staff (not resigned) of this campus and this สาขา
        if($staffId&&!$db->fetchOne('SELECT 1 FROM staff s WHERE s.staff_id=? AND s.campus_id=? AND s.major_id=? AND '.staffCurrentSql('s'),[$staffId,$campusId,$majorId])) jsonError('เลือกได้เฉพาะบุคลากรที่ยังปฏิบัติงานอยู่ในสาขานี้ของศูนย์พื้นที่นี้ กรุณาโหลดหน้าใหม่แล้วเลือกอีกครั้ง');
        $db->query(
            'INSERT INTO major_campus_heads (campus_id, major_id, head_staff_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE head_staff_id = VALUES(head_staff_id)',
            [$campusId, $majorId, $staffId]
        );
        jsonSuccess([],'กำหนดหัวหน้าสาขาเรียบร้อยแล้ว');
    }
}

$filterCampus=(int)($_GET['campus']??0);
$filterMajor=(int)($_GET['major']??0);
$view=in_array($_GET['view']??'',['executive','campus'],true)?$_GET['view']:'executive';
$campuses=$db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");
$majors=$db->fetchAll("SELECT * FROM majors ORDER BY major_name");

$where=staffCurrentSql('s'); $params=[];
if($filterCampus){$where.=' AND s.campus_id=?';$params[]=$filterCampus;}
if($filterMajor){$where.=' AND s.major_id=?';$params[]=$filterMajor;}

$staffAll=$db->fetchAll(
    "SELECT s.staff_id,s.prefix,s.full_name,s.image,s.display_order,s.campus_id,s.supervisor_id,s.email,s.responsibility,s.e_profile_url,
            p.position_name,p.position_level,
            m.major_name,st.staff_type_name,d.department_name
     FROM staff s
     JOIN positions p ON p.position_id=s.position_id
     LEFT JOIN majors m ON m.major_id=s.major_id
     JOIN staff_types st ON st.staff_type_id=s.staff_type_id
     LEFT JOIN departments d ON d.department_id=s.department_id
     WHERE $where
     ORDER BY p.position_level DESC, s.display_order ASC, s.full_name ASC",
    $params
);

// Seed one group per campus that should be shown
$campusesToShow = $filterCampus
    ? array_values(array_filter($campuses, fn($c)=>$c['campus_id']==$filterCampus))
    : $campuses;

$grouped=[];

if ($filterCampus) {
    // ── Single campus selected: show that campus only ──
    foreach ($campusesToShow as $c) {
        $grouped[$c['campus_id']] = [
            'campus_id'         => $c['campus_id'],
            'campus_name'       => $c['campus_name'],
            'head_id'           => $c['head_staff_id'],
            'show_head_control' => true,
            'all'               => [],
        ];
    }
    foreach ($staffAll as $s) {
        $cid = $s['campus_id'];
        if (!isset($grouped[$cid])) continue;
        $grouped[$cid]['all'][]=$s;
    }
} else {
    // ── "แสดงทั้งหมด": merge everyone into one unified chart instead of
    //    splitting per campus (each person appears exactly once) ──
    $grouped['all'] = [
        'campus_id'         => null,
        'campus_name'       => 'ภาพรวมทุกศูนย์พื้นที่',
        'head_id'           => null,
        'show_head_control' => false,
        'all'               => [],
    ];
    foreach ($staffAll as $s) { $grouped['all']['all'][]=$s; }
}

// Drop sections that end up with nobody at all
$grouped = array_filter($grouped, fn($g)=>!empty($g['all']));

/** All of a person's reports, flattened (children, grandchildren, ...) — one flat grid, no further branching. */
function collectDescendants(int $staffId, array $byParent): array {
    $result = [];
    foreach ($byParent[$staffId] ?? [] as $child) {
        $result[] = $child;
        $result = array_merge($result, collectDescendants($child['staff_id'], $byParent));
    }
    return $result;
}

/** Pulls the explicitly-assigned หัวหน้าสาขาวิชา (majors.head_staff_id, set
 *  via the "หัวหน้าสาขา" dropdown below) out of a major's member list, so the
 *  view can render them as the peak of a small pyramid — their card on top,
 *  everyone else in the major below — instead of one flat, unranked grid.
 *  Not driven by position_name: someone can sit on the executive board
 *  under an administrative title and still be the head of their department,
 *  so who's "head" here has to be a deliberate choice, not a string match. */
function splitMajorHead(array $members, ?int $headStaffId): array {
    if (!$headStaffId) return [null, $members];
    foreach ($members as $i => $m) {
        if ((int)$m['staff_id'] === $headStaffId) {
            $head = $m;
            unset($members[$i]);
            return [$head, array_values($members)];
        }
    }
    return [null, $members];
}

// Grouped by campus (for "ประจำศูนย์พื้นที่") and by major (for the drill-down's
// department step). This does NOT exclude people who are also in the
// ผู้บริหาร tree — someone can genuinely hold both an administrative role
// (e.g. a Deputy Dean with reports) AND a campus/major faculty assignment
// at the same time, and hiding them from one side would just make them
// harder to find.
$campusNameById = array_column($campuses, 'campus_name', 'campus_id');
$byCampusGroup = []; $byMajorGroup = [];
foreach ($staffAll as $s) {
    $byCampusGroup[$campusNameById[$s['campus_id']] ?? 'ไม่ระบุศูนย์'][] = $s;
    if (!empty($s['major_name'])) { $byMajorGroup[$s['major_name']][] = $s; }
}

function renderBoardCard(array $s, ?int $headStaffId, bool $compact = false, string $headLabel = 'หัวหน้าศูนย์'): string {
    $isHead = $headStaffId && $headStaffId === (int)$s['staff_id'];
    ob_start(); ?>
    <div class="board-card <?=$isHead?'is-head':''?>" data-id="<?=$s['staff_id']?>">
      <div class="drag-handle"><i class="bi bi-grip-vertical"></i></div>
      <div class="board-avatar">
        <?php if($s['image']):?>
        <img src="<?=UPLOAD_URL.'/'.e($s['image'])?>" alt="">
        <?php else:?>
        <?=mb_substr($s['full_name'],0,1,'UTF-8')?>
        <?php endif;?>
      </div>
      <div class="board-body">
        <div class="board-name"><?=e($s['prefix'].$s['full_name'])?></div>
        <?php if(!$compact):?>
        <div class="board-pos"><?=e($s['position_name'])?></div>
        <?php if($s['responsibility']):?><div class="board-resp"><?=e($s['responsibility'])?></div><?php endif;?>
        <?php endif;?>
        <?php if($isHead):?><span class="head-badge"><i class="bi bi-star-fill" style="font-size:.55rem"></i><?=e($headLabel)?></span><?php endif;?>
        <?php if($s['e_profile_url']):?><a href="<?=e($s['e_profile_url'])?>" target="_blank" rel="noopener" class="board-profile-link" onclick="event.stopPropagation()"><i class="bi bi-box-arrow-up-right"></i>โปรไฟล์</a><?php endif;?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/** A branch: one head card plus a grid of everyone under them (any depth, flattened),
 *  sized to a roughly square shape (4 people -> 2x2, 9 people -> 3x3, ...). */
function renderBoardBranch(array $head, array $byParent, ?int $headStaffId): string {
    $members = collectDescendants($head['staff_id'], $byParent);
    $cols = max(1, (int)ceil(sqrt(count($members))));
    ob_start(); ?>
    <div class="board-branch" data-id="<?=$head['staff_id']?>">
      <?=renderBoardCard($head, $headStaffId)?>
      <div class="branch-grid" style="grid-template-columns:repeat(<?=$cols?>,140px)">
        <?php foreach ($members as $m): ?>
        <?=renderBoardCard($m, $headStaffId)?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/** One root's direct children, each rendered as its own sibling <li> — a
 *  branch (head+grid) for anyone who has their own reports, or just a plain
 *  card for anyone who doesn't. Every direct report is the root's
 *  organizational peer regardless of whether they happen to have reports of
 *  their own — bundling the report-less ones into a shared chain used to let
 *  that chain's own internal wrapping visually demote some of them below
 *  their actual peers. Matches renderStaffChildren() in staff.php (the
 *  public-facing chart), which was fixed the same way for the same reason. */
function renderBoardChildren(int $rootId, array $byParent, ?int $headStaffId): string {
    $children = $byParent[$rootId] ?? [];
    if (!$children) return '';

    ob_start(); ?>
    <ul class="board-tree-children">
      <?php foreach ($children as $c): ?>
      <li><?= collectDescendants($c['staff_id'], $byParent)
            ? renderBoardBranch($c, $byParent, $headStaffId)
            : renderBoardCard($c, $headStaffId) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php
    return ob_get_clean();
}

/**
 * ผังผู้บริหาร (executive view) — collapsible list layout, separate from
 * renderBoardCard()/.board-tree above (still used by the ประจำศูนย์/สาขา
 * drill-down). Same underlying supervisor_id tree, same recursion — only
 * the visual treatment differs: anyone with reports becomes a collapsible
 * unit; anyone without is a plain info row. Drag-to-reorder still works
 * the same way it did for .branch-grid: each .ex-units is its own
 * Sortable list, and .ex-unit keeps the same data-id + .drag-handle
 * saveOrder() already reads from every .board-card.
 */
function execInitials(string $fullName): string {
    return mb_substr(trim($fullName), 0, 1, 'UTF-8');
}

function execAvatarHtml(array $s, string $size = 'md'): string {
    $cls = 'ex-avatar' . ($size !== 'md' ? ' ex-avatar--' . $size : '');
    if (!empty($s['image'])) {
        return '<span class="' . $cls . '"><img src="' . e(UPLOAD_URL . '/' . $s['image']) . '" alt=""></span>';
    }
    return '<span class="' . $cls . '">' . e(execInitials($s['full_name'])) . '</span>';
}

/** ด้านที่รับผิดชอบ + ลิงก์โปรไฟล์ — same fields renderBoardCard() shows,
 *  so the collapsible view doesn't lose information the box-diagram
 *  version had. */
function execPersonMeta(array $p, bool $withResponsibility = true): string {
    $html = '';
    if ($withResponsibility && !empty($p['responsibility'])) {
        $html .= '<span class="ex-unit__resp">' . e($p['responsibility']) . '</span>';
    }
    if (!empty($p['e_profile_url'])) {
        $html .= '<a href="' . e($p['e_profile_url']) . '" class="ex-unit__profile" target="_blank" rel="noopener" onclick="event.stopPropagation()"><i class="bi bi-box-arrow-up-right"></i> โปรไฟล์</a>';
    }
    return $html;
}

function renderExecUnits(array $people, array $byParent, bool $isTop = false, int $tier = 0): string {
    $html = '<div class="ex-units' . ($isTop ? ' is-top' : '') . '">';
    foreach ($people as $p) {
        $reports = $byParent[$p['staff_id']] ?? [];
        $name = e($p['prefix'] . $p['full_name']);
        $role = e($p['position_name']);
        $handle = '<span class="drag-handle"><i class="bi bi-grip-vertical"></i></span>';

        // First tier under the apex reads as the "division" level in the
        // reference design: a kicker sits in front — the person's own
        // ด้านที่รับผิดชอบ if they have one (more specific than หน่วยงาน,
        // which is the same "สายวิชาการ"/"สายสนับสนุน" for nearly everyone
        // and wouldn't tell one division-tier card apart from the next),
        // falling back to หน่วยงาน otherwise — with their own info
        // (photo/name/position/profile) behind it, in a bigger card.
        // Everything deeper stays the compact toggle row with the full
        // info set (responsibility included there too).
        $isDivision = $tier === 0;
        $kicker = e($p['responsibility'] ?: ($p['department_name'] ?? ''));
        $meta = execPersonMeta($p, !$isDivision);
        $personBlock = $handle . execAvatarHtml($p, $isDivision ? 'lg' : 'md')
                     . '<span class="ex-unit__info"><span class="ex-unit__name">' . $name . '</span><span class="ex-unit__role">' . $role . '</span>' . $meta . '</span>';

        $html .= '<article class="ex-unit' . ($isDivision ? ' ex-unit--division' : '') . '" data-id="' . $p['staff_id'] . '">';
        if ($reports) {
            $bodyId = 'exUnit' . (int)$p['staff_id'];
            $countLabel = count($reports) . ' คน';
            $front = $isDivision
                ? '<span class="ex-unit__kicker-block"><span class="ex-unit__kicker">' . $kicker . '</span><span class="ex-unit__kicker-count">' . $countLabel . '</span></span><span class="ex-unit__divider"></span>'
                : '';
            $badge = $isDivision ? '<span class="ex-unit__chev"></span>' : '<span class="ex-unit__badge">' . $countLabel . '<span class="ex-unit__chev"></span></span>';
            $html .= '<button type="button" class="ex-unit__toggle" aria-expanded="true" aria-controls="' . $bodyId . '">'
                   . $front . $personBlock . $badge
                   . '</button>'
                   . '<div class="ex-unit__body" id="' . $bodyId . '">' . renderExecUnits($reports, $byParent, false, $tier + 1) . '</div>';
        } else {
            $front = $isDivision
                ? '<span class="ex-unit__kicker-block"><span class="ex-unit__kicker">' . $kicker . '</span></span><span class="ex-unit__divider"></span>'
                : '';
            $html .= '<div class="ex-unit__toggle ex-unit__toggle--leaf">'
                   . $front . $personBlock
                   . '</div>';
        }
        $html .= '</article>';
    }
    return $html . '</div>';
}

/** Wraps renderExecUnits() with a centered "apex" card when there's exactly
 *  one true root (the dean) — matching the reference design's top card +
 *  stem line. Multiple parallel roots (no single top exec in this group)
 *  skip the apex and start straight at the division tier instead. */
function renderExecTree(array $roots, array $byParent): string {
    $roots = array_values($roots);
    if (count($roots) !== 1) {
        return renderExecUnits($roots, $byParent, true, 0);
    }
    $apex = $roots[0];
    $name = e($apex['prefix'] . $apex['full_name']);
    $role = e($apex['position_name']);
    $meta = execPersonMeta($apex);

    $html = '<div class="ex-apex">'
          . '<p class="ex-apex__label">ผู้บริหารสูงสุด</p>'
          . '<div class="ex-apex__card" data-id="' . $apex['staff_id'] . '">' . execAvatarHtml($apex, 'xl')
          . '<p class="ex-apex__name">' . $name . '</p><p class="ex-apex__role">' . $role . '</p>' . $meta . '</div>'
          . '</div>';

    $children = $byParent[$apex['staff_id']] ?? [];
    if ($children) {
        $html .= '<div class="ex-apex__stem"></div>' . renderExecUnits($children, $byParent, true, 0);
    }
    return $html;
}

$pageTitle='ผังโครงสร้างบุคลากร'; $activeMenu='org-chart';
$breadcrumb=[['label'=>'ผังโครงสร้างบุคลากร']];
$pageActions='';
include BASE_PATH.'/admin/layout/header.php';
?>

<style>
/* ── Toolbar / edit-mode switch ── */
.edit-mode-switch { display:flex; align-items:center; gap:.5rem; }
.edit-mode-switch .form-check-input { width:2.4rem; height:1.3rem; cursor:pointer; }
.edit-hint {
  display:flex; align-items:center; gap:6px; font-size:.78rem; color:var(--ne-slate);
  background:var(--ne-slate-soft); padding:5px 12px; border-radius:8px;
}
body:not(.edit-mode) .edit-hint { opacity:.4; }

/* ── Campus card ── */
.org-section-hdr {
  display:flex; align-items:center; gap:12px; padding:12px 16px;
  background:var(--ne-blue); border-radius:12px;
  margin-bottom:8px; color:white;
}
.org-section-hdr .campus-ico {
  width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.15);
  display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;
}

/* ── Hybrid tree: top lead, connector lines down to each direct report.
   Every direct report renders as its own sibling in the row — same level,
   same connector line — regardless of whether they have reports of their
   own; anyone with their own reports additionally gets a branch (their card
   + a grid of everyone under them below it, sized to a roughly square
   shape).
   justify-content:safe center on the outer row centers the tree when it
   fits the available width, but falls back to start-alignment (scrollable,
   nothing unreachable) when it's wider than the container — plain center
   would push overflow into negative scroll space a scrollbar can never
   reach. Verified against a simulated overflow:hidden ancestor in both the
   "fits" and "overflows" cases before shipping. ── */
.tree-scroll { overflow-x:auto; padding:20px 0 8px; display:flex; justify-content:safe center; }
.board-tree { display:flex; align-items:flex-start; list-style:none; margin:0; padding:0; position:relative; }
.board-tree-children { display:flex; align-items:flex-start; list-style:none; margin:0; padding:0; position:relative; }
.board-tree > li, .board-tree-children > li {
  /* size to each branch's own content, not an equal forced share — with
     flex:1 a narrow single-card sibling got stretched into the same width as
     a wide 3x3 grid branch, stranding its content in the middle of a huge
     empty cell with an absurdly long connector line reaching it.
     padding-top:26px reserves the same height as the connector's own vertical
     drop (below) so the real card renders below it instead of underneath it
     — the connector is a position:absolute pseudo-element and never pushes
     real content down on its own. */
  position:relative; padding:26px 24px 0;
  display:flex; flex-direction:column; align-items:center;
}
.board-tree > li::before, .board-tree > li::after,
.board-tree-children > li::before, .board-tree-children > li::after {
  content:''; position:absolute; top:0; width:50%; height:26px;
  border-top:2px solid #9aa5b8;
}
.board-tree > li::before, .board-tree-children > li::before { right:50%; }
.board-tree > li::after,  .board-tree-children > li::after  { left:50%; border-left:2px solid #9aa5b8; }
.board-tree > li:only-child::before, .board-tree > li:only-child::after,
.board-tree-children > li:only-child::before, .board-tree-children > li:only-child::after { display:none; }
.board-tree > li:first-child::before, .board-tree-children > li:first-child::before { border-top-color:transparent; }
.board-tree > li:last-child::after,  .board-tree-children > li:last-child::after  { border-top-color:transparent; }
.board-tree-children { padding-top:26px; }
.board-tree-children::before {
  content:''; position:absolute; top:0; left:50%;
  border-left:2px solid #9aa5b8; height:26px;
}

/* A branch: head card, then a grid of everyone under them. Column count is
   set per-branch via inline style (sqrt of member count, see PHP) so each
   grid comes out roughly square; this is just a same-shape fallback. */
.board-branch { display:flex; flex-direction:column; align-items:center; }
.branch-grid { display:grid; grid-template-columns:repeat(2, 140px); gap:8px; margin-top:18px; justify-content:center; }
.branch-grid.sortable-ghost, .board-branch.sortable-ghost { opacity:.4; }

/* The ประจำสาขา pyramid (one head + everyone else) is its own single group,
   not several branches sharing a row — capping it at a fixed few columns
   left a tight little cluster of cards stranded in the middle of a much
   wider panel. Letting it use the panel's full width and add as many
   columns as fit means it actually fills the space instead of huddling
   in the center. */
.board-branch.is-wide { width:100%; }
.branch-grid.is-wide { width:100%; gap:20px; justify-items:stretch; }
/* Column count is set per-branch via inline style (min(5, member count),
   see PHP) so the row never has more than 5 tracks — beyond that, cards
   would keep shrinking instead of the row actually feeling bigger. */
.board-branch.is-wide .board-card {
  width:100%; max-width:220px; padding:22px 16px;
}
.board-branch.is-wide .board-avatar { width:76px; height:76px; font-size:1.7rem; }
.board-branch.is-wide .board-name { font-size:1.02rem; }
.board-branch.is-wide .board-pos { font-size:.88rem; }
.board-branch.is-wide .board-resp { font-size:.8rem; }
.board-branch.is-wide .board-profile-link { font-size:.8rem; }
.board-branch.is-wide .head-badge { font-size:.72rem; padding:2px 10px; }

.board-card {
  background:#fff; border:1px solid #e3e6ee; border-radius:12px;
  padding:14px 10px; display:flex; flex-direction:column; align-items:center; text-align:center; gap:8px;
  width:140px; transition:box-shadow .2s;
}
body.edit-mode .board-card { cursor:grab; }
body.edit-mode .board-card:active { cursor:grabbing; }
.board-card:hover { box-shadow:0 2px 10px rgba(22,46,147,.10); }
.board-card.is-head { border-color:var(--ne-blue); background:var(--ne-blue-soft); }
.board-card.sortable-ghost { opacity:.4; }

.board-avatar {
  width:48px;height:48px;border-radius:50%;flex-shrink:0;overflow:hidden;
  background:var(--ne-blue);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-weight:700;font-size:1.1rem;
}
.board-avatar img { width:100%;height:100%;object-fit:cover; }
.board-body { overflow:hidden; min-width:0; width:100%; text-align:center; }
.board-name { font-size:.86rem;font-weight:600;color:var(--ne-ink);line-height:1.25; }
.board-pos  { font-size:.76rem;color:var(--ne-slate); }
.board-resp{ font-size:.7rem;color:var(--ne-slate);font-style:italic;margin-top:1px; }
.board-profile-link{ font-size:.7rem;font-weight:600;color:var(--ne-blue);display:inline-flex;align-items:center;justify-content:center;gap:3px;margin-top:2px; }
.board-profile-link:hover{ text-decoration:underline; }
.head-badge{
  display:inline-flex;align-items:center;gap:3px;
  font-size:.6rem;font-weight:700;
  background:var(--ne-blue);color:#fff;
  padding:1px 7px;border-radius:10px;margin-top:2px;
}
.drag-handle { color:#c7cedb; flex-shrink:0; display:none; }
body.edit-mode .drag-handle { display:block; cursor:grab; }

/* ── ผังผู้บริหาร (executive view) — collapsible list. Separate from
   .board-tree/.board-card above (still used by ประจำศูนย์/สาขา). The first
   tier under the apex (.ex-unit--division) gets a bigger, accented card;
   everything deeper stays the compact row. Mobile-first: base rules are
   the phone layout (tight indent, wrapped division row, no divider);
   min-width:768px/1024px layer on tablet/desktop spacing and the
   single-row division layout. ── */
.ex-apex { text-align:center; padding-bottom:6px; }
.ex-apex__label { font-size:.72rem; letter-spacing:.08em; text-transform:uppercase; color:var(--ne-slate); margin:0 0 10px; }
.ex-apex__card {
  display:inline-flex; flex-direction:column; align-items:center; gap:5px;
  background:var(--ne-surface); border:1px solid var(--ne-line); border-radius:14px;
  box-shadow:0 4px 16px rgba(22,46,147,.08); padding:12px 18px; max-width:260px;
}
.ex-apex__name { font-weight:800; font-size:.94rem; margin:2px 0 0; color:var(--ne-ink); }
.ex-apex__role { color:var(--ne-slate); font-size:.76rem; margin:0; }
.ex-apex__stem { width:2px; height:22px; background:var(--ne-line); margin:0 auto; }

.ex-units { position:relative; padding-left:22px; }
.ex-units.is-top { padding-left:0; }
.ex-units::before { content:''; position:absolute; left:6px; top:0; bottom:1.5rem; width:2px; background:var(--ne-line); }
.ex-units.is-top::before { display:none; }
.ex-unit { position:relative; margin-bottom:10px; }
.ex-units:not(.is-top) > .ex-unit::before { content:''; position:absolute; left:-15px; top:26px; width:15px; height:2px; background:var(--ne-line); }
.ex-units:not(.is-top) > .ex-unit::after { content:''; position:absolute; left:-19px; top:21px; width:9px; height:9px; border-radius:50%; background:var(--ne-surface); border:2px solid var(--ne-blue); }
.ex-unit.sortable-ghost { opacity:.4; }
.ex-unit__toggle {
  display:flex; align-items:center; gap:10px; width:100%; text-align:left; font-family:'Kanit',sans-serif;
  color:var(--ne-ink); background:var(--ne-surface); border:1px solid var(--ne-line); border-radius:10px;
  padding:10px 12px; cursor:pointer; transition:border-color .15s ease;
}
.ex-unit__toggle:hover { border-color:var(--ne-blue); }
.ex-unit__toggle--leaf { cursor:default; }
.ex-unit__toggle--leaf:hover { border-color:var(--ne-line); }
.ex-unit__info { flex:1 1 auto; min-width:0; }
.ex-unit__name { display:block; font-weight:600; font-size:.88rem; }
.ex-unit__role { display:block; color:var(--ne-slate); font-size:.76rem; margin-top:1px; }
.ex-unit__resp { display:block; color:var(--ne-slate); font-size:.7rem; font-style:italic; margin-top:2px; }
.ex-unit__profile { display:inline-flex; align-items:center; gap:4px; margin-top:4px; font-size:.7rem; font-weight:700; color:var(--ne-blue); }
.ex-unit__profile:hover { text-decoration:underline; }
.ex-unit__badge { flex:0 0 auto; display:inline-flex; align-items:center; gap:8px; font-size:.74rem; color:var(--ne-slate); align-self:flex-start; margin-top:2px; }
.ex-unit__chev { width:8px; height:8px; border-right:2px solid var(--ne-slate); border-bottom:2px solid var(--ne-slate); transform:rotate(45deg); transition:transform .2s ease; }
.ex-unit__toggle[aria-expanded="true"] .ex-unit__chev { transform:rotate(-135deg); }
.ex-unit__body { padding-top:10px; }
.ex-unit__body[hidden] { display:none; }

.ex-unit--division { --ex-tone:var(--ne-blue); margin-bottom:14px; }
.ex-unit--division > .ex-unit__toggle { border-left:4px solid var(--ex-tone); border-radius:12px; padding:12px 14px; gap:12px; box-shadow:0 2px 10px rgba(22,46,147,.06); flex-wrap:wrap; }
.ex-unit--division .ex-unit__kicker-block { flex:1 1 100%; display:flex; flex-direction:column; gap:3px; }
.ex-unit--division .ex-unit__kicker { font-size:.92rem; font-weight:700; color:var(--ex-tone); line-height:1.3; }
.ex-unit--division .ex-unit__kicker-count { font-size:.74rem; color:var(--ne-slate); }
.ex-unit--division .ex-unit__divider { display:none; }
.ex-unit--division > .ex-unit__toggle .ex-unit__name { font-size:.92rem; }
.ex-unit--division > .ex-unit__toggle .ex-unit__role { font-size:.78rem; }
.ex-unit--division > .ex-unit__toggle > .ex-unit__chev { margin-left:auto; }

.ex-avatar {
  flex:0 0 auto; width:40px; height:40px; border-radius:50%; overflow:hidden; background:var(--ne-blue);
  display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:.78rem;
}
.ex-avatar img { width:100%; height:100%; object-fit:cover; }
.ex-avatar--lg { width:52px; height:52px; font-size:.94rem; }
.ex-avatar--xl { width:50px; height:50px; font-size:.88rem; }

@media (min-width:768px) {
  .ex-apex__card { padding:14px 22px; }
  .ex-apex__stem { height:24px; }
  .ex-units { padding-left:36px; }
  .ex-units::before { left:8px; }
  .ex-units:not(.is-top) > .ex-unit::before { left:-27px; top:27px; width:27px; }
  .ex-units:not(.is-top) > .ex-unit::after { left:-32px; top:21px; }
  .ex-unit__toggle { gap:12px; padding:12px 16px; }
  .ex-unit__name { font-size:.9rem; }
  .ex-unit__role { font-size:.78rem; }
  .ex-unit--division > .ex-unit__toggle { padding:16px 20px; gap:16px; flex-wrap:nowrap; }
  .ex-unit--division .ex-unit__kicker-block { flex:0 0 auto; max-width:220px; }
  .ex-unit--division .ex-unit__divider { display:block; flex:0 0 auto; align-self:stretch; width:1px; background:var(--ne-line); }
  .ex-unit--division > .ex-unit__toggle .ex-unit__name { font-size:.98rem; }
  .ex-unit--division > .ex-unit__toggle .ex-unit__role { font-size:.82rem; }
  .ex-unit--division .ex-unit__kicker { font-size:1rem; }
  .ex-avatar { width:44px; height:44px; font-size:.84rem; }
  .ex-avatar--lg { width:56px; height:56px; font-size:1rem; }
  .ex-avatar--xl { width:53px; height:53px; font-size:.92rem; }
}

@media (min-width:1024px) {
  .ex-units { padding-left:46px; }
  .ex-units:not(.is-top) > .ex-unit::before { left:-38px; top:28px; width:38px; }
  .ex-units:not(.is-top) > .ex-unit::after { left:-43px; top:22px; width:10px; height:10px; }
  .ex-avatar--lg { width:60px; height:60px; font-size:1.05rem; }
  .ex-avatar--xl { width:56px; height:56px; font-size:.95rem; }
}

/* ── View switcher: big tabs, not a buried <select> — the whole point is
   making it obvious there's more than one chart to look at. ── */
.view-tabs { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
.view-tab {
  display:inline-flex; align-items:center; gap:8px;
  padding:10px 18px; border-radius:12px; font-weight:700; font-size:.88rem;
  background:var(--ne-blue-soft); color:var(--ne-blue); border:2px solid transparent; text-decoration:none;
  transition:background-color .15s, border-color .15s;
}
.view-tab:hover { background:var(--ne-blue-soft); border-color:var(--ne-blue); text-decoration:none; }
.view-tab.active { background:var(--ne-blue); color:#fff; }

/* ── Campus → major drill-down: pick one, then the other, chart only
   shows once both are chosen — not two independent dropdowns. ── */
.picker-hint { display:flex; align-items:center; gap:8px; font-size:.86rem; color:var(--ne-ink-soft); margin-bottom:14px; }
.picker-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:14px; }
.picker-tile {
  display:flex; flex-direction:column; align-items:center; text-align:center;
  overflow:hidden; padding:0; border-radius:14px; background:#fff; border:1px solid #e3e6ee;
  color:var(--ne-ink); text-decoration:none; transition:box-shadow .15s, border-color .15s, transform .15s;
}
.picker-tile:hover { text-decoration:none; border-color:var(--ne-blue); box-shadow:0 4px 16px rgba(22,46,147,.12); transform:translateY(-2px); }
/* aspect-ratio:16/9 is only the fallback for tiles with no photo (the icon
   fills it fine) — tiles with a photo get their own ratio inline so the
   box fits the image exactly instead of cropping it to 16:9. */
.picker-tile-media { width:100%; aspect-ratio:16/9; background:var(--ne-blue-soft); overflow:hidden; }
.picker-tile-media img { width:100%; height:100%; object-fit:contain; display:block; }
.picker-tile-ico { width:100%; height:100%; color:var(--ne-blue); display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
.picker-tile-body { display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 14px 18px; }
.picker-tile-name { font-weight:700; font-size:.9rem; }
.picker-tile-count { font-size:.78rem; color:var(--ne-slate); }

/* ── Drill-down breadcrumb ── */
.drill-crumb { display:flex; align-items:center; gap:8px; font-size:.85rem; color:var(--ne-slate); margin-bottom:14px; }
.drill-crumb a { color:var(--ne-blue); font-weight:600; text-decoration:none; }
.drill-crumb a:hover { text-decoration:underline; }
.drill-crumb span { font-weight:700; color:var(--ne-ink); }
.drill-crumb i { font-size:.7rem; opacity:.6; }

/* ── Tip box ── */
.org-tip {
  display:flex; gap:10px; align-items:flex-start; margin-top:14px;
  background:var(--ne-gold-soft); border:1px solid #f0e2b8; border-radius:10px;
  padding:10px 14px; font-size:.78rem; color:#a06e00;
}
.org-tip i { font-size:1rem; margin-top:1px; }
</style>

<div class="ne">
  <!-- View switcher: which chart is this? made deliberately big/tab-like
       (not a plain <select> buried among filters) so it's obvious there
       are other charts to look at, not just one. Switching view always
       resets campus/major drill-down — a stale selection from a different
       chart doesn't carry over. -->
  <div class="view-tabs">
    <a href="?view=executive" class="view-tab <?=$view==='executive'?'active':''?>"><i class="bi bi-diagram-3"></i> ผู้บริหาร</a>
    <a href="?view=campus" class="view-tab <?=$view==='campus'?'active':''?>"><i class="bi bi-building"></i> อาจารย์ประจำศูนย์พื้นที่</a>
  </div>

  <!-- Toolbar -->
  <!-- a toolbar styled like the filter bar, not a filter: centred in its row, and no search-as-you-pick -->
  <form class="ne-filters" method="GET" data-no-auto style="align-items:center;">
    <input type="hidden" name="view" value="<?=e($view)?>">
    <?php if($view==='campus'):?>
    <!-- Campus view is a two-step drill-down (pick campus, then pick
         major within it) instead of two independent dropdowns — see the
         breadcrumb rendered with the content below. -->
    <div class="ne-filters-field" style="color:var(--ne-ink-soft);font-size:.85rem;">
      <i class="bi bi-signpost-split"></i> เลือกศูนย์พื้นที่ แล้วเลือกสาขาวิชา จากการ์ดด้านล่าง
    </div>
    <?php endif;?>

    <div class="edit-hint" style="margin-left:auto;">
      <i class="bi bi-hand-index-thumb"></i> ลากการ์ดเพื่อเปลี่ยนลำดับ
    </div>
    <div class="edit-mode-switch">
      <label class="small fw-600" style="color:var(--ne-ink-soft);margin-bottom:0;">โหมดแก้ไข</label>
      <input type="checkbox" role="switch" class="form-check-input" id="editModeSwitch" onchange="toggleEditMode(this.checked)">
    </div>
    <button type="button" class="ne-btn ne-btn-primary" id="saveOrderBtn" style="display:none" onclick="saveOrder()">
      <i class="bi bi-save"></i>บันทึกลำดับ
    </button>
  </form>

  <?php if($view==='campus' && $filterCampus):
    $breadcrumbCampusName = $campusNameById[$filterCampus] ?? '';
    $breadcrumbMajorName  = $filterMajor ? (array_values(array_filter($majors,fn($m)=>$m['major_id']==$filterMajor))[0]['major_name'] ?? '') : '';
  ?>
  <nav class="drill-crumb">
    <a href="?view=campus">ศูนย์พื้นที่</a>
    <i class="bi bi-chevron-right"></i>
    <?php if($filterMajor):?>
    <a href="?view=campus&campus=<?=$filterCampus?>"><?=e($breadcrumbCampusName)?></a>
    <i class="bi bi-chevron-right"></i>
    <span><?=e($breadcrumbMajorName)?></span>
    <?php else:?>
    <span><?=e($breadcrumbCampusName)?></span>
    <?php endif;?>
  </nav>
  <?php endif;?>

  <?php
  $isEmpty = $view==='executive'
      ? empty($grouped)
      : (!$filterCampus && empty($byCampusGroup));
  if($isEmpty):?>
  <div class="ne-panel">
    <div class="ne-empty">
      <i class="bi bi-people"></i>ยังไม่มีข้อมูลบุคลากร
      <a href="<?=BASE_URL?>/admin/modules/staff/create.php" class="ne-btn ne-btn-primary" style="margin-top:10px;">เพิ่มบุคลากร</a>
    </div>
  </div>
  <?php endif;?>

<?php if($view==='executive'): foreach($grouped as $campusId=>$cData):
    $currentCampus=array_values(array_filter($campuses,fn($c)=>$c['campus_id']==$cData['campus_id']))[0]??[];
    $totalInCampus=count($cData['all']);

    // Group by real supervisor within this set only. Anyone whose supervisor
    // isn't part of the current (possibly campus-filtered) set surfaces as a
    // root — e.g. their real boss sits in a different campus than the filter.
    $idsInGroup = array_column($cData['all'], 'staff_id');
    $byParent = [];
    $rootCandidates = [];
    foreach ($cData['all'] as $s) {
        $sup = $s['supervisor_id'];
        if ($sup && in_array($sup, $idsInGroup, true)) {
            $byParent[$sup][] = $s;
        } else {
            $rootCandidates[] = $s;
        }
    }
    // A root only counts if it actually heads a real chain of reports —
    // otherwise it's just a regular staff member with no supervisor set,
    // not an executive, and belongs in the ประจำศูนย์/ประจำสาขา views instead.
    $roots = array_filter($rootCandidates, fn($r)=>!empty($byParent[$r['staff_id']]));
    $headStaffId = $currentCampus['head_staff_id'] ?? null;
    if (!$roots) continue;
?>
<div class="ne-panel" style="margin-bottom:20px;">
  <div class="ne-panel-body" style="padding:16px">
    <!-- Campus header -->
    <div class="org-section-hdr">
      <div class="campus-ico"><i class="bi <?=$cData['campus_id']?'bi-building':'bi-diagram-3'?>"></i></div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:.95rem"><?=e($cData['campus_name'])?></div>
        <small style="opacity:.75"><?=array_sum(array_map(fn($r)=>1+count(collectDescendants($r['staff_id'],$byParent)),$roots))?> คน</small>
      </div>
      <!-- Set head staff (only meaningful when a single campus is selected) -->
      <?php if($cData['show_head_control']):?>
      <div class="d-flex align-items-center gap-2">
        <small style="opacity:.8;white-space:nowrap">หัวหน้าศูนย์:</small>
        <select class="form-select form-select-sm" style="min-width:180px;background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:white" onchange="setHead(<?=$cData['campus_id']?>,this.value)">
          <option value="" style="color:#1e2a45">— ไม่ระบุ —</option>
          <?php foreach($cData['all']??[] as $sf):?>
          <option value="<?=$sf['staff_id']?>" style="color:#1e2a45" <?=($currentCampus['head_staff_id']??0)==$sf['staff_id']?'selected':''?>>
            <?=e($sf['prefix'].$sf['full_name'])?>
          </option>
          <?php endforeach;?>
        </select>
      </div>
      <?php endif;?>
    </div>

    <!-- Org tree -->
    <?= renderExecTree($roots, $byParent) ?>

    <div class="org-tip">
      <i class="bi bi-lightbulb"></i>
      <div>เรียงตามสายบังคับบัญชาจริง ("หัวหน้างาน" ที่กำหนดไว้ในหน้าเพิ่ม/แก้ไขบุคลากรของแต่ละคน) — ใครมีลูกน้องจะกลายเป็นแถบที่กดขยาย/ย่อได้ ส่วนใครไม่มีลูกน้องจะแสดงเป็นแถวเฉยๆ เปิด "โหมดแก้ไข" แล้วลากเพื่อสลับลำดับกับพี่น้องที่ขึ้นตรงกับหัวหน้าคนเดียวกัน จากนั้นกด "บันทึกลำดับ"</div>
    </div>
  </div>
</div>
<?php endforeach;

elseif($view==='campus'):
  if(!$filterCampus):
    // STEP 1: pick a campus — no chart yet, just tiles to choose from.
    if(!empty($byCampusGroup)): ?>
<div class="ne-panel" style="margin-bottom:20px;">
  <div class="ne-panel-body" style="padding:16px">
    <div class="picker-hint"><i class="bi bi-hand-index-thumb"></i> เลือกศูนย์พื้นที่เพื่อดูผังบุคลากร</div>
    <div class="picker-grid">
      <?php foreach($campuses as $c):
          $cnt = count($byCampusGroup[$c['campus_name']] ?? []);
          if(!$cnt) continue;
      ?>
      <a href="?view=campus&campus=<?=$c['campus_id']?>" class="picker-tile">
        <div class="picker-tile-media"<?=thumbRatioStyle($c['image'] ?? null)?>>
          <?php if(!empty($c['image'])):?>
          <img src="<?=UPLOAD_URL.'/'.e($c['image'])?>" alt="<?=e($c['campus_name'])?>" loading="lazy">
          <?php else:?>
          <div class="picker-tile-ico"><i class="bi bi-building"></i></div>
          <?php endif;?>
        </div>
        <div class="picker-tile-body">
          <div class="picker-tile-name"><?=e($c['campus_name'])?></div>
          <div class="picker-tile-count"><?=$cnt?> คน</div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
    <?php endif;
  elseif(!$filterMajor):
    // STEP 2: campus chosen — pick a major within it before any chart shows.
    $campusLabelNow = $campusNameById[$filterCampus] ?? '';
    $campusMembers = $byCampusGroup[$campusLabelNow] ?? [];
    $majorsInCampus = [];
    foreach($campusMembers as $s){ if(!empty($s['major_name'])) $majorsInCampus[$s['major_name']][] = $s; }
  ?>
<div class="ne-panel" style="margin-bottom:20px;">
  <div class="ne-panel-body" style="padding:16px">
    <div class="picker-hint"><i class="bi bi-hand-index-thumb"></i> เลือกสาขาวิชาใน <?=e($campusLabelNow)?></div>
    <?php if(empty($majorsInCampus)): ?>
    <div class="ne-empty"><i class="bi bi-people"></i>ไม่มีบุคลากรที่ระบุสาขาวิชาในศูนย์นี้</div>
    <?php else: ?>
    <div class="picker-grid">
      <?php foreach($majors as $m):
          $cnt = count($majorsInCampus[$m['major_name']] ?? []);
          if(!$cnt) continue;
      ?>
      <a href="?view=campus&campus=<?=$filterCampus?>&major=<?=$m['major_id']?>" class="picker-tile">
        <div class="picker-tile-media"<?=thumbRatioStyle($m['image'] ?? null)?>>
          <?php if(!empty($m['image'])):?>
          <img src="<?=UPLOAD_URL.'/'.e($m['image'])?>" alt="<?=e($m['major_name'])?>" loading="lazy">
          <?php else:?>
          <div class="picker-tile-ico"><i class="bi bi-mortarboard"></i></div>
          <?php endif;?>
        </div>
        <div class="picker-tile-body">
          <div class="picker-tile-name"><?=e($m['major_name'])?></div>
          <div class="picker-tile-count"><?=$cnt?> คน</div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
  <?php else:
    // STEP 3: campus + major both chosen — now, finally, show the chart.
    if(empty($byMajorGroup)): ?>
<div class="ne-panel"><div class="ne-empty"><i class="bi bi-people"></i>ไม่พบบุคลากรในสาขานี้ของศูนย์นี้</div></div>
    <?php else:
      // Head is kept per (campus_id, major_id) pair, not per major alone —
      // the same สาขา taught at two ศูนย์ can have two different heads, and
      // the dropdown must only ever offer people who are actually in THIS
      // campus's slice of THIS major, so a choice made while viewing one
      // campus can never leak into another campus's dropdown for the same
      // major.
      $majorHeadRow = $db->fetchOne(
          'SELECT head_staff_id FROM major_campus_heads WHERE campus_id=? AND major_id=?',
          [$filterCampus, $filterMajor]
      );
      $majorHeadId = $majorHeadRow ? (int)$majorHeadRow['head_staff_id'] : null;
      foreach($byMajorGroup as $majorLabel=>$members):
        [$majorHead, $majorRest] = splitMajorHead($members, $majorHeadId);
    ?>
<div class="ne-panel" style="margin-bottom:20px;">
  <div class="ne-panel-body" style="padding:16px">
    <div class="org-section-hdr">
      <div class="campus-ico"><i class="bi bi-mortarboard"></i></div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:.95rem"><?=e($majorLabel)?></div>
        <small style="opacity:.75"><?=count($members)?> คน</small>
      </div>
      <!-- Set department head — a deliberate choice, not auto-detected, since
           someone can hold an executive-board title and still be the head
           of their own department at the same time. -->
      <div class="d-flex align-items-center gap-2">
        <small style="opacity:.8;white-space:nowrap">หัวหน้าสาขา:</small>
        <select class="form-select form-select-sm" style="min-width:180px;background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:white" onchange="setMajorHead(<?=$filterCampus?>,<?=$filterMajor?>,this.value)">
          <option value="" style="color:#1e2a45">— ไม่ระบุ —</option>
          <?php foreach($members as $sf):?>
          <option value="<?=$sf['staff_id']?>" style="color:#1e2a45" <?=$majorHeadId==$sf['staff_id']?'selected':''?>>
            <?=e($sf['prefix'].$sf['full_name'])?>
          </option>
          <?php endforeach;?>
        </select>
      </div>
    </div>
    <?php if($majorHead): $cols=min(5,max(1,count($majorRest))); ?>
    <div class="tree-scroll">
      <div class="board-branch is-wide">
        <?=renderBoardCard($majorHead, $majorHeadId, true, 'หัวหน้าสาขา')?>
        <?php if($majorRest):?>
        <div class="branch-grid is-wide" style="grid-template-columns:repeat(<?=$cols?>,1fr)">
          <?php foreach($majorRest as $s): ?>
          <?=renderBoardCard($s, $majorHeadId, true, 'หัวหน้าสาขา')?>
          <?php endforeach; ?>
        </div>
        <?php endif;?>
      </div>
    </div>
    <?php else: $flatCols=min(5,max(1,count($members))); ?>
    <div class="tree-scroll">
      <div class="board-branch is-wide">
        <div class="branch-grid is-wide" style="grid-template-columns:repeat(<?=$flatCols?>,1fr)">
          <?php foreach($members as $s): ?>
          <?=renderBoardCard($s, $majorHeadId, true, 'หัวหน้าสาขา')?>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
    <?php endforeach; endif;
  endif;
endif; ?>
</div>

<?php
$extraJs='
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
const csrf='.json_encode(csrfToken()).';
const sortables=[];

// ── Init Sortable: drag cards to reorder people within the same branch\'s
//    grid (.branch-grid, the ประจำศูนย์/สาขา views) or the same .ex-units
//    list at any depth (ผู้บริหาร\'s collapsible view) ──
document.querySelectorAll(".branch-grid, .ex-units").forEach(list=>{
  const s=new Sortable(list,{
    animation:150,
    handle:".drag-handle",
    ghostClass:"sortable-ghost",
    disabled:true,
  });
  sortables.push(s);
});

// ── ผังผู้บริหาร: click a unit to collapse/expand it ──
document.addEventListener("click",(e)=>{
  const toggle=e.target.closest(".ex-unit__toggle");
  if(!toggle||toggle.tagName!=="BUTTON")return;
  const body=document.getElementById(toggle.getAttribute("aria-controls"));
  const open=toggle.getAttribute("aria-expanded")==="true";
  toggle.setAttribute("aria-expanded",String(!open));
  if(body)body.hidden=open;
});

// ── Edit mode toggle ──
function toggleEditMode(on){
  document.body.classList.toggle("edit-mode",on);
  sortables.forEach(s=>s.option("disabled",!on));
  document.getElementById("saveOrderBtn").style.display=on?"inline-flex":"none";
}

// ── Save order (siblings only matter relative to each other; a single
//    incrementing counter across the whole page keeps that relative order) ──
async function saveOrder(){
  const orders=[];
  document.querySelectorAll(".board-card, .ex-unit").forEach((card,idx)=>{
    orders.push({id:card.dataset.id, order:idx+1});
  });
  const fd=new FormData();
  fd.append("action","reorder"); fd.append("orders",JSON.stringify(orders)); fd.append("csrf_token",csrf);
  const r=await axios.post("",fd);
  if(r.data.status==="success") showSuccess(r.data.message);
  else showError(r.data.message);
}

// ── Set head of campus ──
async function setHead(campusId,staffId){
  const fd=new FormData();
  fd.append("action","set_head"); fd.append("campus_id",campusId);
  fd.append("staff_id",staffId); fd.append("csrf_token",csrf);
  const r=await axios.post("",fd);
  if(r.data.status==="success"){showSuccess(r.data.message);setTimeout(()=>location.reload(),1300);}
  else showError(r.data.message);
}

// ── Set head of major/department ──
async function setMajorHead(campusId,majorId,staffId){
  const fd=new FormData();
  fd.append("action","set_major_head"); fd.append("campus_id",campusId); fd.append("major_id",majorId);
  fd.append("staff_id",staffId); fd.append("csrf_token",csrf);
  const r=await axios.post("",fd);
  if(r.data.status==="success"){showSuccess(r.data.message);setTimeout(()=>location.reload(),1300);}
  else showError(r.data.message);
}
</script>';
include BASE_PATH.'/admin/layout/footer.php';?>
