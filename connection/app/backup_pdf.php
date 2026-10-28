<?php

// database_report.pdf inside each backup: every table, every row, credentials masked.
// Reference copy only — restore always uses database.sql.

if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', dirname(__DIR__) . '/lib/fpdf/font/');
}
require_once dirname(__DIR__) . '/lib/fpdf/fpdf.php';

const GJC_BACKUP_PDF_MASK = '/password|passwd|token|secret|hash|otp|csrf|session|login_key|(^|_)pin(_|$)/i';

class GjcBackupPdf extends FPDF
{
    public string $docTitle = '';
    public string $section = '';
    /** @var array<int,array{0:string,1:float}> current column headers + widths, repeated on each page */
    public array $cols = [];

    public function Header(): void
    {
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(110);
        $this->Cell(0, 4, gjc_pdf_text($this->docTitle . ($this->section !== '' ? '  -  ' . $this->section : '')), 0, 1);
        $this->SetTextColor(0);
        if ($this->cols) {
            $this->tableHeader();
        }
    }

    public function Footer(): void
    {
        $this->SetY(-8);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(110);
        $this->Cell(0, 4, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
        $this->SetTextColor(0);
    }

    public function tableHeader(): void
    {
        $this->SetFont('Helvetica', 'B', 6);
        $this->SetFillColor(232, 240, 233);
        foreach ($this->cols as [$label, $w]) {
            $this->Cell($w, 4.5, gjc_pdf_fit($this, $label, $w), 1, 0, 'L', true);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 6);
    }
}

/** FPDF core fonts are Windows-1252. */
function gjc_pdf_text(string $s): string
{
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
}

/** Single-line cell text, cut with "..." to fit the column. */
function gjc_pdf_fit(FPDF $pdf, string $s, float $w): string
{
    $s = gjc_pdf_text($s);
    $max = $w - 1.2;
    if ($pdf->GetStringWidth($s) <= $max) return $s;
    $s = substr($s, 0, (int) max(1, $max / 1.1)); // rough cut first, then trim exactly
    while ($s !== '' && $pdf->GetStringWidth($s . '...') > $max) {
        $s = substr($s, 0, -1);
    }
    return $s . '...';
}

function gjc_backup_build_pdf(PDO $db, string $outPath, array $manifest): void
{
    $pdf = new GjcBackupPdf('L', 'mm', 'A4');
    $pdf->SetMargins(10, 8, 10);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AliasNbPages();
    $pdf->SetTitle('GenPay database report');
    $pdf->docTitle = 'GenPay database report  -  ' . date('M j, Y g:i A', strtotime($manifest['created_at']));
    $usable = 277.0;

    // Cover + table index
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'GenPay database report', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $c = $manifest['circulation'] ?? [];
    foreach ([
        'Created' => date('F j, Y g:i A', strtotime($manifest['created_at'])),
        'Backup' => $manifest['name'] . ' (' . $manifest['label'] . ')',
        'Created by' => $manifest['created_by']['name'] ?: 'System (scheduled)',
        'Database' => $manifest['database'] . ' on MySQL ' . $manifest['mysql'],
        'Circulation cap' => $c['cap'] === null ? 'n/a' : 'PHP ' . number_format((float) $c['cap'], 2),
        'Circulation drift' => $c['drift'] === null ? 'n/a' : 'PHP ' . number_format((float) $c['drift'], 2),
    ] as $k => $v) {
        $pdf->Cell(40, 5, $k, 0, 0);
        $pdf->Cell(0, 5, gjc_pdf_text((string) $v), 0, 1);
    }
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->MultiCell(0, 4, 'Reference copy for reading only. Restores use database.sql from the same backup. '
        . 'Credential columns (passwords, PINs, tokens, hashes, session references) are masked. '
        . 'This document contains personal and financial data: store it as securely as the backup itself.');
    $pdf->Ln(2);
    $pdf->cols = [['Table', 120.0], ['Rows', 40.0]];
    $pdf->tableHeader();
    foreach ($manifest['tables'] as $t => $n) {
        $pdf->Cell(120, 4, gjc_pdf_text($t), 1);
        $pdf->Cell(40, 4, number_format($n), 1, 1, 'R');
    }
    $pdf->cols = [];

    $colStmt = $db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $binary = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'];

    foreach ($manifest['tables'] as $table => $rowCount) {
        $colStmt->execute([$table]);
        $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        $qt = '`' . str_replace('`', '``', $table) . '`';

        // Width per column from the header and a sample of rows, clamped.
        $pdf->SetFont('Helvetica', '', 6);
        $widths = [];
        $sample = $db->query("SELECT * FROM $qt LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            $name = $col['COLUMN_NAME'];
            $w = $pdf->GetStringWidth(gjc_pdf_text($name)) + 3;
            foreach ($sample as $r) {
                $w = max($w, $pdf->GetStringWidth(gjc_pdf_text(substr((string) ($r[$name] ?? ''), 0, 60))) + 2);
            }
            $widths[$name] = min(max($w, 10), 55);
        }

        // shortcut: wide tables are split into column slabs with truncated cells, upgrade to MultiCell if full text is needed.
        $slabs = [];
        $cur = [];
        $sum = 0.0;
        foreach ($cols as $col) {
            $w = $widths[$col['COLUMN_NAME']];
            if ($cur && $sum + $w > $usable) {
                $slabs[] = $cur;
                $cur = [];
                $sum = 0.0;
            }
            $cur[] = $col;
            $sum += $w;
        }
        if ($cur) $slabs[] = $cur;

        foreach ($slabs as $si => $slab) {
            $scale = $usable / array_sum(array_map(fn($c) => $widths[$c['COLUMN_NAME']], $slab));
            $scale = min($scale, 1.6);
            $pdf->section = $table . (count($slabs) > 1 ? ' (columns ' . ($si + 1) . ' of ' . count($slabs) . ')' : '');
            $pdf->cols = [];
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->Cell(0, 6, gjc_pdf_text($pdf->section) . '  -  ' . number_format($rowCount) . ' rows', 0, 1);
            $pdf->cols = array_map(fn($c) => [$c['COLUMN_NAME'], $widths[$c['COLUMN_NAME']] * $scale], $slab);
            $pdf->tableHeader();

            if ($rowCount === 0) {
                $pdf->Cell(0, 5, '(no rows)', 0, 1);
                continue;
            }

            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $rows = $db->query("SELECT * FROM $qt");
                while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
                    foreach ($pdf->cols as $i => [$name, $w]) {
                        $v = $r[$name];
                        if ($v === null) {
                            $txt = '';
                        } elseif (preg_match(GJC_BACKUP_PDF_MASK, $name)) {
                            $txt = "\u{2022}\u{2022}\u{2022}\u{2022}";
                        } elseif (in_array($slab[$i]['DATA_TYPE'], $binary, true)) {
                            $txt = '[binary]';
                        } else {
                            $txt = (string) $v;
                        }
                        $pdf->Cell($w, 4, gjc_pdf_fit($pdf, $txt, $w), 1);
                    }
                    $pdf->Ln();
                }
                $rows->closeCursor();
            } finally {
                $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
        $pdf->cols = [];
    }

    $pdf->Output('F', $outPath);
}
