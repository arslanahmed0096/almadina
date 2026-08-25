@if(isset($taxes) && count($taxes))
<div style="margin: 10px 0 12px; page-break-inside: avoid;">
    <div style="font-size: 9pt; font-weight: bold; color: #374151; margin-bottom: 4px;">Tax breakdown</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;" cellpadding="4" cellspacing="0">
        <thead><tr style="background: #f3f4f6; color: #4b5563;">
            <th style="border: 1px solid #e5e7eb; text-align: left;">Tax</th>
            <th style="border: 1px solid #e5e7eb; text-align: left;">Price type</th>
            <th style="border: 1px solid #e5e7eb; text-align: right;">Taxable base</th>
            <th style="border: 1px solid #e5e7eb; text-align: right;">Rate/value</th>
            <th style="border: 1px solid #e5e7eb; text-align: left;">Behavior</th>
            <th style="border: 1px solid #e5e7eb; text-align: right;">Amount</th>
        </tr></thead>
        <tbody>
        @foreach($taxes->groupBy(fn($tax) => $tax->tax_code.'|'.$tax->price_type_code.'|'.$tax->behavior.'|'.$tax->rate) as $group)
            @php $tax = $group->first(); @endphp
            <tr>
                <td style="border: 1px solid #e5e7eb;">{{ $tax->tax_name }} ({{ $tax->tax_code }})</td>
                <td style="border: 1px solid #e5e7eb;">{{ $tax->price_type_name }}</td>
                <td style="border: 1px solid #e5e7eb; text-align: right;">{{ $symbol }} {{ number_format((float)$group->sum('taxable_base'), 2) }}</td>
                <td style="border: 1px solid #e5e7eb; text-align: right;">{{ $tax->calculation_type === 'percentage' ? number_format((float)$tax->rate, 2).'%' : $symbol.' '.number_format((float)$tax->rate, 2) }}</td>
                <td style="border: 1px solid #e5e7eb;">{{ ucfirst($tax->behavior) }}{{ $tax->is_reversal ? ' reversal' : '' }}</td>
                <td style="border: 1px solid #e5e7eb; text-align: right; color: {{ $tax->behavior === 'deductive' || $tax->is_reversal ? '#dc2626' : '#166534' }};">{{ $tax->behavior === 'deductive' || $tax->is_reversal ? '-' : '+' }}{{ $symbol }} {{ number_format((float)$group->sum('tax_amount'), 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
