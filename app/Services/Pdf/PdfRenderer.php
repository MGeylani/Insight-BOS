<?php

namespace App\Services\Pdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Turns branded HTML into a PDF with mPDF. mPDF is used because it shapes Arabic text and lays out
 * RTL tables correctly with the company font (Madani Arabic, TTF copies in resources/fonts).
 * Shared by every branded document (quotes now; contracts and invoices later).
 */
class PdfRenderer
{
    public function __construct(private ?string $fontDir = null, private ?string $tempDir = null)
    {
        $this->fontDir ??= resource_path('fonts');
        $this->tempDir ??= storage_path('framework/cache/mpdf');
    }

    /**
     * @param  array{title?: string, author?: string, watermark?: string|null, footer?: string|null}  $opts
     */
    public function render(string $html, array $opts = []): string
    {
        if (! is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0775, true);
        }

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 14,
            'margin_bottom' => 22,
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_footer' => 8,
            'tempDir' => $this->tempDir,
            'fontDir' => array_merge($fontDirs, [$this->fontDir]),
            'fontdata' => $fontData + [
                'madani' => [
                    'R' => 'madani-400.ttf',
                    'B' => 'madani-700.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'madani',
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
        ]);

        $mpdf->SetTitle($opts['title'] ?? '');
        $mpdf->SetAuthor($opts['author'] ?? '');
        $mpdf->SetCreator($opts['author'] ?? '');

        if (! empty($opts['watermark'])) {
            $mpdf->SetWatermarkText($opts['watermark'], 0.07);
            $mpdf->showWatermarkText = true;
            $mpdf->watermark_font = 'madani';
        }
        if (! empty($opts['footer'])) {
            $mpdf->SetHTMLFooter($opts['footer']);
        }

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** Branded footer: gradient strip, company contact line and page numbers. */
    public static function footer(string $contactLine): string
    {
        $contact = htmlspecialchars($contactLine, ENT_QUOTES, 'UTF-8');

        return '<div style="height:2.5pt;background:linear-gradient(to left,#00027C,#0082F6,#00BEF2);margin-bottom:4pt"></div>'
            .'<table width="100%" style="font-family:madani;font-size:8pt;color:#64748b"><tr>'
            .'<td style="text-align:right">'.$contact.'</td>'
            .'<td style="text-align:left;direction:ltr" width="18%">{PAGENO} / {nbpg}</td>'
            .'</tr></table>';
    }
}
