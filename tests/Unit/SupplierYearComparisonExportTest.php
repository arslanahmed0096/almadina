<?php

namespace Tests\Unit;

use App\Exports\SupplierYearComparisonExport;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SupplierYearComparisonExportTest extends TestCase
{
    public function test_it_exports_the_comparison_layout_months_totals_and_note(): void
    {
        $rows = collect(range(1, 12))->map(function (int $month) {
            return [
                'month_number' => $month,
                'month' => date('F', mktime(0, 0, 0, $month, 1)),
                'baseline_sales' => $month === 1 ? 1000.0 : 0.0,
                'baseline_payments' => $month === 1 ? 800.0 : 0.0,
                'baseline_variance' => $month === 1 ? 200.0 : 0.0,
                'comparison_sales' => $month === 1 ? 1200.0 : null,
                'comparison_payments' => $month === 1 ? 900.0 : null,
                'comparison_variance' => $month === 1 ? 300.0 : null,
                'growth' => $month === 1 ? 20.0 : 0.0,
            ];
        })->all();

        $report = [
            'baseline_year' => 2025,
            'comparison_year' => 2026,
            'supplier' => ['id' => 4, 'name' => 'Test Supplier'],
            'branch' => ['id' => 2, 'name' => 'Main Branch'],
            'currency' => 'PKR',
            'rows' => $rows,
            'totals' => [
                'baseline_sales' => 1000.0,
                'baseline_payments' => 800.0,
                'baseline_variance' => 200.0,
                'comparison_sales' => 1200.0,
                'comparison_payments' => 900.0,
                'comparison_variance' => 300.0,
                'growth' => 20.0,
            ],
            'comparison_through' => 'September 7, 2026',
        ];

        $binary = ExcelFacade::raw(new SupplierYearComparisonExport($report), Excel::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'supplier-comparison-');
        file_put_contents($path, $binary);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame('SUPPLIER-WISE SALES & PAYMENTS OVERVIEW  |  2025 vs 2026', $sheet->getCell('A1')->getValue());
            $this->assertStringContainsString('Supplier: Test Supplier', $sheet->getCell('A2')->getValue());
            $this->assertSame('YEAR 2025', $sheet->getCell('B4')->getValue());
            $this->assertSame('YEAR 2026', $sheet->getCell('E4')->getValue());
            $this->assertSame([null, 'Sales', 'Payments', 'Variance', 'Sales', 'Payments', 'Variance', null], $sheet->rangeToArray('A5:H5')[0]);
            $this->assertSame('January', $sheet->getCell('A6')->getValue());
            $this->assertSame(1200.0, $sheet->getCell('E6')->getValue());
            $this->assertSame('-', $sheet->getCell('E7')->getValue());
            $this->assertSame('TOTAL', $sheet->getCell('A18')->getValue());
            $this->assertSame(300.0, $sheet->getCell('G18')->getValue());
            $this->assertStringContainsString('partial period', $sheet->getCell('A19')->getValue());
        } finally {
            @unlink($path);
        }
    }
}
