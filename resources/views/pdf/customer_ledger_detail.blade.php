@php
    $priceFormat = $settings->price_format ?? 'comma_dot';
    $money = function ($value) use ($priceFormat) {
        $decimal = $priceFormat === 'dot_comma' || $priceFormat === 'space_comma' ? ',' : '.';
        $thousands = $priceFormat === 'dot_comma' ? '.' : ($priceFormat === 'space_comma' ? ' ' : ',');
        return number_format((float) $value, 2, $decimal, $thousands);
    };
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    $rate = fn ($value) => number_format((float) $value, 2, '.', ',');
@endphp
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Customer General Ledger - {{ $client->name }}</title>
  <style>
    @page { size: A4 landscape; margin: 28px 24px 36px; }
    body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 8px; }
    h1 { text-align: center; font-size: 15px; margin: 0 0 20px; }
    .account { width: 100%; margin-bottom: 8px; border-collapse: collapse; }
    .account td { padding: 2px 0; vertical-align: top; }
    .account .right { text-align: right; }
    .statement { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .statement thead { display: table-header-group; }
    .statement th {
      border-top: 1px solid #222;
      border-bottom: 1px solid #222;
      text-align: left;
      padding: 4px 2px;
      font-size: 7.5px;
    }
    .statement td { padding: 4px 2px; vertical-align: top; overflow-wrap: break-word; }
    .statement .num { text-align: right; white-space: nowrap; }
    .entry { border-top: 1px dashed #888; }
    .entry td { padding-top: 6px; }
    .detail td { padding-top: 1px; padding-bottom: 1px; }
    .detail .description { padding-left: 8px; white-space: nowrap; overflow-wrap: normal; font-size: 7px; }
    .detail .amount { border-bottom: 1px solid #777; }
    .product-heading td { padding-top: 4px; padding-bottom: 1px; color: #5b21b6; font-size: 7px; font-weight: bold; }
    .product-code { color: #555; font-size: 6.5px; }
    .allocation td { padding-top: 2px; padding-bottom: 2px; color: #333; }
    .allocation .description { padding-left: 8px; font-size: 7px; }
    .allocation .amount { font-weight: bold; }
    .summary td { padding-top: 2px; padding-bottom: 2px; }
    .summary .label { font-weight: bold; padding-left: 8px; }
    .opening td { font-weight: bold; border-bottom: 1px dashed #888; }
    .totals td { border-top: 1px solid #222; font-weight: bold; padding-top: 7px; }
    .footer {
      position: fixed;
      bottom: -24px;
      left: 0;
      right: 0;
      font-size: 7px;
      border-top: 1px solid #bbb;
      padding-top: 4px;
    }
    .footer .page { float: right; }
    .footer .page:after { content: counter(page); }
    .note { margin-top: 8px; font-size: 7px; color: #444; }
  </style>
</head>
<body>
  <div class="footer">
    Print Date: {{ now()->format('d-M-Y h:i:s a') }}
    <span class="page">Page </span>
  </div>

  <h1>General Ledger [Detail]</h1>
  <table class="account">
    <tr>
      <td style="width:13%"><strong>Account ID:</strong></td>
      <td style="width:47%">{{ $client->code ?: $client->id }}</td>
      <td class="right" style="width:20%"><strong>From Date:</strong></td>
      <td class="right" style="width:20%">{{ $statement['from_date'] ?? '-' }}</td>
    </tr>
    <tr>
      <td><strong>Title:</strong></td>
      <td><strong>{{ $client->name }}</strong></td>
      <td class="right"><strong>To Date:</strong></td>
      <td class="right">{{ $statement['to_date'] ?? '-' }}</td>
    </tr>
  </table>

  <table class="statement">
    <colgroup>
      <col style="width:7%">
      <col style="width:5.5%">
      <col style="width:9%">
      <col style="width:29.5%">
      <col style="width:4.5%">
      <col style="width:6.5%">
      <col style="width:7.5%">
      <col style="width:8%">
      <col style="width:7%">
      <col style="width:7%">
      <col style="width:8.5%">
    </colgroup>
    <thead>
      <tr>
        <th>Date</th>
        <th>Type</th>
        <th>Vr. #</th>
        <th>Narration</th>
        <th class="num">Qty</th>
        <th class="num">Rate</th>
        <th class="num">Amount</th>
        <th class="num">Net Amount</th>
        <th class="num">Debit</th>
        <th class="num">Credit</th>
        <th class="num">Balance</th>
      </tr>
    </thead>
    <tbody>
      <tr class="opening">
        <td colspan="10" class="num">Previous Balance:</td>
        <td class="num">{{ $money($statement['opening_balance']) }}</td>
      </tr>
      @forelse ($statement['entries'] as $entry)
        <tr class="entry">
          <td>{{ \Carbon\Carbon::parse($entry['date'])->format('d-m-Y') }}</td>
          <td>{{ $entry['type'] }}</td>
          <td>{{ $entry['ref'] }}</td>
          <td>{{ $entry['narration'] }}</td>
          <td></td><td></td><td></td>
          <td class="num"><strong>{{ $money($entry['net_amount']) }}</strong></td>
          <td class="num">{{ $entry['debit'] > 0 ? $money($entry['debit']) : '0.00' }}</td>
          <td class="num">{{ $entry['credit'] > 0 ? $money($entry['credit']) : '0.00' }}</td>
          <td class="num">{{ $money($entry['balance']) }}</td>
        </tr>
        @foreach (($entry['allocations'] ?? []) as $allocation)
          <tr class="allocation">
            <td></td>
            <td>Allocation</td>
            <td>{{ $allocation['receipt_ref'] }}</td>
            <td class="description">
              Applied to invoice <strong>{{ $allocation['invoice_ref'] }}</strong>
              ({{ $allocation['allocation_type'] === 'previous' ? 'previous invoice' : 'current invoice' }})
            </td>
            <td></td><td></td>
            <td class="num amount">{{ $money($allocation['amount']) }}</td>
            <td></td><td></td><td></td><td></td>
          </tr>
        @endforeach
        @if (count($entry['lines']))
          <tr class="product-heading">
            <td></td><td></td><td></td>
            <td>Products Purchased</td>
            <td class="num">Qty</td>
            <td class="num">Rate</td>
            <td class="num">Line Amount</td>
            <td></td><td></td><td></td><td></td>
          </tr>
        @endif
        @foreach ($entry['lines'] as $line)
          <tr class="detail">
            <td></td><td></td><td></td>
            <td class="description">
              <strong>{{ $line['name'] }}</strong>{{ $line['unit'] ? ' ('.$line['unit'].')' : '' }}
              @if (! empty($line['code']))
                <br><span class="product-code">Model/Code: {{ $line['code'] }}</span>
              @endif
            </td>
            <td class="num">{{ $quantity($line['quantity']) }}</td>
            <td class="num">{{ $rate($line['rate']) }}</td>
            <td class="num amount">{{ $money($line['amount']) }}</td>
            <td></td><td></td><td></td><td></td>
          </tr>
        @endforeach
        @if (count($entry['lines']))
          <tr class="summary">
            <td colspan="6" class="label num">Items Amount:</td>
            <td class="num">{{ $money($entry['items_total']) }}</td>
            <td></td><td></td><td></td><td></td>
          </tr>
          @if ($entry['discount'] > 0)
            <tr class="summary">
              <td colspan="7" class="label num">Order Discount{{ $entry['discount_method'] === '1' ? ' (%)' : ':' }}</td>
              <td class="num">{{ $money($entry['discount']) }}</td>
              <td></td><td></td><td></td>
            </tr>
          @endif
          @if ($entry['tax'] > 0 || $entry['shipping'] > 0)
            <tr class="summary">
              <td colspan="7" class="label num">Tax / Shipping:</td>
              <td class="num">{{ $money($entry['tax'] + $entry['shipping']) }}</td>
              <td></td><td></td><td></td>
            </tr>
          @endif
          <tr class="summary">
            <td colspan="7" class="label num">Net Amount:</td>
            <td class="num"><strong>{{ $money($entry['net_amount']) }}</strong></td>
            <td></td><td></td><td></td>
          </tr>
        @endif
      @empty
        <tr><td colspan="11">No transactions recorded.</td></tr>
      @endforelse
      <tr class="totals">
        <td colspan="7" class="num">Totals / Closing Balance:</td>
        <td class="num">{{ $money($statement['total_debit'] + $statement['total_credit']) }}</td>
        <td class="num">{{ $money($statement['total_debit']) }}</td>
        <td class="num">{{ $money($statement['total_credit']) }}</td>
        <td class="num">{{ $money($statement['closing_balance']) }}</td>
      </tr>
    </tbody>
  </table>
  <div class="note">Debit increases the customer's balance; credit reduces it. A payment allocated across multiple invoices is posted once as a receipt, with its invoice allocations listed underneath for reference.</div>
</body>
</html>
