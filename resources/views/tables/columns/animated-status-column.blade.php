<div class="flex flex-col gap-y-1">
    @php
        $record = $getRecord();
        $status = $record->status;
        $postProcessingStep = $record->post_processing_step;

        $isAnimated = in_array(
            $status,
            [\App\Enums\DvrRecordingStatus::Recording, \App\Enums\DvrRecordingStatus::PostProcessing],
            true,
        );
        $label = $status?->getLabel() ?? ucfirst($status?->value ?? 'unknown');
    @endphp

    <x-filament::badge size="sm" :color="$status?->getColor() ?? 'gray'" :class="$isAnimated ? 'dfi-status-animated-pulse' : ''">
        {{ $label }}
    </x-filament::badge>

    @if ($postProcessingStep)
        <div class="flex items-center gap-1.5 mt-0.5">
            <svg class="animate-spin h-3.5 w-3.5 text-indigo-500 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg"
                fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                </circle>
                <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                </path>
            </svg>
            <span
                class="fi-ta-text-description dfi-description-animated text-xs text-indigo-600 dark:text-indigo-400">{{ $postProcessingStep }}</span>
        </div>
    @endif
</div>
