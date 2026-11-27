<div>
    <p>Invoice {{ $invoice->invoice_number }} — {{ $invoice->customer?->display_name }}</p>
    @if($invoice->sync_error)<p>{{ $invoice->sync_error }}</p>@endif
    @if($invoice->operation_status === 'out_of_scope')<p>Fuera del alcance operativo</p>@endif
    @if($invoice->operation_status === 'sync_conflict')<p>QuickBooks changed this invoice. Review the operation; its requirements were preserved.</p>@endif
    @if(in_array($invoice->operation_status, ['requires_review', 'sync_conflict'], true))
        @php($diagnostics = $invoice->events()->whereNotNull('metadata->operational_diagnostics')->latest('id')->first()?->metadata['operational_diagnostics'] ?? [])
        <p>Review details</p>
        <ul>
            @forelse($diagnostics as $diagnostic)
                <li>
                    {{ $diagnostic['message'] ?? $diagnostic['code'] ?? 'Review required' }}
                    @if(isset($diagnostic['line_position'])) · Line {{ $diagnostic['line_position'] }} @endif
                    @if(isset($diagnostic['item_ref'])) · ItemRef {{ $diagnostic['item_ref'] }} @endif
                </li>
            @empty
                <li>Consult Invoice Sync Events for this QuickBooks ID before changing the invoice or its operation.</li>
            @endforelse
        </ul>
    @endif
    <table style="width:100%"><thead><tr><th>Description</th><th>Quantity</th><th>Detected type / mapping</th></tr></thead><tbody>
    @foreach($invoice->lines as $line)
    <tr><td>{{ $line->description ?: $line->quickbooks_item_id ?: $line->detail_type }}</td><td>{{ $line->quantity }}</td><td>{{ $line->item_type ?: $line->detail_type }} @if($line->isPhysical()) — {{ $line->asset?->name ?: 'Unmapped Item' }} @endif</td></tr>
    @endforeach
    </tbody></table>
    @foreach($invoice->events()->latest()->limit(10)->get() as $event)<p>{{ $event->created_at }} — {{ $event->event }}</p>@endforeach
</div>
