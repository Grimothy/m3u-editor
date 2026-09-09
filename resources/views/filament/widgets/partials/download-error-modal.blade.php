<div class="space-y-3">
    <div class="text-sm text-gray-500 dark:text-gray-400">
        <div><strong>{{ __('Last failed') }}:</strong> {{ $lastFailedAt }}</div>
        <div><strong>{{ __('Total failures') }}:</strong> {{ $failures }}</div>
    </div>
    <div>
        <div class="text-sm font-medium mb-1">{{ __('Error message') }}</div>
        <pre class="bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded p-3 text-xs whitespace-pre-wrap break-words max-h-96 overflow-y-auto font-mono">{{ $message }}</pre>
    </div>
</div>
