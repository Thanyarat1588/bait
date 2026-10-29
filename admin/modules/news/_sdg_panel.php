<?php
/**
 * BAIT Admin - News form: the "SDGs" panel (shared by create.php and edit.php).
 * Expects $selectedSdgs (array of goal numbers). Sends sdgs[] = 1..17; a news item can have any number of goals.
 * Shows a note instead when the news_sdgs table does not exist yet (migration step 9).
 */
if (!function_exists('hasNewsSdgs')) { http_response_code(404); exit; }   // a part of the news form: opened on its own it is just "not found"
$selectedSdgs = $selectedSdgs ?? [];
?>
<style>
.sdg-pick { display: grid; grid-template-columns: repeat(auto-fill, minmax(148px, 1fr)); gap: 6px; }
.sdg-pick label { position: relative; display: flex; align-items: center; gap: 8px; min-height: 44px; padding: 6px 8px 6px 6px; border-radius: 9px;
  border: 1.5px solid #e3e6ee; background: #fff; cursor: pointer; font-size: .8rem; line-height: 1.25; color: #1e2a45; }
.sdg-pick input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.sdg-pick .sdg-n { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px;
  background: var(--sdg); color: var(--sdg-ink); font-weight: 800; font-size: .9rem; }
.sdg-pick label.is-on { border-color: var(--sdg); background: var(--sdg); color: var(--sdg-ink); }
.sdg-pick label.is-on .sdg-n { background: rgba(255, 255, 255, .25); color: inherit; }
.sdg-pick label:focus-within { outline: 3px solid #93B4F5; outline-offset: 2px; }
.sdg-pick label .bi-check-lg { margin-left: auto; display: none; }
.sdg-pick label.is-on .bi-check-lg { display: inline; }
</style>
<div class="ne-panel">
  <p class="ne-panel-title"><i class="bi bi-bullseye"></i>เป้าหมายการพัฒนาที่ยั่งยืน (SDGs)</p>
  <?php if (!hasNewsSdgs()): ?>
  <p class="ne-hint" style="margin:0;color:#b45309;font-weight:600;">ยังเลือก SDGs ไม่ได้: ต้องรันไฟล์ <code>sql/bait_migrations_safe_to_rerun.sql</code> (ข้อ 9) ใน phpMyAdmin หนึ่งครั้งก่อน</p>
  <?php else: ?>
  <p class="ne-hint" style="margin-top:0;">เลือกได้มากกว่า 1 ข้อ ไม่เลือกก็ได้ · ข่าวจะแสดงป้าย SDGs และกรองข่าวตามเป้าหมายได้ในหน้าข่าวสาร · <span id="sdgCount"><?= count($selectedSdgs) ?></span> ข้อที่เลือก</p>
  <div class="sdg-pick" role="group" aria-label="เป้าหมายการพัฒนาที่ยั่งยืน (SDGs)">
    <?php foreach (sdgList() as $no => $g): ?>
    <label class="<?= in_array($no, $selectedSdgs, true) ? 'is-on' : '' ?>" style="--sdg:<?= $g[0] ?>;--sdg-ink:<?= inkOn($g[0]) ?>" title="<?= e('SDGs ' . $no . ': ' . $g[3]) ?>">
      <input type="checkbox" name="sdgs[]" value="<?= $no ?>"<?= in_array($no, $selectedSdgs, true) ? ' checked' : '' ?>>
      <span class="sdg-n"><?= $no ?></span><span><?= e($g[1]) ?></span><i class="bi bi-check-lg" aria-hidden="true"></i>
    </label>
    <?php endforeach; ?>
  </div>
  <script>
  (function () {
    var box = document.currentScript.previousElementSibling, out = document.getElementById('sdgCount');
    box.addEventListener('change', function (ev) {
      if (ev.target.closest) ev.target.closest('label').classList.toggle('is-on', ev.target.checked);
      out.textContent = box.querySelectorAll('input:checked').length;
    });
  })();
  </script>
  <?php endif; ?>
</div>
