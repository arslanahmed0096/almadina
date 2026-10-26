<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StockTransferLedgerExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function __construct(private array $rows, private string $type = 'transfers') {}

    public function headings(): array
    {
        return $this->type === 'ledger'
            ? ['Date', 'Type', 'Reference', 'Company', 'Warehouse', 'Debit', 'Credit', 'Running Balance', 'Notes']
            : ['Date', 'Transfer #', 'From Warehouse', 'To Warehouse', 'Company', 'Quantity', 'Transfer Value', 'Paid/Allocated', 'Outstanding', 'Payment Status'];
    }

    public function array(): array { return $this->rows; }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
