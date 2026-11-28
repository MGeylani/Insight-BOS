<?php

namespace App\Support;

use DateTimeInterface;

/** One sheet of an {@see Xlsx} workbook. */
class XlsxSheet
{
    /** @var string[] */
    private array $rows = [];

    /** @var array<int, float> */
    private array $widths = [];

    private ?array $freeze = null;

    /** @var string[] */
    private array $merges = [];

    public function __construct(private Xlsx $book, public string $name, public bool $rtl) {}

    /** @param  array<int, float|int|null>  $widths  character widths from column 1 */
    public function widths(array $widths, int $from = 1): static
    {
        foreach (array_values($widths) as $i => $w) {
            if ($w !== null) {
                $this->widths[$from + $i] = (float) $w;
            }
        }

        return $this;
    }

    /** Freeze the first $rows rows and $cols columns. */
    public function freeze(int $rows, int $cols = 0): static
    {
        $this->freeze = [$rows, $cols];

        return $this;
    }

    /** Merge a range on the next/any row, e.g. merge(1, 3, 1, 9). */
    public function merge(int $row, int $col1, int $row2, int $col2): static
    {
        $this->merges[] = Xlsx::col($col1).$row.':'.Xlsx::col($col2).$row2;

        return $this;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * Append a row. A cell is a scalar, a DateTimeInterface, null (empty) or ['v' => value, 's' => style].
     * $style applies to every cell that has no style of its own.
     */
    public function row(array $cells, array $style = [], ?float $height = null): static
    {
        $r = count($this->rows) + 1;
        $xml = '';
        foreach (array_values($cells) as $i => $cell) {
            $s = $style;
            $v = $cell;
            if (is_array($cell)) {
                $v = $cell['v'] ?? null;
                $s = array_merge($style, $cell['s'] ?? []);
            }
            if ($v instanceof DateTimeInterface && ! (is_array($cell) && isset($cell['s']['num']))) {
                $s['num'] = 'yyyy-mm-dd'; // a row-wide number format never hides a date
            }
            $ref = Xlsx::col($i + 1).$r;
            $sid = $this->book->styleId($s);
            $sa = $sid ? ' s="'.$sid.'"' : '';
            if ($v === null || $v === '') {
                if ($sid) {
                    $xml .= '<c r="'.$ref.'"'.$sa.'/>';
                }
            } elseif ($v instanceof DateTimeInterface) {
                $xml .= '<c r="'.$ref.'"'.$sa.'><v>'.Xlsx::serial($v).'</v></c>';
            } elseif (is_int($v) || is_float($v)) {
                $xml .= '<c r="'.$ref.'"'.$sa.'><v>'.(is_finite((float) $v) ? $v : 0).'</v></c>';
            } elseif (is_bool($v)) {
                $xml .= '<c r="'.$ref.'"'.$sa.' t="b"><v>'.(int) $v.'</v></c>';
            } else {
                $xml .= '<c r="'.$ref.'"'.$sa.' t="inlineStr"><is><t xml:space="preserve">'.Xlsx::esc((string) $v).'</t></is></c>';
            }
        }
        $this->rows[] = '<row r="'.$r.'"'.($height ? ' ht="'.$height.'" customHeight="1"' : '').'>'.$xml.'</row>';

        return $this;
    }

    public function xml(): string
    {
        $view = '<sheetView workbookViewId="0"'.($this->rtl ? ' rightToLeft="1"' : '').'>';
        if ($this->freeze && ($this->freeze[0] || $this->freeze[1])) {
            [$r, $c] = $this->freeze;
            $pane = $r && $c ? 'bottomRight' : ($r ? 'bottomLeft' : 'topRight');
            $view .= '<pane'.($c ? ' xSplit="'.$c.'"' : '').($r ? ' ySplit="'.$r.'"' : '').' topLeftCell="'.Xlsx::col($c + 1).($r + 1).'" activePane="'.$pane.'" state="frozen"/>';
        }
        $view .= '</sheetView>';
        $cols = '';
        if ($this->widths) {
            ksort($this->widths);
            foreach ($this->widths as $i => $w) {
                $cols .= '<col min="'.$i.'" max="'.$i.'" width="'.$w.'" customWidth="1"/>';
            }
            $cols = '<cols>'.$cols.'</cols>';
        }
        $merges = $this->merges ? '<mergeCells count="'.count($this->merges).'">'.implode('', array_map(fn ($m) => '<mergeCell ref="'.$m.'"/>', $this->merges)).'</mergeCells>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews>'.$view.'</sheetViews><sheetFormatPr defaultRowHeight="15"/>'.$cols
            .'<sheetData>'.implode('', $this->rows).'</sheetData>'.$merges.'</worksheet>';
    }
}
