<?php
/**
 * BAIT Admin - Program View
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/Auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db   = Database::getInstance();

$programId = (int)($_GET['id'] ?? 0);
$program = $db->fetchOne(
    "SELECT p.*, m.major_name, el.level_name
     FROM programs p
     JOIN majors m ON m.major_id = p.major_id
     JOIN education_levels el ON el.level_id = p.level_id
     WHERE p.program_id = ?",
    [$programId]
);
if (!$program) { header('Location: index.php'); exit; }

$programCampuses = $db->fetchAll(
    "SELECT c.campus_name FROM program_campuses pc
     JOIN campuses c ON c.campus_id = pc.campus_id
     WHERE pc.program_id = ? ORDER BY c.campus_name",
    [$programId]
);
$campusNames = array_column($programCampuses, 'campus_name');

// Decode the JSON payload kept in the `about` column (see create.php for the writer side).
$aboutData = ['description' => '', 'topics' => []];
if (!empty($program['about'])) {
    $decoded = json_decode($program['about'], true);
    if (is_array($decoded) && array_key_exists('description', $decoded)) {
        $aboutData = $decoded;
        $aboutData['topics'] = $aboutData['topics'] ?? [];
    } else {
        $aboutData['description'] = $program['about']; // legacy plain text
    }
}

// Decode the JSON payload kept in the `overview` column into title+content
// sections (see create.php for the writer side). Falls back to treating the
// raw value as one legacy HTML block if it isn't in the new JSON shape.
$overviewSections = [];
$legacyOverviewHtml = '';
if (!empty($program['overview'])) {
    $decodedOverview = json_decode($program['overview'], true);
    if (is_array($decodedOverview)) {
        foreach ($decodedOverview as $item) {
            if (is_array($item) && (isset($item['title']) || isset($item['content']))) {
                $overviewSections[] = ['title' => $item['title'] ?? '', 'content' => $item['content'] ?? ''];
            }
        }
    }
    if (!$overviewSections) {
        $legacyOverviewHtml = $program['overview']; // pre-existing raw HTML from before this change
    }
}

$pageTitle  = $program['program_name'];
$activeMenu = 'programs-list';
$breadcrumb = [
    ['label'=>'จัดการหลักสูตร','url'=>BASE_URL.'/admin/modules/programs/index.php'],
    ['label'=>$program['program_name']],
];
$pageActions = ''; // ปุ่มแก้ไขย้ายไปอยู่ใน hero ที่ออกแบบใหม่ด้านล่างแทน (เหมือนหน้าข่าวสาร)
// The hero card below already shows the program name (plus level/major/
// campus meta the plain header can't), so the auto page-header would just
// repeat the exact same title above it with nothing extra.
$hidePageHeader = true;
include BASE_PATH . '/admin/layout/header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
.ne {
  --ne-ink: #1e2a45;
  --ne-ink-soft: #5b6480;
  --ne-paper: #f5f6fa;
  --ne-surface: #ffffff;
  --ne-line: #e3e6ee;
  --ne-gold: #FBC02D;
  --ne-gold-soft: #f7f1e3;
  --ne-blue: #162E93;
  --ne-blue-deep: #11236E;
  --ne-blue-soft: #ECEEF6;
  --ne-green: #3e8564;
  --ne-green-soft: #e5f2eb;
  --ne-slate: #8b93a7;
  --ne-slate-soft: #eef0f5;
  --ne-danger: #c1443d;
  --ne-danger-soft: #fbeae9;
  font-family: 'Kanit', sans-serif;
  color: var(--ne-ink);
}
.ne * { box-sizing: border-box; }

.ne-btn {
  font-family: 'Kanit', sans-serif;
  font-weight: 600;
  font-size: 14.5px;
  border-radius: 9px;
  padding: 10px 18px;
  border: 1px solid transparent;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  cursor: pointer;
  text-decoration: none;
  transition: all .15s ease;
  line-height: 1;
}
.ne-btn-primary { background: var(--ne-blue); color: #fff; }
.ne-btn-primary:hover { background: var(--ne-blue-deep); color: #fff; }

/* ── Hero ─────────────────────────────────── */
.ne-hero {
  background: var(--ne-blue-soft);
  border: 1px solid var(--ne-line);
  border-radius: 14px;
  padding: 26px 28px;
  margin-bottom: 20px;
}
.ne-hero-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 16px; }
.ne-hero h1 {
  font-family: 'Kanit', sans-serif;
  font-size: 27px;
  font-weight: 700;
  margin: 0 0 4px;
  color: var(--ne-ink);
}
.ne-hero .sub { color: var(--ne-ink-soft); font-size: 13.5px; margin-bottom: 12px; }
.ne-hero-meta { display: flex; flex-wrap: wrap; gap: 18px; font-size: 13.5px; color: var(--ne-ink-soft); }
.ne-hero-meta span { display: flex; align-items: center; gap: 6px; }
.ne-hero-meta i { color: var(--ne-blue); }
.ne-hero-side { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }

.ne-campus-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.ne-campus-chips .chip {
  display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px;
  background: var(--ne-surface); border: 1px solid var(--ne-blue); color: var(--ne-blue-deep);
  font-size: 12.5px; font-weight: 600;
}

.ne-status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 600; }
.ne-status-pill.on { background: var(--ne-green-soft); color: #1f6b47; }
.ne-status-pill.off { background: var(--ne-danger-soft); color: var(--ne-danger); }
.ne-status-pill i { font-size: 8px; }

/* ── Panels ───────────────────────────────── */
.ne-panel { background: var(--ne-surface); border: 1px solid var(--ne-line); border-radius: 14px; margin-bottom: 18px; }
.ne-panel:last-child { margin-bottom: 0; }
.ne-panel-title {
  font-size: 12.5px;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ne-ink-soft);
  display: flex;
  align-items: center;
  gap: 7px;
  margin: 0;
  padding: 16px 22px;
  border-bottom: 1px solid var(--ne-line);
}
.ne-panel-title i { color: var(--ne-gold); font-size: 14px; }
.ne-panel-body { padding: 20px 22px; }

.ne-topic-card {
  border: 1px solid var(--ne-line);
  border-radius: 12px;
  padding: 16px;
  height: 100%;
  background: var(--ne-paper);
  transition: box-shadow .15s;
}
.ne-topic-card:hover { box-shadow: 0 4px 14px rgba(30,42,69,.07); }
.ne-topic-card i { font-size: 22px; color: var(--ne-blue); margin-bottom: 8px; display: block; }
.ne-topic-card .t { font-weight: 600; font-size: 14px; color: var(--ne-ink); }

.ne-about-desc { white-space: pre-line; line-height: 1.8; color: var(--ne-ink-soft); }

.ne-overview-content { line-height: 1.8; color: var(--ne-ink-soft); }
.ne-overview-content img { max-width: 100%; height: auto; }

.ne-ov-list { display: flex; flex-direction: column; }
.ne-ov-item { display: flex; gap: 14px; padding: 16px 0; border-bottom: 1px dashed var(--ne-line); }
.ne-ov-item:last-child { border-bottom: none; padding-bottom: 0; }
.ne-ov-item:first-child { padding-top: 0; }
.ne-ov-num {
  flex: 0 0 auto; width: 28px; height: 28px; border-radius: 50%;
  background: var(--ne-blue-soft); color: var(--ne-blue-deep); font-size: 12.5px; font-weight: 700;
  display: flex; align-items: center; justify-content: center; margin-top: 2px;
}
.ne-ov-body { flex: 1 1 auto; min-width: 0; }
.ne-ov-title { font-weight: 700; color: var(--ne-ink); margin-bottom: 4px; font-size: 14.5px; }
.ne-ov-content { color: var(--ne-ink-soft); line-height: 1.75; font-size: 14px; white-space: pre-line; }

.ne-doc-preview {
  display: flex; align-items: center; gap: 12px; padding: 14px; border: 1px solid var(--ne-line);
  border-radius: 10px; text-decoration: none; color: inherit;
}
.ne-doc-preview:hover { background: var(--ne-paper); }
.ne-doc-preview i { font-size: 30px; }

.ne-info-table { width: 100%; font-size: 14px; border-collapse: collapse; }
.ne-info-table td { padding: 9px 0; border-bottom: 1px solid var(--ne-line); }
.ne-info-table tr:last-child td { border-bottom: none; }
.ne-info-table td:first-child { color: var(--ne-slate); }
.ne-info-table td:last-child { text-align: right; font-weight: 600; color: var(--ne-ink); }
</style>

<div class="ne">

  <div class="ne-hero">
    <div class="ne-hero-top">
      <div>
        <h1><?= e($program['program_name']) ?></h1>
        <?php if ($program['program_abbr']): ?>
        <div class="sub">ชื่อย่อ: <?= e($program['program_abbr']) ?></div>
        <?php endif; ?>
        <div class="ne-hero-meta">
          <span><i class="bi bi-mortarboard"></i><?= e($program['level_name']) ?></span>
          <span><i class="bi bi-diagram-3"></i><?= e($program['major_name']) ?></span>
        </div>
        <div class="ne-campus-chips">
          <?php if (empty($campusNames)): ?>
          <span class="chip"><i class="bi bi-geo-alt"></i>ทุกศูนย์</span>
          <?php else: foreach ($campusNames as $cn): ?>
          <span class="chip"><i class="bi bi-geo-alt"></i><?= e($cn) ?></span>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <div class="ne-hero-side">
        <span class="ne-status-pill <?= $program['is_active'] ? 'on' : 'off' ?>">
          <i class="bi bi-circle-fill"></i>
          <?= $program['is_active'] ? 'เปิดรับสมัคร' : 'ปิดรับสมัคร' ?>
        </span>
        <a href="create.php?id=<?= $program['program_id'] ?>" class="ne-btn ne-btn-primary">
          <i class="bi bi-pencil"></i>แก้ไขหลักสูตร
        </a>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">

      <?php if ($aboutData['description'] || !empty($aboutData['topics'])): ?>
      <div class="ne-panel">
        <p class="ne-panel-title"><i class="bi bi-info-circle"></i>เกี่ยวกับหลักสูตร</p>
        <div class="ne-panel-body">
          <?php if ($aboutData['description']): ?>
          <h6 style="font-weight:600;margin-bottom:8px;">เรียนเกี่ยวกับอะไร</h6>
          <p class="ne-about-desc" style="margin-bottom:22px;"><?= e($aboutData['description']) ?></p>
          <?php endif; ?>

          <?php if (!empty($aboutData['topics'])): ?>
          <h6 style="font-weight:600;margin-bottom:12px;">เรียนอะไรบ้าง</h6>
          <div class="row g-3">
            <?php foreach ($aboutData['topics'] as $t): ?>
            <div class="col-md-4 col-6">
              <div class="ne-topic-card">
                <i class="bi <?= e($t['icon'] ?? 'bi-mortarboard') ?>"></i>
                <div class="t"><?= e($t['title'] ?? '') ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($overviewSections)): ?>
      <div class="ne-panel">
        <p class="ne-panel-title"><i class="bi bi-file-text"></i>ข้อมูลเกี่ยวกับหลักสูตรเบื้องต้น</p>
        <div class="ne-panel-body">
          <div class="ne-ov-list">
            <?php foreach ($overviewSections as $idx => $sec): ?>
            <div class="ne-ov-item">
              <div class="ne-ov-num"><?= $idx+1 ?></div>
              <div class="ne-ov-body">
                <?php if ($sec['title']): ?><div class="ne-ov-title"><?= e($sec['title']) ?></div><?php endif; ?>
                <?php if ($sec['content']): ?><div class="ne-ov-content"><?= nl2br(e($sec['content'])) ?></div><?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php elseif (!empty($legacyOverviewHtml)): ?>
      <div class="ne-panel">
        <p class="ne-panel-title"><i class="bi bi-file-text"></i>ข้อมูลเกี่ยวกับหลักสูตรเบื้องต้น</p>
        <div class="ne-panel-body">
          <div class="ne-overview-content"><?= $legacyOverviewHtml ?></div>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <div class="col-lg-4">
      <div class="ne-panel">
        <p class="ne-panel-title"><i class="bi bi-list-check"></i>ข้อมูลทั่วไป</p>
        <div class="ne-panel-body">
          <table class="ne-info-table">
            <tr><td>สาขาวิชา</td><td><?= e($program['major_name']) ?></td></tr>
            <tr><td>ระดับการศึกษา</td><td><?= e($program['level_name']) ?></td></tr>
            <tr><td>ศูนย์พื้นที่</td><td><?= e($campusNames ? implode(', ', $campusNames) : 'ทุกศูนย์') ?></td></tr>
            <tr><td>สถานะ</td><td><?= $program['is_active'] ? 'เปิดรับสมัคร' : 'ปิดรับสมัคร' ?></td></tr>
          </table>
        </div>
      </div>

      <div class="ne-panel">
        <p class="ne-panel-title"><i class="bi bi-file-earmark-text"></i>เอกสารหลักสูตร</p>
        <div class="ne-panel-body">
          <?php if (!empty($program['file'])):
              $docExt   = strtolower(pathinfo($program['file'], PATHINFO_EXTENSION));
              $docIcon  = $docExt === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-word';
              $docColor = $docExt === 'pdf' ? 'var(--ne-danger)' : 'var(--ne-blue)';
          ?>
          <a href="<?= UPLOAD_URL.'/'.e($program['file']) ?>" target="_blank" class="ne-doc-preview">
            <i class="bi <?= $docIcon ?>" style="color:<?= $docColor ?>;"></i>
            <div class="text-truncate">
              <div style="font-weight:600;" class="text-truncate"><?= e(basename($program['file'])) ?></div>
              <small style="color:var(--ne-slate);">คลิกเพื่อเปิด / ดาวน์โหลด</small>
            </div>
          </a>
          <?php else: ?>
          <p style="margin:0;font-size:13.5px;color:var(--ne-slate);">ยังไม่มีเอกสารแนบสำหรับหลักสูตรนี้</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include BASE_PATH . '/admin/layout/footer.php'; ?>