<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Api;

use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EntityCrudController extends Controller
{
    public function index(Request $request, string $entity): JsonResponse
    {
        $model = InventoryEntityRegistry::makeModel($entity);
        $query = $model->newQuery();

        $search = trim((string) $request->string('q'));
        $columns = InventoryEntityRegistry::searchableColumns($entity);

        if ($search !== '' && $columns !== []) {
            $query->where(function ($builder) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $builder->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        return response()->json(
            $query->latest($model->getKeyName())->paginate((int) $request->integer('per_page', 15)),
        );
    }

    public function show(string $entity, int $recordId): JsonResponse
    {
        $record = $this->findRecord($entity, $recordId);

        return response()->json($record);
    }

    public function store(Request $request, string $entity): JsonResponse
    {
        $model = InventoryEntityRegistry::makeModel($entity);
        $payload = InventoryEntityRegistry::fillablePayload($entity, $request->all());
        $validator = Validator::make($payload, InventoryEntityRegistry::validationRules($entity, null, $payload));
        $validator->validate();

        /** @var Model $record */
        $record = $model->newQuery()->create($payload);

        return response()->json($record->fresh(), 201);
    }

    public function update(Request $request, string $entity, int $recordId): JsonResponse
    {
        $record = $this->findRecord($entity, $recordId);
        $payload = InventoryEntityRegistry::fillablePayload($entity, $request->all());
        $validator = Validator::make($payload, InventoryEntityRegistry::validationRules($entity, $record, $payload));
        $validator->validate();

        $record->fill($payload)->save();

        return response()->json($record->fresh());
    }

    public function destroy(string $entity, int $recordId): JsonResponse
    {
        $record = $this->findRecord($entity, $recordId);
        $record->delete();

        return response()->json([
            'message' => Str::headline($entity) . ' deleted.',
        ]);
    }

    private function findRecord(string $entity, int $recordId): Model
    {
        $model = InventoryEntityRegistry::makeModel($entity);

        return $model->newQuery()->findOrFail($recordId);
    }
}
