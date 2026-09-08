<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class BranchYearComparisonExport implements FromCollection, WithEvents
{
    public function __construct(private readonly array $report, private readonly string $view = 'both')
    {
    }

    public function collection(): Collection
    {
        $yearA = $this->report['baseline_year'];
        $yearB = $this->report['comparison_year'];
        $currency = $this->report['currency'];
        $rows = collect([
            ["BRANCH PERFORMANCE COMPARISON  |  {$yearA} vs {$yearB}"],
            ["All figures in {$currency}  |  Completed sales only  |  Difference = {$yearB} Total Sale - {$yearA} Total Sale"],
            ['', '', '', '', '', ''],
        ]);

        if ($this->includesAnnual()) {
            $rows->push(['1. ANNUAL SALES TOTAL BY BRANCH']);
            $rows->push(['Branch', "{$yearA} Total Sale", "{$yearB} Total Sale", 'Difference', '% Change', 'Trend']);

            foreach ($this->report['rows'] as $row) {
                $rows->push([
                    $row['branch'],
                    $row['baseline_total'],
                    $row['comparison_total'],
                    $row['difference'],
                    $row['change_percent'] / 100,
                    $this->trendLabel($row['trend']),
                ]);
            }

            $total = $this->report['totals'];
            $rows->push([
                'GRAND TOTAL',
                $total['baseline_total'],
                $total['comparison_total'],
                $total['difference'],
                $total['change_percent'] / 100,
                $this->trendLabel($total['trend']),
            ]);
        }

        if ($this->includesMonthly()) {
            if ($this->includesAnnual()) {
                $rows->push(['', '', '', '', '', '']);
            }
            $sectionNumber = $this->includesAnnual() ? 2 : 1;
            $rows->push(["{$sectionNumber}. MONTH-WISE BRANCH COMPARISON"]);
            $rows->push(['Month', 'Branch', "{$yearA} Sale", "{$yearB} Sale", 'Difference', '% Change']);

            foreach ($this->report['months'] as $month) {
                foreach ($month['rows'] as $branchIndex => $row) {
                    $rows->push([
                        $branchIndex === 0 ? $month['month'] : '',
                        $row['branch'],
                        $this->amount($row['baseline_total']),
                        $this->amount($row['comparison_total']),
                        $row['difference'],
                        $row['change_percent'] / 100,
                    ]);
                }
            }
        }

        if ($this->report['comparison_through']) {
            $note = "Note: {$yearB} figures are available through {$this->report['comparison_through']} only.";
            if ($this->includesMonthly()) {
                $note .= ' Future months display a dash.';
            }
            $rows->push([$note]);
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $layout = $this->layout();
            $navy = '244574';

            $sheet->mergeCells('A1:F1')->mergeCells('A2:F2');
            if ($layout['note']) {
                $sheet->mergeCells("A{$layout['note']}:F{$layout['note']}");
            }
            $sheet->freezePane('B6');

            foreach (['A' => 16, 'B' => 28, 'C' => 20, 'D' => 20, 'E' => 20, 'F' => 14] as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }

            $titleRanges = ['A1:F1'];
            if ($layout['annual']) {
                $sheet->mergeCells("A{$layout['annual']['title']}:F{$layout['annual']['title']}");
                $titleRanges[] = "A{$layout['annual']['title']}:F{$layout['annual']['title']}";
                $titleRanges[] = "A{$layout['annual']['header']}:F{$layout['annual']['header']}";
            }
            if ($layout['monthly']) {
                $sheet->mergeCells("A{$layout['monthly']['title']}:F{$layout['monthly']['title']}");
                $titleRanges[] = "A{$layout['monthly']['title']}:F{$layout['monthly']['title']}";
                $titleRanges[] = "A{$layout['monthly']['header']}:F{$layout['monthly']['header']}";
            }

            foreach ($titleRanges as $range) {
                $sheet->getStyle($range)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $navy]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
            }
            $sheet->getStyle('A1:F1')->getFont()->setSize(16);
            $sheet->getStyle('A2:F2')->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
            ]);

            if ($layout['annual']) {
                $this->styleAnnualSection($sheet, $layout['annual'], $navy);
            }
            if ($layout['monthly']) {
                $this->styleMonthlySection($sheet, $layout['monthly']);
            }
            if ($layout['note']) {
                $sheet->getStyle("A{$layout['note']}:F{$layout['note']}")->applyFromArray([
                    'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
                ]);
            }

            $sheet->getRowDimension(1)->setRowHeight(25);
        }];
    }

    private function styleAnnualSection($sheet, array $section, string $navy): void
    {
        $sheet->getStyle("A{$section['header']}:F{$section['header']}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$section['header']}:F{$section['total']}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B8C4D4']]],
        ]);

        for ($row = $section['first']; $row < $section['total']; $row++) {
            if (($row - $section['first']) % 2 === 0) {
                $sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF0FA');
            }
            $reportRow = $this->report['rows'][$row - $section['first']];
            $sheet->getStyle("D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->valueColor($reportRow['difference']));
            $trendColor = $reportRow['trend'] === 'Up' ? 'E2F0D9' : ($reportRow['trend'] === 'Down' ? 'FBE1E1' : 'F3F4F6');
            $sheet->getStyle("F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($trendColor);
        }

        $sheet->getStyle("A{$section['total']}:F{$section['total']}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $navy]],
        ]);
        $sheet->getStyle("B{$section['first']}:D{$section['total']}")->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00)');
        $sheet->getStyle("E{$section['first']}:E{$section['total']}")->getNumberFormat()->setFormatCode('0.0%;[Red]-0.0%');
        $sheet->getStyle("B{$section['first']}:E{$section['total']}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("F{$section['first']}:F{$section['total']}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($section['title'])->setRowHeight(22);
        $sheet->getRowDimension($section['header'])->setRowHeight(22);
    }

    private function styleMonthlySection($sheet, array $section): void
    {
        $sheet->getStyle("A{$section['header']}:F{$section['header']}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        if ($section['count'] > 0) {
            $sheet->getStyle("A{$section['header']}:F{$section['last']}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B8C4D4']]],
            ]);
        }

        $excelRow = $section['first'];
        foreach ($this->report['months'] as $month) {
            $monthStartRow = $excelRow;
            foreach ($month['rows'] as $row) {
                if ($month['month_number'] % 2 === 1) {
                    $sheet->getStyle("A{$excelRow}:D{$excelRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF0FA');
                    $sheet->getStyle("F{$excelRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF0FA');
                }
                $sheet->getStyle("E{$excelRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->valueColor($row['difference']));
                $excelRow++;
            }
            $monthEndRow = $excelRow - 1;
            if ($monthEndRow > $monthStartRow) {
                $sheet->mergeCells("A{$monthStartRow}:A{$monthEndRow}");
            }
            if ($monthEndRow >= $monthStartRow) {
                $sheet->getStyle("A{$monthStartRow}:A{$monthEndRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }
        }

        if ($section['count'] > 0) {
            $sheet->getStyle("C{$section['first']}:E{$section['last']}")->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00)');
            $sheet->getStyle("F{$section['first']}:F{$section['last']}")->getNumberFormat()->setFormatCode('0.0%;[Red]-0.0%');
            $sheet->getStyle("C{$section['first']}:F{$section['last']}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getRowDimension($section['title'])->setRowHeight(22);
        $sheet->getRowDimension($section['header'])->setRowHeight(22);
    }

    private function layout(): array
    {
        $cursor = 4;
        $annual = null;
        $monthly = null;

        if ($this->includesAnnual()) {
            $first = $cursor + 2;
            $annual = [
                'title' => $cursor,
                'header' => $cursor + 1,
                'first' => $first,
                'total' => $first + count($this->report['rows']),
            ];
            $cursor = $annual['total'] + 1;
        }

        if ($this->includesMonthly()) {
            if ($this->includesAnnual()) {
                $cursor++;
            }
            $count = collect($this->report['months'])->sum(fn ($month) => count($month['rows']));
            $first = $cursor + 2;
            $monthly = [
                'title' => $cursor,
                'header' => $cursor + 1,
                'first' => $first,
                'last' => $first + $count - 1,
                'count' => $count,
            ];
            $cursor = $monthly['last'] + 1;
        }

        return [
            'annual' => $annual,
            'monthly' => $monthly,
            'note' => $this->report['comparison_through'] ? $cursor : null,
        ];
    }

    private function includesAnnual(): bool
    {
        return $this->view === 'annual' || $this->view === 'both';
    }

    private function includesMonthly(): bool
    {
        return $this->view === 'monthly' || $this->view === 'both';
    }

    private function valueColor($value): string
    {
        return $value > 0 ? 'E2F0D9' : ($value < 0 ? 'FBE1E1' : 'F3F4F6');
    }

    private function amount($value)
    {
        return $value === null ? '-' : (float) $value;
    }

    private function trendLabel(string $trend): string
    {
        return $trend === 'Up' ? '▲ Up' : ($trend === 'Down' ? '▼ Down' : '— Flat');
    }
}
