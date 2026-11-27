<div class="space-y-4">
    @forelse ($machines as $machine)
        <section>
            <h3 class="font-semibold">Equipment #{{ $machine->id }} {{ $machine->identifier }}</h3>
            <ol class="space-y-2">
                @foreach ($machine->installationTemplateSteps as $step)
                    <li>{{ $step->sort_order }}. {{ $step->title }} @if ($step->is_required) (required) @endif
                        <p class="whitespace-pre-wrap">{{ $step->description }}</p>
                        @if ($step->img)
                            <p>Image: {{ $step->img }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @empty
        <p>No original machine guides recorded.</p>
    @endforelse
</div>
