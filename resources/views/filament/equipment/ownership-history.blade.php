<div class="space-y-3">
    @forelse ($transfers as $transfer)
        <p>
            {{ $transfer->transferred_at }} · {{ $transfer->previousCustomer?->display_name ?? 'Unassigned' }}
            → {{ $transfer->newCustomer->display_name }} · {{ $transfer->user->name }}
            @if ($transfer->reason) · {{ $transfer->reason }} @endif
        </p>
    @empty
        <p>No ownership changes recorded.</p>
    @endforelse
</div>