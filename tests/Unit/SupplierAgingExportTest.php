<?php

namespace Tests\Unit;

use App\Exports\SupplierAgingExport;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SupplierAgingExportTest extends TestCase
{
    public function test_it_exports_summary_totals_and_invoice_details_to_excel(): void
    {
        $binary = ExcelFacade::raw(new SupplierAgingExport($this->report()), Excel::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'supplier-aging-');
        file_put_contents($path, $binary);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame('AL-MADINA ELECTRONICS PAYMENT PLAN FOR OCTOBER 2026', $sheet->getCell('A1')->getValue());
            $this->assertSame('Alpha Supplier', $sheet->getCell('A5')->getValue());
            $this->assertSame(800.0, $sheet->getCell('E5')->getValue());
            $this->assertSame('TOTAL', $sheet->getCell('A6')->getValue());
            $this->assertSame('OUTSTANDING INVOICE AGING DETAIL', $sheet->getCell('A8')->getValue());
            $this->assertSame('PU-001', $sheet->getCell('B10')->getValue());
            $this->assertSame(800.0, $sheet->getCell('G10')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_it_renders_the_pdf_report_template(): void
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('/api/test'));
        $html = view('reports.supplier_aging', ['report' => $this->report()])->render();

        $this->assertStringContainsString('Payment Plan for October 2026', $html);
        $this->assertStringContainsString('Alpha Supplier', $html);
        $this->assertStringContainsString('PU-001', $html);
        $this->assertStringContainsString('800.00', $html);
    }

    private function report(): array
    {
        $detail = [
            'purchase_reference' => 'PU-001', 'purchase_date' => '2026-08-01',
            'invoice_age_days' => 81, 'due_date' => '2026-08-28', 'days_overdue' => 54,
            'outstanding' => 800.0, 'status' => 'Overdue by 54 days',
            'overdue_threshold_reached' => true,
        ];

        return [
            'month_name' => 'October 2026', 'as_of' => '2026-10-21',
            'currency' => 'PKR', 'overdue_days' => 40,
            'rows' => collect([[
                'supplier_name' => 'Alpha Supplier', 'total_balance' => 1000.0,
                'due_payments' => 500.0, 'month_collection' => 200.0,
                'overdue_amount' => 800.0, 'details' => collect([$detail]),
            ]]),
            'totals' => [
                'total_balance' => 1000.0, 'due_payments' => 500.0,
                'month_collection' => 200.0, 'overdue_amount' => 800.0,
            ],
        ];
    }
}
