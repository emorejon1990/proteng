<x-filament::page>

    <div class="space-y-6">

        {{-- ESTADO DE CONEXIÓN --}}
        @if(\App\Models\QuickbooksToken::exists())
            <x-filament::badge color="success">
                ✅ QuickBooks Connected
            </x-filament::badge>
        @else
            <x-filament::badge color="danger">
                ❌ QuickBooks Not Connected
            </x-filament::badge>
        @endif

        {{-- TABLA DE CUSTOMERS --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow p-4">

            <h2 class="text-lg font-bold mb-4">QuickBooks Customers</h2>

            @if(empty($this->customers))
                <p class="text-sm text-gray-500">Push “Load Customers” to get data.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b">
                            <tr>
                                <th class="text-left py-2">ID</th>
                                <th class="text-left py-2">Name</th>
                                <th class="text-left py-2">Email</th>
                                <th class="text-left py-2">Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($this->customers as $customer)
                                <tr class="border-b">
                                    <td class="py-2">{{ $customer['id'] }}</td>
                                    <td class="py-2">{{ $customer['name'] }}</td>
                                    <td class="py-2">{{ $customer['email'] }}</td>
                                    <td class="py-2">{{ $customer['phone'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        </div>

    </div>

</x-filament::page>

