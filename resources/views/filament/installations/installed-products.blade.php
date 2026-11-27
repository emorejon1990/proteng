@if ($installedProducts->isEmpty())
    <p class="text-sm">No installed products recorded.</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead>
                <tr>
                    @foreach (['Serial', 'Product', 'Installation', 'Equipment', 'Customer', 'Work Order', 'Installed on', 'Registered by', 'Current status', 'Current location'] as $heading)
                        <th scope="col" class="p-2 text-start">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($installedProducts as $record)
                    <tr wire:key="installed-product-{{ $record->id }}">
                        <td class="p-2">{{ $record->serial_number ?? $record->product?->serial }}</td>
                        <td class="p-2">{{ $record->product?->asset?->name }}</td>
                        <td class="p-2">{{ $record->installation_id }}</td>
                        <td class="p-2">{{ trim(($record->equipment?->brand ?? '').' '.($record->equipment?->model ?? '')) }}</td>
                        <td class="p-2">{{ $record->customer?->display_name }}</td>
                        <td class="p-2">{{ $record->workOrder?->name }}</td>
                        <td class="p-2">{{ $record->installed_at?->toDateString() }}</td>
                        <td class="p-2">{{ $record->registrar?->name }}</td>
                        <td class="p-2">{{ $record->product?->status?->name }}</td>
                        <td class="p-2">{{ $record->product?->location?->name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
