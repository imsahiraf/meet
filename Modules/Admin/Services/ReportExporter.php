<?php

namespace Modules\Admin\Services;

use RuntimeException;
use ZipArchive;

/**
 * Exports a small report table ([[column, ...], [value, ...], ...]) as CSV, Excel (.xlsx) or PDF with no extra library:
 * the .xlsx is the standard zipped XML workbook, the PDF a plain text table on A4 pages (Helvetica).
 */
class ReportExporter
{
    /** @param array<int, array<int, scalar|null>> $rows first row = headings */
    public static function csv(array $rows): string
    {
        $f = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            // a cell starting with = + - @ would run as a formula in Excel: keep it as text
            fputcsv($f, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));
        }
        rewind($f);

        return "\xEF\xBB\xBF" . stream_get_contents($f); // BOM so Excel reads UTF-8
    }

    public static function xlsx(array $rows, string $sheet = 'Report'): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is needed for Excel files.');
        }
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26) . $s;
            }

            return $s;
        };
        $xml = '';
        foreach (array_values($rows) as $ri => $r) {
            $xml .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = $col($ci) . ($ri + 1);
                $xml .= is_int($v) || is_float($v) ? '<c r="' . $ref . '"><v>' . $v . '</v></c>'
                    : '<c r="' . $ref . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '><is><t>' . $esc($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr($sheet, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xml . '</sheetData></worksheet>');
        $z->close();
        $out = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $out;
    }

    /** A plain PDF: title, then the rows as columns of text, new A4 pages as needed. Text outside Latin-1 is shown as "?". */
    public static function pdf(array $rows, string $title): string
    {
        $txt = fn ($s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) (@iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $s) ?: preg_replace('/[^\x20-\x7E]/', '?', (string) $s)));
        $widths = [];
        foreach ($rows as $r) {
            foreach (array_values($r) as $i => $v) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $v));
            }
        }
        $total = max(1, array_sum($widths));
        $x = [50];
        foreach ($widths as $i => $w) {
            $x[$i + 1] = $x[$i] + max(60, 495 * $w / $total);
        }
        $pages = [];
        $y = 790;
        $stream = 'BT /F2 16 Tf 50 ' . $y . ' Td (' . $txt($title) . ') Tj ET' . "\n";
        $y -= 30;
        foreach (array_values($rows) as $ri => $r) {
            if ($y < 50) {
                $pages[] = $stream;
                $stream = '';
                $y = 800;
            }
            foreach (array_values($r) as $ci => $v) {
                $stream .= 'BT /' . ($ri === 0 ? 'F2' : 'F1') . ' 10 Tf ' . round($x[$ci]) . ' ' . $y . ' Td (' . $txt(mb_substr((string) $v, 0, 60)) . ') Tj ET' . "\n";
            }
            $y -= $ri === 0 ? 18 : 15;
        }
        $pages[] = $stream;
        $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        $n = 5;
        foreach ($pages as $p) {
            $objs[$n] = "<< /Length " . strlen($p) . " >>\nstream\n" . $p . "endstream";
            $objs[$n + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $n . ' 0 R >>';
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }

        return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
