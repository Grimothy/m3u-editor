<x-filament-panels::page>
    <div
        x-data="scheduleBuilder({
            networkId: {{ $network->id }},
            scheduleWindowDays: {{ $scheduleWindowDays }},
            recurrenceMode: '{{ $recurrenceMode }}',
            gapSeconds: {{ $gapSeconds }},
        })"
        x-cloak
        class="schedule-builder"
    >
        {{-- Header bar: Day nav + Now playing + Actions --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            {{-- Day Navigation --}}
            <div class="flex items-center gap-1.5 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-1.5">
                <button
                    @click="previousDay()"
                    class="inline-flex items-center justify-center rounded-lg w-8 h-8 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                    :disabled="!canGoPrevious()"
                    :class="{ 'opacity-30 cursor-not-allowed': !canGoPrevious() }"
                >
                    <x-heroicon-s-chevron-left class="w-4 h-4" />
                </button>
                <button
                    @click="goToToday()"
                    class="rounded-lg px-3 h-8 text-xs font-semibold text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/30 transition"
                >
                    Today
                </button>
                <button
                    @click="nextDay()"
                    class="inline-flex items-center justify-center rounded-lg w-8 h-8 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                    :disabled="!canGoNext()"
                    :class="{ 'opacity-30 cursor-not-allowed': !canGoNext() }"
                >
                    <x-heroicon-s-chevron-right class="w-4 h-4" />
                </button>
            </div>

            <div class="flex items-baseline gap-2">
                <span class="text-lg font-bold text-gray-900 dark:text-white" x-text="currentDateDisplay"></span>
                <span class="text-sm text-gray-400 dark:text-gray-500" x-text="currentDayOfWeek"></span>
            </div>

            {{-- Now-Playing pill --}}
            <div class="flex items-center gap-1.5 ml-auto text-xs rounded-full px-3 py-1.5 font-medium"
                 :class="{
                     'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300': nowPlaying?.status === 'playing',
                     'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300': nowPlaying?.status === 'gap',
                     'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300': nowPlaying?.status === 'empty',
                     'bg-gray-100 dark:bg-gray-800 text-gray-400': !nowPlaying,
                 }"
            >
                <template x-if="nowPlaying?.status === 'playing'">
                    <span class="flex items-center gap-1.5">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                        </span>
                        <span>Now: <strong x-text="nowPlaying.title"></strong></span>
                    </span>
                </template>
                <template x-if="nowPlaying?.status === 'gap'">
                    <span class="flex items-center gap-1.5">
                        <span class="inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                        <span>Idle &mdash; Next: <strong x-text="nowPlaying.next_title"></strong></span>
                    </span>
                </template>
                <template x-if="nowPlaying?.status === 'empty'">
                    <span class="flex items-center gap-1.5">
                        <span class="inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                        <span>No programmes</span>
                    </span>
                </template>
                <template x-if="!nowPlaying">
                    <span>&hellip;</span>
                </template>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-1.5">
                <button
                    @click="openCopyModal()"
                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                >
                    <x-heroicon-o-document-duplicate class="w-3.5 h-3.5" />
                    Copy Day
                </button>
                <template x-if="recurrenceMode === 'weekly'">
                    <button
                        @click="applyWeeklyTemplate()"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/30 transition"
                    >
                        <x-heroicon-o-arrow-path class="w-3.5 h-3.5" />
                        Apply Template
                    </button>
                </template>
                <button
                    @click="clearCurrentDay()"
                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-danger-600 dark:text-danger-400 hover:bg-danger-50 dark:hover:bg-danger-900/20 transition"
                >
                    <x-heroicon-o-trash class="w-3.5 h-3.5" />
                    Clear
                </button>
            </div>
        </div>

        {{-- Click-to-assign banner --}}
        <div
            x-show="selectedMediaItem"
            x-transition
            x-cloak
            class="mb-3 flex items-center justify-between rounded-lg bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800 px-4 py-2"
        >
            <span class="text-xs text-primary-700 dark:text-primary-300">
                Click a time slot or <strong>+</strong> button to place: <strong x-text="selectedMediaItem?.title"></strong>
            </span>
            <button
                @click="selectedMediaItem = null"
                class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline"
            >Cancel</button>
        </div>

        {{-- Main Layout: Timeline + Sticky Media Pool --}}
        <div class="flex gap-5 items-start">
            {{-- Timeline --}}
            <div
                class="flex-1 min-w-0"
                x-ref="timeGrid"
                :class="{ 'cursor-crosshair': selectedMediaItem }"
                @dragover.prevent="handleGridDragOver($event)"
                @dragleave="handleGridDragLeave($event)"
                @drop.prevent="handleGridDrop($event)"
                @click="handleGridClick($event)"
            >
                <div class="relative">
                    <template x-for="slot in timeSlots" :key="slot.time">
                        <div
                            class="flex slot-row"
                            :class="{
                                'border-b border-gray-200/60 dark:border-gray-700/40': slot.isHour,
                                'border-b border-gray-100/40 dark:border-gray-700/20': !slot.isHour && slot.minute === 30,
                                'border-b border-transparent': !slot.isHour && slot.minute !== 0 && slot.minute !== 30,
                                'bg-primary-50/50 dark:bg-primary-900/20': dropTarget === slot.time,
                            }"
                            :data-slot-time="slot.time"
                            style="min-height: 28px;"
                        >
                            {{-- Time gutter --}}
                            <div class="w-14 shrink-0 flex items-start justify-end pr-3 -mt-[9px] select-none">
                                <template x-if="slot.isHour">
                                    <span class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 tabular-nums" x-text="slot.label"></span>
                                </template>
                            </div>

                            {{-- Content lane --}}
                            <div class="flex-1 relative min-h-[28px]"
                                 :class="{ 'border-l border-gray-200 dark:border-gray-700': true }"
                            >
                                <template x-for="prog in getProgrammesAtSlot(slot.time)" :key="prog.id">
                                    <div
                                        class="absolute inset-x-0 mx-1 rounded-lg overflow-hidden shadow-sm ring-1 transition-shadow hover:shadow-md group/prog"
                                        :class="getTypeColor(prog.contentable_type)"
                                        :style="getProgrammeStyle(prog, slot.time)"
                                    >
                                        {{-- Actions overlay --}}
                                        <div class="absolute top-1.5 right-1.5 flex items-center gap-1 z-20 opacity-0 group-hover/prog:opacity-100 transition-opacity" style="pointer-events: auto;">
                                            <template x-if="selectedMediaItem">
                                                <button
                                                    @click.stop="insertAfterProgramme(prog.id, selectedMediaItem); selectedMediaItem = null;"
                                                    class="p-1 rounded-md bg-white/90 dark:bg-gray-800/80 backdrop-blur text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-900/60 shadow transition"
                                                    title="Insert after this"
                                                >
                                                    <x-heroicon-o-plus class="w-3.5 h-3.5" />
                                                </button>
                                            </template>
                                            <button
                                                @click.stop="handleRemoveClick($event, prog.id)"
                                                class="p-1 rounded-md bg-white/90 dark:bg-gray-800/80 backdrop-blur text-gray-400 hover:text-red-600 dark:hover:text-red-400 shadow transition"
                                                title="Remove"
                                            >
                                                <x-heroicon-o-trash class="w-3.5 h-3.5" />
                                            </button>
                                        </div>

                                        {{-- Programme content (draggable for move) --}}
                                        <div
                                            class="flex h-full cursor-grab active:cursor-grabbing"
                                            style="pointer-events: auto;"
                                            draggable="true"
                                            @dragstart="handleProgrammeDragStart($event, prog)"
                                            @dragend="handleProgrammeDragEnd($event)"
                                        >
                                            <template x-if="prog.image">
                                                <div class="shrink-0 w-16 sm:w-24 h-full relative overflow-hidden">
                                                    <img :src="prog.image" class="absolute inset-0 w-full h-full object-cover" loading="lazy" />
                                                    <div class="absolute inset-0 bg-gradient-to-r from-transparent to-black/10"></div>
                                                </div>
                                            </template>
                                            <div class="flex-1 min-w-0 px-3 py-2 flex flex-col justify-center">
                                                <p class="text-sm font-semibold truncate leading-snug" x-text="prog.title"></p>
                                                <div class="flex items-center gap-2 mt-1 text-[11px] opacity-60">
                                                    <span x-text="formatTimeRange(prog)"></span>
                                                    <span>&middot;</span>
                                                    <span x-text="formatDuration(prog.duration_seconds)"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Media Pool — sticky sidebar --}}
            <div class="w-72 shrink-0 sticky top-4 max-h-[calc(100vh-6rem)]  flex flex-col bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                {{-- Pool Header --}}
                <div class="p-3 border-b border-gray-200 dark:border-gray-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Media Pool</h3>
                        <span class="text-[10px] text-gray-400 dark:text-gray-500" x-text="filteredMediaPool.length + ' items'"></span>
                    </div>

                    <input
                        type="text"
                        x-model="mediaSearch"
                        placeholder="Search..."
                        class="w-full rounded-lg border-gray-200 dark:border-gray-600 dark:bg-gray-700/50 dark:text-white text-xs px-3 py-1.5 focus:ring-primary-500 focus:border-primary-500 placeholder-gray-400"
                    />

                    <label class="flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400 cursor-pointer">
                        <input type="checkbox" x-model="showAllMedia" @change="loadMediaPool()" class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 w-3.5 h-3.5" />
                        Show all media
                    </label>
                </div>

                {{-- Pool Items --}}
                <div class="flex-1 overflow-y-auto p-2 space-y-1">
                    <template x-if="loadingPool">
                        <div class="flex items-center justify-center py-8">
                            <x-filament::loading-indicator class="w-5 h-5" />
                        </div>
                    </template>

                    <template x-if="!loadingPool && filteredMediaPool.length === 0">
                        <p class="text-center text-[11px] text-gray-400 dark:text-gray-500 py-8">No media available</p>
                    </template>

                    <template x-for="item in filteredMediaPool" :key="item.contentable_type + '-' + item.contentable_id">
                        <div
                            class="group/item flex items-center gap-2.5 p-2 rounded-lg border cursor-grab hover:shadow-sm transition-all"
                            :class="selectedMediaItem && selectedMediaItem.contentable_id === item.contentable_id && selectedMediaItem.contentable_type === item.contentable_type
                                ? 'border-primary-400 dark:border-primary-500 bg-primary-50/50 dark:bg-primary-900/20 ring-1 ring-primary-400/50'
                                : 'border-gray-100 dark:border-gray-700/50 hover:border-gray-200 dark:hover:border-gray-600'"
                            draggable="true"
                            @dragstart="handleMediaDragStart($event, item)"
                            @click="selectMediaItem(item)"
                        >
                            <template x-if="item.image">
                                <img :src="item.image" class="w-10 h-14 rounded object-cover shrink-0" loading="lazy" />
                            </template>
                            <template x-if="!item.image">
                                <div class="w-10 h-14 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0">
                                    <x-heroicon-o-film class="w-4 h-4 text-gray-400" />
                                </div>
                            </template>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-gray-900 dark:text-white truncate leading-tight" x-text="item.title"></p>
                                <div class="flex items-center gap-1 mt-0.5">
                                    <span
                                        class="text-[10px] font-medium"
                                        :class="item.type === 'episode' ? 'text-blue-500 dark:text-blue-400' : 'text-purple-500 dark:text-purple-400'"
                                        x-text="item.type === 'episode' ? 'Episode' : 'Movie'"
                                    ></span>
                                    <span class="text-[10px] text-gray-400">&middot;</span>
                                    <span class="text-[10px] text-gray-400 dark:text-gray-500" x-text="item.duration_display"></span>
                                </div>
                            </div>
                            <button
                                @click.stop="appendToEnd(item)"
                                class="shrink-0 p-1.5 rounded-lg text-gray-300 dark:text-gray-600 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/30 opacity-0 group-hover/item:opacity-100 transition-all"
                                title="Append to end of schedule"
                            >
                                <x-heroicon-o-plus-circle class="w-4 h-4" />
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Copy Day Modal --}}
        <div
            x-show="showCopyModal"
            x-cloak
            x-transition.opacity
            @keydown.escape.window="showCopyModal = false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
        >
            <div
                x-transition.scale.95
                @click.outside="showCopyModal = false"
                class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-6 w-full max-w-sm border border-gray-200 dark:border-gray-700"
            >
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">Copy Schedule</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    Copy <strong class="text-gray-900 dark:text-white" x-text="currentDateDisplay"></strong> to:
                </p>
                <select
                    x-model="copyTargetDate"
                    class="w-full rounded-lg border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm mb-5"
                >
                    <template x-for="date in availableDates" :key="date.value">
                        <option :value="date.value" :disabled="date.value === currentDate" x-text="date.label"></option>
                    </template>
                </select>
                <div class="flex justify-end gap-2">
                    <button @click="showCopyModal = false" class="rounded-lg px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        Cancel
                    </button>
                    <button @click="copyDay()" class="rounded-lg px-4 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-500 shadow-sm transition">
                        Copy
                    </button>
                </div>
            </div>
        </div>

        {{-- Loading Overlay --}}
        <div x-show="loading" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/10 backdrop-blur-[2px]">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-5 flex items-center gap-3">
                <x-filament::loading-indicator class="w-5 h-5" />
                <span class="text-sm text-gray-600 dark:text-gray-300">Loading...</span>
            </div>
        </div>
    </div>
</x-filament-panels::page>
