<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions\Concerns;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Shared by every create-then-redirect transaction form (sale/purchase orders, returns,
 * transfers, shipments, adjustments) to stop a double-click — or a request retried because a
 * slow ERP/accounting sync made the first submission look stuck — from creating a duplicate
 * record. Using classes must call initializeFormToken() from mount() and wrap their create
 * call in onceForThisSubmission().
 *
 * $form_token is generated once per page load and stays constant across a double-click's two
 * requests, since both carry the same Livewire snapshot — that's what lets
 * onceForThisSubmission() recognize "this is the same in-flight submission" and block the
 * second one, while a genuinely new page load (fresh mount, fresh token) is never blocked.
 */
trait GuardsAgainstDuplicateSubmission
{
    public string $form_token = '';

    protected function initializeFormToken(): void
    {
        $this->form_token = (string) Str::uuid();
    }

    /**
     * Runs $callback only if no other submission sharing this form_token is already in
     * flight. Returns null (a Livewire no-op) when a duplicate is blocked, otherwise
     * $callback()'s own return value (typically a redirect).
     */
    protected function onceForThisSubmission(string $lockKey, Closure $callback): mixed
    {
        $lock = Cache::lock("{$lockKey}.{$this->form_token}", 30);

        if (!$lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
