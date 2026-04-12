<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Payroll;

use Centrex\Inventory\Models\{Employee, PayrollAccount, PayrollEntry, PayrollEntryLine};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\{Component, WithPagination};

class PayrollEntriesPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $typeFilter = '';

    public bool $showModal = false;

    public string $date = '';

    public string $type = 'salary';

    public string $reference = '';

    public string $description = '';

    public array $lines = [];

    protected array $queryString = ['search', 'statusFilter', 'typeFilter'];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = [
            'employee_id'        => '',
            'payroll_account_id' => '',
            'amount'             => 0,
            'description'        => '',
            'reference'          => '',
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function openCreate(): void
    {
        $this->reset(['reference', 'description', 'lines']);
        $this->date = now()->format('Y-m-d');
        $this->type = 'salary';
        $this->addLine();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'date'                       => 'required|date',
            'type'                       => 'required|string|in:salary,bonus,deduction,tax,adjustment',
            'lines'                      => 'required|array|min:1',
            'lines.*.employee_id'        => 'required|integer',
            'lines.*.payroll_account_id' => 'required|integer',
            'lines.*.amount'             => 'required|numeric|gt:0',
            'lines.*.description'        => 'nullable|string|max:500',
            'lines.*.reference'          => 'nullable|string|max:255',
        ]);

        DB::transaction(function (): void {
            $entry = PayrollEntry::create([
                'date'          => $this->date,
                'reference'     => $this->reference ?: null,
                'description'   => $this->description ?: null,
                'currency'      => config('inventory.base_currency', 'BDT'),
                'type'          => $this->type,
                'exchange_rate' => 1,
                'status'        => 'draft',
            ]);

            foreach ($this->lines as $line) {
                PayrollEntryLine::create([
                    'payroll_entry_id'   => $entry->id,
                    'employee_id'        => $line['employee_id'],
                    'payroll_account_id' => $line['payroll_account_id'],
                    'amount'             => $line['amount'],
                    'description'        => $line['description'] ?: null,
                    'reference'          => $line['reference'] ?: null,
                ]);
            }
        });

        $this->dispatch('notify', type: 'success', message: 'Payroll entry recorded successfully.');
        $this->showModal = false;
        $this->reset(['reference', 'description', 'lines']);
        $this->date = now()->format('Y-m-d');
        $this->type = 'salary';
        $this->addLine();
    }

    public function approve(int $id): void
    {
        $entry = PayrollEntry::findOrFail($id);

        if ($entry->status !== 'draft') {
            $this->dispatch('notify', type: 'warning', message: 'Only draft payroll entries can be approved.');

            return;
        }

        $entry->update([
            'status'      => 'approved',
            'approved_at' => now(),
        ]);

        $this->dispatch('notify', type: 'success', message: "Payroll {$entry->entry_number} approved.");
    }

    public function getTotalProperty(): float
    {
        return collect($this->lines)->sum(fn (array $line): float => (float) ($line['amount'] ?? 0));
    }

    public function render(): View
    {
        $entries = PayrollEntry::query()
            ->with(['lines.employee', 'lines.payrollAccount'])
            ->when($this->search, fn ($query) => $query->where(function ($query): void {
                $query->where('entry_number', 'like', '%' . $this->search . '%')
                    ->orWhere('reference', 'like', '%' . $this->search . '%')
                    ->orWhere('description', 'like', '%' . $this->search . '%');
            }))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter))
            ->latest('date')
            ->paginate(config('inventory.per_page.payroll_entries', 15));

        $accounts = PayrollAccount::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $employees = Employee::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('inventory::livewire.payroll-entries', [
            'entries'   => $entries,
            'accounts'  => $accounts,
            'employees' => $employees,
        ])->layout($layout, ['title' => __('Payroll')]);
    }
}
