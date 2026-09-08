<?php

namespace Tests\Unit;

use App\Exports\BranchYearComparisonExport;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class BranchYearComparisonExportTest extends TestCase
{
    public function test_it_exports_the_branch_comparison_layout_and_values(): void
    {
        $annualRows = [
            ['warehouse_id' => 1, 'branch' => 'Plaza', 'baseline_total' => 1000.0, 'comparison_total' => 750.0, 'difference' => -250.0, 'change_percent' => -25.0, 'trend' => 'Down'],
            ['warehouse_id' => 2, 'branch' => 'Bazar', 'baseline_total' => 500.0, 'comparison_total' => 650.0, 'difference' => 150.0, 'change_percent' => 30.0, 'trend' => 'Up'],
        ];
        $months = collect(range(1, 12))->map(function (int $month) use ($annualRows) {
            return [
                'month_number' => $month,
                'month' => date('F', mktime(0, 0, 0, $month, 1)),
                'rows' => collect($annualRows)->map(function (array $row) use ($month) {
                    if ($month > 9) {
                        $row['comparison_total'] = null;
                        $row['difference'] = -$row['baseline_total'];
                        $row['change_percent'] = -100.0;
                        $row['trend'] = 'Down';
                    }

                    return $row;
                })->all(),
            ];
        })->all();

        $report = [
            'baseline_year' => 2025,
            'comparison_year' => 2026,
            'currency' => 'PKR',
            'rows' => $annualRows,
            'months' => $months,
            'totals' => ['warehouse_id' => null, 'branch' => 'GRAND TOTAL', 'baseline_total' => 1500.0, 'comparison_total' => 1400.0, 'difference' => -100.0, 'change_percent' => -6.67, 'trend' => 'Down'],
            'comparison_through' => 'September 8, 2026',
        ];

        $binary = ExcelFacade::raw(new BranchYearComparisonExport($report), Excel::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'branch-comparison-');
        file_put_contents($path, $binary);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame('BRANCH PERFORMANCE COMPARISON  |  2025 vs 2026', $sheet->getCell('A1')->getValue());
            $this->assertStringContainsString('All figures in PKR', $sheet->getCell('A2')->getValue());
            $this->assertSame('1. ANNUAL SALES TOTAL BY BRANCH', $sheet->getCell('A4')->getValue());
            $this->assertSame(['Branch', '2025 Total Sale', '2026 Total Sale', 'Difference', '% Change', 'Trend'], $sheet->rangeToArray('A5:F5')[0]);
            $this->assertSame('Plaza', $sheet->getCell('A6')->getValue());
            $this->assertSame(-250.0, $sheet->getCell('D6')->getValue());
            $this->assertSame(-0.25, $sheet->getCell('E6')->getValue());
            $this->assertSame('▼ Down', $sheet->getCell('F6')->getValue());
            $this->assertSame('GRAND TOTAL', $sheet->getCell('A8')->getValue());
            $this->assertSame(1400.0, $sheet->getCell('C8')->getValue());
            $this->assertSame('2. MONTH-WISE BRANCH COMPARISON', $sheet->getCell('A10')->getValue());
            $this->assertSame(['Month', 'Branch', '2025 Sale', '2026 Sale', 'Difference', '% Change'], $sheet->rangeToArray('A11:F11')[0]);
            $this->assertSame('January', $sheet->getCell('A12')->getValue());
            $this->assertSame('Plaza', $sheet->getCell('B12')->getValue());
            $this->assertSame('Bazar', $sheet->getCell('B13')->getValue());
            $this->assertSame('-', $sheet->getCell('D30')->getValue());
            $this->assertSame(-1000.0, $sheet->getCell('E30')->getValue());
            $this->assertStringContainsString('available through September 8, 2026', $sheet->getCell('A36')->getValue());

            $monthlyBinary = ExcelFacade::raw(new BranchYearComparisonExport($report, 'monthly'), Excel::XLSX);
            $monthlyPath = tempnam(sys_get_temp_dir(), 'branch-monthly-');
            file_put_contents($monthlyPath, $monthlyBinary);
            try {
                $monthlySheet = IOFactory::load($monthlyPath)->getActiveSheet();
                $this->assertSame('1. MONTH-WISE BRANCH COMPARISON', $monthlySheet->getCell('A4')->getValue());
                $this->assertSame(['Month', 'Branch', '2025 Sale', '2026 Sale', 'Difference', '% Change'], $monthlySheet->rangeToArray('A5:F5')[0]);
                $this->assertSame('January', $monthlySheet->getCell('A6')->getValue());
                $this->assertSame('Plaza', $monthlySheet->getCell('B6')->getValue());
                $this->assertSame('-', $monthlySheet->getCell('D24')->getValue());
                $this->assertStringContainsString('Future months display a dash', $monthlySheet->getCell('A30')->getValue());
            } finally {
                @unlink($monthlyPath);
            }
        } finally {
            @unlink($path);
        }
    }
}
