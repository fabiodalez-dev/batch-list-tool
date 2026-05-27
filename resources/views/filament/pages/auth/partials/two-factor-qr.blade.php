<div class="space-y-3">
    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800 inline-block">
        {!! $svg !!}
    </div>

    <div class="text-xs text-gray-500 dark:text-gray-400">
        <p>If you cannot scan the QR, type this secret into your authenticator app manually:</p>
        <code class="font-mono text-sm bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">{{ $secret }}</code>
    </div>
</div>
