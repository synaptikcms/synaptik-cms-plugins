<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Form Builder Plugin — PDF Generator
 *
 * A minimal, dependency-free PDF 1.4 writer. Rather than shipping a
 * third-party library (Dompdf/TCPDF, several MB of vendored code for a
 * simple label/value recap), this builds the PDF byte structure directly:
 * objects, a cross-reference table, and a trailer, using only the
 * standard Helvetica base font — which every PDF viewer/reader has built
 * in, so no font file needs to ship with the plugin either.
 *
 * Scope is deliberately narrow: a title, a submission date, and a list of
 * "Label: value" rows with word-wrap and automatic pagination. This is
 * enough for a form recap; it is not a general-purpose PDF layout engine.
 */

if (defined('FB_PDF_LOADED')) return;
define('FB_PDF_LOADED', true);

final class FormBuilderPdf
{
    private const PAGE_WIDTH   = 595.28; // A4, points (72 dpi)
    private const PAGE_HEIGHT  = 841.89;
    private const MARGIN       = 50;
    private const LINE_HEIGHT  = 16;
    private const FONT_SIZE    = 11;
    private const TITLE_SIZE   = 16;
    private const LABEL_SIZE   = 9;

    // Helvetica average character width ratio per font size unit.
    // 0.52 is theoretically correct but real Helvetica runs wider; 0.60
    // gives a conservative maxChars that prevents lines from overflowing.
    private const CHAR_WIDTH_RATIO = 0.60;

    /** @var array<int, string> Content stream text per page, built up as we go. */
    private array $pageContents = [];

    private float $cursorY;
    private int $currentPage = -1;

    public function __construct(private readonly string $title, private readonly string $subtitle)
    {
        $this->addPage();
        $this->writeTitle();
    }

    private function addPage(): void
    {
        $this->currentPage++;
        $this->pageContents[$this->currentPage] = '';
        $this->cursorY = self::PAGE_HEIGHT - self::MARGIN;
    }

    private function writeTitle(): void
    {
        $this->drawText($this->title, self::TITLE_SIZE, true);
        // Drop by the title font size + a small gap before the subtitle.
        $this->cursorY -= self::TITLE_SIZE + 6;

        if ($this->subtitle !== '') {
            $this->drawText($this->subtitle, self::LABEL_SIZE, false, 0.45);
            $this->cursorY -= self::LABEL_SIZE + 10;
        } else {
            $this->cursorY -= 14;
        }

        $this->drawRule();
        $this->cursorY -= 16;
    }

    /**
     * Adds one "Label" + wrapped value block, starting a new page first if
     * the block would not fit on the remaining space of the current page.
     */
    public function addField(string $label, string $value): void
    {
        $wrapped     = $this->wrapText($value === '' ? '-' : $value, self::FONT_SIZE, self::PAGE_WIDTH - (self::MARGIN * 2));
        $blockHeight = self::LINE_HEIGHT + (count($wrapped) * self::LINE_HEIGHT) + 10;

        if ($this->cursorY - $blockHeight < self::MARGIN) {
            $this->addPage();
        }

        $this->drawText($label, self::LABEL_SIZE, true, 0.35);
        $this->cursorY -= self::LINE_HEIGHT - 2;

        foreach ($wrapped as $line) {
            $this->drawText($line, self::FONT_SIZE);
            $this->cursorY -= self::LINE_HEIGHT;
        }

        $this->cursorY -= 8;
    }

    /**
     * Splits $text into lines that fit within $maxWidth using a conservative
     * Helvetica character width estimate. Words longer than maxChars are
     * hard-broken character by character so they never overflow the margin.
     */
    private function wrapText(string $text, int $fontSize, float $maxWidth): array
    {
        $avgCharWidth = $fontSize * self::CHAR_WIDTH_RATIO;
        $maxChars     = max(10, (int)floor($maxWidth / $avgCharWidth));

        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            if (trim($paragraph) === '') {
                $lines[] = '';
                continue;
            }

            $words   = preg_split('/\s+/', trim($paragraph));
            $current = '';

            foreach ($words as $word) {
                // Hard-break words that are longer than the line width on their own.
                while (mb_strlen($word) > $maxChars) {
                    $chunk = mb_substr($word, 0, $maxChars - mb_strlen($current) - ($current !== '' ? 1 : 0));
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                        $chunk   = mb_substr($word, 0, $maxChars);
                    }
                    $lines[] = $chunk;
                    $word    = mb_substr($word, mb_strlen($chunk));
                }

                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if (mb_strlen($candidate) > $maxChars && $current !== '') {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines ?: [''];
    }

    private function drawRule(): void
    {
        $y = $this->cursorY;
        $this->pageContents[$this->currentPage] .= sprintf(
            "0.75 G\n%.2f w\n%.2f %.2f m %.2f %.2f l S\n",
            0.75,
            self::MARGIN, $y,
            self::PAGE_WIDTH - self::MARGIN, $y
        );
    }

    /**
     * Emits a single line of text at the current cursor position.
     * $gray: 0 = black, 1 = white; used for slightly muted label/subtitle text.
     */
    private function drawText(string $text, int $fontSize, bool $bold = false, float $gray = 0.0): void
    {
        $font    = $bold ? 'F2' : 'F1';
        $escaped = $this->escapePdfString($text);

        $this->pageContents[$this->currentPage] .= sprintf(
            "BT\n%.2f g\n/%s %d Tf\n%.2f %.2f Td\n(%s) Tj\nET\n",
            $gray, $font, $fontSize, self::MARGIN, $this->cursorY, $escaped
        );
    }

    /**
     * Escapes a string for use inside a PDF literal string (...) token, and
     * transliterates to Latin-1 (PDFDocEncoding-compatible) since the
     * standard Helvetica base font has no embedded Unicode CMap — accented
     * characters render correctly this way, symbols outside Latin-1
     * (emoji, CJK, etc.) degrade to '?' rather than corrupting the file.
     */
    private function escapePdfString(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text);
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    /**
     * Assembles the full PDF byte stream: catalog, page tree, font
     * resources, one content stream per page, cross-reference table, trailer.
     */
    public function output(): string
    {
        $objects = [];

        // 1: Catalog, 2: Pages (filled below), 3/4: Fonts
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $pageObjNums = [];
        $nextObjNum  = 5;

        foreach ($this->pageContents as $content) {
            $contentObjNum = $nextObjNum++;
            $pageObjNum    = $nextObjNum++;

            $stream = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objects[$contentObjNum] = $stream;

            $objects[$pageObjNum] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                self::PAGE_WIDTH, self::PAGE_HEIGHT, $contentObjNum
            );

            $pageObjNums[] = $pageObjNum;
        }

        $kids       = implode(' ', array_map(fn($n) => "{$n} 0 R", $pageObjNums));
        $objects[2] = sprintf("<< /Type /Pages /Kids [%s] /Count %d >>", $kids, count($pageObjNums));
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        ksort($objects);

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $maxObjNum = max(array_keys($objects));

        $pdf .= "xref\n0 " . ($maxObjNum + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObjNum; $i++) {
            if (isset($offsets[$i])) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }

        $pdf .= "trailer\n<< /Size " . ($maxObjNum + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefStart}\n%%EOF";

        return $pdf;
    }
}

/**
 * Builds the PDF recap for a form submission: title (form name), submission
 * date, and one "Label: value" block per field, in the form's own field
 * order. Dropdown/text values are rendered as-is; checkbox values are
 * translated to a localized Yes/No via $checkedLabel/$uncheckedLabel so the
 * recap never shows a raw "1"/"" to a human reading the PDF.
 */
function fb_build_submission_pdf(array $form, array $values, string $submittedAt, string $checkedLabel, string $uncheckedLabel): string
{
    $dateFormatted = $submittedAt;
    $ts = strtotime($submittedAt);
    if ($ts !== false) {
        $dateFormatted = date('Y-m-d H:i', $ts);
    }

    $pdf = new FormBuilderPdf($form['name'] ?? 'Form submission', $dateFormatted);

    foreach ($form['fields'] ?? [] as $field) {
        if ($field['type'] === 'separator') {
            continue;
        }

        $key   = $field['key'];
        $label = $field['label'] !== '' ? $field['label'] : $key;
        $value = $values[$key] ?? '';

        if ($field['type'] === 'checkbox') {
            $value = !empty($value) ? $checkedLabel : $uncheckedLabel;
        } elseif (is_array($value)) {
            $value = implode(', ', $value);
        }

        $pdf->addField((string)$label, (string)$value);
    }

    return $pdf->output();
}
