<x-filament-panels::page>
    @if ($selectiveReport !== [])
        <section @if ($selectiveRunId !== null) wire:poll.1s="continueSelectiveDryRun" @endif class="space-y-3 rounded-xl bg-white p-4 shadow-sm dark:bg-gray-900">
            <h2 class="font-semibold">Dry Run — 50 random invoices</h2>
            <p>Estado: {{ $selectiveReport['status'] ?? 'pending' }}. Apply está deshabilitado.</p>
            @if ($selectiveReport['notice'] ?? null)<p>{{ $selectiveReport['notice'] }}</p>@endif
            @if (isset($selectiveReport['summary']))
                @php
                    $summaryLabels = ['selected' => 'Seleccionadas', 'consulted' => 'Consultadas', 'processed' => 'Procesadas',
                        'pending_or_partial' => 'Pendientes o parciales', 'paid' => 'Pagadas', 'with_product' => 'Con Product',
                        'excluded_payment' => 'Excluidas por pago', 'excluded_category' => 'Excluidas por categoría',
                        'both_filters' => 'Pasan ambos filtros', 'review' => 'Revisión', 'technical_errors' => 'Errores técnicos'];
                @endphp
                <dl class="grid grid-cols-2 gap-2 md:grid-cols-4">
                    @foreach ($summaryLabels as $key => $label)
                        <div><dt>{{ $label }}</dt><dd>{{ $selectiveReport['summary'][$key] }}</dd></div>
                    @endforeach
                </dl>
            @endif
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
                            @if (isset($result['filters']))
                                @php
                                    $filters = $result['filters'];
                                    $filterLabels = ['passed' => 'Pasa', 'excluded' => 'Excluida', 'review' => 'Revisión'];
                                    $paymentLabels = ['pending' => 'Pendiente', 'partial' => 'Parcial', 'paid' => 'Pagada', 'void' => 'Anulada', 'unknown' => 'Revisión'];
                                    $resultLabels = ['candidate' => 'Candidata', 'excluded' => 'Excluida', 'review' => 'Revisión'];
                                @endphp
                                <tr><td colspan="8" class="py-2 text-sm">
                                    Balance: {{ $result['balance'] ?? '—' }} ·
                                    Estado de pago: {{ $paymentLabels[$filters['payment_state']] ?? 'Revisión' }} ·
                                    Items Product: {{ $filters['product_items'] }} · Otros Items: {{ $filters['other_items'] }} ·
                                    Desconocidos: {{ $filters['unknown_items'] }} ·
                                    Filtro categoría: {{ $filterLabels[$filters['category_filter']] }} ·
                                    Filtro pago: {{ $filterLabels[$filters['payment_filter']] }} ·
                                    Resultado: {{ $resultLabels[$result['selection_result'] ?? $filters['result']] }}
                                </td></tr>
                            @endif
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
            <p class="text-sm">Download Test Report incluye los IDs seleccionados, posiciones del sorteo, filtros, diagnósticos, tiempos y resumen. Las exclusiones válidas no son errores. Mantén esta página abierta hasta finalizar; no se habilita Apply.</p>
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

