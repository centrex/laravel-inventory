<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Models\Audit as DefaultAudit;
use Throwable;

trait ShowsAuditTrail
{
    public bool $showAuditTrailModal = false;

    public ?string $auditTrailSubjectType = null;

    public ?int $auditTrailSubjectId = null;

    public string $auditTrailSubjectLabel = '';

    public function supportsAuditTrail(string $modelClass): bool
    {
        return is_subclass_of($modelClass, Model::class)
            && is_subclass_of($modelClass, Auditable::class);
    }

    public function openAuditTrail(string $modelClass, int $modelId, ?string $label = null): void
    {
        abort_unless($this->supportsAuditTrail($modelClass), 404);

        /** @var Model $record */
        $record = $modelClass::query()->findOrFail($modelId);

        $this->auditTrailSubjectType = $modelClass;
        $this->auditTrailSubjectId = (int) $record->getKey();
        $this->auditTrailSubjectLabel = $label ?: $this->auditTrailLabelFor($record);
        $this->showAuditTrailModal = true;
    }

    public function closeAuditTrail(): void
    {
        $this->showAuditTrailModal = false;
        $this->auditTrailSubjectType = null;
        $this->auditTrailSubjectId = null;
        $this->auditTrailSubjectLabel = '';
    }

    public function getAuditTrailProperty(): Collection
    {
        if (!$this->auditTrailSubjectType || !$this->auditTrailSubjectId) {
            return collect();
        }

        $auditClass = config('audit.implementation', DefaultAudit::class);

        if (!is_string($auditClass) || !class_exists($auditClass)) {
            return collect();
        }

        try {
            return $auditClass::query()
                ->with('user')
                ->where('auditable_type', $this->auditTrailSubjectType)
                ->where('auditable_id', $this->auditTrailSubjectId)
                ->latest()
                ->limit(30)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function auditTrailLabelFor(Model $record): string
    {
        foreach (['sku', 'so_number', 'po_number', 'return_number', 'transfer_number', 'shipment_number', 'code', 'name'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return class_basename($record) . ' #' . $record->getKey();
    }
}
