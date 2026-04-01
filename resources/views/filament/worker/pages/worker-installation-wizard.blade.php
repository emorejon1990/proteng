<x-filament-panels::page>
    @if (! $installation)
        <div class="rounded-lg border bg-white p-4">
            <p class="text-sm text-gray-700">You have no assigned installations.</p>
        </div>
    @else
        <div class="space-y-4">
            <div class="rounded-lg border bg-white p-4">
                <div class="grid gap-3 md:grid-cols-3">
                    <div>
                        <p class="text-xs text-gray-500">Customer</p>
                        <p class="font-medium">{{ $installation->customer?->display_name }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Equipment</p>
                        <p class="font-medium">{{ trim(($installation->equipment?->brand ?? '').' '.($installation->equipment?->model ?? '')) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Status</p>
                        <p class="font-medium">{{ $installation->status }}</p>
                    </div>
                </div>
            </div>

            @php $step = $this->currentStep(); @endphp

            @if ($completed)
                <div class="rounded-lg border border-green-200 bg-green-50 p-4">
                    <p class="font-semibold text-green-800">Installation completed</p>
                    <p class="text-sm text-green-700">The installation closed successfully.</p>
                </div>
            @elseif ($step)
                @php
                    $stepImageUrl = null;
                    if (! empty($step['img'])) {
                        $stepImageUrl = str_starts_with($step['img'], 'http')
                            ? $step['img']
                            : \Illuminate\Support\Facades\Storage::disk('public')->url($step['img']);
                    }
                @endphp

                <div class="rounded-lg border bg-white p-4 space-y-3" x-data="{ showImageModal: false }">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">{{ $this->progressLabel() }}</h2>
                        @if($step['is_required'])
                            <span class="text-xs rounded bg-amber-100 px-2 py-1 text-amber-800">Required</span>
                        @endif
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-3">
                            <div>
                                <p class="font-medium">{{ $step['title'] }}</p>
                                @if(! empty($step['description']))
                                    <p class="text-sm text-gray-600 mt-1">{{ $step['description'] }}</p>
                                @endif
                            </div>

                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" wire:model="currentStepDone" class="rounded border-gray-300">
                                <span class="text-sm">Done</span>
                            </label>
                        </div>

                        <div class="md:justify-self-end">
                            @if($stepImageUrl)
                                <button
                                    type="button"
                                    class="block rounded-lg border border-gray-200 p-1 transition hover:border-primary-500"
                                    @click="showImageModal = true"
                                >
                                    <img
                                        src="{{ $stepImageUrl }}"
                                        alt="Step image"
                                        class="h-24 w-24 rounded object-cover"
                                        style="width: 240px;"
                                    >
                                </button>
                            @else
                                <div class="h-24 w-24 rounded-lg border border-dashed border-gray-300 bg-gray-50 text-[11px] text-gray-500 flex items-center justify-center text-center p-2">
                                    No image
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($stepImageUrl)
                        <div
                            x-show="showImageModal"
                            x-cloak
                            x-transition.opacity
                            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
                            @click.self="showImageModal = false"
                            @keydown.escape.window="showImageModal = false"
                        >
                            <div class="relative max-h-[90vh] max-w-[90vw]">
                                <button
                                    type="button"
                                    class="absolute -top-10 right-0 rounded bg-white px-2 py-1 text-sm font-medium text-gray-800"
                                    @click="showImageModal = false"
                                >
                                    Close
                                </button>
                                <img
                                    src="{{ $stepImageUrl }}"
                                    alt="Step image large"
                                    class="max-h-[85vh] max-w-[90vw] rounded-lg object-contain"
                                >
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="text-sm font-medium">Notes</label>
                        <textarea
                            wire:model="currentStepNotes"
                            rows="3"
                            class="mt-1 w-full rounded-md border-gray-300 shadow-sm"
                        ></textarea>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-filament::button color="gray" wire:click="previousStep" :disabled="$currentStepIndex === 0">
                            Back
                        </x-filament::button>

                        <x-filament::button color="info" wire:click="saveCurrentStep">
                            Save Step
                        </x-filament::button>

                        <x-filament::button color="primary" wire:click="nextStep">
                            {{ $currentStepIndex === (count($steps) - 1) ? 'Finish' : 'Next' }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
