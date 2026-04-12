<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Api;

use Centrex\Inventory\Http\Requests\StorePayrollEntryRequest;
use Centrex\Inventory\Http\Resources\PayrollEntryResource;
use Centrex\Inventory\Models\{Employee, PayrollEntry, PayrollEntryLine};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PayrollEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entries = PayrollEntry::query()
            ->with(['lines.employee', 'lines.payrollAccount'])
            ->when($request->status, fn ($query) => $query->where('status', $request->status))
            ->when($request->type, fn ($query) => $query->where('type', $request->type))
            ->when($request->employee_id, fn ($query) => $query->whereHas('lines', fn ($lineQuery) => $lineQuery->where('employee_id', $request->integer('employee_id'))))
            ->when($request->date_from, fn ($query) => $query->whereDate('date', '>=', $request->date_from))
            ->when($request->date_to, fn ($query) => $query->whereDate('date', '<=', $request->date_to))
            ->latest('date')
            ->paginate($request->integer('per_page', 15));

        return response()->json(PayrollEntryResource::collection($entries)->response()->getData(true));
    }

    public function store(StorePayrollEntryRequest $request): JsonResponse
    {
        $employeeIds = collect($request->input('lines', []))
            ->pluck('employee_id')
            ->filter()
            ->unique()
            ->values();

        if ($employeeIds->isNotEmpty() && Employee::query()->whereIn('id', $employeeIds)->count() !== $employeeIds->count()) {
            return response()->json([
                'message' => 'One or more selected employees are invalid.',
                'errors'  => ['lines' => ['One or more selected employees are invalid.']],
            ], 422);
        }

        $entry = DB::transaction(function () use ($request): PayrollEntry {
            $entry = PayrollEntry::create([
                'date'          => $request->string('date')->toString(),
                'reference'     => $request->input('reference'),
                'description'   => $request->input('description'),
                'currency'      => $request->input('currency', config('inventory.base_currency', 'BDT')),
                'type'          => $request->string('type')->toString(),
                'exchange_rate' => $request->input('exchange_rate', 1),
                'created_by'    => optional($request->user())->getAuthIdentifier(),
                'status'        => 'draft',
            ]);

            foreach ($request->input('lines', []) as $line) {
                PayrollEntryLine::create([
                    'payroll_entry_id'   => $entry->id,
                    'employee_id'        => $line['employee_id'],
                    'payroll_account_id' => $line['payroll_account_id'],
                    'amount'             => $line['amount'],
                    'description'        => $line['description'] ?? null,
                    'reference'          => $line['reference'] ?? null,
                ]);
            }

            return $entry->load(['lines.employee', 'lines.payrollAccount']);
        });

        return response()->json(['data' => new PayrollEntryResource($entry)], 201);
    }

    public function show(PayrollEntry $payrollEntry): JsonResponse
    {
        return response()->json([
            'data' => new PayrollEntryResource($payrollEntry->load(['lines.employee', 'lines.payrollAccount'])),
        ]);
    }

    public function approve(Request $request, PayrollEntry $payrollEntry): JsonResponse
    {
        if ($payrollEntry->status !== 'draft') {
            return response()->json(['message' => 'Only draft payroll entries can be approved.'], 422);
        }

        $payrollEntry->update([
            'status'      => 'approved',
            'approved_by' => optional($request->user())->getAuthIdentifier(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'data' => new PayrollEntryResource($payrollEntry->fresh()->load(['lines.employee', 'lines.payrollAccount'])),
        ]);
    }

    public function destroy(PayrollEntry $payrollEntry): JsonResponse
    {
        if ($payrollEntry->status !== 'draft') {
            return response()->json(['message' => 'Only draft payroll entries can be deleted.'], 422);
        }

        $payrollEntry->delete();

        return response()->json(null, 204);
    }
}
