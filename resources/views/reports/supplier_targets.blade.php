<!doctype html>
<html>
<head>
    <meta charset='utf-8'>
    <title>Supplier Target Report</title>
    <style>
        @page { margin: 35px 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #19133c; font-size: 11px; }
        h1 { color: #6f2dbd; margin: 0 0 4px; }
        .meta { color: #666; margin-bottom: 18px; }
        .summary { width: 100%; margin-bottom: 18px; border-spacing: 8px; }
        .summary td { background: #f4edff; border-radius: 7px; padding: 12px; }
        table.report { width: 100%; border-collapse: collapse; }
        .report th, .report td { border: 1px solid #ddd7ea; padding: 7px; text-align: left; }
        .report th { color: white; background: #6f2dbd; }
        .num { text-align: right !important; }
        .footer { position: fixed; bottom: -20px; left: 0; right: 0; color: #777; font-size: 9px; }
        .page:after { content: counter(page); }
    </style>
</head>
<body>
    <h1>Al Madina Electronics</h1>
    <h2>Supplier Target versus Achievement Report</h2>
    <div class='meta'>Generated {{ now()->format('d M Y, h:i A') }}
        @if(!empty(array_filter($filters ?? [])))<br>Applied filters:
            @foreach(array_filter($filters) as $name => $value) {{ str_replace('_', ' ', ucfirst($name)) }}={{ $value }}@if(!$loop->last), @endif @endforeach
        @endif
    </div>
    <table class='summary'><tr>
        <td>Total Target<br><strong>{{ number_format($data['summary']['target'] ?? 0, 3) }}</strong></td>
        <td>Achieved<br><strong>{{ number_format($data['summary']['achieved'] ?? 0, 3) }}</strong></td>
        <td>Remaining<br><strong>{{ number_format($data['summary']['remaining'] ?? 0, 3) }}</strong></td>
        <td>Achievement<br><strong>{{ number_format($data['summary']['percentage'] ?? 0, 1) }}%</strong></td>
    </tr></table>
    <table class='report'>
        <thead><tr><th>Supplier</th><th>Target</th><th>Period</th><th>Date range</th><th class='num'>Target</th><th class='num'>Achieved</th><th class='num'>Remaining</th><th class='num'>%</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($data['targets'] ?? [] as $target)
            <tr>
                <td>{{ $target['supplier'] }}</td><td>{{ $target['target_name'] }}</td><td>{{ ucfirst($target['period_type']) }}</td>
                <td>{{ $target['start_date'] }} - {{ $target['end_date'] }}</td>
                <td class='num'>{{ number_format($target['metrics']['target'] ?? 0, 3) }}</td>
                <td class='num'>{{ number_format($target['metrics']['achieved'] ?? 0, 3) }}</td>
                <td class='num'>{{ number_format($target['metrics']['remaining'] ?? 0, 3) }}</td>
                <td class='num'>{{ number_format($target['metrics']['percentage'] ?? 0, 1) }}%</td>
                <td>{{ $target['metrics']['status'] ?? $target['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan='9'>No targets matched the selected filters.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if(collect($data['targets'] ?? [])->contains(fn ($target) => $target['period_type'] === 'annual'))
        <h2>Annual Monthly Achievement</h2>
        <table class='report'>
            <thead><tr><th>Supplier / Target</th>
                @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $month)<th class='num'>{{ $month }}</th>@endforeach
                <th class='num'>Total</th><th class='num'>Target</th><th class='num'>Remaining</th><th class='num'>%</th>
            </tr></thead>
            <tbody>
            @foreach($data['targets'] ?? [] as $target)
                @if($target['period_type'] === 'annual')
                    <tr><td>{{ $target['supplier'] }}<br>{{ $target['target_name'] }}</td>
                        @foreach($target['metrics']['monthly'] ?? [] as $month)<td class='num'>{{ number_format($month['achieved'], 3) }}</td>@endforeach
                        <td class='num'>{{ number_format($target['metrics']['achieved'] ?? 0, 3) }}</td>
                        <td class='num'>{{ number_format($target['metrics']['target'] ?? 0, 3) }}</td>
                        <td class='num'>{{ number_format($target['metrics']['remaining'] ?? 0, 3) }}</td>
                        <td class='num'>{{ number_format($target['metrics']['percentage'] ?? 0, 1) }}%</td>
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    @endif
    <h2>Warehouse Breakdown</h2>
    <table class='report'>
        <thead><tr><th>Warehouse</th><th class='num'>Allocated Target</th><th class='num'>Achieved</th><th class='num'>Remaining</th><th class='num'>Achievement %</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($data['warehouses'] ?? [] as $warehouse)
            <tr><td>{{ $warehouse['name'] }}</td>
                <td class='num'>{{ number_format($warehouse['target'], 3) }}</td>
                <td class='num'>{{ number_format($warehouse['achieved'], 3) }}</td>
                <td class='num'>{{ number_format($warehouse['remaining'], 3) }}</td>
                <td class='num'>{{ number_format($warehouse['percentage'], 1) }}%</td>
                <td>{{ $warehouse['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan='6'>No warehouse allocation data matched the filters.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Product / Category Breakdown</h2>
    <table class='report'>
        <thead><tr><th>Product / Category</th><th class='num'>Target</th><th class='num'>Achieved</th><th class='num'>Remaining</th><th class='num'>Achievement %</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($data['lines'] ?? [] as $line)
            <tr><td>{{ $line['name'] }}</td>
                <td class='num'>{{ number_format($line['target'], 3) }}</td>
                <td class='num'>{{ number_format($line['achieved'], 3) }}</td>
                <td class='num'>{{ number_format($line['remaining'], 3) }}</td>
                <td class='num'>{{ number_format($line['percentage'], 1) }}%</td>
                <td>{{ $line['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan='6'>No product/category data matched the filters.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class='footer'>Al Madina Electronics - Confidential <span style='float:right'>Page <span class='page'></span></span></div>
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
