<x-filament-widgets::widget class="fi-wi-table">
    {{--
        Wrap the cache activity table in a <x-filament::section> so it reads as
        a discrete section on the Cached Downloads page, matching the
        filament-first convention. Section is collapsed by default; expands
        once the widget table mounts.
    --}}
    <x-filament::section
        wire:key="cached-content-activity-section-{{ $this->getSectionHeading() }}"
        icon="heroicon-o-circle-stack"
        :heading="$this->getSectionHeading()"
        :description="$this->getSectionDescription()"
        collapsible
        :collapsed="false"
        class="fi-section-cached-content-activity"
    >
        <x-slot name="afterHeader">
            <x-filament::icon-button
                icon="heroicon-m-question-mark-circle"
                color="gray"
                :tooltip="$this->getSectionDescription()"
                :label="$this->getSectionDescription()"
            />
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-widgets::widget>
