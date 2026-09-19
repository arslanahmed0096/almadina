<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $item->period->reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #222; font-size: 12px; }
        h1, h2 { margin: 0 0 8px; } .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border: 1px solid #ddd; padding: 7px; text-align: left; }
        th { background: #f2f2f2; } .right { text-align: right; }
        .totals { width: 55%; margin-left: auto; } .negative { color: #a00; }
    </style>
</head>
<body>
    <h1>{{ $item->employee->company->name ?? config('app.name') }}</h1>
    <div class="muted">Monthly Payroll Payslip</div>
    <table>
        <tr><th>Payroll period</th><td>{{ str_pad($item->period->month, 2, '0', STR_PAD_LEFT) }}/{{ $item->period->year }}</td><th>Status</th><td>{{ ucwords(str_replace('_', ' ', $item->status)) }}</td></tr>
        <tr><th>Employee</th><td>{{ $item->employee->username }}</td><th>Employee code</th><td>EMP-{{ str_pad($item->employee_id, 5, '0', STR_PAD_LEFT) }}</td></tr>
        <tr><th>Branch</th><td>{{ $item->period->warehouse->name }}</td><th>Role</th><td>{{ $item->designation->designation }}</td></tr>
        <tr><th>Generated</th><td>{{ optional($item->period->generated_at)->format('Y-m-d H:i') }}</td><th>Approved</th><td>{{ optional($item->period->approved_at)->format('Y-m-d H:i') ?: '' }}</td></tr>
    </table>
    <h2>Earnings and deductions</h2>
    <table>
        <thead><tr><th>Component</th><th>Type</th><th class="right">Amount (PKR)</th></tr></thead>
        <tbody>
        @foreach($item->components as $component)
            <tr><td>{{ $component->label }}</td><td>{{ in_array($component->type, ['absence_deduction','deductions','salary_advance','loan_recovery','commission_adjustments']) ? 'Deduction' : 'Earning' }}</td><td class="right">{{ number_format(abs($component->amount), 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><th>Gross salary</th><td class="right">{{ number_format($item->gross_salary, 2) }}</td></tr>
        <tr><th>Total deductions</th><td class="right">{{ number_format($item->total_deductions, 2) }}</td></tr>
        <tr><th>Net payable</th><td class="right">{{ number_format($item->net_payable, 2) }}</td></tr>
        <tr><th>Paid</th><td class="right">{{ number_format($item->paid_amount, 2) }}</td></tr>
        <tr><th>Remaining</th><td class="right">{{ number_format($item->remaining_amount, 2) }}</td></tr>
    </table>
    <h2>Commission summary</h2>
    <table>
        <thead><tr><th>Date</th><th>Sale</th><th>Product</th><th>Qty</th><th>Price type</th><th>Stored profit</th><th>Rate</th><th>Commission</th></tr></thead>
        <tbody>
        @forelse($item->commissionLinks as $link)
            <tr>
                <td>{{ $link->entry->commission_date->format('Y-m-d') }}</td><td>{{ $link->entry->sale->Ref ?? '' }}</td>
                <td>{{ $link->entry->product->name ?? '' }}</td><td>{{ number_format($link->entry->quantity, 2) }}</td>
                <td>{{ ucwords($link->entry->price_type) }}</td><td class="right">{{ number_format($link->entry->stored_profit_per_unit, 2) }}</td>
                <td class="right">{{ number_format($link->entry->commission_percentage, 2) }}%</td><td class="right">{{ number_format($link->amount, 2) }}</td>
            </tr>
        @empty <tr><td colspan="8">No commission entries.</td></tr> @endforelse
        </tbody>
    </table>
    <h2>Payments</h2>
    <table>
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Account</th><th class="right">Amount</th></tr></thead>
        <tbody>
        @forelse($item->payments as $payment)
            <tr><td>{{ $payment->payment_date->format('Y-m-d') }}</td><td>{{ $payment->paymentMethod->name }}</td><td>{{ $payment->transaction_reference ?: $payment->reference }}</td><td>{{ $payment->account->account_name ?? 'Cash' }}</td><td class="right">{{ number_format($payment->amount, 2) }}</td></tr>
        @empty <tr><td colspan="5">No payments recorded.</td></tr> @endforelse
        </tbody>
    </table>
</body>
</html>
