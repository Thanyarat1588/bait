<?php
/**
 * Shared server side of the editable statistics grids (student-counts, staff-counts).
 *
 * Model: only the CURRENT numbers are kept. The grid shows every existing row; the admin types either the change
 * (+5 / -3) or the new total, presses one Save, and only the rows that really changed are written (over the old value).
 * Before overwriting, a row remembers its previous total (prev_total) so that one row - not the whole page - can be
 * undone. `year` is no longer shown anywhere: all rows carry the latest year already in the table.
 *
 *   statGridState($db, $table)            -> ['year' => int, 'hasPrev' => bool, 'updated' => ?string]
 *   statGridHandle($db, $table, $state)   -> answers the POST actions save_bulk / undo / delete (JSON) and exits
 */

/** Latest year in the table (new rows join it), whether prev_total exists yet, and when the data was last touched. */
function statGridState(Database $db, string $table): array {
    $year = (int)($db->fetchOne("SELECT MAX(year) AS y FROM `$table`")['y'] ?? 0) ?: (int)date('Y');
    $hasPrev = (bool)$db->fetchOne("SHOW COLUMNS FROM `$table` LIKE 'prev_total'");
    $updated = $db->fetchOne("SELECT MAX(updated_at) AS u FROM `$table` WHERE year = ?", [$year])['u'] ?? null;
    return ['year' => $year, 'hasPrev' => $hasPrev, 'updated' => $updated];
}

function statGridHandle(Database $db, string $table, array $state): void {
    $action = $_POST['action'] ?? '';
    $hasPrev = $state['hasPrev'];

    if ($action === 'save_bulk') {
        $rows = json_decode(postRaw('rows', '[]'), true);
        if (!is_array($rows) || !$rows) jsonError('ไม่มีรายการที่แก้ไข');
        if (count($rows) > 500) jsonError('แก้ไขพร้อมกันได้ไม่เกิน 500 แถว');
        $clean = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['id'], $r['total'])) jsonError('ข้อมูลที่ส่งมาไม่ถูกต้อง');
            if (!is_numeric($r['id']) || !is_numeric($r['total']) || floor((float)$r['total']) != (float)$r['total']) jsonError('จำนวนต้องเป็นจำนวนเต็ม');
            $total = (int)$r['total'];
            if ($total < 0 || $total > 1000000) jsonError('จำนวนต้องเป็นตัวเลขตั้งแต่ 0 ถึง 1,000,000');
            // `old` = the number the admin was looking at when typing. If somebody else changed the row since, saving would silently overwrite their work.
            if (!isset($r['old']) || !is_numeric($r['old'])) jsonError('ข้อมูลที่ส่งมาไม่ครบ กรุณาโหลดหน้านี้ใหม่แล้วลองอีกครั้ง');   // without it a concurrent edit could not be detected
            $clean[(int)$r['id']] = ['total' => $total, 'old' => (int)$r['old']];
        }
        $changed = 0;
        $db->beginTransaction();
        try {
            // 1) lock the rows and make sure nobody changed them since the admin loaded the page; if anybody did, nothing at all is written
            $conflicts = [];
            foreach ($clean as $id => $r) {
                $row = $db->fetchOne("SELECT total FROM `$table` WHERE count_id = ? FOR UPDATE", [$id]);
                if ($row && (int)$row['total'] !== $r['old']) $conflicts[] = ['id' => $id, 'current' => (int)$row['total']];
            }
            if ($conflicts) {
                $db->rollback();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'conflict', 'message' => 'มีผู้ใช้อื่นแก้ไขตัวเลขของ ' . count($conflicts) . ' แถวไปแล้ว ยังไม่ได้บันทึกอะไร', 'conflicts' => $conflicts]);
                exit;
            }
            // 2) write
            foreach ($clean as $id => $r) {
                $total = $r['total'];
                $cur = $db->fetchOne("SELECT total FROM `$table` WHERE count_id = ?", [$id]);
                if (!$cur) continue;                                   // row deleted meanwhile
                if ((int)$cur['total'] === $total) continue;           // untouched rows are not written
                if ($hasPrev) $db->query("UPDATE `$table` SET total = ?, prev_total = ? WHERE count_id = ?", [$total, (int)$cur['total'], $id]);
                else          $db->query("UPDATE `$table` SET total = ? WHERE count_id = ?", [$total, $id]);
                $changed++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            error_log('[stat grid] save_bulk failed: ' . $e->getMessage());
            jsonError('บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        }
        jsonSuccess(['updated' => $changed], $changed ? "บันทึกแล้ว $changed แถว" : 'ไม่มีแถวที่เปลี่ยนแปลง');
    }

    if ($action === 'undo') {
        if (!$hasPrev) jsonError('ยังไม่เปิดใช้ฟังก์ชันย้อนกลับ (ต้องรัน SQL stat_counts_current.sql ก่อน)');
        $id = (int)($_POST['count_id'] ?? 0);
        // `old` = the number on screen when the admin pressed undo; if somebody changed the row since, undoing would wipe their edit
        $seen = (isset($_POST['old']) && is_numeric($_POST['old'])) ? (int)$_POST['old'] : null;
        $db->beginTransaction();
        $cur = $db->fetchOne("SELECT total, prev_total FROM `$table` WHERE count_id = ? FOR UPDATE", [$id]);
        if (!$cur) { $db->rollback(); jsonError('ไม่พบแถวนี้'); }
        if ($cur['prev_total'] === null) { $db->rollback(); jsonError('แถวนี้ไม่มีค่าก่อนหน้าให้ย้อนกลับ'); }
        if ($seen === null || (int)$cur['total'] !== $seen) { $db->rollback(); jsonError('มีผู้ใช้อื่นแก้ไขตัวเลขแถวนี้ไปแล้ว (ตอนนี้ ' . number_format((int)$cur['total']) . ') กรุณาโหลดหน้านี้ใหม่ก่อนย้อนกลับ'); }
        $db->query("UPDATE `$table` SET total = prev_total, prev_total = NULL WHERE count_id = ?", [$id]);
        $db->commit();
        jsonSuccess(['total' => (int)$cur['prev_total']], 'ย้อนกลับเป็น ' . number_format((int)$cur['prev_total']) . ' แล้ว');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['count_id'] ?? 0);
        // 0 rows = somebody else deleted it already (or a made-up id): say so instead of "deleted"
        if ($db->delete($table, 'count_id = ?', [$id]) < 1) jsonError('ไม่พบแถวนี้ (อาจถูกลบไปแล้ว) กรุณาโหลดหน้านี้ใหม่');
        jsonSuccess([], 'ลบแล้ว');
    }
}
