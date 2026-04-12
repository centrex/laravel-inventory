<div>
<x-tallui-notification />

<x-tallui-page-header title="Payroll" subtitle="Track payroll runs and salary head allocations" icon="o-users">
    <x-slot:actions>
        <x-tallui-button :link="route('inventory.entities.employees.index')" icon="o-identification" class="btn-outline btn-sm">Employees</x-tallui-button>
        <x-tallui-button :link="route('inventory.entities.payroll-accounts.index')" icon="o-banknotes" class="btn-outline btn-sm">Payroll Heads</x-tallui-button>
        <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary btn-sm">New Payroll Entry</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Entry #, reference, description..." class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-40">
            <x-tallui-form-group label="Status">
                <x-tallui-select wire:model.live="statusFilter" class="select-sm">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="approved">Approved</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="w-44">
            <x-tallui-form-group label="Type">
                <x-tallui-select wire:model.live="typeFilter" class="select-sm">
                    <option value="">All</option>
                    <option value="salary">Salary</option>
                    <option value="bonus">Bonus</option>
                    <option value="deduction">Deduction</option>
                    <option value="tax">Tax</option>
                    <option value="adjustment">Adjustment</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Entry #</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th>Lines</th>
                    <th>Employees</th>
                    <th class="text-right">Total</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($entries as $entry)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5 font-mono text-sm text-primary font-semibold">{{ $entry->entry_number }}</td>
                        <td class="text-sm text-base-content/70">{{ $entry->date->format('M d, Y') }}</td>
                        <td class="text-sm font-medium">{{ ucfirst($entry->type) }}</td>
                        <td class="text-sm text-base-content/60">{{ $entry->reference ?: '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ $entry->lines->count() }}</td>
                        <td class="text-sm text-base-content/60">{{ $entry->lines->pluck('employee_id')->filter()->unique()->count() }}</td>
                        <td class="text-right text-sm font-mono font-medium">{{ number_format($entry->lines->sum('amount'), 2) }}</td>
                        <td>
                            <x-tallui-badge :type="$entry->status === 'approved' ? 'success' : 'neutral'">
                                {{ ucfirst($entry->status) }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                @if($entry->status === 'draft')
                                    <x-tallui-button wire:click="approve({{ $entry->id }})" class="btn-success btn-xs">Approve</x-tallui-button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-tallui-empty-state title="No payroll entries found" description="Create the first payroll run to begin tracking salary heads." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $entries->links() }}</div>
</x-tallui-card>

<x-tallui-modal id="payroll-modal" title="New Payroll Entry" icon="o-users" size="xl">
    <x-slot:trigger>
        <span x-effect="if ($wire.showModal) $dispatch('open-modal', 'payroll-modal'); else $dispatch('close-modal', 'payroll-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-3 gap-4">
            <x-tallui-form-group label="Date *" :error="$errors->first('date')">
                <x-tallui-input type="date" wire:model="date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Type *" :error="$errors->first('type')">
                <x-tallui-select wire:model="type">
                    <option value="salary">Salary</option>
                    <option value="bonus">Bonus</option>
                    <option value="deduction">Deduction</option>
                    <option value="tax">Tax</option>
                    <option value="adjustment">Adjustment</option>
                </x-tallui-select>
            </x-tallui-form-group>
            <x-tallui-form-group label="Reference">
                <x-tallui-input wire:model="reference" placeholder="Payroll month, batch id..." />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Description">
            <x-tallui-textarea wire:model="description" rows="2" placeholder="Optional notes for the run..." />
        </x-tallui-form-group>

        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-semibold text-base-content/70">Salary Heads</label>
                <x-tallui-button wire:click="addLine" icon="o-plus" class="btn-ghost btn-xs">Add Line</x-tallui-button>
            </div>
            <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                @foreach($lines as $i => $line)
                    <div class="grid grid-cols-[1.2fr_1.2fr_0.7fr_1fr_auto] gap-2 items-start bg-base-50 border border-base-200 p-2 rounded-xl">
                        <x-tallui-select wire:model="lines.{{ $i }}.employee_id" class="{{ $errors->has('lines.' . $i . '.employee_id') ? 'select-error' : '' }}">
                            <option value="">Select employee</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->code }} - {{ $employee->name }}</option>
                            @endforeach
                        </x-tallui-select>
                        <x-tallui-select wire:model="lines.{{ $i }}.payroll_account_id" class="{{ $errors->has('lines.' . $i . '.payroll_account_id') ? 'select-error' : '' }}">
                            <option value="">Select payroll account</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                            @endforeach
                        </x-tallui-select>
                        <x-tallui-input type="number" step="0.01" wire:model.lazy="lines.{{ $i }}.amount" placeholder="Amount" class="text-right" />
                        <x-tallui-input wire:model="lines.{{ $i }}.description" placeholder="Description" />
                        <x-tallui-button wire:click="removeLine({{ $i }})" icon="o-trash" class="btn-ghost btn-sm text-error" />
                    </div>
                @endforeach
            </div>
            <div class="mt-3 p-3 bg-base-50 rounded-xl border border-base-200 text-sm">
                <div class="flex justify-between font-bold">
                    <span>Total payroll</span>
                    <span class="font-mono">{{ number_format($this->total, 2) }}</span>
                </div>
                <div class="mt-2 text-xs text-base-content/60">
                    Maintain employees and payroll heads from their master data screens, then allocate each payroll line to an employee.
                </div>
            </div>
        </div>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" class="btn-primary">Save Payroll Entry</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
