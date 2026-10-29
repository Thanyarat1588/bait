<?php
/**
 * BAIT - ค่าธรรมเนียมการศึกษา (tuition fees of every programme). One tab per education level, filters: campus, length of
 * study, search. Figures come from tuition_fees (admin: หลักสูตร > ค่าเทอม); a programme with no figures yet says "สอบถามคณะ".
 * Mobile first: each programme is a card; from 768px up it is a table with a full grid.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/components.php';

$db   = Database::getInstance();
$lang = currentLang();
if (!in_array($lang, ['th', 'en'])) $lang = 'th';
$_SESSION['lang'] = $lang;
$t  = fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;
$en = $lang === 'en';

// ── levels that have programmes (the tabs) ──
$levels = $db->fetchAll(
    "SELECT el.level_id, el.level_name, el.level_abbr, COUNT(p.program_id) AS n FROM education_levels el
     JOIN programs p ON p.level_id = el.level_id AND p.is_active = 1 GROUP BY el.level_id ORDER BY el.level_order");
$levelIds = array_map(fn($l) => (int)$l['level_id'], $levels);
$levelId  = (int)getStr('level');
if (!in_array($levelId, $levelIds, true)) $levelId = 0;   // 0 = every level (the default tab "ทั้งหมด")

$campuses = $db->fetchAll('SELECT campus_id, campus_name FROM campuses ORDER BY campus_id');
$campusId = (int)getStr('campus');
if (!in_array($campusId, array_map(fn($c) => (int)$c['campus_id'], $campuses), true)) $campusId = 0;
$q = mb_substr(getStr('q'), 0, 80);

// ── programmes (of the chosen level, or all), their campuses and fee rows ──
$programs = $levels ? $db->fetchAll(
    "SELECT p.program_id, p.program_name, p.is_active, COALESCE(m.major_name, '') AS major_name, el.level_name,
            GROUP_CONCAT(pc.campus_id ORDER BY pc.campus_id) AS campus_ids,
            GROUP_CONCAT(c.campus_name ORDER BY c.campus_id SEPARATOR ', ') AS campus_names
     FROM programs p JOIN education_levels el ON el.level_id = p.level_id LEFT JOIN majors m ON m.major_id = p.major_id
     LEFT JOIN program_campuses pc ON pc.program_id = p.program_id LEFT JOIN campuses c ON c.campus_id = pc.campus_id
     WHERE 1=1" . ($levelId ? ' AND p.level_id = ' . (int)$levelId : '') . "
     GROUP BY p.program_id ORDER BY el.level_order, m.major_name, p.program_name") : [];
$fees = tuitionRowsByProgram(array_column($programs, 'program_id'));

// lengths of study that exist in what is shown (the "ระยะเวลา" filter)
$yearsList = [];
foreach ($fees as $rows) foreach ($rows as $r) if ($r['duration_years'] !== null) $yearsList[(string)(float)$r['duration_years']] = (float)$r['duration_years'];
ksort($yearsList, SORT_NUMERIC);
$years = getStr('years');
if (!isset($yearsList[$years])) $years = '';

// apply the filters
$groups = []; $shown = 0;
foreach ($programs as $p) {
    $pid = (int)$p['program_id'];
    $offered = $p['campus_ids'] ? array_map('intval', explode(',', $p['campus_ids'])) : [];   // none listed = every campus
    if ($campusId && $offered && !in_array($campusId, $offered, true)) continue;
    if ($q !== '' && mb_stripos($p['program_name'] . ' ' . $p['major_name'] . ' ' . $t($p['program_name']), $q) === false) continue;
    $rows = array_values(array_filter($fees[$pid] ?? [], function ($r) use ($campusId, $years) {
        if ($campusId && $r['campus_id'] !== null && (int)$r['campus_id'] !== $campusId) return false;
        if ($years !== '' && (string)(float)$r['duration_years'] !== $years) return false;
        return true;
    }));
    if (!$rows && $years !== '') continue;            // filtering by length: only programmes that have it
    $p['rows'] = $rows;
    $groups[$p['level_name']][$p['major_name']][] = $p;   // level -> major -> programmes
    $shown++;
}

$tuSet = tuitionSettings();
$updated  = $tuSet['data_date'] ?: null;
if (!$updated) foreach ($fees as $rows) foreach ($rows as $r) $updated = max((string)$updated, (string)$r['updated_at']);
$money = fn($v) => ($v === null || $v === '') ? '-' : number_format((int)$v);
$types = tuitionFeeTypes();
$typeLabel = fn(string $k) => $en ? ($k === 'credit' ? 'Per credit' : 'Flat rate') : ($types[$k] ?? $types['lump']);
$keep = array_filter(['level' => $levelId ?: '', 'campus' => $campusId ?: '', 'years' => $years, 'q' => $q], fn($v) => $v !== '');
$clearUrl = '?' . http_build_query(array_filter(['level' => $levelId ?: ''], fn($v) => $v !== '') + ['lang' => $lang]);

trackPageView($_SERVER['REQUEST_URI'], $en ? 'Tuition Fees' : 'ค่าธรรมเนียมการศึกษา');
$pageTitle = $en ? 'Tuition Fees' : 'ค่าธรรมเนียมการศึกษา';
$metaDesc  = $en
    ? 'Tuition fees of every programme of the Faculty of Business Administration and Information Technology, RMUTSB, by level, campus and length of study.'
    : 'อัตราค่าธรรมเนียมการศึกษา (ค่าเทอม) ทุกหลักสูตรของคณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มทร.สุวรรณภูมิ แยกตามระดับการศึกษา ศูนย์พื้นที่ และระยะเวลาเรียน';
$activePage = 'programs';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $t('หลักสูตร'), 'url' => BASE_URL . '/programs.php?lang=' . $lang],
    ['label' => $pageTitle],
];
$cols = $en
    ? ['Programme / campus', 'Study schedule', 'Length', 'Fee per semester', 'Summer term', 'Fee type', 'Student loan', 'Whole programme (approx.)']
    : ['หลักสูตร / ศูนย์พื้นที่', 'ภาคเรียนและวัน', 'ระยะเวลา', 'ค่าเทอม/ภาคเรียน', 'ภาคฤดูร้อน', 'รูปแบบ', 'กองทุนกู้ยืม', 'ตลอดหลักสูตร (โดยประมาณ)'];
?>

<section class="page-hero">
  <div class="container">
    <h1><?= e($pageTitle) ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section" style="padding-top:28px">
  <?php if ($levels): ?>
  <nav class="tab-pills tu-levels" aria-label="<?= e($t('ระดับการศึกษา')) ?>">
    <a href="?<?= e(http_build_query(array_intersect_key($keep, ['campus' => 1]) + ['lang' => $lang])) ?>" class="tab-pill <?= $levelId === 0 ? 'is-active' : '' ?>"<?= $levelId === 0 ? ' aria-current="page"' : '' ?>><?= $t('ทั้งหมด') ?></a>
    <?php foreach ($levels as $l): ?>
    <a href="?<?= e(http_build_query(['level' => (int)$l['level_id']] + array_intersect_key($keep, ['campus' => 1]) + ['lang' => $lang])) ?>" class="tab-pill <?= (int)$l['level_id'] === $levelId ? 'is-active' : '' ?>"<?= (int)$l['level_id'] === $levelId ? ' aria-current="page"' : '' ?>><?= e($t($l['level_name'])) ?></a>
    <?php endforeach; ?>
  </nav>

  <form class="filter-bar tu-filters" method="GET">
    <input type="hidden" name="lang" value="<?= e($lang) ?>">
    <?php if ($levelId): ?><input type="hidden" name="level" value="<?= (int)$levelId ?>"><?php endif; ?>
    <span class="filter-label"><?= icon('filter') ?> <?= $t('กรอง:') ?></span>
    <select class="field-select" name="campus" data-filter-select="campus" aria-label="<?= e($t('ศูนย์พื้นที่')) ?>">
      <option value=""><?= $t('ทุกศูนย์พื้นที่') ?></option>
      <?php foreach ($campuses as $c): ?><option value="<?= (int)$c['campus_id'] ?>"<?= $campusId === (int)$c['campus_id'] ? ' selected' : '' ?>><?= e($t($c['campus_name'])) ?></option><?php endforeach; ?>
    </select>
    <select class="field-select" name="years" data-filter-select="years" aria-label="<?= e($en ? 'Length of study' : 'ระยะเวลาเรียน') ?>">
      <option value=""><?= $en ? 'Any length' : 'ทุกระยะเวลา' ?></option>
      <?php foreach ($yearsList as $k => $v): ?><option value="<?= e($k) ?>"<?= $years === (string)$k ? ' selected' : '' ?>><?= e(tuitionYears($v, $lang)) ?></option><?php endforeach; ?>
    </select>
    <div class="search-box search-box--grow">
      <?= icon('search') ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e($en ? 'Search programme / major' : 'ค้นหาหลักสูตร / สาขา') ?>" aria-label="<?= e($en ? 'Search programme' : 'ค้นหาหลักสูตร') ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><?= $t('ค้นหา') ?></button>
    <?php if ($campusId || $years !== '' || $q !== ''): ?>
    <a href="<?= e($clearUrl) ?>" class="btn btn-ghost btn-sm"><?= icon('close', 'icon-sm') ?> <?= $t('ล้างตัวกรอง') ?></a>
    <?php endif; ?>
  </form>

  <div class="tu-meta">
    <?php if ($tuSet['academic_year'] !== ''): ?><span><?= $en ? 'Academic year' : 'ปีการศึกษา' ?> <b><?= e($tuSet['academic_year']) ?></b></span><?php endif; ?>
    <span><?= $en ? 'Amounts in' : 'หน่วย:' ?> <b><?= $en ? 'Thai baht' : 'บาท' ?></b></span>
    <?php if ($updated): ?><span><?= $en ? 'Figures as of' : 'ข้อมูล ณ วันที่' ?> <b><?= e(thaiDate($updated)) ?></b></span><?php endif; ?>
    <span><?= $shown ?> <?= $t('หลักสูตร') ?></span>
  </div>

  <?php if (!$groups): ?>
  <div class="empty-state"><?= icon('book') ?><div><?= $t('ไม่พบหลักสูตร') ?></div>
    <a href="<?= e($clearUrl) ?>" class="btn btn-secondary btn-sm" style="margin-top:12px"><?= $t('ล้างตัวกรอง') ?></a></div>
  <?php else: ?>
  <div class="tu-wrap">
    <table class="tu-table">
      <caption class="visually-hidden"><?= e($pageTitle . ' — ' . ($levelId ? $t($levels[array_search($levelId, $levelIds, true)]['level_name'] ?? '') : $t('ทั้งหมด'))) ?></caption>
      <thead><tr>
        <?php foreach ($cols as $i => $c): ?><th scope="col"<?= in_array($i, [3, 4, 7], true) ? ' class="tu-num"' : '' ?>><?= e($c) ?></th><?php endforeach; ?>
      </tr></thead>
      <?php foreach ($groups as $levelName => $majors): ?>
      <?php if (!$levelId): ?><tbody class="tu-level"><tr><th colspan="8" scope="colgroup"><?= e($t($levelName)) ?></th></tr></tbody><?php endif; ?>
      <?php foreach ($majors as $major => $list): ?>
      <tbody class="tu-group"><tr><th colspan="8" scope="colgroup"><?= e($major !== '' ? ($en ? $t('สาขาวิชา' . $major) : 'สาขาวิชา' . $major) : $t('หลักสูตรระดับบัณฑิตศึกษา')) ?></th></tr></tbody>
      <?php foreach ($list as $p):
          $rows = $p['rows'] ?: [null];
          $url  = BASE_URL . '/program-detail.php?id=' . (int)$p['program_id'] . '&amp;lang=' . e($lang);
          $cont = str_contains($p['program_name'], 'ต่อเนื่อง');
      ?>
      <tbody class="tu-prog">
        <?php foreach ($rows as $i => $r): ?>
        <tr>
          <?php if ($i === 0): ?>
          <th scope="rowgroup" rowspan="<?= count($rows) ?>" class="tu-name">
            <a href="<?= $url ?>"><?= e($t(preg_replace('/\s+/u', ' ', $p['program_name']))) ?></a><?= $cont ? ' <span class="tu-tag">' . ($en ? 'Continuing' : 'ต่อเนื่อง') . '</span>' : '' ?><?= (int)$p['is_active'] !== 1 ? ' <span class="program-closed-tag">' . ($en ? 'Closed' : 'ปิดรับสมัคร') . '</span>' : '' ?>
            <span class="tu-camp"><?= icon('pin', 'icon-sm') ?> <?= e($p['campus_names'] ? $t($p['campus_names']) : $t('ทุกศูนย์พื้นที่')) ?></span>
          </th>
          <?php endif; ?>
          <?php if ($r === null): ?>
          <td colspan="7" class="tu-ask"><?= $en ? 'No fee figures yet — please' : 'ยังไม่มีข้อมูลค่าเทอม' ?> <a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>"><?= $en ? 'contact the faculty' : 'สอบถามคณะ' ?></a></td>
          <?php else:
              $credit = $r['fee_type'] === 'credit';
              $per    = $credit ? ' ' . ($en ? '/ credit' : '/ หน่วยกิต') : '';
              $two    = $r['term2'] !== null && $r['term2'] !== '' && (int)$r['term2'] !== (int)$r['term1'];
              $total  = tuitionTotal($r);
          ?>
          <td data-label="<?= e($cols[1]) ?>"><span class="tu-val"><?= e($r['schedule'] !== '' ? $t($r['schedule']) : '-') ?>
            <?php if ($r['campus_id'] !== null): ?><span class="tu-only"><?= $en ? 'Only at' : 'เฉพาะ' ?> <?= e($t((string)$r['campus_name'])) ?></span><?php endif; ?>
            <?php if ($r['note'] !== ''): ?><span class="tu-note"><?= e($t($r['note'])) ?></span><?php endif; ?></span></td>
          <td data-label="<?= e($cols[2]) ?>" class="tu-nw"><?= e(tuitionYears($r['duration_years'], $lang)) ?></td>
          <td data-label="<?= e($cols[3]) ?>" class="tu-num"><?php if ($two): ?><?= $en ? 'Sem. 1' : 'ภาค 1' ?>: <?= $money($r['term1']) ?><br><?= $en ? 'Sem. 2' : 'ภาค 2' ?>: <?= $money($r['term2']) ?><?php else: ?><?= $money($r['term1']) ?><?php endif; ?><?= $r['term1'] !== null ? $per : '' ?></td>
          <td data-label="<?= e($cols[4]) ?>" class="tu-num"><?= $money($r['summer']) ?><?= $r['summer'] !== null ? $per : '' ?></td>
          <td data-label="<?= e($cols[5]) ?>" class="tu-nw"><?= e($typeLabel($r['fee_type'])) ?></td>
          <td data-label="<?= e($cols[6]) ?>"><?= e($r['loan_fund'] !== '' ? $t($r['loan_fund']) : '-') ?></td>
          <td data-label="<?= e($cols[7]) ?>" class="tu-num tu-total"><?= $total !== null ? number_format($total) : '-' ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; endforeach; endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <div class="tu-foot">
    <p><strong><?= $en ? 'Notes' : 'หมายเหตุ' ?></strong></p>
    <?php if (trim((string)$tuSet['note']) !== ''): ?>
    <p><?= nl2br(e($t((string)$tuSet['note']))) ?></p>
    <?php else: ?>
    <p><?= $en ? 'Fees may change according to university announcements. "Whole programme" is an estimate (fee per semester × semesters) and does not include other expenses.'
                : 'อัตราค่าธรรมเนียมอาจเปลี่ยนแปลงตามประกาศของมหาวิทยาลัย · ยอด "ตลอดหลักสูตร" เป็นการประมาณจากค่าเทอม × จำนวนภาคเรียน ไม่รวมค่าใช้จ่ายอื่น' ?></p>
    <?php endif; ?>
    <div class="cluster gap-3" style="margin-top:14px">
      <a href="https://admis.rmutsb.ac.th/admissions" target="_blank" rel="noopener" class="btn btn-accent"><?= $t('สมัครเรียน') ?></a>
      <a href="<?= BASE_URL ?>/contact.php?lang=<?= e($lang) ?>" class="btn btn-secondary"><?= $t('ติดต่อเรา') ?></a>
    </div>
  </div>
  <?php else: ?>
  <div class="empty-state"><?= icon('book') ?><div><?= $t('ไม่พบหลักสูตร') ?></div></div>
  <?php endif; ?>
</div>

<script>
// the column titles stick just below the site's own sticky header, whatever its height on this screen
(function () {
  var table = document.querySelector('.tu-table'), head = document.querySelector('.site-header');
  if (!table || !head) return;
  var set = function () { table.style.setProperty('--tu-sticky-top', head.getBoundingClientRect().height + 'px'); };
  set(); window.addEventListener('resize', set);
  if (window.ResizeObserver) new ResizeObserver(set).observe(head);
})();
</script>

<?php include __DIR__ . '/layout/footer.php'; ?>
