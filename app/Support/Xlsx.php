<?php

namespace App\Support;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * A small .xlsx writer (Office Open XML) on ZipArchive — enough for exports:
 * several sheets, text / numbers / dates, fills, bold, alignment, column widths,
 * frozen panes and right-to-left sheets. No formulas, no charts.
 *
 *   $x = new Xlsx;
 *   $s = $x->sheet('المهام');
 *   $s->widths([12, 40])->row(['الرمز', 'العنوان'], ['bold' => true, 'fill' => 'F1F5F9'])->freeze(1);
 *   $s->row(['ULF-1', ['v' => 'تصميم', 's' => ['color' => 'FF0000']]]);
 *   $x->save($path);
 *
 * Style keys: bold, color (font hex), fill (hex), date (yyyy-mm-dd), num (custom format),
 * align (left|center|right), border (thin light border), size (font pt), wrap.
 */
class Xlsx
{
    /** @var XlsxSheet[] */
    private array $sheets = [];

    /** @var array<string, int> */
    private array $fonts = [];

    /** @var array<string, int> */
    private array $fills = [];

    /** @var array<string, int> */
    private array $numFmts = [];

    /** @var array<string, int> */
    private array $xfs = [];

    public function __construct()
    {
        $this->fonts[json_encode([false, null, 11])] = 0;
        $this->fills['none'] = 0;
        $this->fills['gray125'] = 1;
        $this->xfs[json_encode([0, 0, 0, 0, null, false])] = 0;
    }

    public function sheet(string $name, bool $rtl = true): XlsxSheet
    {
        // Sheet names: max 31 chars, none of : \ / ? * [ ]
        $name = mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $name), 0, 31) ?: 'Sheet'.(count($this->sheets) + 1);

        return $this->sheets[] = new XlsxSheet($this, $name, $rtl);
    }

    /** Style array → cellXfs index (registered on first use). */
    public function styleId(array $s): int
    {
        if (! $s) {
            return 0;
        }
        $font = json_encode([! empty($s['bold']), isset($s['color']) ? strtoupper($s['color']) : null, $s['size'] ?? 11]);
        $this->fonts[$font] ??= count($this->fonts);
        $fill = isset($s['fill']) ? strtoupper($s['fill']) : 'none';
        $this->fills[$fill] ??= count($this->fills);
        $fmt = $s['num'] ?? (! empty($s['date']) ? 'yyyy-mm-dd' : null);
        $numId = 0;
        if ($fmt !== null) {
            $numId = $this->numFmts[$fmt] ??= 164 + count($this->numFmts);
        }
        $key = json_encode([$this->fonts[$font], $this->fills[$fill], $numId, ! empty($s['border']) ? 1 : 0, $s['align'] ?? null, ! empty($s['wrap'])]);

        return $this->xfs[$key] ??= count($this->xfs);
    }

    public function save(string $path): void
    {
        if (! $this->sheets) {
            $this->sheet('Sheet1');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write $path");
        }
        $n = count($this->sheets);
        $overrides = '';
        $sheetsXml = '';
        $rels = '';
        foreach ($this->sheets as $i => $sheet) {
            $k = $i + 1;
            $zip->addFromString("xl/worksheets/sheet$k.xml", $sheet->xml());
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$k.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetsXml .= '<sheet name="'.self::esc($sheet->name).'" sheetId="'.$k.'" r:id="rId'.$k.'"/>';
            $rels .= '<Relationship Id="rId'.$k.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$k.'.xml"/>';
        }
        $rels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView/></bookViews>'
            .'<sheets>'.$sheetsXml.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();
    }

    private function stylesXml(): string
    {
        $numFmts = '';
        foreach ($this->numFmts as $code => $id) {
            $numFmts .= '<numFmt numFmtId="'.$id.'" formatCode="'.self::esc($code).'"/>';
        }
        $fonts = '';
        foreach (array_keys($this->fonts) as $f) {
            [$bold, $color, $size] = json_decode($f, true);
            $fonts .= '<font>'.($bold ? '<b/>' : '').'<sz val="'.$size.'"/>'.($color ? '<color rgb="FF'.$color.'"/>' : '').'<name val="Calibri"/><family val="2"/></font>';
        }
        $fills = '';
        foreach (array_keys($this->fills) as $f) {
            $fills .= in_array($f, ['none', 'gray125'], true)
                ? '<fill><patternFill patternType="'.$f.'"/></fill>'
                : '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$f.'"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $xfs = '';
        foreach (array_keys($this->xfs) as $x) {
            [$font, $fill, $num, $border, $align, $wrap] = json_decode($x, true);
            $xfs .= '<xf numFmtId="'.$num.'" fontId="'.$font.'" fillId="'.$fill.'" borderId="'.$border.'" xfId="0"'
                .($num ? ' applyNumberFormat="1"' : '').($font ? ' applyFont="1"' : '').($fill ? ' applyFill="1"' : '').($border ? ' applyBorder="1"' : '')
                .($align || $wrap ? ' applyAlignment="1"><alignment'.($align ? ' horizontal="'.$align.'"' : '').' vertical="center"'.($wrap ? ' wrapText="1"' : '').'/></xf>' : '/>');
        }
        $side = '<color rgb="FFE2E8F0"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .($numFmts ? '<numFmts count="'.count($this->numFmts).'">'.$numFmts.'</numFmts>' : '')
            .'<fonts count="'.count($this->fonts).'">'.$fonts.'</fonts>'
            .'<fills count="'.count($this->fills).'">'.$fills.'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin">'.$side.'</left><right style="thin">'.$side.'</right><top style="thin">'.$side.'</top><bottom style="thin">'.$side.'</bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($this->xfs).'">'.$xfs.'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    public static function esc(string $s): string
    {
        // Strip characters XML 1.0 does not allow.
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** 1 → A, 27 → AA. */
    public static function col(int $n): string
    {
        $s = '';
        for (; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /** Excel date serial (1900 system). */
    public static function serial(DateTimeInterface $d): float
    {
        return round(($d->getTimestamp() + $d->getOffset()) / 86400 + 25569, 6);
    }
}
