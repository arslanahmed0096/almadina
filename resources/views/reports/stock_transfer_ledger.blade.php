<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#222}h2{text-align:center;margin:0 0 14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #555;padding:5px}th{background:#eee;text-align:left}.num{text-align:right}
</style></head><body>
<h2>Stock Transfer {{ $type === 'ledger' ? 'Financial Ledger' : 'Ledger Report' }}</h2>
<table><thead><tr>
@foreach(($type === 'ledger' ? ['Date','Type','Reference','Company','Warehouse','Debit','Credit','Balance','Notes'] : ['Date','Transfer #','From','To','Company','Quantity','Transfer Value','Paid','Outstanding','Status']) as $heading)<th>{{ $heading }}</th>@endforeach
</tr></thead><tbody>
@forelse($rows as $row)<tr>@foreach($row as $index => $value)<td class="{{ $index >= ($type === 'ledger' ? 5 : 5) && $index <= ($type === 'ledger' ? 7 : 8) ? 'num' : '' }}">{{ is_numeric($value) ? number_format((float)$value, 2) : $value }}</td>@endforeach</tr>@empty<tr><td colspan="10">No records found.</td></tr>@endforelse
</tbody></table></body></html>
