<!doctype html>
<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ccc;padding:8px;text-align:left}</style></head><body>
<h1>{{ config('app.name', 'PROTENG') }} — Packing Slip</h1>
<p>Customer: {{ $invoice->customer?->display_name }}</p>
<p>Shipping Address:<br>
@foreach(($invoice->shipping_address ?? $invoice->customer?->shipping_address ?? []) as $key => $value)
@if(in_array($key, ['Line1','Line2','Line3','Line4','Line5','City','CountrySubDivisionCode','PostalCode','Country']) && is_scalar($value)){{ $value }}<br>@endif
@endforeach
</p>
<p>Invoice: {{ $invoice->invoice_number }}<br>Work Order: {{ $order->name }}<br>Date: {{ $order->date }}</p>
<table><thead><tr><th>Product</th><th>Quantity</th><th>Description</th></tr></thead><tbody>
@foreach($order->workOrderAssets as $requirement)
<tr><td>{{ $requirement->asset?->name }}</td><td>{{ $requirement->quantity }}</td><td>{{ $invoice->operation_snapshot['descriptions'][$requirement->asset_id] ?? $requirement->asset?->description }}</td></tr>
@endforeach
</tbody></table>
</body></html>
