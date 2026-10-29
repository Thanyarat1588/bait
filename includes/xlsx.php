<?php
/**
 * Minimal .xlsx writer (no Composer, no ZipArchive needed - the zip container is written by hand, "stored", no compression).
 * Good enough for plain tables: text, numbers, a bold header row, column widths, several sheets.
 *
 *   $x = new SimpleXlsx();
 *   $x->addSheet('สรุป', [['หัวข้อ', 'จำนวน'], ['ผู้เข้าชม', 1234]], [30, 14]);   // sheet name, rows, column widths
 *   $x->download('report.xlsx');
 *
 * Cell values: int/float -> number (thousands separator), anything else -> text. The first row of each sheet is the header.
 */
class SimpleXlsx {
    private array $sheets = [];

    public function addSheet(string $name, array $rows, array $widths = []): void {
        $name = trim(preg_replace('/[\[\]\:\*\?\/\\\\]/u', ' ', $name));
        $name = trim($name, " '");   // a sheet name may not start or end with an apostrophe
        $name = mb_substr($name !== '' ? $name : 'Sheet' . (count($this->sheets) + 1), 0, 31, 'UTF-8');
        $taken = array_map(fn($x) => mb_strtolower($x['name'], 'UTF-8'), $this->sheets);   // Excel refuses a workbook with two sheets of the same name (any case)
        for ($i = 2, $base = $name; in_array(mb_strtolower($name, 'UTF-8'), $taken, true); $i++) $name = mb_substr($base, 0, 28, 'UTF-8') . ' ' . $i;
        $this->sheets[] = ['name' => $name, 'rows' => array_values($rows), 'widths' => $widths];
    }

    private static function esc(string $s): string {
        // control characters are illegal in XML 1.0
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function colName(int $i): string {   // 0 -> A, 26 -> AA
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private function sheetXml(array $sheet): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
           . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
           . '<sheetFormatPr defaultRowHeight="18"/>';
        if ($sheet['widths']) {
            $x .= '<cols>';
            foreach ($sheet['widths'] as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($sheet['rows'] as $r => $row) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = self::colName($c) . ($r + 1);
                if ($v === null || $v === '') { continue; }
                if ($r === 0) {
                    $x .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$v) . '</t></is></c>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '" s="2"><v>' . $v . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        return $x . '</sheetData></worksheet>';
    }

    /** Binary string of the finished .xlsx file. */
    public function build(): string {
        if (!$this->sheets) $this->addSheet('Sheet1', [['']]);
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $ct .= '</Types>';

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $wb  .= '<sheet name="' . self::esc($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            $rel .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $wb  .= '</sheets></workbook>';
        $rel .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

        // style 0 = normal, 1 = bold header on a light fill, 2 = number with thousands separator
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><name val="Tahoma"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEFB"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

        $files = [
            '[Content_Types].xml'        => $ct,
            '_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'            => $wb,
            'xl/_rels/workbook.xml.rels' => $rel,
            'xl/styles.xml'              => $styles,
        ];
        foreach ($this->sheets as $i => $s) $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheetXml($s);
        return self::zip($files);
    }

    /** Zip container, "stored" (no compression). */
    private static function zip(array $files): string {
        $out = ''; $central = ''; $count = 0;
        $dosTime = 0; $dosDate = ((2024 - 1980) << 9) | (1 << 5) | 1;
        foreach ($files as $name => $data) {
            $crc = crc32($data); $size = strlen($data); $off = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name . $data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
            $count++;
        }
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
    }

    /** Send the file to the browser and stop. */
    public function download(string $filename): void {
        $bin = $this->build();
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
        exit;
    }
}
