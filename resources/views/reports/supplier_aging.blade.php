<!doctype html>
<html>
<head>
    <meta charset='utf-8'>
    <title>Supplier Aging Report</title>
    <style>
        @page { margin: 24px 22px; }
        body { color: #111; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { color: #82bd4b; font-size: 18px; margin: 0; text-align: center; }
        .meta { color: #666; margin: 3px 0 12px; text-align: right; }
        .section { background: #f1f3f5; border: 1px solid #222; font-size: 12px; font-weight: bold; margin-top: 16px; padding: 6px; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th, td { border: 1px solid #222; padding: 5px 6px; vertical-align: middle; }
        th { background: #f5f6f7; font-weight: bold; }
        .num { text-align: right; }
        .center { text-align: center; }
        .supplier { font-weight: bold; }
        .green { color: #82bd4b; }
        .red { color: #d21e1e; font-weight: bold; }
        .small { color: #666; font-size: 7px; }
        .summary th:first-child { width: 24%; }
        .summary th:nth-child(2) { width: 16%; }
        .summary th:nth-child(3) { width: 15%; }
        .summary th:nth-child(4) { width: 17%; }
        .summary th:nth-child(5) { width: 28%; }
        .detail th:nth-child(1) { width: 21%; }
        .detail th:nth-child(2) { width: 12%; }
        .detail th:nth-child(3) { width: 11%; }
        .detail th:nth-child(4) { width: 9%; }
        .detail th:nth-child(5) { width: 11%; }
        .detail th:nth-child(6) { width: 9%; }
        .detail th:nth-child(7) { width: 13%; }
        .detail th:nth-child(8) { width: 14%; }
        tr { page-break-inside: avoid; }
        .footer { bottom: -12px; color: #777; font-size: 7px; left: 0; position: fixed; right: 0; }
        .page:after { content: counter(page); }
    </style>
</head>
<body>
    <h1>Al-Madina Electronics Payment Plan for {{ $report['month_name'] }} (Dated: {{ \Carbon\Carbon::parse($report['as_of'])->format('d-M-Y') }})</h1>
    <div class='meta'>Amounts in {{ $report['currency'] }} | Overdue threshold: {{ $report['overdue_days'] }} days</div>

    <table class='summary'>
        <thead><tr>
            <th>Supplier Name</th>
            <th class='num'>Total Balance</th>
            <th class='num'>Due Payments</th>
            <th class='num green'>{{ $report['month_name'] }} Collection</th>
            <th class='num red'>Pending Overdue Payment ({{ $report['overdue_days'] }} &amp; Above Days)</th>
        </tr></thead>
        <tbody>
        @forelse($report['rows'] as $row)
            <tr>
                <td class='supplier'>{{ $row['supplier_name'] }}</td>
                <td class='num'>{{ number_format($row['total_balance'], 2) }}</td>
                <td class='num'>{{ number_format($row['due_payments'], 2) }}</td>
                <td class='num green'>{{ number_format($row['month_collection'], 2) }}</td>
                <td class='num {{ $row['overdue_amount'] > 0 ? 'red' : '' }}'>{{ number_format($row['overdue_amount'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan='5' class='center'>No supplier balances or collections found.</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr>
            <th>Total</th>
            <th class='num'>{{ number_format($report['totals']['total_balance'], 2) }}</th>
            <th class='num'>{{ number_format($report['totals']['due_payments'], 2) }}</th>
            <th class='num green'>{{ number_format($report['totals']['month_collection'], 2) }}</th>
            <th class='num red'>{{ number_format($report['totals']['overdue_amount'], 2) }}</th>
        </tr></tfoot>
    </table>

    <div class='section'>Outstanding Invoice Aging Detail</div>
    <table class='detail'>
        <thead><tr>
            <th>Supplier Name</th><th>Invoice</th><th>Purchase Date</th><th class='num'>Age Days</th>
            <th>Due Date</th><th class='num'>Overdue</th><th class='num'>Amount</th><th>Due Payment Status</th>
        </tr></thead>
        <tbody>
        @forelse(collect($report['rows'])->flatMap(fn ($supplier) => collect($supplier['details'])->map(fn ($detail) => ['supplier' => $supplier['supplier_name'], 'detail' => $detail])) as $item)
            <tr>
                <td class='supplier'>{{ $item['supplier'] }}</td>
                <td>{{ $item['detail']['purchase_reference'] }}</td>
                <td>{{ $item['detail']['purchase_date'] }}</td>
                <td class='num'>{{ $item['detail']['invoice_age_days'] }}</td>
                <td>{{ $item['detail']['due_date'] }}</td>
                <td class='num {{ $item['detail']['overdue_threshold_reached'] ? 'red' : '' }}'>{{ $item['detail']['days_overdue'] }}</td>
                <td class='num {{ $item['detail']['overdue_threshold_reached'] ? 'red' : '' }}'>{{ number_format($item['detail']['outstanding'], 2) }}</td>
                <td>{{ $item['detail']['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan='8' class='center'>No outstanding supplier invoices found.</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr><th colspan='6' class='num'>Total Outstanding Payment</th><th class='num red'>{{ number_format($report['totals']['total_balance'], 2) }}</th><th></th></tr></tfoot>
    </table>
    <div class='footer'>Al-Madina Electronics - Supplier Aging Report <span style='float:right'>Page <span class='page'></span></span></div>
</body>
</html>
