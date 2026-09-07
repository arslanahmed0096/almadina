<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SupplierYearComparisonExport implements FromCollection, WithEvents
{
    public function __construct(private readonly array $report)
    {
    }

    public function collection(): Collection
    {
        $yearA = $this->report['baseline_year'];
        $yearB = $this->report['comparison_year'];
        $supplier = $this->report['supplier']['name'] ?? 'All Suppliers';
        $branch = $this->report['branch']['name'] ?? 'All Branches';
        $currency = $this->report['currency'];
        $rows = collect([
            ["SUPPLIER-WISE SALES & PAYMENTS OVERVIEW  |  {$yearA} vs {$yearB}"],
            ["All figures in {$currency}  |  Supplier: {$supplier}  |  Branch: {$branch}  |  Variance = Sales - Payments"],
            ['', '', '', '', '', '', '', ''],
            ['Month', "YEAR {$yearA}", '', '', "YEAR {$yearB}", '', '', 'YoY Sales Growth %'],
            ['', 'Sales', 'Payments', 'Variance', 'Sales', 'Payments', 'Variance', ''],
        ]);

        foreach ($this->report['rows'] as $row) {
            $rows->push([
                $row['month'],
                $this->amount($row['baseline_sales']),
                $this->amount($row['baseline_payments']),
                $this->amount($row['baseline_variance']),
                $this->amount($row['comparison_sales']),
                $this->amount($row['comparison_payments']),
                $this->amount($row['comparison_variance']),
                $row['growth'] === null ? 'N/A' : $row['growth'] / 100,
            ]);
        }

        $total = $this->report['totals'];
        $rows->push([
            'TOTAL', $total['baseline_sales'], $total['baseline_payments'], $total['baseline_variance'],
            $total['comparison_sales'], $total['comparison_payments'], $total['comparison_variance'],
            $total['growth'] === null ? 'N/A' : $total['growth'] / 100,
        ]);

        $note = $this->report['comparison_through']
            ? "Note: {$yearB} figures are available through {$this->report['comparison_through']} only; totals and YoY growth reflect this partial period."
            : "Note: Totals and YoY growth compare YEAR {$yearA} with YEAR {$yearB}.";
        $rows->push([$note]);

        return $rows;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $totalRow = 18;
            $noteRow = 19;
            $sheet->mergeCells('A1:H1')->mergeCells('A2:H2');
            $sheet->mergeCells('A4:A5')->mergeCells('B4:D4')->mergeCells('E4:G4')->mergeCells('H4:H5');
            $sheet->mergeCells("A{$noteRow}:H{$noteRow}");
            $sheet->freezePane('B6');
            foreach (['A' => 16, 'B' => 18, 'C' => 18, 'D' => 18, 'E' => 18, 'F' => 18, 'G' => 18, 'H' => 19] as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }
            $navy = '244574';
            $sheet->getStyle('A1:H1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $navy]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ]);
            $sheet->getStyle('A2:H2')->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
            ]);
            $sheet->getStyle('A4:H5')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $navy]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'AAB7C9']]],
            ]);
            $sheet->getStyle("A6:H{$totalRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B8C4D4']]],
            ]);
            for ($row = 6; $row <= 17; $row++) {
                if ($row % 2 === 0) {
                    $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF0FA');
                }
                $growth = $sheet->getCell("H{$row}")->getValue();
                $color = is_numeric($growth) && $growth < 0 ? 'FBE1E1' : 'E2F0D9';
                $sheet->getStyle("H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            }
            $sheet->getStyle("A{$totalRow}:H{$totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $navy]],
            ]);
            $sheet->getStyle('B6:G18')->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
            $sheet->getStyle('H6:H18')->getNumberFormat()->setFormatCode('0%;[Red]-0%');
            $sheet->getStyle('B6:H18')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("A{$noteRow}:H{$noteRow}")->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
            ]);
            $sheet->getRowDimension(1)->setRowHeight(25);
            $sheet->getRowDimension(4)->setRowHeight(22);
            $sheet->getRowDimension(5)->setRowHeight(22);
        }];
    }

    private function amount($value)
    {
        return $value === null ? '-' : (float) $value;
    }
}
