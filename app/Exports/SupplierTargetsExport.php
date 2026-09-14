<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SupplierTargetsExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(private array $data) {}

    public function headings(): array
    {
        return ['Supplier', 'Target', 'Period', 'Start', 'End', 'Target Units', 'Achieved', 'Remaining', 'Achievement %', 'Status'];
    }

    public function array(): array
    {
        return collect($this->data['targets'] ?? [])->map(fn ($target) => [
            $target['supplier'], $target['target_name'], ucfirst($target['period_type']),
            $target['start_date'], $target['end_date'], $target['metrics']['target'] ?? 0,
            $target['metrics']['achieved'] ?? 0, $target['metrics']['remaining'] ?? 0,
            $target['metrics']['percentage'] ?? 0, $target['metrics']['status'] ?? $target['status'],
        ])->all();
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '6F2DBD']]]];
    }
}
