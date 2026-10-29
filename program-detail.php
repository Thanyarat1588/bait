<?php
/**
 * BAIT - Program Detail Page (Phase 3 rebuild)
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

$programId = (int)($_GET['id'] ?? 0);
$program = $db->fetchOne(
    "SELECT p.*, m.major_name, el.level_name, el.level_abbr
     FROM programs p
     LEFT JOIN majors m ON m.major_id = p.major_id
     JOIN education_levels el ON el.level_id = p.level_id
     WHERE p.program_id = ?",
    [$programId]
);
// Not filtered by is_active here — a program with enrollment closed still
// needs its own page to work (contact CTA instead of the apply button
// below), it just shouldn't offer to take applications for it. Truly
// nonexistent programs are the only ones that bounce to the catalog.
if (!$program) { require __DIR__ . '/404.php'; exit; }
$isOpenForEnrollment = (int)($program['is_active'] ?? 1) === 1;

// Campuses this program is offered at — a many-to-many via program_campuses
// (a program can run at several campuses, or none selected at all meaning
// "every campus", per the same convention the admin form uses). The old
// single programs.campus_id column this page used to join against isn't
// kept in sync with that table any more, so it always shows the program's
// own real, currently-selected campus list instead of a stale/empty one.
$programCampusNames = array_column(
    $db->fetchAll(
        "SELECT c.campus_name FROM program_campuses pc
         JOIN campuses c ON c.campus_id = pc.campus_id
         WHERE pc.program_id = ? ORDER BY c.campus_id",
        [$programId]
    ),
    'campus_name'
);

// "Related" means same major, any level — e.g. showing the ปวส./ป.ตรี
// continuations of a ปวช. program is a genuine relation (progression path
// within the field), whereas matching only on level surfaced completely
// unrelated majors that just happened to share a level, which was noise.
// Capped at RELATED_LIMIT so this sidebar widget can't grow unbounded (a
// major could pick up more programs over time) — but unlike the old fixed
// LIMIT 4, we know the *real* total, so a "ดูหลักสูตรทั้งหมด" link only
// shows up when something was actually left off, and it goes to the
// existing major filter on programs.php rather than trying to paginate
// in place, which would be overkill for a small reference widget.
const RELATED_LIMIT = 5;
$relatedTotal = (int)($db->fetchOne(
    "SELECT COUNT(*) as c FROM programs WHERE major_id <=> ? AND program_id != ?",
    [$program['major_id'], $programId]
)['c'] ?? 0);
$related = $db->fetchAll(
    "SELECT p.program_id, p.program_name, p.is_active, el.level_abbr, m.major_name
     FROM programs p
     LEFT JOIN majors m ON m.major_id=p.major_id
     JOIN education_levels el ON el.level_id=p.level_id
     WHERE p.major_id <=> ? AND p.program_id != ?
     ORDER BY p.is_active DESC, p.level_id, p.program_id LIMIT " . RELATED_LIMIT,
    [$program['major_id'], $programId]
);

$displayName = $t($program['program_name']);

/**
 * 'about' / 'overview' are stored as JSON, e.g.:
 *   about:    {"description":"...","topics":[{"icon":"...","title":"VR/AR"}, ...]}
 *   overview: [{"title":"...","content":"..."}, ...]
 * — must be decoded, not echoed raw. See also components.php's
 * programAboutSummary() for the compact card-view equivalent.
 */
function renderAbout($raw, $lang, $t) {
    if (!$raw) return '';
    $data = json_decode($raw, true);
    if (!is_array($data)) return '<p class="prose">' . nl2br(e($t($raw))) . '</p>';

    $html = '';
    if (!empty($data['description'])) {
        $html .= '<p class="prose">' . nl2br(e($t($data['description']))) . '</p>';
    }
    if (!empty($data['topics']) && is_array($data['topics'])) {
        $html .= '<div class="cluster gap-2" style="margin-top:8px">';
        foreach ($data['topics'] as $topic) {
            $title = $t($topic['title'] ?? '');
            $html .= '<span class="badge-soft">' . e($title) . '</span>';
        }
        $html .= '</div>';
    }
    return $html;
}

// $feeHtml: the programme's tuition figures (admin: หลักสูตร > ค่าเทอม). When there are some, they take the place of the free-text
// "ค่าธรรมเนียมการศึกษา" block (which usually holds only the empty "ภาคเรียนที่ 1:" template), or are added at the end.
function renderOverview($raw, $lang, $t, string $feeHtml = '') {
    $feeTitle = 'ค่าธรรมเนียมการศึกษา';
    $feeCard  = fn() => '<div class="card" style="border-left:3px solid var(--primary);border-radius:0 var(--radius-md) var(--radius-md) 0;padding:16px 18px;box-shadow:none">'
        . '<h3 class="fs-h4" style="color:var(--primary);margin-bottom:6px">' . e($t($feeTitle)) . '</h3>' . $feeHtml . '</div>';
    if (!$raw) return $feeHtml !== '' ? '<div class="stack gap-3">' . $feeCard() . '</div>' : '';
    $data = json_decode($raw, true);
    if (!is_array($data)) return '<div class="prose">' . cleanRichHtml($t($raw)) . '</div>' . ($feeHtml !== '' ? '<div class="stack gap-3" style="margin-top:12px">' . $feeCard() . '</div>' : '');
    $feeDone = false;

    $html = '<div class="stack gap-3">';
    foreach ($data as $item) {
        $title = $item['title'] ?? ''; $content = $item['content'] ?? '';
        if ($title === '' && $content === '') continue;
        if (trim($title) === $feeTitle) { if ($feeHtml !== '' && !$feeDone) $html .= $feeCard(); $feeDone = true; continue; }   // old free-text fees: replaced by หลักสูตร > ค่าเทอม
        $title   = $t($title);
        $content = str_replace(["\r\n", "\n"], '<br>', cleanRichHtml($t($content)));
        $html .= '<div class="card" style="border-left:3px solid var(--primary);border-radius:0 var(--radius-md) var(--radius-md) 0;padding:16px 18px;box-shadow:none">'
               . '<h3 class="fs-h4" style="color:var(--primary);margin-bottom:6px">' . e($title) . '</h3>'
               . '<div class="prose" style="font-size:.94rem">' . $content . '</div></div>';
    }
    if ($feeHtml !== '' && !$feeDone) $html .= $feeCard();
    $html .= '</div>';
    return $html;
}

$displayAbout    = renderAbout($program['about'], $lang, $t);
// tuition figures of this programme as a small gridded table, plus a link to the page with every programme
$feeHtml = '';
$feeRows = tuitionRowsByProgram([$programId])[$programId] ?? [];
if ($feeRows) {
    $en = $lang === 'en';
    $feeHtml = '<div class="tu-mini-wrap"><table class="tu-mini"><thead><tr><th scope="col">' . ($en ? 'Study schedule' : 'ภาคเรียนและวัน') . '</th><th scope="col">' . ($en ? 'Length' : 'ระยะเวลา') . '</th><th scope="col">' . ($en ? 'Fee per semester' : 'ค่าเทอม/ภาคเรียน') . '</th><th scope="col">' . ($en ? 'Whole programme (approx.)' : 'ตลอดหลักสูตร (โดยประมาณ)') . '</th></tr></thead><tbody>';
    foreach ($feeRows as $r) {
        $per   = $r['fee_type'] === 'credit' ? ($en ? ' / credit' : ' / หน่วยกิต') : '';
        $two   = $r['term2'] !== null && (int)$r['term2'] !== (int)$r['term1'];
        $fee   = $two ? ($en ? 'Sem. 1: ' : 'ภาค 1: ') . number_format((int)$r['term1']) . '<br>' . ($en ? 'Sem. 2: ' : 'ภาค 2: ') . number_format((int)$r['term2']) : number_format((int)$r['term1']);
        $tot   = tuitionTotal($r);
        $where = ($r['schedule'] !== '' ? e($t($r['schedule'])) : '-') . ($r['campus_id'] !== null ? '<br><small>' . ($en ? 'Only at ' : 'เฉพาะ') . e($t((string)$r['campus_name'])) . '</small>' : '')
               . ($r['loan_fund'] !== '' ? '<br><small>' . ($en ? 'Student loan: ' : 'กองทุนกู้ยืม: ') . e($t($r['loan_fund'])) . '</small>' : '');
        $feeHtml .= '<tr><td>' . $where . '</td><td>' . e(tuitionYears($r['duration_years'], $lang)) . '</td><td class="tu-num">' . $fee . $per
                  . ($r['summer'] !== null ? '<br><small>' . ($en ? 'Summer: ' : 'ภาคฤดูร้อน: ') . number_format((int)$r['summer']) . $per . '</small>' : '') . '</td><td class="tu-num tu-total">' . ($tot !== null ? number_format($tot) : '-') . '</td></tr>';
    }
    $feeHtml .= '</tbody></table></div><p style="margin:8px 0 0;font-size:.85rem">' . ($en ? 'Amounts in Thai baht. ' : 'หน่วย: บาท · ') . '<a href="' . BASE_URL . '/tuition.php?level=' . (int)$program['level_id'] . '&amp;lang=' . e($lang) . '">' . ($en ? 'Tuition fees of every programme' : 'ดูค่าเทอมทุกหลักสูตร') . ' &rarr;</a></p>';
}
$displayOverview = renderOverview($program['overview'], $lang, $t, $feeHtml);

trackPageView($_SERVER['REQUEST_URI'], $program['program_name']);

$pageTitle = $displayName;
$metaDesc  = $displayAbout ? metaSnippet($displayAbout) : '';
if ($metaDesc === '') {
    // No description written for this program yet — build one from its facts.
    $metaDesc = mb_strimwidth($displayName . ' — ' . $t($program['level_name']) . ($program['major_name'] ? ' · ' . $t($program['major_name']) : '')
        . ' · ' . ($lang === 'en' ? 'Faculty of Business Administration and Information Technology, RMUTSB' : 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มทร.สุวรรณภูมิ'), 0, 160, '...', 'UTF-8');
}
$ogImage   = $program['image'] ?? null;

$schemaJson = json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Course',
    'name'        => $displayName,
    'description' => $metaDesc,
    'provider'    => ['@type' => 'Organization', 'name' => 'BAIT RMUTSB', 'url' => BASE_URL_ABSOLUTE],
    'url'         => BASE_URL_ABSOLUTE . '/program-detail.php?id=' . $programId,
    'courseMode'  => 'onsite',
], JSON_UNESCAPED_UNICODE);

$activePage = 'programs';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('หลักสูตร'), 'url' => BASE_URL . '/programs.php?lang=' . $lang],
    ['label' => mb_strimwidth($displayName, 0, 40, '...', 'UTF-8')],
];
?>

<section class="page-hero">
  <div class="container">
    <span class="badge-soft" style="background:rgba(255,255,255,.16);color:#fff;margin-bottom:8px;display:inline-block"><?= e($t($program['level_name'])) ?></span>
    <h1 style="font-size:clamp(1.2rem,3vw,1.8rem)"><?= e($displayName) ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">
  <div class="layout-with-sidebar">

    <div>
      <?php if ($program['image']): ?>
      <img src="<?= UPLOAD_URL . '/' . e($program['image']) ?>" class="campus-hero-img reveal" style="margin-bottom:24px" alt="<?= e($displayName) ?>">
      <?php endif; ?>

      <?php if ($displayAbout): ?>
      <div class="card reveal" style="padding:24px;margin-bottom:24px">
        <h2 class="fs-h3" style="color:var(--primary);margin-bottom:14px"><?= icon('info', 'icon-sm') ?> <?= $t('เกี่ยวกับหลักสูตร') ?></h2>
        <?= $displayAbout ?>
      </div>
      <?php endif; ?>

      <?php if ($displayOverview): ?>
      <div class="card reveal" style="padding:24px;margin-bottom:24px">
        <h2 class="fs-h3" style="color:var(--primary);margin-bottom:14px"><?= icon('list', 'icon-sm') ?> <?= $t('รายละเอียดหลักสูตร') ?></h2>
        <?= $displayOverview ?>
      </div>
      <?php endif; ?>

    </div>

    <aside class="sidebar-sticky">
      <div class="sidebar-card">
        <h3 class="fs-h4"><?= $t('ข้อมูลหลักสูตร') ?></h3>
        <div class="info-list">
          <div class="info-row">
            <?= icon('graduation-cap') ?>
            <div><span class="info-row-label"><?= $t('ระดับ') ?></span><span class="info-row-value"><?= e($t($program['level_name'])) ?></span></div>
          </div>
          <?php if ($program['major_name']): ?>
          <div class="info-row">
            <?= icon('book') ?>
            <div><span class="info-row-label"><?= $t('สาขาวิชา') ?></span><span class="info-row-value"><?= e($t($program['major_name'])) ?></span></div>
          </div>
          <?php endif; ?>
          <div class="info-row">
            <?= icon('pin') ?>
            <div><span class="info-row-label"><?= $t('ศูนย์พื้นที่') ?></span><span class="info-row-value"><?= $programCampusNames ? e($t(implode(', ', $programCampusNames))) : e($t('ทุกศูนย์พื้นที่')) ?></span></div>
          </div>
          <div class="info-row">
            <span style="color:<?= $isOpenForEnrollment ? 'var(--success-text)' : 'var(--error-text)' ?>"><?= icon('check-circle') ?></span>
            <div><span class="info-row-label"><?= $t('สถานะ') ?></span><span class="info-row-value" style="color:<?= $isOpenForEnrollment ? 'var(--success-text)' : 'var(--error-text)' ?>"><?= $isOpenForEnrollment ? $t('เปิดรับสมัคร') : $t('ปิดรับสมัคร') ?></span></div>
          </div>
        </div>
      </div>

      <?php if (!empty($program['file']) && is_file(UPLOAD_PATH . '/' . $program['file'])): // no download link for a file that is not on the server
          $docExt = strtoupper(pathinfo($program['file'], PATHINFO_EXTENSION));
      ?>
      <div class="sidebar-card">
        <h3 class="fs-h4"><?= icon('download', 'icon-sm') ?> <?= $t('เอกสารหลักสูตร') ?></h3>
        <a href="<?= UPLOAD_URL . '/' . e($program['file']) ?>" download
           class="side-list-item" style="text-decoration:none;align-items:center;border:1px solid var(--border);border-radius:var(--radius-md);padding:12px">
          <span class="value-icon" style="--value-color:var(--primary)"><?= icon('download') ?></span>
          <div style="min-width:0">
            <p class="side-list-title" style="margin:0 0 2px"><?= $t('เอกสารหลักสูตร') ?></p>
            <span style="font-size:.78rem;color:var(--ink-soft)"><?= e($docExt) ?> &middot; <?= $t('คลิกเพื่อดาวน์โหลด') ?></span>
          </div>
        </a>
      </div>
      <?php endif; ?>

      <?php if ($isOpenForEnrollment): ?>
      <div class="apply-cta">
        <?= icon('edit') ?>
        <h3 class="fs-h4"><?= $t('สนใจสมัครเรียน?') ?></h3>
        <p><?= $t('คลิกเพื่อสมัครเรียนออนไลน์') ?></p>
        <a href="https://admis.rmutsb.ac.th/admissions" target="_blank" rel="noopener" class="btn btn-light"><?= $t('สมัครเรียน') ?> <?= icon('external-link', 'icon-sm') ?></a>
      </div>
      <?php else: ?>
      <div class="apply-cta">
        <?= icon('edit') ?>
        <h3 class="fs-h4"><?= $t('สนใจสมัครเรียน?') ?></h3>
        <p><?= $t('ติดต่อสอบถามข้อมูลเพิ่มเติม') ?></p>
        <a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>" class="btn btn-light"><?= $t('ติดต่อเรา') ?></a>
      </div>
      <?php endif; ?>

      <?php if ($related): ?>
      <div class="sidebar-card">
        <h3 class="fs-h4"><?= $t('หลักสูตรที่เกี่ยวข้อง') ?></h3>
        <?php foreach ($related as $r): ?>
        <a href="?id=<?= (int)$r['program_id'] ?>&lang=<?= e($lang) ?>" class="side-list-item" style="text-decoration:none;align-items:flex-start">
          <span class="badge-soft" style="flex-shrink:0"><?= e(tx((string)($r['level_abbr'] ?? ''), $lang)) ?></span>
          <p class="side-list-title" style="margin:0"><?= e($t($r['program_name'])) ?><?= (int)$r['is_active'] !== 1 ? '<span class="program-closed-tag">' . ($lang === 'en' ? 'Closed' : 'ปิดรับสมัคร') . '</span>' : '' ?></p>
        </a>
        <?php endforeach; ?>
        <?php if ($relatedTotal > count($related)): ?>
        <a href="<?= BASE_URL ?>/programs.php?major=<?= $program['major_id'] === null ? 'none' : (int)$program['major_id'] ?>&lang=<?= e($lang) ?>" class="side-list-item" style="text-decoration:none;justify-content:center;font-weight:600;color:var(--primary)">
          <?= $t('ดูหลักสูตรทั้งหมดในสาขานี้') ?> <?= icon('arrow-right', 'icon-sm') ?>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </aside>

  </div>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>
