<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SupplierAgingExport implements FromCollection, ShouldAutoSize, WithEvents
{
    public function __construct(private readonly array $report)
    {
    }

    public function collection(): Collection
    {
        $rows = collect([
            ['AL-MADINA ELECTRONICS PAYMENT PLAN FOR '.strtoupper($this->report['month_name'])],
            ['Dated: '.$this->report['as_of'].' | Amounts in '.$this->report['currency'].' | Overdue threshold: '.$this->report['overdue_days'].' days'],
            [''],
            ['Supplier Name', 'Total Balance', 'Due Payments', $this->report['month_name'].' Collection', 'Pending Overdue Payment'],
        ]);

        foreach ($this->report['rows'] as $row) {
            $rows->push([
                $row['supplier_name'],
                (float) $row['total_balance'],
                (float) $row['due_payments'],
                (float) $row['month_collection'],
                (float) $row['overdue_amount'],
            ]);
        }

        $totals = $this->report['totals'];
        $rows->push([
            'TOTAL',
            (float) $totals['total_balance'],
            (float) $totals['due_payments'],
            (float) $totals['month_collection'],
            (float) $totals['overdue_amount'],
        ]);
        $rows->push(['']);
        $rows->push(['OUTSTANDING INVOICE AGING DETAIL']);
        $rows->push(['Supplier Name', 'Invoice', 'Purchase Date', 'Age Days', 'Due Date', 'Days Overdue', 'Outstanding', 'Due Payment Status']);

        foreach ($this->report['rows'] as $supplier) {
            foreach ($supplier['details'] as $detail) {
                $rows->push([
                    $supplier['supplier_name'],
                    $detail['purchase_reference'],
                    $detail['purchase_date'],
                    (int) $detail['invoice_age_days'],
                    $detail['due_date'],
                    (int) $detail['days_overdue'],
                    (float) $detail['outstanding'],
                    $detail['status'],
                ]);
            }
        }

        $rows->push(['', '', '', '', '', 'TOTAL OUTSTANDING', (float) $totals['total_balance'], '']);

        return $rows;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $summaryCount = count($this->report['rows']);
            $summaryTotalRow = 5 + $summaryCount;
            $detailTitleRow = $summaryTotalRow + 2;
            $detailHeaderRow = $detailTitleRow + 1;
            $detailStartRow = $detailHeaderRow + 1;
            $detailCount = collect($this->report['rows'])->sum(fn ($row) => count($row['details']));
            $detailTotalRow = $detailStartRow + $detailCount;

            $sheet->mergeCells('A1:H1')->mergeCells('A2:H2')->mergeCells("A{$detailTitleRow}:H{$detailTitleRow}");
            $sheet->freezePane('A5');
            foreach (['A' => 34, 'B' => 18, 'C' => 16, 'D' => 20, 'E' => 24, 'F' => 15, 'G' => 18, 'H' => 28] as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }

            $green = '82BD4B';
            $red = 'D21E1E';
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '40566F']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']]],
            ];
            $sheet->getStyle('A1:H1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => $green]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getStyle('A2:H2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A4:E4')->applyFromArray($headerStyle);
            $sheet->getStyle("A{$detailTitleRow}:H{$detailTitleRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $green]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ]);
            $sheet->getStyle("A{$detailHeaderRow}:H{$detailHeaderRow}")->applyFromArray($headerStyle);
            $sheet->getStyle("A5:E{$summaryTotalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$detailStartRow}:H{$detailTotalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$summaryTotalRow}:E{$summaryTotalRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$summaryTotalRow}:E{$summaryTotalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F2DC');
            $sheet->getStyle("F{$detailTotalRow}:G{$detailTotalRow}")->getFont()->setBold(true)->getColor()->setRGB($red);
            $sheet->getStyle("B5:E{$summaryTotalRow}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
            $sheet->getStyle("G{$detailStartRow}:G{$detailTotalRow}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
            $sheet->getStyle("B5:E{$summaryTotalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("D{$detailStartRow}:G{$detailTotalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("E5:E{$summaryTotalRow}")->getFont()->getColor()->setRGB($red);
            $sheet->setAutoFilter("A4:E".max(4, $summaryTotalRow - 1));
            $sheet->getRowDimension(1)->setRowHeight(26);
        }];
    }
}
