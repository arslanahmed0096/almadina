<?php

namespace Tests\Unit;

use App\Exports\SupplierStatementExport;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SupplierStatementExportTest extends TestCase
{
    public function test_it_exports_the_requested_columns_running_balance_and_totals(): void
    {
        $entries = collect([
            [
                'type' => 'Purchase',
                'date' => '2026-09-07',
                'description' => 'Purchase - PR-1',
                'debit' => 0,
                'credit' => 1250.50,
                'balance' => 1250.50,
            ],
            [
                'type' => 'Supplier Payment',
                'date' => '2026-09-08',
                'description' => 'Payment for PR-1 - PAY-1',
                'debit' => 250.50,
                'credit' => 0,
                'balance' => 1000,
            ],
        ]);

        $binary = ExcelFacade::raw(
            new SupplierStatementExport('Test Supplier', $entries),
            Excel::XLSX
        );
        $path = tempnam(sys_get_temp_dir(), 'supplier-statement-');
        file_put_contents($path, $binary);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame(['Type', 'Date', 'Description', 'Debit', 'Credit', 'Balance'], $sheet->rangeToArray('A4:F4')[0]);
            $this->assertSame('Purchase', $sheet->getCell('A5')->getValue());
            $this->assertSame(1250.5, $sheet->getCell('E5')->getValue());
            $this->assertSame(1250.5, $sheet->getCell('F5')->getValue());
            $this->assertSame('TOTAL', $sheet->getCell('A7')->getValue());
            $this->assertSame(250.5, $sheet->getCell('D7')->getValue());
            $this->assertSame(1250.5, $sheet->getCell('E7')->getValue());
            $this->assertSame(1000.0, $sheet->getCell('F7')->getValue());
        } finally {
            @unlink($path);
        }
    }
}
