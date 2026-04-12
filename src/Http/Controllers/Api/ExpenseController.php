<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Api;

use Centrex\Inventory\Http\Requests\StoreExpenseRequest;
use Centrex\Inventory\Http\Resources\{ExpenseItemResource, ExpenseResource};
use Centrex\Inventory\Models\{Expense, ExpenseItem};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\{DB, Log};

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $expenses = Expense::query()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->account_id, fn ($q) => $q->where('account_id', $request->account_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('expense_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('expense_date', '<=', $request->date_to))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('expense_number', 'like', "%{$request->search}%")
                    ->orWhere('vendor_name', 'like', "%{$request->search}%")
                    ->orWhere('reference', 'like', "%{$request->search}%");
            }))
            ->latest('expense_date')
            ->paginate($request->integer('per_page', 15));

        return response()->json(ExpenseResource::collection($expenses)->response()->getData(true));
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $data = $request->validated();

        $expense = DB::transaction(function () use ($data): Expense {
            $currency  = $data['currency'] ?? config('inventory.base_currency', 'BDT');
            $subtotal  = 0;
            $taxAmount = 0;

            foreach ($data['items'] as $item) {
                $amount    = $item['quantity'] * $item['unit_price'];
                $itemTax   = $amount * (($item['tax_rate'] ?? 0) / 100);
                $subtotal  += $amount;
                $taxAmount += $itemTax;
            }

            $expense = Expense::create([
                'account_id'     => $data['account_id'] ?? null,
                'expense_date'   => $data['expense_date'],
                'due_date'       => $data['due_date'] ?? null,
                'subtotal'       => $subtotal,
                'tax_amount'     => $taxAmount,
                'total'          => $subtotal + $taxAmount,
                'currency'       => $currency,
                'status'         => 'draft',
                'payment_method' => $data['payment_method'] ?? null,
                'reference'      => $data['reference'] ?? null,
                'vendor_name'    => $data['vendor_name'] ?? null,
                'notes'          => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $tax    = $amount * (($item['tax_rate'] ?? 0) / 100);

                ExpenseItem::create([
                    'expense_id'  => $expense->id,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                    'tax_rate'    => $item['tax_rate'] ?? 0,
                    'tax_amount'  => $tax,
                ]);
            }

            return $expense;
        });

        return response()->json(['data' => new ExpenseResource($expense->load('items'))], 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        $expense->load('items');

        return response()->json(['data' => new ExpenseResource($expense)]);
    }

    public function post(Expense $expense): JsonResponse
    {
        try {
            if (class_exists(\Centrex\Accounting\Facades\Accounting::class)) {
                \Centrex\Accounting\Facades\Accounting::postExpense($expense);
            } else {
                $expense->update(['status' => 'approved']);
            }

            return response()->json(['data' => new ExpenseResource($expense->fresh('items'))]);
        } catch (\Throwable $e) {
            Log::error('Expense post error', ['expense_id' => $expense->id, 'exception' => $e]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function recordPayment(Request $request, Expense $expense): JsonResponse
    {
        $request->validate([
            'date'   => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string'],
        ]);

        try {
            if (class_exists(\Centrex\Accounting\Facades\Accounting::class)) {
                $payment = \Centrex\Accounting\Facades\Accounting::recordExpensePayment($expense, $request->only('date', 'amount', 'method', 'reference', 'notes'));

                return response()->json(['data' => $payment], 201);
            }

            $paid = (float) $expense->paid_amount + (float) $request->amount;
            $expense->update([
                'paid_amount' => $paid,
                'status'      => $paid >= (float) $expense->total ? 'paid' : 'partial',
            ]);

            return response()->json(['data' => $expense->fresh()], 201);
        } catch (\Throwable $e) {
            Log::error('Expense payment error', ['expense_id' => $expense->id, 'exception' => $e]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Expense $expense): JsonResponse
    {
        if ($expense->status !== 'draft') {
            return response()->json(['message' => 'Only draft expenses can be deleted'], 422);
        }

        $expense->delete();

        return response()->json(null, 204);
    }
}
