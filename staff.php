<?php
/**
 * BAIT - Staff Page (Phase 3 rebuild)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/components.php';


$db   = Database::getInstance();
$lang = currentLang();
if (!in_array($lang, ['th', 'en'])) $lang = 'th';
$_SESSION['lang'] = $lang;
$t = fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;

/**
 * Same hybrid-tree layout as admin/modules/org-chart, reusing staffCard()
 * (the large photo card) instead of the admin's compact box. Real direct
 * reports (supervisor_id) with their own reports get a branch (their card +
 * a grid of everyone under them, sized to a roughly square shape); anyone
 * with no reports of their own is bundled into one connected vertical chain.
 *
 * Just like admin/modules/org-chart, the page shows one view at a time,
 * switched via ?view=executive|campus|major (default: executive) — the
 * public directory only ever shows the ผู้บริหาร tree unless a visitor
 * explicitly asks for the campus or major breakdown instead.
 */
function collectStaffDescendants(int $staffId, array $byParent): array {
    $result = [];
    foreach ($byParent[$staffId] ?? [] as $child) {
        $result[] = $child;
        $result = array_merge($result, collectStaffDescendants($child['staff_id'], $byParent));
    }
    return $result;
}

function renderStaffBranch(array $head, array $byParent, string $lang): string {
    $members = collectStaffDescendants($head['staff_id'], $byParent);
    $cols = max(1, (int)ceil(sqrt(count($members))));
    $html = '<div class="staff-branch">' . staffCard($head, $lang);
    $html .= '<div class="staff-branch-grid" style="grid-template-columns:repeat(' . $cols . ',150px)">';
    foreach ($members as $m) { $html .= staffCard($m, $lang); }
    return $html . '</div></div>';
}

function renderStaffChildren(int $rootId, array $byParent, string $lang): string {
    $children = $byParent[$rootId] ?? [];
    if (!$children) return '';
    // Every direct report is the dean's (or any supervisor's) organizational
    // peer regardless of whether they happen to have reports of their own —
    // bundling the ones without reports into one shared grid slot used to
    // make that grid's own internal wrapping cut some of them off onto a
    // visibly lower row, as if they'd been demoted below their actual
    // peers. Rendering every child as its own <li> keeps them all on the
    // same level with their own connector line; staff-tree-scale.js's
    // existing shrink/scroll handling already covers "too many to fit"
    // for this row exactly the same way it does for branches.
    $html = '<ul class="staff-tree-children">';
    foreach ($children as $c) {
        $html .= '<li>' . (collectStaffDescendants($c['staff_id'], $byParent)
            ? renderStaffBranch($c, $byParent, $lang)
            : staffCard($c, $lang)) . '</li>';
    }
    return $html . '</ul>';
}

/**
 * ผังผู้บริหาร (executive view) — collapsible list layout, separate from
 * staffCard()/.staff-tree above (still used by the campus/major drill-down).
 * Same underlying supervisor_id tree, same recursion — only the visual
 * treatment differs: instead of connector-line boxes, anyone with reports
 * becomes a collapsible unit toggle; anyone without is a plain info row.
 * Both share the exact same DOM shape so .ex-unit's connector line and
 * search/collapse JS apply uniformly at any depth.
 */
function execInitials(string $fullName): string {
    $cleaned = preg_replace('/^(นาย|นางสาว|นาง|ดร\.|ผศ\.|รศ\.|ศ\.)+/u', '', trim($fullName));
    $parts = preg_split('/\s+/u', trim($cleaned)) ?: [];
    return mb_substr($parts[0] ?? '', 0, 1, 'UTF-8') . mb_substr($parts[1] ?? '', 0, 1, 'UTF-8');
}

function execAvatarHtml(array $s, string $size = 'md'): string {
    $cls = 'ex-avatar' . ($size !== 'md' ? ' ex-avatar--' . $size : '');
    if (!empty($s['image'])) {
        return '<span class="' . $cls . '"><img src="' . e(UPLOAD_URL . '/' . $s['image']) . '" alt=""' . imgSizeAttrs($s['image']) . ' loading="lazy"></span>';
    }
    return '<span class="' . $cls . '" aria-hidden="true">' . e(execInitials($s['full_name'])) . '</span>';
}

/** ด้านที่รับผิดชอบ + ลิงก์โปรไฟล์ — same fields staffCard() shows, so the
 *  collapsible view doesn't lose information the box-diagram version had. */
function execPersonMeta(array $p, string $lang, bool $withResponsibility = true): string {
    $html = '';
    if ($withResponsibility && !empty($p['responsibility'])) {
        $html .= '<span class="ex-unit__resp">' . thaiNoBreak(tx($p['responsibility'], $lang)) . '</span>';
    }
    if (!empty($p['e_profile_url'])) {
        $html .= '<a href="' . e($p['e_profile_url']) . '" class="ex-unit__profile" target="_blank" rel="noopener" onclick="event.stopPropagation()">'
                . icon('external-link', 'icon-sm') . ' ' . ($lang === 'en' ? 'Profile' : 'ดูโปรไฟล์') . '</a>';
    }
    return $html;
}

function renderExecUnits(array $people, array $byParent, string $lang, bool $isTop = false, int $tier = 0): string {
    $html = '<div class="ex-units' . ($isTop ? ' is-top' : '') . '">';
    foreach ($people as $p) {
        $reports = $byParent[$p['staff_id']] ?? [];
        // lines wrap only at spaces (no "สถาน|ศึกษา" on a phone): see thaiNoBreak()
        $name = thaiNoBreak(staffName($p, $lang));
        $role = thaiNoBreak(tx($p['position_name'], $lang));
        // The search box matches either spelling of the name.
        $searchKey = e(mb_strtolower(($p['prefix'] ?? '') . $p['full_name'] . ' ' . ($p['full_name_en'] ?? '') . ' ' . tx($p['position_name'], $lang), 'UTF-8'));

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
        $kicker = thaiNoBreak(tx($p['responsibility'] ?: ($p['department_name'] ?? ''), $lang));
        $meta = execPersonMeta($p, $lang, !$isDivision);
        $personBlock = execAvatarHtml($p, $isDivision ? 'lg' : 'md')
                     . '<span class="ex-unit__info"><span class="ex-unit__name">' . $name . '</span><span class="ex-unit__role">' . $role . '</span>' . $meta . '</span>';

        $html .= '<article class="ex-unit' . ($isDivision ? ' ex-unit--division' : '') . '">';
        if ($reports) {
            $bodyId = 'exUnit' . (int)$p['staff_id'];
            $countLabel = count($reports) . ' ' . e(tx('คน', $lang));
            $front = $isDivision
                ? '<span class="ex-unit__kicker-block"><span class="ex-unit__kicker">' . $kicker . '</span><span class="ex-unit__kicker-count">' . $countLabel . '</span></span><span class="ex-unit__divider"></span>'
                : '';
            $badge = $isDivision ? '<span class="ex-unit__chev"></span>' : '<span class="ex-unit__badge">' . $countLabel . '<span class="ex-unit__chev"></span></span>';
            $html .= '<button type="button" class="ex-unit__toggle" aria-expanded="true" aria-controls="' . $bodyId . '" data-search="' . $searchKey . '">'
                   . $front . $personBlock . $badge
                   . '</button>'
                   . '<div class="ex-unit__body" id="' . $bodyId . '">' . renderExecUnits($reports, $byParent, $lang, false, $tier + 1) . '</div>';
        } else {
            $front = $isDivision
                ? '<span class="ex-unit__kicker-block"><span class="ex-unit__kicker">' . $kicker . '</span></span><span class="ex-unit__divider"></span>'
                : '';
            $html .= '<div class="ex-unit__toggle ex-unit__toggle--leaf" data-search="' . $searchKey . '">'
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
function renderExecTree(array $roots, array $byParent, string $lang): string {
    $roots = array_values($roots);
    if (count($roots) !== 1) {
        return renderExecUnits($roots, $byParent, $lang, true, 0);
    }
    $apex = $roots[0];
    $name = thaiNoBreak(staffName($apex, $lang));
    $role = thaiNoBreak(tx($apex['position_name'], $lang));
    $meta = execPersonMeta($apex, $lang);

    $html = '<div class="ex-apex">'
          . '<p class="ex-apex__label">' . e(tx('ผู้บริหารสูงสุด', $lang)) . '</p>'
          . '<div class="ex-apex__card">' . execAvatarHtml($apex, 'xl')
          . '<p class="ex-apex__name">' . $name . '</p><p class="ex-apex__role">' . $role . '</p>' . $meta . '</div>'
          . '</div>';

    $children = $byParent[$apex['staff_id']] ?? [];
    if ($children) {
        $html .= '<div class="ex-apex__stem"></div>' . renderExecUnits($children, $byParent, $lang, true, 0);
    }
    return $html;
}

/** Pulls the explicitly-assigned หัวหน้าสาขาวิชา (majors.head_staff_id, set
 *  by an admin) out of a major's member list, so the view can render them
 *  as the peak of a small pyramid — their card on top, everyone else in the
 *  major below — instead of one flat, unranked grid. Not driven by
 *  position_name: someone can sit on the executive board under an
 *  administrative title and still be the head of their department. */
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

// Tabs: ผู้บริหาร · บุคลากรสายวิชาการ (campus → major → people) · บุคลากรสายสนับสนุน (campus → people, head on top) ·
// คณะกรรมการประจำคณะ. The academic tab used to be ?view=campus ("อาจารย์ประจำศูนย์พื้นที่"): old links still open it.
// Which tab someone appears in comes from their หน่วยงาน (สายวิชาการ / สายสนับสนุน); people who resigned appear nowhere.
$viewIn   = getStr('view');
$view     = $viewIn === 'campus' ? 'academic' : (in_array($viewIn, ['executive', 'academic', 'support', 'committee'], true) ? $viewIn : 'executive');
$campusId = (int)getStr('campus');
$typeId   = (int)getStr('type');
$majorId  = $view === 'academic' ? (int)getStr('major') : 0;

$where = staffCurrentSql('s'); $params = [];
if ($campusId) { $where .= ' AND s.campus_id = ?'; $params[] = $campusId; }
if ($typeId)   { $where .= ' AND s.staff_type_id = ?'; $params[] = $typeId; }
if ($majorId)  { $where .= ' AND s.major_id = ?';      $params[] = $majorId; }

$staffList = $db->fetchAll(
    "SELECT s.*, p.position_name, p.position_level, c.campus_name, m.major_name, st.staff_type_name, d.department_name
     FROM staff s
     JOIN positions p ON p.position_id = s.position_id
     LEFT JOIN campuses c ON c.campus_id = s.campus_id
     LEFT JOIN majors m ON m.major_id = s.major_id
     JOIN staff_types st ON st.staff_type_id = s.staff_type_id
     LEFT JOIN departments d ON d.department_id = s.department_id
     WHERE $where
     ORDER BY p.position_level DESC, s.display_order ASC, s.full_name ASC",
    $params
);
$campuses = $db->fetchAll("SELECT * FROM campuses ORDER BY campus_id");

// The executive chart is split by staff type, but someone reporting to a boss of another type (e.g. หัวหน้าสำนักงานคณบดี,
// พนักงานมหาวิทยาลัย, under the ข้าราชการ Dean) must stay in the boss's tree, not be cut off from it: everyone is grouped
// under the type of the top of their supervisor chain.
$byId = array_column($staffList, null, 'staff_id');
$treeType = function (array $s) use ($byId): string {
    $seen = [];
    while (!empty($s['supervisor_id']) && isset($byId[$s['supervisor_id']]) && !isset($seen[$s['staff_id']])) {
        $seen[$s['staff_id']] = true; $s = $byId[$s['supervisor_id']];
    }
    return $s['staff_type_name'];
};
$grouped = [];
if ($campusId) {
    $campusRow   = array_values(array_filter($campuses, fn($c) => $c['campus_id'] == $campusId))[0] ?? null;
    $campusLabel = $campusRow['campus_name'] ?? '';
    foreach ($staffList as $s) { $grouped[$campusLabel][$treeType($s)][] = $s; }
} else {
    foreach ($staffList as $s) { $grouped[$lang === 'en' ? 'All Campuses' : 'ภาพรวมทุกศูนย์พื้นที่'][$treeType($s)][] = $s; }
}
$staffTypes = $db->fetchAll("SELECT * FROM staff_types ORDER BY staff_type_id");
$majors     = $db->fetchAll("SELECT * FROM majors ORDER BY major_name");

// Grouped by campus and by major for the "บุคลากรสายวิชาการ" drill-down (support staff are left out: they have their own
// tab). This does NOT exclude people who are also in the ผู้บริหาร tree — someone can genuinely hold an administrative
// role (e.g. a Deputy Dean with reports) AND a campus/major faculty assignment at the same time.
$byCampusGroup = []; $byMajorGroup = []; $supportByCampus = [];
foreach ($staffList as $s) {
    if (isSupportDepartmentName($s['department_name'] ?? null)) { $supportByCampus[(int)$s['campus_id']][] = $s; continue; }
    $byCampusGroup[$s['campus_name'] ?? $t('ไม่ระบุศูนย์')][] = $s;
    if (!empty($s['major_name'])) { $byMajorGroup[$s['major_name']][] = $s; }
}
$committee = $view === 'committee' ? committeeMembers() : [];

/**
 * บุคลากรสายสนับสนุน of one campus: one chart per งาน (staff_units, in their order) — the people ticked
 * "เป็นหัวหน้างาน" on top with a "หัวหน้างาน" badge, everyone else of that งาน below (same look as the major chart).
 * The position shown is the person's ตำแหน่งงาน (job_title) when it is filled in, otherwise their ตำแหน่ง. The หัวหน้างาน
 * links (supervisor_id) are NOT used here: they only build the executive chart. People with no งาน come last.
 * Before migration step 15 (no งาน yet) everyone is listed in one group.
 */
function renderSupportCharts(array $people, string $lang, callable $t): string {
    $units = staffUnits();
    $byUnit = [];
    foreach ($people as $p) {
        if (!empty($p['job_title'])) $p['position_name'] = $p['job_title'];
        // ฝ่าย/ด้านที่รับผิดชอบ belongs to the executive chart (e.g. งานภายในสำนักงานคณบดี); here the งาน heading says it
        unset($p['responsibility']);
        $u = (int)($p['unit_id'] ?? 0);
        $byUnit[isset($units[$u]) ? $u : 0][] = $p;
    }
    $order = array_keys($units); $order[] = 0;
    $html = '';
    foreach ($order as $u) {
        if (empty($byUnit[$u])) continue;
        $group = $byUnit[$u];
        $heads = array_values(array_filter($group, fn($p) => !empty($p['is_unit_head'])));
        $rest  = array_values(array_filter($group, fn($p) => empty($p['is_unit_head'])));
        $title = $u ? $units[$u] : ($units ? 'บุคลากรสายสนับสนุน (ยังไม่ระบุงาน)' : 'บุคลากรสายสนับสนุน');
        $card  = fn(array $p, bool $isHead) => staffCard($p, $lang, false, $isHead ? (int)$p['staff_id'] : null, $t('หัวหน้างาน'));
        $html .= '<div class="reveal" style="margin-bottom:36px"><div class="staff-cluster-heading"><div class="staff-cluster-icon">' . icon('briefcase') . '</div>'
               . '<h2 style="font-size:1.1rem;color:var(--primary);margin:0">' . e($t($title)) . '</h2>'
               . '<span class="badge-soft" style="margin-left:auto">' . count($group) . ' ' . e($t('คน')) . '</span></div>'
               . '<div class="staff-tree-scroll"><div class="staff-branch is-wide">';
        if ($heads) {
            // the head(s) side by side on top, the team below
            $html .= count($heads) === 1 ? $card($heads[0], true)
                   : '<div class="staff-branch-grid is-wide">' . implode('', array_map(fn($p) => $card($p, true), $heads)) . '</div>';
        }
        if ($rest) $html .= '<div class="staff-branch-grid is-wide">' . implode('', array_map(fn($p) => $card($p, false), $rest)) . '</div>';
        $html .= '</div></div></div>';
    }
    return $html;
}

trackPageView($_SERVER['REQUEST_URI'], $lang === 'en' ? 'Staff' : 'บุคลากร');
$pageTitle = $lang === 'en' ? 'Faculty & Staff' : 'บุคลากร';
$metaDesc = $lang === 'en'
    ? 'Directory of lecturers and staff of the Faculty of Business Administration and IT, with positions, majors and campuses. Search by name or position.'
    : 'รายชื่อคณาจารย์และบุคลากรของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ พร้อมตำแหน่ง สาขาวิชา และศูนย์พื้นที่ที่สังกัด ค้นหาได้ตามชื่อหรือตำแหน่ง';

$schemaJson = json_encode([
    '@context'         => 'https://schema.org',
    '@type'            => 'ItemList',
    'name'             => 'BAIT Faculty & Staff',
    'itemListElement'  => array_values(array_map(fn($s, $i) => [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'item'     => ['@type' => 'Person', 'name' => trim(staffName($s, $lang)), 'jobTitle' => $s['position_name']],
    ], array_slice($staffList, 0, 40), array_keys(array_slice($staffList, 0, 40)))),
], JSON_UNESCAPED_UNICODE);

$activePage = 'info';
$activeSubPage = 'staff';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('บุคลากร')],
];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= $t('บุคลากร') ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section" style="padding-top:32px">

  <div class="view-tabs">
    <a href="?view=executive&lang=<?= e($lang) ?>" class="view-tab <?= $view === 'executive' ? 'active' : '' ?>"><?= icon('diagram', 'icon-sm') ?> <?= $t('ผู้บริหาร') ?></a>
    <a href="?view=academic&lang=<?= e($lang) ?>" class="view-tab <?= $view === 'academic' ? 'active' : '' ?>"><?= icon('graduation-cap', 'icon-sm') ?> <?= $t('บุคลากรสายวิชาการ') ?></a>
    <a href="?view=support&lang=<?= e($lang) ?>" class="view-tab <?= $view === 'support' ? 'active' : '' ?>"><?= icon('briefcase', 'icon-sm') ?> <?= $t('บุคลากรสายสนับสนุน') ?></a>
    <a href="?view=committee&lang=<?= e($lang) ?>" class="view-tab <?= $view === 'committee' ? 'active' : '' ?>"><?= icon('users', 'icon-sm') ?> <?= $t('คณะกรรมการประจำคณะ') ?></a>
  </div>

  <?php if (in_array($view, ['academic', 'support'], true) && $campusId):
    $campusRowNow = array_values(array_filter($campuses, fn($c) => $c['campus_id'] == $campusId))[0] ?? null;
    $campusLabelNow = $campusRowNow['campus_name'] ?? '';
    $majorLabelNow = $majorId ? (array_values(array_filter($majors, fn($m) => $m['major_id'] == $majorId))[0]['major_name'] ?? '') : '';
  ?>
  <nav class="drill-crumb">
    <a href="?view=<?= e($view) ?>&lang=<?= e($lang) ?>"><?= $t('ศูนย์พื้นที่') ?></a>
    <?= icon('chevron-right', 'icon-sm') ?>
    <?php if ($majorId): ?>
    <a href="?view=<?= e($view) ?>&campus=<?= $campusId ?>&lang=<?= e($lang) ?>"><?= e($t($campusLabelNow)) ?></a>
    <?= icon('chevron-right', 'icon-sm') ?>
    <span><?= e($t($majorLabelNow)) ?></span>
    <?php else: ?>
    <span><?= e($t($campusLabelNow)) ?></span>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php
  $isEmpty = $view === 'executive'
      ? empty($staffList)
      : ($view === 'academic' ? (!$campusId && empty($byCampusGroup)) : false);   // support / committee say "empty" in their own way
  // Any active filter (campus/type/major) is a slice of the real hierarchy,
  // not the whole thing — someone's supervisor can end up filtered out of
  // view, leaving their card connected to nothing. Rather than render that
  // broken/crossing-line tree, a filtered result falls back to the same
  // plain card grid the department (สาขา) view uses: no connector lines.
  // It still only shows the same people the tree would — roots (someone
  // with reports) plus everyone under them — NOT every staff member in
  // that campus/type; a plain lecturer with no reports isn't ผู้บริหาร and
  // has never belonged in this view, filtered or not. The full,
  // unfiltered ผู้บริหาร view keeps the real tree since it's showing the
  // actual complete structure.
  $executiveFiltered = (bool)($campusId || $typeId || $majorId);
  if ($isEmpty): ?>
  <div class="empty-state"><?= icon('users') ?><div><?= $t('ไม่พบบุคลากร') ?></div></div>

  <?php elseif ($view === 'executive'): ?>
  <div class="ex-board">
    <?php if (!$executiveFiltered): ?>
    <div class="ex-search">
      <label class="sr-only" for="execSearch"><?= $t('ค้นหาชื่อหรือตำแหน่ง') ?></label>
      <input type="search" id="execSearch" class="ex-search__input" data-exec-search autocomplete="off" placeholder="<?= e($t('ค้นหาชื่อหรือตำแหน่ง')) ?>">
    </div>
    <div class="empty-state ex-empty" hidden><?= icon('users') ?><div><?= $t('ไม่พบบุคลากรที่ตรงกับคำค้น') ?></div></div>
    <?php endif; ?>
  <?php foreach ($grouped as $campusName => $types):
        $campusHtml = '';
        foreach ($types as $typeName => $members):
            $idsInGroup = array_column($members, 'staff_id');
            $byParent = []; $rootCandidates = [];
            foreach ($members as $s) {
                $sup = $s['supervisor_id'];
                if ($sup && in_array($sup, $idsInGroup, true)) { $byParent[$sup][] = $s; }
                else { $rootCandidates[] = $s; }
            }
            // Only someone who actually has direct reports counts as a real
            // root of a hierarchy — a person with no supervisor AND no
            // reports is just an unconnected staff member (e.g. a plain
            // lecturer), not an executive, and belongs in the campus/major
            // views instead.
            $roots = array_filter($rootCandidates, fn($r) => !empty($byParent[$r['staff_id']]));
            if (!$roots) continue;
            $treeCount = 0;
            foreach ($roots as $root) { $treeCount += 1 + count(collectStaffDescendants($root['staff_id'], $byParent)); }
            if ($executiveFiltered) {
                $executivePeople = [];
                foreach ($roots as $root) {
                    $executivePeople[] = $root;
                    foreach (collectStaffDescendants($root['staff_id'], $byParent) as $d) { $executivePeople[] = $d; }
                }
    ?>
    <?php ob_start(); ?>
    <div style="margin-bottom:28px">
      <h3 class="staff-group-heading">
        <?= icon($typeName === 'สายวิชาการ' ? 'user' : 'briefcase', 'icon-sm') ?>
        <?= e($t($typeName)) ?>
        <span class="badge-soft"><?= $treeCount ?> <?= $t('คน') ?></span>
      </h3>
      <div class="staff-tree-scroll">
        <div class="staff-branch is-wide">
          <div class="staff-branch-grid is-wide">
            <?php foreach ($executivePeople as $m): echo staffCard($m, $lang); endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php $campusHtml .= ob_get_clean(); continue; ?>
    <?php }
    ?>
    <?php ob_start(); ?>
    <div style="margin-bottom:28px">
      <h3 class="staff-group-heading">
        <?= icon($typeName === 'สายวิชาการ' ? 'user' : 'briefcase', 'icon-sm') ?>
        <?= e($t($typeName)) ?>
        <span class="badge-soft"><?= $treeCount ?> <?= $t('คน') ?></span>
      </h3>
      <?= renderExecTree($roots, $byParent, $lang) ?>
    </div>
    <?php $campusHtml .= ob_get_clean(); ?>
    <?php endforeach; ?>
    <?php if ($campusHtml): ?>
  <div class="reveal" style="margin-bottom:44px">
    <div class="staff-cluster-heading">
      <div class="staff-cluster-icon"><?= icon($campusId ? 'building' : 'diagram') ?></div>
      <h2 style="font-size:1.2rem;color:var(--primary);margin:0"><?= e($t($campusName)) ?></h2>
    </div>
    <?= $campusHtml ?>
  </div>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>

  <?php elseif ($view === 'academic'):
    if (!$campusId):
      // STEP 1: pick a campus — no chart yet, just tiles to choose from.
      if (!empty($byCampusGroup)): ?>
  <div class="picker-hint"><?= icon('compass', 'icon-sm') ?> <?= $t('เลือกศูนย์พื้นที่เพื่อดูผังบุคลากร') ?></div>
  <div class="picker-grid">
    <?php foreach ($campuses as $c):
        $cnt = count($byCampusGroup[$c['campus_name']] ?? []);
        if (!$cnt) continue;
    ?>
    <a href="?view=academic&campus=<?= (int)$c['campus_id'] ?>&lang=<?= e($lang) ?>" class="picker-tile">
      <div class="picker-tile-media"<?= thumbRatioStyle($c['image'] ?? null) ?>>
        <?php if (!empty($c['image'])): ?>
        <img src="<?= UPLOAD_URL . '/' . e($c['image']) ?>" alt="<?= e($t($c['campus_name'])) ?>" loading="lazy"<?= imgSizeAttrs($c['image']) ?>>
        <?php else: ?>
        <div class="picker-tile-ico"><?= icon('building') ?></div>
        <?php endif; ?>
      </div>
      <div class="picker-tile-body">
        <div class="picker-tile-name"><?= e($t($c['campus_name'])) ?></div>
        <div class="picker-tile-count"><?= $cnt ?> <?= $t('คน') ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
      <?php endif;
    elseif (!$majorId):
      // STEP 2: campus chosen — pick a major within it before any chart shows.
      $campusMembersNow = $byCampusGroup[$campusLabelNow] ?? [];
      $majorsInCampus = [];
      foreach ($campusMembersNow as $s) { if (!empty($s['major_name'])) { $majorsInCampus[$s['major_name']][] = $s; } }
    ?>
  <div class="picker-hint"><?= icon('compass', 'icon-sm') ?> <?= $t('เลือกสาขาวิชาใน') ?> <?= e($t($campusLabelNow)) ?></div>
    <?php if (empty($majorsInCampus)): ?>
  <div class="empty-state"><?= icon('users') ?><div><?= $t('ไม่มีบุคลากรที่ระบุสาขาวิชาในศูนย์นี้') ?></div></div>
    <?php else: ?>
  <div class="picker-grid">
    <?php foreach ($majors as $m):
        $cnt = count($majorsInCampus[$m['major_name']] ?? []);
        if (!$cnt) continue;
    ?>
    <a href="?view=academic&campus=<?= $campusId ?>&major=<?= (int)$m['major_id'] ?>&lang=<?= e($lang) ?>" class="picker-tile">
      <div class="picker-tile-media"<?= thumbRatioStyle($m['image'] ?? null) ?>>
        <?php if (!empty($m['image'])): ?>
        <img src="<?= UPLOAD_URL . '/' . e($m['image']) ?>" alt="<?= e($t($m['major_name'])) ?>" loading="lazy"<?= imgSizeAttrs($m['image']) ?>>
        <?php else: ?>
        <div class="picker-tile-ico"><?= icon('graduation-cap') ?></div>
        <?php endif; ?>
      </div>
      <div class="picker-tile-body">
        <div class="picker-tile-name"><?= e($t($m['major_name'])) ?></div>
        <div class="picker-tile-count"><?= $cnt ?> <?= $t('คน') ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
    <?php endif; ?>
    <?php else:
      // STEP 3: campus + major both chosen — now, finally, show the chart.
      if (empty($byMajorGroup)): ?>
  <div class="empty-state"><?= icon('users') ?><div><?= $t('ไม่พบบุคลากรในสาขานี้ของศูนย์นี้') ?></div></div>
      <?php else:
        // Head is kept per (campus_id, major_id) pair — the same สาขา
        // taught at two ศูนย์ can have two different heads.
        $majorHeadRow = $db->fetchOne(
            'SELECT head_staff_id FROM major_campus_heads WHERE campus_id=? AND major_id=?',
            [$campusId, $majorId]
        );
        $majorHeadId = $majorHeadRow ? (int)$majorHeadRow['head_staff_id'] : null;
        foreach ($byMajorGroup as $majorLabel => $members):
          [$majorHead, $majorRest] = splitMajorHead($members, $majorHeadId);
      ?>
  <div class="reveal" style="margin-bottom:44px">
    <div class="staff-cluster-heading">
      <div class="staff-cluster-icon"><?= icon('graduation-cap') ?></div>
      <h2 style="font-size:1.2rem;color:var(--primary);margin:0"><?= e($t($majorLabel)) ?></h2>
      <span class="badge-soft" style="margin-left:auto"><?= count($members) ?> <?= $t('คน') ?></span>
    </div>
    <?php if ($majorHead): ?>
    <div class="staff-tree-scroll">
      <div class="staff-branch is-wide">
        <?= staffCard($majorHead, $lang, true, $majorHeadId) ?>
        <?php if ($majorRest): ?>
        <div class="staff-branch-grid is-wide">
          <?php foreach ($majorRest as $s): echo staffCard($s, $lang, true, $majorHeadId); endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="staff-tree-scroll">
      <div class="staff-branch is-wide">
        <div class="staff-branch-grid is-wide">
          <?php foreach ($members as $s): echo staffCard($s, $lang, true, $majorHeadId); endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
      <?php endforeach; endif;
    endif;

  elseif ($view === 'support'):
    if (!$campusId):
      // STEP 1: pick a campus (only campuses that have support staff)
      if (!$supportByCampus): ?>
  <div class="empty-state"><?= icon('users') ?><div><?= $t('ยังไม่มีข้อมูลบุคลากรสายสนับสนุน') ?></div></div>
      <?php else: ?>
  <div class="picker-hint"><?= icon('compass', 'icon-sm') ?> <?= $t('เลือกศูนย์พื้นที่เพื่อดูบุคลากรสายสนับสนุน') ?></div>
  <div class="picker-grid">
    <?php foreach ($campuses as $c):
        $cnt = count($supportByCampus[(int)$c['campus_id']] ?? []);
        if (!$cnt) continue; ?>
    <a href="?view=support&campus=<?= (int)$c['campus_id'] ?>&lang=<?= e($lang) ?>" class="picker-tile">
      <div class="picker-tile-media"<?= thumbRatioStyle($c['image'] ?? null) ?>>
        <?php if (!empty($c['image'])): ?>
        <img src="<?= UPLOAD_URL . '/' . e($c['image']) ?>" alt="<?= e($t($c['campus_name'])) ?>" loading="lazy"<?= imgSizeAttrs($c['image']) ?>>
        <?php else: ?>
        <div class="picker-tile-ico"><?= icon('building') ?></div>
        <?php endif; ?>
      </div>
      <div class="picker-tile-body">
        <div class="picker-tile-name"><?= e($t($c['campus_name'])) ?></div>
        <div class="picker-tile-count"><?= $cnt ?> <?= $t('คน') ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
      <?php endif;
    else:
      // STEP 2: the campus's support staff, each head on top of their team
      $supportPeople = $supportByCampus[$campusId] ?? [];
      if (!$supportPeople): ?>
  <div class="empty-state"><?= icon('users') ?><div><?= $t('ไม่พบบุคลากรสายสนับสนุนในศูนย์นี้') ?></div></div>
      <?php else: echo renderSupportCharts($supportPeople, $lang, $t); endif;
    endif;

  elseif ($view === 'committee'):
    if (!$committee): ?>
  <div class="empty-state"><?= icon('users') ?><div><?= $t('ยังไม่มีข้อมูลคณะกรรมการประจำคณะ') ?></div></div>
    <?php else:
      // the chair on top, everyone else below in role order (ผู้ทรงคุณวุฒิภายนอก, ภายใน, กรรมการ, เลขานุการ)
      $chairs = array_values(array_filter($committee, fn($m) => $m['role'] === 'chair'));
      $others = array_values(array_filter($committee, fn($m) => $m['role'] !== 'chair'));
      // each chart shows only its own position: here the role from the committee form, not the person's ตำแหน่ง
      // (รองคณบดี, หัวหน้าสาขาวิชา…); an outsider keeps the ตำแหน่ง/หน่วยงานต้นสังกัด typed in that same form
      $card = fn(array $m) => staffCard(['staff_id' => -$m['member_id'], 'responsibility' => $m['is_external'] ? $m['responsibility'] : ''] + $m, $lang); ?>
  <div class="reveal" style="margin-bottom:44px">
    <div class="staff-cluster-heading">
      <div class="staff-cluster-icon"><?= icon('users') ?></div>
      <h2 style="font-size:1.2rem;color:var(--primary);margin:0"><?= $t('คณะกรรมการประจำคณะ') ?></h2>
      <span class="badge-soft" style="margin-left:auto"><?= count($committee) ?> <?= $t('คน') ?></span>
    </div>
    <div class="staff-tree-scroll">
      <div class="staff-branch is-wide">
        <?php foreach ($chairs as $m) echo $card($m); ?>
        <?php if ($others): ?>
        <div class="staff-branch-grid is-wide">
          <?php foreach ($others as $m) echo $card($m); ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
    <?php endif;
  endif; ?>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>
