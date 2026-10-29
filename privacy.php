<?php
/**
 * BAIT - Privacy notice (นโยบายความเป็นส่วนตัว, PDPA). What the public site records about a visitor, why, for how long,
 * which cookies / browser storage it uses, and how to use the rights under the Personal Data Protection Act B.E. 2562.
 * The text is written here in both languages (not machine-translated): it is a legal notice and must say exactly this.
 * If the site starts collecting anything new (a form, a sign-up, analytics), this page has to be updated with it.
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

$days    = defined('PAGEVIEW_RETENTION_DAYS') ? (int)PAGEVIEW_RETENTION_DAYS : 400;   // the same setting trackPageView() uses
$contact = BASE_URL . '/contact.php?lang=' . e($lang);
$en      = $lang === 'en';

trackPageView($_SERVER['REQUEST_URI'], $en ? 'Privacy Policy' : 'นโยบายความเป็นส่วนตัว');
$pageTitle = $en ? 'Privacy Policy' : 'นโยบายความเป็นส่วนตัว';
$metaDesc  = $en
    ? 'How the website of the Faculty of Business Administration and Information Technology, RMUTSB, handles visitor data and cookies under the PDPA.'
    : 'การเก็บและใช้ข้อมูลผู้เข้าชม และคุกกี้ของเว็บไซต์คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มทร.สุวรรณภูมิ ตาม พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล';
$activePage = '';
include __DIR__ . '/layout/header.php';

$breadcrumbItems = [
    ['label' => $t('หน้าหลัก'), 'url' => BASE_URL . '/'],
    ['label' => $pageTitle],
];
$keepText = $days > 0
    ? ($en ? "{$days} days (about " . round($days / 30.4) . " months)" : "{$days} วัน (ประมาณ " . round($days / 30.4) . " เดือน)")
    : ($en ? 'the period the faculty needs for the purposes above' : 'เท่าที่จำเป็นตามวัตถุประสงค์ข้างต้น');

if ($en) {
    $updated  = 'Effective from 20 October 2026';
    $intro    = 'The Faculty of Business Administration and Information Technology, Rajamangala University of Technology Suvarnabhumi ("the faculty"), respects the privacy of everyone who visits this website. This notice explains what the website records, why, and the rights you have under the Personal Data Protection Act B.E. 2562 (2019) ("PDPA").';
    $sections = [
        ['Who is responsible for the data',
         '<p>The faculty is the data controller for the data recorded by this website. Contact details are in section 9.</p>'],
        ['What the website records',
         '<p>You can read the whole website without signing up or giving your name. When a page is opened, the website records:</p>
          <ul><li>the address and title of the page opened (including any search words that are part of the address);</li>
          <li>the date and time;</li>
          <li>the IP address of the device;</li>
          <li>the browser and device information the browser sends (the "user agent");</li>
          <li>when you arrive by clicking a link on another website: the address of that page (without its query part), so the faculty can see which websites link to it.</li></ul>
          <p>The website does not record your name, e-mail address, telephone number or location, and it has no forms that collect them. Visits by search engines and other automated programs are not recorded.</p>'],
        ['Why, and on what legal basis',
         '<ul><li>to count visitors and see which pages are used, so the website and its information can be improved;</li>
          <li>to keep the website secure and to investigate misuse or attacks.</li></ul>
          <p>The legal basis is the faculty\'s public task as a public educational institution and its legitimate interest in running a secure, useful website (PDPA section 24 (4) and (5)). The data is not used for advertising, profiling or any automated decision about you.</p>'],
        ['How long it is kept',
         '<p>Visit records are kept for ' . $keepText . ' and are then deleted automatically. Only totals (for example "visitors this month") are shown, and only to the faculty\'s website administrators; the public footer shows totals with no individual information.</p>'],
        ['Cookies and browser storage',
         '<p>The website uses only what it needs to work, so it does not ask for cookie consent. There are no advertising or cross-site tracking cookies.</p>
          <table><thead><tr><th>Name</th><th>What it is for</th><th>How long</th></tr></thead><tbody>
          <tr><td><code>lang</code></td><td>Remembers the language you chose (Thai / English)</td><td>1 year</td></tr>
          <tr><td><code>bait_v</code></td><td>Counts visitors per visit: holds only the time of the last page you opened (not who you are), so moving between pages is counted as one visit</td><td>Until the browser is closed</td></tr>
          <tr><td><code>bait_session</code></td><td>Keeps faculty administrators signed in to the administration area (only set for them)</td><td>Until sign-out or 8 hours</td></tr>
          <tr><td>Session storage</td><td>Remembers that an announcement pop-up was already shown, so it is not shown again on every page</td><td>Until the browser tab is closed</td></tr>
          </tbody></table>
          <p>You can delete or block cookies in your browser settings; the website still works, but it will not remember your language.</p>'],
        ['Services from other providers',
         '<ul><li><strong>Google Fonts</strong>: the website\'s typeface is loaded from Google, so your browser connects to Google, which receives your IP address and browser information.</li>
          <li><strong>Embedded videos</strong> (YouTube in privacy-enhanced mode, Vimeo) and <strong>links to social media</strong> (Facebook, LINE, TikTok, Instagram, X): once you play a video or open those sites, their own privacy policies apply.</li>
          <li><strong>Automatic translation</strong>: to show pages in English, the website\'s own text is sent to a translation service. No information about visitors is sent.</li></ul>'],
        ['Who can see the data',
         '<p>Only the faculty\'s website administrators, and the company that hosts the website as far as running the server requires. The faculty does not sell or pass the data to anyone else, except where the law requires it (for example a lawful request from an authority).</p>'],
        ['Your rights',
         '<p>Under the PDPA you may ask to see or receive a copy of your data, to have it corrected or deleted, to restrict its use, or object to its use, and you may complain to the Office of the Personal Data Protection Committee (PDPC). Because the website does not know visitors\' names, please give the IP address and the approximate date and time of your visit so the records can be found. The faculty will reply within 30 days.</p>'],
        ['Contact',
         '<p>For questions or requests about personal data, contact the faculty through the <a href="' . $contact . '">Contact page</a>, or the university\'s data protection officer (DPO).</p>'],
        ['Changes to this notice',
         '<p>If the website starts recording anything new, this page will be updated first. The date at the top shows when it last changed.</p>'],
    ];
} else {
    $updated  = 'มีผลตั้งแต่วันที่ 20 ตุลาคม 2569';
    $intro    = 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ ("คณะ") เคารพความเป็นส่วนตัวของผู้เข้าชมเว็บไซต์ทุกท่าน ประกาศนี้อธิบายว่าเว็บไซต์บันทึกข้อมูลอะไร เพื่ออะไร และท่านมีสิทธิอะไรบ้างตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 ("PDPA")';
    $sections = [
        ['ผู้ควบคุมข้อมูลส่วนบุคคล',
         '<p>คณะเป็นผู้ควบคุมข้อมูลส่วนบุคคลที่เว็บไซต์นี้บันทึก ช่องทางติดต่ออยู่ในข้อ 9</p>'],
        ['ข้อมูลที่เว็บไซต์บันทึก',
         '<p>ท่านอ่านเว็บไซต์ได้ทั้งหมดโดยไม่ต้องสมัครสมาชิกหรือแจ้งชื่อ เมื่อมีการเปิดหน้าเว็บ ระบบจะบันทึก</p>
          <ul><li>ที่อยู่และชื่อหน้าที่เปิด (รวมถึงคำค้นหาที่อยู่ในที่อยู่หน้า)</li>
          <li>วันและเวลา</li>
          <li>หมายเลข IP ของอุปกรณ์</li>
          <li>ข้อมูลเบราว์เซอร์และอุปกรณ์ที่เบราว์เซอร์ส่งมา (user agent)</li>
          <li>กรณีเข้ามาโดยคลิกลิงก์จากเว็บไซต์อื่นหรือจากแอป: ที่อยู่ของหน้าเว็บนั้น (ไม่รวมส่วนท้ายที่เป็นพารามิเตอร์) หรือชื่อแอป เพื่อให้คณะทราบว่ามีเว็บไซต์ใดลิงก์มาถึง พร้อมรหัสย่อที่คำนวณทางเดียวจากวันที่ หมายเลข IP และเบราว์เซอร์ ใช้เพียงเพื่อไม่นับการรีเฟรชหน้าเดิมซ้ำ</li></ul>
          <p>เว็บไซต์ไม่บันทึกชื่อ อีเมล เบอร์โทรศัพท์ หรือตำแหน่งที่ตั้งของท่าน และไม่มีแบบฟอร์มเก็บข้อมูลเหล่านี้ การเข้าชมของเครื่องมือค้นหาและโปรแกรมอัตโนมัติจะไม่ถูกบันทึก</p>'],
        ['วัตถุประสงค์และฐานทางกฎหมาย',
         '<ul><li>เพื่อนับจำนวนผู้เข้าชมและดูว่าหน้าใดถูกใช้งาน นำไปปรับปรุงเว็บไซต์และข้อมูลให้ดีขึ้น</li>
          <li>เพื่อดูแลความปลอดภัยของเว็บไซต์ และตรวจสอบการใช้งานที่ผิดปกติหรือการโจมตี</li></ul>
          <p>อาศัยฐานการปฏิบัติภารกิจเพื่อประโยชน์สาธารณะในฐานะสถาบันการศึกษาของรัฐ และฐานประโยชน์โดยชอบด้วยกฎหมายในการดูแลเว็บไซต์ให้ปลอดภัยและเป็นประโยชน์ (มาตรา 24 (4) และ (5)) ข้อมูลไม่ถูกนำไปใช้เพื่อการโฆษณา การวิเคราะห์พฤติกรรมรายบุคคล หรือการตัดสินใจอัตโนมัติใด ๆ เกี่ยวกับท่าน</p>'],
        ['ระยะเวลาการเก็บรักษา',
         '<p>ข้อมูลการเข้าชมเก็บไว้ ' . $keepText . ' แล้วระบบจะลบออกโดยอัตโนมัติ ข้อมูลแสดงผลเป็นยอดรวมเท่านั้น (เช่น "ผู้เข้าชมเดือนนี้") และให้เฉพาะผู้ดูแลเว็บไซต์ของคณะดู ส่วนท้ายเว็บไซต์แสดงเพียงยอดรวมโดยไม่มีข้อมูลรายบุคคล</p>'],
        ['คุกกี้และพื้นที่จัดเก็บในเบราว์เซอร์',
         '<p>เว็บไซต์ใช้เฉพาะคุกกี้ที่จำเป็นต่อการทำงาน จึงไม่มีการขอความยินยอมคุกกี้ และไม่มีคุกกี้โฆษณาหรือคุกกี้ติดตามข้ามเว็บไซต์</p>
          <table><thead><tr><th>ชื่อ</th><th>ใช้เพื่อ</th><th>ระยะเวลา</th></tr></thead><tbody>
          <tr><td><code>lang</code></td><td>จำภาษาที่ท่านเลือก (ไทย / อังกฤษ)</td><td>1 ปี</td></tr>
          <tr><td><code>bait_v</code></td><td>นับผู้เข้าชมเป็นรายครั้งการเข้าชม: เก็บเฉพาะเวลาที่เปิดหน้าล่าสุด (ไม่ระบุตัวตน) เพื่อให้การเปลี่ยนหน้าในการเข้าชมครั้งเดียวกันนับเป็น 1 ครั้ง</td><td>จนปิดเบราว์เซอร์</td></tr>
          <tr><td><code>bait_session</code></td><td>ให้ผู้ดูแลเว็บไซต์ของคณะอยู่ในระบบหลังบ้าน (ตั้งเฉพาะผู้ดูแลเท่านั้น)</td><td>จนออกจากระบบ หรือ 8 ชั่วโมง</td></tr>
          <tr><td>Session storage</td><td>จำว่าแสดงป๊อปอัปประกาศไปแล้ว จะได้ไม่แสดงซ้ำทุกหน้า</td><td>จนปิดแท็บเบราว์เซอร์</td></tr>
          </tbody></table>
          <p>ท่านลบหรือปิดคุกกี้ได้ในการตั้งค่าเบราว์เซอร์ เว็บไซต์ยังใช้งานได้ตามปกติ เพียงแต่จะจำภาษาที่เลือกไม่ได้</p>'],
        ['บริการของผู้ให้บริการภายนอก',
         '<ul><li><strong>Google Fonts</strong> — แบบอักษรของเว็บไซต์โหลดจาก Google เบราว์เซอร์ของท่านจึงเชื่อมต่อกับ Google ซึ่งจะได้รับหมายเลข IP และข้อมูลเบราว์เซอร์</li>
          <li><strong>วิดีโอที่ฝังในหน้า</strong> (YouTube แบบเพิ่มความเป็นส่วนตัว, Vimeo) และ<strong>ลิงก์ไปยังโซเชียลมีเดีย</strong> (Facebook, LINE, TikTok, Instagram, X) — เมื่อท่านกดเล่นวิดีโอหรือเปิดเว็บไซต์เหล่านั้น จะเป็นไปตามนโยบายความเป็นส่วนตัวของผู้ให้บริการนั้น</li>
          <li><strong>การแปลภาษาอัตโนมัติ</strong> — เพื่อแสดงหน้าเป็นภาษาอังกฤษ ระบบส่งข้อความของเว็บไซต์เองไปยังบริการแปลภาษา โดยไม่มีข้อมูลของผู้เข้าชมถูกส่งไป</li></ul>'],
        ['ผู้ที่เข้าถึงข้อมูลได้',
         '<p>เฉพาะผู้ดูแลเว็บไซต์ของคณะ และผู้ให้บริการโฮสต์เว็บไซต์เท่าที่จำเป็นต่อการดูแลเซิร์ฟเวอร์ คณะไม่ขายหรือส่งต่อข้อมูลให้ผู้อื่น เว้นแต่กฎหมายกำหนด (เช่น หน่วยงานรัฐร้องขอโดยชอบด้วยกฎหมาย)</p>'],
        ['สิทธิของเจ้าของข้อมูล',
         '<p>ตาม PDPA ท่านมีสิทธิขอเข้าถึงหรือขอรับสำเนาข้อมูล ขอแก้ไข ขอลบ ขอระงับการใช้ หรือคัดค้านการใช้ข้อมูลของท่าน และมีสิทธิร้องเรียนต่อสำนักงานคณะกรรมการคุ้มครองข้อมูลส่วนบุคคล (สคส.) เนื่องจากเว็บไซต์ไม่ทราบชื่อผู้เข้าชม กรุณาแจ้งหมายเลข IP และวันเวลาโดยประมาณที่เข้าชม เพื่อให้ค้นหาข้อมูลได้ คณะจะตอบกลับภายใน 30 วัน</p>'],
        ['ช่องทางติดต่อ',
         '<p>หากมีคำถามหรือต้องการใช้สิทธิเกี่ยวกับข้อมูลส่วนบุคคล ติดต่อคณะได้ทาง<a href="' . $contact . '">หน้าติดต่อเรา</a> หรือเจ้าหน้าที่คุ้มครองข้อมูลส่วนบุคคล (DPO) ของมหาวิทยาลัย</p>'],
        ['การเปลี่ยนแปลงประกาศนี้',
         '<p>หากเว็บไซต์เริ่มบันทึกข้อมูลอื่นเพิ่มเติม จะปรับปรุงหน้านี้ก่อนเสมอ วันที่ด้านบนแสดงวันที่แก้ไขล่าสุด</p>'],
    ];
}
?>

<section class="page-hero">
  <div class="container">
    <h1><?= e($pageTitle) ?></h1>
    <?= breadcrumb($breadcrumbItems) ?>
  </div>
</section>

<div class="container section">
  <article class="prose privacy-notice" style="max-width:820px;margin-inline:auto">
    <p class="text-muted"><small><?= e($updated) ?></small></p>
    <p><?= e($intro) ?></p>
    <?php foreach ($sections as $i => [$heading, $html]): ?>
    <h2><?= ($i + 1) . '. ' . e($heading) ?></h2>
    <?= $html ?>
    <?php endforeach; ?>
  </article>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>
