<?php

namespace App\Support;

/**
 * Minimal .xlsx writer with no PHP extension requirements (no ext-zip / ext-gd):
 * builds the OOXML parts and packs them in an uncompressed ("stored") zip.
 * Good enough for report exports: strings, numbers, a bold first header row per sheet.
 */
class SimpleXlsx
{
    /** @var array<string, array{rows: list<list<string|int|float|null>>, bold: list<int>}> */
    private array $sheets = [];

    /**
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<int>  $boldRows  0-based row indexes to render bold (e.g. header rows)
     */
    public function addSheet(string $name, array $rows, array $boldRows = [0]): self
    {
        // Excel sheet names: max 31 chars, no []:*?/\
        $name = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', '', $name), 0, 31);
        $this->sheets[$name] = ['rows' => $rows, 'bold' => $boldRows];

        return $this;
    }

    public function output(): string
    {
        $strings = [];
        $stringIndex = function (string $s) use (&$strings): int {
            if (! array_key_exists($s, $strings)) {
                $strings[$s] = count($strings);
            }

            return $strings[$s];
        };

        $files = [];
        $i = 0;
        foreach ($this->sheets as $sheet) {
            $i++;
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($sheet['rows'] as $r => $row) {
                $xml .= '<row r="'.($r + 1).'">';
                $style = in_array($r, $sheet['bold'], true) ? ' s="1"' : '';
                foreach (array_values($row) as $c => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $ref = self::column($c).($r + 1);
                    if (is_int($value) || is_float($value)) {
                        $xml .= '<c r="'.$ref.'"'.$style.'><v>'.self::number($value).'</v></c>';
                    } else {
                        $xml .= '<c r="'.$ref.'" t="s"'.$style.'><v>'.$stringIndex((string) $value).'</v></c>';
                    }
                }
                $xml .= '</row>';
            }
            $files["xl/worksheets/sheet{$i}.xml"] = $xml.'</sheetData></worksheet>';
        }

        $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
        foreach (array_keys($strings) as $s) {
            $sst .= '<si><t xml:space="preserve">'.self::esc((string) $s).'</t></si>';
        }
        $files['xl/sharedStrings.xml'] = $sst.'</sst>';

        $sheetEntries = $rels = $overrides = '';
        $i = 0;
        foreach (array_keys($this->sheets) as $name) {
            $i++;
            $sheetEntries .= '<sheet name="'.self::esc($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $n = $i;
        $rels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId'.($n + 2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .$overrides.'</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetEntries.'</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
        $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>';

        return self::zip($files);
    }

    /** Uncompressed zip archive from name => contents. */
    public static function zip(array $files): string
    {
        $data = $central = '';
        $time = 0; // 00:00:00
        $date = ((2026 - 1980) << 9) | (1 << 5) | 1;
        foreach ($files as $name => $contents) {
            $crc = crc32($contents);
            $size = strlen($contents);
            $offset = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0).$name.$contents;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $data.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }

    private static function column(int $index): string
    {
        $col = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $col = chr(65 + (($n - 1) % 26)).$col;
        }

        return $col;
    }

    private static function number(int|float $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }

        return rtrim(rtrim(sprintf('%.2F', $v), '0'), '.') ?: '0';
    }

    private static function esc(string $s): string
    {
        // Strip characters that are invalid in XML 1.0.
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
