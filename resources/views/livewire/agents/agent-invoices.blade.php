<div>
    {{-- Breadcrumb + header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="mb-1 flex items-center gap-2 text-sm text-base-content/50">
                <a href="{{ route('inventory.agents.index') }}" class="link link-hover">Agents</a>
                <span>/</span>
                <a href="{{ route('inventory.agents.edit', $agent->id) }}" class="link link-hover">{{ $agent->name }}</a>
                <span>/</span>
                <span>Invoices</span>
            </div>
            <h1 class="text-2xl font-bold">{{ $agent->name }} — Agent Invoices</h1>
            <p class="mt-1 text-sm text-base-content/60">
                Internal B2B cost invoices for this agent. Not visible in the main accounting invoice list.
            </p>
        </div>
        <a href="{{ route('inventory.agent-orders.create') }}"
           class="btn btn-primary btn-sm">+ New Agent Order</a>
    </div>

    @if (! $hasAccounting)
        <div class="alert alert-warning mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>laravel-accounting is not installed. Agent invoices require the accounting package.</span>
        </div>
    @else

        {{-- Summary stats --}}
        <div class="stats shadow w-full mb-6">
            <div class="stat">
                <div class="stat-title">Total Invoices</div>
                <div class="stat-value text-2xl">{{ number_format($totals['count']) }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Total Billed</div>
                <div class="stat-value text-2xl">{{ number_format($totals['total'], 2) }}</div>
                <div class="stat-desc">{{ $agent->customer->currency ?? 'BDT' }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Total Paid</div>
                <div class="stat-value text-2xl text-success">{{ number_format($totals['paid'], 2) }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Outstanding</div>
                <div class="stat-value text-2xl {{ $totals['outstanding'] > 0 ? 'text-warning' : 'text-success' }}">
                    {{ number_format($totals['outstanding'], 2) }}
                </div>
            </div>
        </div>

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="Search invoice # or order ref…"
                   class="input input-bordered input-sm w-full max-w-xs" />
            <select wire:model.live="statusFilter" class="select select-bordered select-sm">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="posted">Posted</option>
                <option value="partially_settled">Part-paid</option>
                <option value="settled">Settled</option>
                <option value="overdue">Overdue</option>
                <option value="void">Void</option>
            </select>
        </div>

        {{-- Invoices table --}}
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Order Ref</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th class="text-right">Subtotal</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Paid</th>
                                <th class="text-right">Outstanding</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($invoices as $invoice)
                                @php
                                    $outstanding = (float) $invoice->total - (float) $invoice->paid_amount;
                                    $statusLabel = match((string) $invoice->status?->value ?? $invoice->status) {
                                        'draft'             => ['Draft',    'badge-ghost'],
                                        'posted'            => ['Posted',   'badge-info'],
                                        'partially_settled' => ['Part-paid','badge-warning'],
                                        'settled'           => ['Settled',  'badge-success'],
                                        'overdue'           => ['Overdue',  'badge-error'],
                                        'void'              => ['Void',     'badge-ghost opacity-50'],
                                        default             => [ucfirst((string) ($invoice->status?->value ?? $invoice->status)), 'badge-neutral'],
                                    };
                                @endphp
                                <tr>
                                    <td class="font-mono text-sm font-medium">{{ $invoice->invoice_number }}</td>
                                    <td class="font-mono text-sm text-base-content/60">{{ $invoice->source_reference ?? '—' }}</td>
                                    <td class="text-sm">{{ \Illuminate\Support\Carbon::parse($invoice->invoice_date)->format('d M Y') }}</td>
                                    <td class="text-sm {{ \Illuminate\Support\Carbon::parse($invoice->due_date)->isPast() && $outstanding > 0 ? 'text-error font-medium' : 'text-base-content/60' }}">
                                        {{ \Illuminate\Support\Carbon::parse($invoice->due_date)->format('d M Y') }}
                                    </td>
                                    <td class="text-right font-mono text-sm">{{ number_format((float) $invoice->subtotal, 2) }}</td>
                                    <td class="text-right font-mono text-sm font-medium">{{ number_format((float) $invoice->total, 2) }}</td>
                                    <td class="text-right font-mono text-sm text-success">{{ number_format((float) $invoice->paid_amount, 2) }}</td>
                                    <td class="text-right font-mono text-sm {{ $outstanding > 0 ? 'text-warning font-medium' : 'text-base-content/30' }}">
                                        {{ number_format($outstanding, 2) }}
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-sm {{ $statusLabel[1] }}">{{ $statusLabel[0] }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-12 text-center text-base-content/40">
                                        @if ($search || $statusFilter)
                                            No invoices match the current filters.
                                        @else
                                            No agent invoices yet. They are created automatically when you
                                            <a href="{{ route('inventory.agent-orders.create') }}" class="link">create an agent order</a>.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($invoices->isNotEmpty())
                            <tfoot>
                                <tr class="border-t border-base-300 font-semibold">
                                    <td colspan="4" class="text-base-content/60 text-sm">Total ({{ $totals['count'] }} invoices)</td>
                                    <td class="text-right font-mono text-sm">{{ number_format($totals['total'], 2) }}</td>
                                    <td class="text-right font-mono text-sm">{{ number_format($totals['total'], 2) }}</td>
                                    <td class="text-right font-mono text-sm text-success">{{ number_format($totals['paid'], 2) }}</td>
                                    <td class="text-right font-mono text-sm {{ $totals['outstanding'] > 0 ? 'text-warning' : 'text-base-content/30' }}">
                                        {{ number_format($totals['outstanding'], 2) }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>

    @endif
</div>
