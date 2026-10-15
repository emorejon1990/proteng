<x-filament-panels::page>
    @if ($selectiveReport !== [])
        <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm dark:bg-gray-900">
            <h2 class="font-semibold">Selective test: {{ $selectiveReport['mode'] === 'dry_run' ? 'Dry Run' : 'Apply' }} — 1982, 11241, 11990</h2>
            <p>{{ $selectiveReport['passed'] ? 'All mandatory checks passed.' : 'Checks failed or execution stopped. Review the results; this is not a successful synchronization.' }}</p>
            @foreach ($selectiveReport['errors'] as $error)
                <p class="text-danger-600">{{ $error }}</p>
            @endforeach
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr><th>QuickBooks ID</th><th>Consulted</th><th>Validation</th><th>Change</th><th>Lines</th><th>Total</th><th>Final state</th><th>Seconds</th></tr></thead>
                    <tbody>
                        @foreach ($selectiveReport['invoices'] as $id => $result)
                            <tr>
                                <td>{{ $id }}</td><td>{{ $result['consulted'] ? 'Yes' : 'No' }}</td>
                                <td>{{ $result['passed'] ? 'Passed' : 'Failed / not processed' }}</td>
                                <td>{{ $result['change'] ?? 'not_processed' }}</td><td>{{ $result['line_count'] ?? '—' }}</td>
                                <td>{{ $result['total'] ?? '—' }}</td><td>{{ $result['final_state'] === 'out_of_scope' ? 'Fuera del alcance operativo' : $result['final_state'] }}</td><td>{{ $result['seconds'] }}</td>
                            </tr>
                            @if (($result['errors'] ?? []) !== [])
                                <tr><td colspan="8" class="text-danger-600">{{ implode('; ', $result['errors']) }}</td></tr>
                            @endif
                            @foreach (['import_diagnostics' => 'Importación contable', 'operational_diagnostics' => 'Validación operativa'] as $key => $label)
                                @foreach ($result[$key] ?? [] as $diagnostic)
                                    <tr><td colspan="8" class="text-danger-600">
                                        {{ $label }}: {{ $diagnostic['code'] ?? 'validation_failed' }} — {{ $diagnostic['message'] }}
                                        <pre class="whitespace-pre-wrap">{{ json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </td></tr>
                                @endforeach
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-sm">Download the report for individual checks, line IDs and before/after integrity summaries. Apply requires a successful Dry Run and a second confirmation within 15 minutes.</p>
        </section>
    @endif
    <div class="space-y-2">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Use the buttons above to synchronize QuickBooks data into your local database.
        </p>

        <div class="text-sm text-gray-600 dark:text-gray-300">
            <ul class="list-disc pl-5 space-y-1">
                <li>Sync Customers: updates the local customers table.</li>
                <li>Sync Invoices: updates the local invoices table.</li>
                <li>Sync ALL: runs both operations sequentially.</li>
            </ul>
        </div>
    </div>
</x-filament-panels::page>

