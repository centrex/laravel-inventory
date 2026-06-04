<x-tallui-modal id="inventory-audit-trail-modal" title="Audit trail" icon="o-clock" size="xl">
    <div class="space-y-4">
        <div>
            <p class="text-sm font-semibold text-base-content">{{ $auditTrailSubjectLabel ?: 'Selected record' }}</p>
            <p class="mt-1 text-xs text-base-content/60">
                {{ $auditTrailSubjectType ? class_basename($auditTrailSubjectType) : 'Record' }} #{{ $auditTrailSubjectId ?: '—' }}
            </p>
        </div>

        <div class="max-h-[32rem] space-y-3 overflow-y-auto pr-1">
            @forelse ($this->auditTrail as $audit)
                <article class="rounded-xl border border-base-200 bg-base-100 p-4">
                    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge badge-neutral badge-sm">{{ $audit->event }}</span>
                                <span class="text-xs text-base-content/50">{{ $audit->created_at?->format('Y-m-d H:i:s') }}</span>
                            </div>
                            <p class="mt-2 text-sm font-medium">
                                {{ $audit->user?->name ?? 'System' }}
                                <span class="font-normal text-base-content/60">updated this record</span>
                            </p>
                        </div>
                        <div class="text-right text-xs text-base-content/50">
                            <div>{{ $audit->ip_address ?? 'No IP' }}</div>
                            <div class="max-w-80 truncate">{{ $audit->url ?? 'No URL captured' }}</div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div class="rounded-lg bg-base-200/50 p-3">
                            <div class="text-xs font-semibold uppercase text-base-content/50">Old values</div>
                            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode($audit->old_values ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                        <div class="rounded-lg bg-base-200/50 p-3">
                            <div class="text-xs font-semibold uppercase text-base-content/50">New values</div>
                            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode($audit->new_values ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </div>
                </article>
            @empty
                <x-tallui-empty-state title="No audit events" description="This record does not have an audit trail yet." icon="o-clock" size="sm" />
            @endforelse
        </div>
    </div>

    <x-slot:footer>
        <x-tallui-button wire:click="closeAuditTrail" class="btn-ghost">Close</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
