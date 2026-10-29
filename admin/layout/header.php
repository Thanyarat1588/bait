<?php
/**
 * BAIT Admin - Sidebar/Header Layout (include in every admin page)
 * Usage: include __DIR__ . '/../layout/header.php';
 *
 * Variables to set before include:
 *   $pageTitle       - ชื่อหน้า (string)
 *   $activeMenu      - key ของเมนูที่ active (string)
 *   $hidePageHeader  - true to skip the auto <h2>$pageTitle</h2> block below
 *                      (still used for the <title> tag) — for pages that
 *                      render their own richer heading (e.g. a hero card
 *                      with extra meta/badges), so the title doesn't show
 *                      twice with nothing extra from the plain version.
 */

if (!isset($auth)) { require_once BASE_PATH . '/includes/Auth.php'; $auth = new Auth(); }
$auth->requireLogin();
$user = $auth->currentUser();

$menu = [
    'dashboard'   => ['icon' => 'bi-speedometer2',       'label' => 'แดชบอร์ด',         'url' => '/admin/dashboard.php',                'role' => 'all'],
    'statistics'  => ['icon' => 'bi-bar-chart-line',      'label' => 'สถิติเว็บไซต์',      'url' => '/admin/modules/statistics/index.php', 'role' => 'all',
        'children' => [
            'statistics-overview'  => ['icon' => 'bi-graph-up',     'label' => 'ภาพรวมผู้เข้าชม', 'url' => '/admin/modules/statistics/index.php'],
            'statistics-backlinks' => ['icon' => 'bi-link-45deg',   'label' => 'Backlink',        'url' => '/admin/modules/statistics/backlinks.php'],
        ]],
    'news'        => ['icon' => 'bi-newspaper',           'label' => 'ข่าวสาร',           'url' => '/admin/modules/news/index.php',       'role' => 'all'],
    'categories'  => ['icon' => 'bi-tags',                'label' => 'ประเภทข่าวสาร',     'url' => '/admin/modules/categories/index.php', 'role' => 'all'],
    'it-news'     => ['icon' => 'bi-rss',                 'label' => 'ข่าวไอที (RSS)',     'url' => '/admin/modules/it-news/index.php',    'role' => 'all'],
    'articles'    => ['icon' => 'bi-file-earmark-text',   'label' => 'บทความ',            'url' => '/admin/modules/articles/index.php',   'role' => 'all'],
    'form-categories' => ['icon' => 'bi-folder2-open',    'label' => 'หมวดหมู่แบบฟอร์ม',   'url' => '/admin/modules/form-categories/index.php', 'role' => 'all'],
    'forms'       => ['icon' => 'bi-file-earmark-arrow-down', 'label' => 'แบบฟอร์มดาวน์โหลด', 'url' => '/admin/modules/forms/index.php?audience=student', 'role' => 'all',
        'children' => [
            'forms-student' => ['icon' => 'bi-mortarboard',  'label' => 'สำหรับนักศึกษา', 'url' => '/admin/modules/forms/index.php?audience=student'],
            'forms-staff'   => ['icon' => 'bi-person-badge', 'label' => 'สำหรับบุคลากร',  'url' => '/admin/modules/forms/index.php?audience=staff'],
        ]],
    'info-links'  => ['icon' => 'bi-diagram-2',           'label' => 'สารสนเทศ',          'url' => '/admin/modules/info-links/index.php?audience=student', 'role' => 'all',
        // One sub-menu per audience: the group is chosen by where the admin clicks, never by a dropdown in the form.
        'children' => [
            'info-student' => ['icon' => 'bi-mortarboard',  'label' => 'สำหรับนักศึกษา', 'url' => '/admin/modules/info-links/index.php?audience=student'],
            'info-staff'   => ['icon' => 'bi-person-badge', 'label' => 'สำหรับบุคลากร',  'url' => '/admin/modules/info-links/index.php?audience=staff'],
        ]],
    'programs'    => ['icon' => 'bi-book',                'label' => 'หลักสูตร',          'url' => '/admin/modules/programs/index.php',   'role' => 'all',
        'children' => [
            'programs-list'    => ['icon' => 'bi-journal-text', 'label' => 'หลักสูตร', 'url' => '/admin/modules/programs/index.php'],
            'programs-tuition' => ['icon' => 'bi-cash-coin',    'label' => 'ค่าเทอม',  'url' => '/admin/modules/tuition/index.php'],
        ]],
    'majors'      => ['icon' => 'bi-diagram-3',           'label' => 'สาขาวิชา',          'url' => '/admin/modules/majors/index.php',     'role' => 'all'],
    'levels'      => ['icon' => 'bi-mortarboard',         'label' => 'ระดับการศึกษา',     'url' => '/admin/modules/levels/index.php',     'role' => 'all'],
    'faculty'     => ['icon' => 'bi-building',            'label' => 'ข้อมูลคณะ',        'url' => '/admin/modules/faculty/index.php',    'role' => 'all',
        'children' => [
            'faculty-info'      => ['icon' => 'bi-info-circle', 'label' => 'ข้อมูลคณะ',               'url' => '/admin/modules/faculty/index.php'],
            'settings-about-bg' => ['icon' => 'bi-image-alt',   'label' => 'พื้นหลัง "เกี่ยวกับคณะ"', 'url' => '/admin/modules/settings/about-bg.php'],
        ]],
    'campus'      => ['icon' => 'bi-geo-alt',             'label' => 'ศูนย์พื้นที่',      'url' => '/admin/modules/campus/index.php',     'role' => 'all'],
    'departments' => ['icon' => 'bi-diagram-2',           'label' => 'หน่วยงาน',          'url' => '/admin/modules/departments/index.php',   'role' => 'all'],
    'staff'       => ['icon' => 'bi-people',              'label' => 'บุคลากร',           'url' => '/admin/modules/staff/index.php',      'role' => 'all'],
    'staff-units' => ['icon' => 'bi-diagram-2',           'label' => 'งาน (สายสนับสนุน)', 'url' => '/admin/modules/staff-units/index.php', 'role' => 'all'],
    'positions'   => ['icon' => 'bi-person-badge',        'label' => 'ตำแหน่งบุคลากร',    'url' => '/admin/modules/positions/index.php',  'role' => 'all'],
    'staff-types' => ['icon' => 'bi-person-lines-fill',   'label' => 'ประเภทบุคลากร',     'url' => '/admin/modules/staff-types/index.php','role' => 'all'],
    'org-chart'   => ['icon' => 'bi-diagram-3-fill',      'label' => 'ผังโครงสร้างบุคลากร','url' => '/admin/modules/org-chart/index.php',  'role' => 'all'],
    'committee'   => ['icon' => 'bi-people-fill',         'label' => 'คณะกรรมการประจำคณะ','url' => '/admin/modules/committee/index.php',  'role' => 'all'],
    'student-counts' => ['icon' => 'bi-mortarboard-fill', 'label' => 'สถิตินักศึกษา',     'url' => '/admin/modules/student-counts/index.php', 'role' => 'all'],
    'staff-counts' => ['icon' => 'bi-people-fill',        'label' => 'สถิติบุคลากร',      'url' => '/admin/modules/staff-counts/index.php', 'role' => 'all'],
    'banners'     => ['icon' => 'bi-image',               'label' => 'แบนเนอร์',         'url' => '/admin/modules/banners/index.php',    'role' => 'all'],
    'popups'      => ['icon' => 'bi-window-stack',        'label' => 'ป๊อปอัพหน้าเว็บ',   'url' => '/admin/modules/popups/index.php',     'role' => 'all'],
    'partners'    => ['icon' => 'bi-building-check',       'label' => 'โลโก้พันธมิตร',    'url' => '/admin/modules/partners/index.php',   'role' => 'all'],
    'home-promo'  => ['icon' => 'bi-play-btn',            'label' => 'วิดีโอโปรโมท',     'url' => '/admin/modules/home-promo/index.php', 'role' => 'all'],
    'settings'    => ['icon' => 'bi-gear',                'label' => 'ตั้งค่าเว็บไซต์',  'url' => '/admin/modules/settings/index.php',   'role' => 'all',
        // each sub-menu is its own page with its own Save button (modules/settings/)
        'children' => [
            'settings-general'  => ['icon' => 'bi-globe',              'label' => 'ข้อมูลทั่วไป & โลโก้',     'url' => '/admin/modules/settings/index.php'],
            'settings-mourning' => ['icon' => 'bi-flower1',            'label' => 'ไว้อาลัย',              'url' => '/admin/modules/settings/mourning.php'],
            'settings-footer'   => ['icon' => 'bi-layout-text-window', 'label' => 'ข้อความ & ภาพ Footer',  'url' => '/admin/modules/settings/footer.php'],
        ]],
    'users'       => ['icon' => 'bi-person-gear',         'label' => 'จัดการผู้ใช้',     'url' => '/admin/modules/users/index.php',      'role' => 'superadmin'],
];

$db = Database::getInstance();

// Site branding (logo / name) for the sidebar + page <title>
$siteSettings = $db->fetchOne("SELECT logo, site_name, site_abbr FROM site_settings LIMIT 1") ?: [];
$brandAbbr    = $siteSettings['site_abbr'] ?? 'BAIT';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="only light">   <!-- the admin has one (light) design; stops browsers from forcing a broken dark version of it -->
<title><?= e($pageTitle ?? 'Admin') ?> | <?= e($brandAbbr) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.min.css" integrity="sha384-dCW5imOdApH6OwpFau8cZNKjqVbJYnCA5q+8YsMYP3XwXKsV6Jfz1u6MZLnXaBsS" crossorigin="anonymous">
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/ne-theme.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/ne-theme.css') ?: time() ?>">
<style>
 :root { color-scheme: only light;}
:root {
  --primary: var(--ne-blue);
  --primary-dark: var(--ne-blue-deep);
  --secondary: var(--ne-blue);
  --accent: var(--ne-gold);
  --sidebar-w: 260px;
  --sidebar-collapsed: 70px;
  --topbar-h: 60px;
}

* { box-sizing: border-box; }
body { margin:0; font-family:'Kanit','Segoe UI',sans-serif; background: var(--ne-paper); color: var(--ne-ink); }

/* ── Sidebar (mobile-first: off-canvas drawer by default, up to tablet) ── */
#sidebar {
  position: fixed; top: 0; left: 0; bottom: 0;
  width: min(var(--sidebar-w), 82vw);
  background: var(--ne-blue);
  display: flex; flex-direction: column;
  z-index: 1000; transition: transform 0.3s ease, width 0.3s ease;
  overflow: hidden;
  transform: translateX(-100%);
}
#sidebar.mobile-open { transform: translateX(0); box-shadow: 0 0 40px rgba(0,0,0,.3); }
@media (min-width: 1024px) {
  #sidebar { width: var(--sidebar-w); transform: translateX(0); }
  #sidebar.collapsed { width: var(--sidebar-collapsed); }
}

.sidebar-brand {
  padding: 1.1rem 1.2rem;
  border-bottom: 1px solid rgba(255,255,255,0.1);
  display: flex; align-items: center; gap: 0.85rem;
  min-height: var(--topbar-h); flex-shrink: 0;
}
.sidebar-brand .brand-icon {
  width: 38px; height: 38px; flex-shrink: 0;
  background: rgba(255,255,255,0.15);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; color: var(--ne-gold);
}
.sidebar-brand .brand-text { overflow: hidden; white-space: nowrap; }
.sidebar-brand .brand-text h5 { margin:0; font-size:0.9rem; font-weight:700; color:white; font-family:'Kanit',serif; }
.sidebar-brand .brand-text small { font-size:0.7rem; color:rgba(255,255,255,0.6); }

.sidebar-nav { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 0.75rem 0; }
.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 2px; }

.nav-section-label {
  font-size: 0.65rem; font-weight: 700; letter-spacing: 1.5px;
  text-transform: uppercase; color: rgba(255,255,255,0.4);
  padding: 1rem 1.2rem 0.3rem;
  white-space: nowrap; overflow: hidden;
}
#sidebar.collapsed .nav-section-label { opacity: 0; height: 0; padding: 0; }

.nav-item-link {
  display: flex; align-items: center; gap: 0.85rem;
  padding: 0.65rem 1.2rem;
  color: rgba(255,255,255,0.75);
  text-decoration: none; white-space: nowrap;
  border-left: 3px solid transparent;
  transition: all 0.2s;
  position: relative;
}
.nav-item-link:hover {
  background: rgba(255,255,255,0.08);
  color: white; border-left-color: var(--ne-gold);
}
.nav-item-link.active {
  background: rgba(251,192,45,0.18);
  color: white; border-left-color: var(--ne-gold);
  font-weight: 600;
}
.nav-item-link i { font-size: 1.1rem; flex-shrink: 0; }
.nav-item-link span { font-size: 0.88rem; overflow: hidden; }

#sidebar.collapsed .nav-item-link span,
#sidebar.collapsed .nav-item-link .badge { display: none; }

/* Sidebar group with sub-menus (สารสนเทศ → นักศึกษา / บุคลากร) */
.nav-group-toggle .nav-chev { margin-left: auto; font-size: 0.8rem; transition: transform .2s; }
.nav-group.open > .nav-group-toggle .nav-chev { transform: rotate(180deg); }
.nav-sub { display: none; background: rgba(0,0,0,0.12); }
.nav-group.open > .nav-sub { display: block; }
.nav-sub-link { display: flex; align-items: center; gap: 0.7rem; padding: 0.55rem 1.2rem 0.55rem 2.9rem; color: rgba(255,255,255,0.7); text-decoration: none; white-space: nowrap; border-left: 3px solid transparent; font-size: 0.85rem; }
.nav-sub-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
.nav-sub-link.active { color: #fff; font-weight: 600; border-left-color: var(--ne-gold); background: rgba(251,192,45,0.14); }
.nav-sub-link i { font-size: 1rem; flex-shrink: 0; }
#sidebar.collapsed .nav-sub, #sidebar.collapsed .nav-chev { display: none; }

/* Tooltip on collapsed */
#sidebar.collapsed .nav-item-link::after {
  content: attr(data-tooltip);
  position: absolute; left: calc(var(--sidebar-collapsed) + 10px); top: 50%;
  transform: translateY(-50%);
  background: var(--primary-dark); color: white;
  padding: 0.3rem 0.7rem; border-radius: 6px; font-size: 0.8rem;
  white-space: nowrap; pointer-events: none; opacity: 0;
  transition: opacity 0.2s; z-index: 999;
}
#sidebar.collapsed .nav-item-link:hover::after { opacity: 1; }

.sidebar-footer {
  border-top: 1px solid rgba(255,255,255,0.1);
  padding: 0.85rem 1.2rem;
  display: flex; align-items: center; gap: 0.85rem;
  flex-shrink: 0;
}
.sidebar-footer .avatar {
  width: 36px; height: 36px; flex-shrink: 0;
  background: var(--ne-blue-deep);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.95rem; font-weight: 700; color: white;
}
.sidebar-footer .user-info { overflow: hidden; flex: 1; }
.sidebar-footer .user-info p { margin:0; font-size:0.82rem; color:white; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sidebar-footer .user-info small { font-size:0.7rem; color:rgba(255,255,255,0.5); }
#sidebar.collapsed .user-info, #sidebar.collapsed .sidebar-footer .logout-btn span { display:none; }

/* ── Topbar (mobile-first: full width, offset only from desktop up) ── */
#topbar {
  position: fixed; top: 0;
  left: 0; right: 0;
  height: var(--topbar-h);
  background: var(--ne-surface);
  border-bottom: 1px solid var(--ne-line);
  display: flex; align-items: center; padding: 0 1rem;
  gap: .75rem; z-index: 999; transition: left 0.3s;
}
@media (min-width: 1024px) {
  #topbar { left: var(--sidebar-w); padding: 0 1.5rem; gap: 1rem; }
  #topbar.sidebar-collapsed { left: var(--sidebar-collapsed); }
}

.topbar-toggle {
  background: none; border: none; cursor: pointer;
  padding: 0.4rem; border-radius: 8px; color: var(--ne-ink-soft);
  font-size: 1.2rem; transition: background 0.2s;
}
.topbar-toggle:hover { background: var(--ne-paper); }

.breadcrumb-wrap { flex: 1; }
.breadcrumb { margin:0; padding:0; background:none; }
.breadcrumb-item { font-size: 0.85rem; }
.breadcrumb-item a { color: var(--ne-blue); text-decoration: none; }
.breadcrumb-item.active { color: var(--ne-slate); }

.topbar-actions { display: flex; align-items: center; gap: 0.75rem; }
.topbar-badge-btn {
  position: relative; background: none; border: none;
  padding: 0.4rem; font-size: 1.15rem; color: var(--ne-ink-soft);
  border-radius: 8px; cursor: pointer; transition: background 0.2s;
}
.topbar-badge-btn:hover { background: var(--ne-paper); color: var(--ne-blue); }
.topbar-badge-btn .badge-dot {
  position: absolute; top: 3px; right: 3px;
  width: 8px; height: 8px; background: var(--ne-danger); border-radius: 50%;
}

/* ── Main content (mobile-first: no sidebar offset until desktop) ── */
#mainContent {
  margin-left: 0;
  padding-top: var(--topbar-h);
  min-height: 100vh; transition: margin-left 0.3s;
}
@media (min-width: 1024px) {
  #mainContent { margin-left: var(--sidebar-w); }
  #mainContent.sidebar-collapsed { margin-left: var(--sidebar-collapsed); }
}

.content-area { padding: 1rem; }
@media (min-width: 768px) { .content-area { padding: 1.5rem; } }
@media (min-width: 1024px) { .content-area { padding: 1.75rem; } }

.page-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.75rem;
}
.page-header h2 { margin:0; font-size:1.4rem; font-weight:700; color: var(--ne-ink); font-family:'Kanit',serif; }

/* Cards */
.card { border:1px solid var(--ne-line); border-radius:14px; box-shadow:0 2px 12px rgba(30,42,69,0.05); }
.card-header-custom {
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--ne-line);
  font-weight: 600; color: var(--ne-ink);
  display: flex; align-items: center; justify-content: space-between;
}

/* Stat cards */
.stat-card {
  border-radius: 14px; padding: 1.25rem;
  color: white; position: relative; overflow: hidden;
  border: none; box-shadow: 0 4px 16px rgba(30,42,69,0.14);
  background: var(--ne-blue-deep);
}
.stat-card::after {
  content: ''; position: absolute;
  width: 100px; height: 100px; border-radius: 50%;
  background: rgba(255,255,255,0.12);
  right: -20px; top: -20px;
}
.stat-card .stat-icon {
  font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.85;
}
.stat-card .stat-num { font-size: 2rem; font-weight: 700; line-height: 1; font-family:'Kanit',serif; }
.stat-card .stat-label { font-size: 0.85rem; opacity: 0.85; margin-top: 0.25rem; }

/* Table */
.table th { font-size: 0.82rem; font-weight: 600; color: var(--ne-slate); text-transform: uppercase; letter-spacing: 0.5px; }
.table td { vertical-align: middle; font-size: 0.9rem; color: var(--ne-ink); }

/* Badges */
.status-badge {
  padding: 0.25rem 0.65rem; border-radius: 20px; font-size: 0.78rem; font-weight: 600;
}
.status-published { background: var(--ne-green-soft); color: #1f6b47; }
.status-draft     { background: var(--ne-gold-soft); color: #a06e00; }
.status-pending   { background: var(--ne-blue-soft); color: var(--ne-blue-deep); }
.status-closed    { background: var(--ne-slate-soft); color: var(--ne-ink-soft); }

/* ── Mobile/tablet drawer overlay (shown only while #sidebar.mobile-open, via JS inline style) ── */
.sidebar-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.5); z-index: 998;
}
@media (min-width: 1024px) {
  .sidebar-overlay { display: none !important; }
}
</style>
</head>
<body>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ── SIDEBAR ── -->
<nav id="sidebar">
  <!-- Brand -->
  <div class="sidebar-brand">
    <?php if (!empty($siteSettings['logo'])): ?>
    <div class="brand-icon" style="background:#fff;padding:4px;overflow:hidden">
      <img src="<?=UPLOAD_URL.'/'.e($siteSettings['logo'])?>" alt="<?=e($brandAbbr)?>"
           style="width:100%;height:100%;object-fit:contain">
    </div>
    <?php else: ?>
    <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
    <?php endif; ?>
    <div class="brand-text">
      <h5><?=e($brandAbbr)?> Admin</h5>
      <small>เว็บไซต์ประชาสัมพันธ์</small>
    </div>
  </div>

  <!-- Nav -->
  <div class="sidebar-nav">
    <div class="nav-section-label">เมนูหลัก</div>

    <?php foreach ($menu as $key => $item):
      if ($item['role'] === 'superadmin' && !$auth->isSuperAdmin()) continue;
      $isActive = ($activeMenu ?? '') === $key;
      $badge = '';
      if (!empty($item['children'])):
        $groupOpen = $isActive || array_key_exists($activeMenu ?? '', $item['children']);
    ?>
    <div class="nav-group <?= $groupOpen ? 'open' : '' ?>">
      <a href="<?= BASE_URL . $item['url'] ?>" class="nav-item-link nav-group-toggle <?= $groupOpen ? 'active' : '' ?>"
         data-tooltip="<?= e($item['label']) ?>" aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>"
         onclick="if(!document.getElementById('sidebar').classList.contains('collapsed')){event.preventDefault();var g=this.parentNode,o=!g.classList.contains('open');document.querySelectorAll('.nav-group.open').forEach(function(x){x.classList.remove('open');x.firstElementChild.setAttribute('aria-expanded','false');});if(o){g.classList.add('open');}this.setAttribute('aria-expanded',o?'true':'false');}">
        <i class="bi <?= $item['icon'] ?>"></i>
        <span><?= e($item['label']) ?></span>
        <i class="bi bi-chevron-down nav-chev"></i>
      </a>
      <div class="nav-sub">
        <?php foreach ($item['children'] as $ck => $child): ?>
        <a href="<?= BASE_URL . $child['url'] ?>" class="nav-sub-link <?= ($activeMenu ?? '') === $ck ? 'active' : '' ?>">
          <i class="bi <?= $child['icon'] ?>"></i><span><?= e($child['label']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php continue; endif; ?>
    <a href="<?= BASE_URL . $item['url'] ?>"
       class="nav-item-link <?= $isActive ? 'active' : '' ?>"
       data-tooltip="<?= e($item['label']) ?>">
      <i class="bi <?= $item['icon'] ?>"></i>
      <span><?= e($item['label']) ?></span>
      <?= $badge ?>
    </a>

    <?php if ($key === 'statistics'): ?>
    <div class="nav-section-label">จัดการเนื้อหา</div>
    <?php elseif ($key === 'programs'): ?>
    <div class="nav-section-label">ข้อมูลพื้นฐาน</div>
    <?php elseif ($key === 'banners'): ?>
    <div class="nav-section-label">ระบบ</div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- Footer / User -->
  <div class="sidebar-footer">
    <div class="avatar"><?= mb_substr($user['full_name'], 0, 1, 'UTF-8') ?></div>
    <div class="user-info">
      <p><?= e($user['full_name']) ?></p>
      <small><?= $user['role'] === 'superadmin' ? 'Super Admin' : 'Sub Admin' ?></small>
    </div>
    <a href="<?= BASE_URL ?>/admin/logout.php" onclick="event.preventDefault(); submitPostForm(this.href);" class="logout-btn ms-auto text-white opacity-75"
       title="ออกจากระบบ" style="text-decoration:none;font-size:1.1rem;">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</nav>
<script>
/* Keep the sidebar where it was: every menu click loads a new page, which would otherwise put the menu back at the top. Runs before the
   first paint (no jump). Restores the saved scroll position, then makes sure the current menu item is on screen. */
(function () {
  var nav = document.querySelector('#sidebar .sidebar-nav'); if (!nav) return;
  var KEY = 'bait_admin_nav_scroll';
  try { var saved = parseInt(window.sessionStorage.getItem(KEY), 10); if (saved > 0) nav.scrollTop = saved; } catch (e) {}
  var cur = nav.querySelector('.nav-sub-link.active') || nav.querySelector('.nav-item-link.active:not(.nav-group-toggle)') || nav.querySelector('.nav-item-link.active');
  if (cur) {
    var c = nav.getBoundingClientRect(), r = cur.getBoundingClientRect();
    if (r.top < c.top || r.bottom > c.bottom) nav.scrollTop += (r.top - c.top) - (c.height - r.height) / 2;   // centre it
  }
  function save() { try { window.sessionStorage.setItem(KEY, String(Math.round(nav.scrollTop))); } catch (e) {} }
  var t; nav.addEventListener('scroll', function () { clearTimeout(t); t = setTimeout(save, 80); }, { passive: true });
  nav.addEventListener('click', save);       // a menu link is about to load a new page
  window.addEventListener('pagehide', save);
})();
</script>

<!-- ── TOPBAR ── -->
<header id="topbar">
  <button class="topbar-toggle" onclick="toggleSidebar()" title="ย่อ/ขยายเมนู">
    <i class="bi bi-list"></i>
  </button>

  <!-- Breadcrumb -->
  <div class="breadcrumb-wrap">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/dashboard.php"><i class="bi bi-house"></i></a></li>
      <?php if (!empty($breadcrumb)):
        foreach ($breadcrumb as $bc):
          if (!empty($bc['url'])): ?>
            <li class="breadcrumb-item"><a href="<?= e($bc['url']) ?>"><?= e($bc['label']) ?></a></li>
          <?php else: ?>
            <li class="breadcrumb-item active"><?= e($bc['label']) ?></li>
          <?php endif;
        endforeach;
      endif; ?>
    </ol>
  </div>

  <!-- Actions -->
  <div class="topbar-actions">
    <!-- ดูเว็บไซต์ -->
    <a href="<?= BASE_URL ?>" target="_blank" class="topbar-badge-btn" title="ดูหน้าเว็บไซต์">
      <i class="bi bi-box-arrow-up-right"></i>
    </a>
    <!-- User dropdown -->
    <div class="dropdown">
      <button class="topbar-badge-btn dropdown-toggle" data-bs-toggle="dropdown" style="display:flex;align-items:center;gap:0.4rem;">
        <i class="bi bi-person-circle"></i>
        <span class="d-none d-md-inline" style="font-size:0.85rem;color:#1e2a45;"><?= e($user['full_name']) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width:200px">
        <li>
          <div class="px-3 py-2 border-bottom">
            <p class="mb-0 fw-600" style="font-size:0.85rem"><?= e($user['full_name']) ?></p>
            <small class="text-muted"><?= e($user['email']) ?></small>
          </div>
        </li>
        <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/modules/users/profile.php">
          <i class="bi bi-person me-2"></i>โปรไฟล์
        </a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/admin/logout.php" onclick="event.preventDefault(); submitPostForm(this.href);">
          <i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ
        </a></li>
      </ul>
    </div>
  </div>
</header>

<!-- ── MAIN CONTENT ── -->
<main id="mainContent">
<div class="content-area">
<!-- Page header -->
<?php if (!empty($pageTitle) && empty($hidePageHeader)): ?>
<div class="page-header">
  <h2><?= e($pageTitle) ?></h2>
  <?php if (!empty($pageActions)) echo $pageActions; ?>
</div>
<?php endif; ?>