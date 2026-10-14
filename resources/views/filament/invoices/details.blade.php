<div>
    <p>Invoice {{ $invoice->invoice_number }} — {{ $invoice->customer?->display_name }}</p>
    @if($invoice->sync_error)<p>{{ $invoice->sync_error }}</p>@endif
    @if($invoice->operation_status === 'sync_conflict')<p>QuickBooks changed this invoice. Review the operation; its requirements were preserved.</p>@endif
    <table style="width:100%"><thead><tr><th>Description</th><th>Quantity</th><th>Detected type / mapping</th></tr></thead><tbody>
    @foreach($invoice->lines as $line)
    <tr><td>{{ $line->description ?: $line->quickbooks_item_id ?: $line->detail_type }}</td><td>{{ $line->quantity }}</td><td>{{ $line->item_type ?: $line->detail_type }} @if($line->isPhysical()) — {{ $line->asset?->name ?: 'Unmapped Item' }} @endif</td></tr>
    @endforeach
    </tbody></table>
    @foreach($invoice->events()->latest()->limit(10)->get() as $event)<p>{{ $event->created_at }} — {{ $event->event }}</p>@endforeach
</div>
