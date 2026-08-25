<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px 45px; } body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 11px; }
        .header { border-bottom: 3px solid #1f4e78; padding-bottom: 12px; margin-bottom: 18px; }
        .brand { font-size: 22px; font-weight: bold; color: #1f4e78; } .title { float: right; font-size: 20px; font-weight: bold; }
        .meta { width: 100%; margin-bottom: 16px; } .meta td { width: 50%; vertical-align: top; padding: 3px 0; }
        table.lines { width: 100%; border-collapse: collapse; } .lines th { background: #1f4e78; color: white; padding: 7px 5px; }
        .lines td { padding: 7px 5px; border-bottom: 1px solid #d8dee9; } .num { text-align: right; }
        .totals { width: 42%; float: right; margin-top: 12px; } .totals td { padding: 4px; } .grand { font-weight: bold; border-top: 2px solid #1f4e78; }
        .notes { clear: both; padding-top: 24px; } .signature { margin-top: 45px; width: 100%; } .signature td { width: 50%; padding-top: 25px; border-top: 1px solid #777; }
        .footer { position: fixed; bottom: -28px; left: 0; right: 0; text-align: center; color: #6b7280; font-size: 9px; }
        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
@php
    $logoSrc = null;
    if (!empty($setting?->logo)) {
        $logoPath = public_path('images/'.$setting->logo);
        if (is_readable($logoPath) && ($logoData = @file_get_contents($logoPath)) !== false) {
            $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $mime = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'jpg' ? 'image/jpeg' : 'image/'.$ext);
            $logoSrc = 'data:'.$mime.';base64,'.base64_encode($logoData);
        }
    }
@endphp
<div class="header">
    @if($logoSrc)<img src="{{ $logoSrc }}" alt="Al Madina Electronics" style="max-height:52px;max-width:145px;vertical-align:middle;margin-right:12px">@endif
    <span class="brand">{{ $setting->CompanyName ?? $setting->company_name ?? 'Al Madina Electronics' }}</span>
    <span class="title">PURCHASE ORDER</span>
</div>
<table class="meta">
    <tr><td><strong>PO Number:</strong> {{ $order->number }}<br><strong>PO Date:</strong> {{ $order->order_date->format('d M Y') }}<br><strong>Expected:</strong> {{ optional($order->expected_delivery_date)->format('d M Y') ?: '—' }}</td>
        <td><strong>Supplier:</strong> {{ $order->provider->name }}<br>{{ $order->supplier_contact_snapshot }}<br><strong>Deliver to:</strong> {{ $order->warehouse->name }}</td></tr>
</table>
<table class="lines">
    <thead><tr><th>#</th><th>Product / Model</th><th>SKU</th><th>Unit</th><th class="num">Qty</th><th class="num">Unit Price</th><th class="num">Tax</th><th class="num">Total</th></tr></thead>
    <tbody>@foreach($order->items as $i => $item)<tr><td>{{ $i + 1 }}</td><td>{{ $item->product_name }}@if($item->variant_name)<br><small>{{ $item->variant_name }}</small>@endif</td><td>{{ $item->sku }}</td><td>{{ $item->unit_name }}</td><td class="num">{{ number_format($item->ordered_quantity, 2) }}</td><td class="num">{{ number_format($item->unit_price, 2) }}</td><td class="num">{{ number_format($item->tax_amount, 2) }}</td><td class="num">{{ number_format($item->line_total, 2) }}</td></tr>@endforeach</tbody>
</table>
<table class="totals"><tr><td>Subtotal</td><td class="num">{{ number_format($order->subtotal, 2) }}</td></tr><tr><td>Discount</td><td class="num">{{ number_format($order->discount_total, 2) }}</td></tr><tr><td>Tax</td><td class="num">{{ number_format($order->tax_total, 2) }}</td></tr><tr class="grand"><td>Grand Total</td><td class="num">{{ number_format($order->grand_total, 2) }}</td></tr></table>
<div class="notes">@if($order->notes)<strong>Notes</strong><p>{{ $order->notes }}</p>@endif @if($order->terms)<strong>Terms and conditions</strong><p>{!! nl2br(e($order->terms)) !!}</p>@endif</div>
<table class="signature"><tr><td>Prepared by</td><td style="text-align:right">Authorized signature</td></tr></table>
<div class="footer">{{ $order->number }} · Page <span class="page-number"></span></div>
</body></html>
