<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Api;

use Centrex\Inventory\Support\{EntityUserProvisioner, InventoryEntityRegistry};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\{Arr, Str};
use Illuminate\Support\Facades\{Gate, Validator};

class EntityCrudController extends Controller
{
    // 'entity' and 'recordId' are read from the route rather than taken as typed method
    // parameters. These routes bind 'entity' via ->defaults('entity', ...) rather than a URI
    // wildcard, so it lands after 'recordId' in $route->parametersWithoutNulls(); Laravel's
    // ControllerDispatcher fills scalar (non-class) method parameters positionally from that
    // array, so an (Request $request, string $entity, string $recordId) signature would
    // actually receive them swapped. Pulling both by name from the route sidesteps that.
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('inventory.master-data.view');

        $entity = $this->entity($request);
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

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('inventory.master-data.view');

        $record = $this->findRecord($this->entity($request), $this->recordId($request));

        return response()->json($record);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('inventory.master-data.manage');

        $entity = $this->entity($request);
        $model = InventoryEntityRegistry::makeModel($entity);
        $payload = InventoryEntityRegistry::fillablePayload($entity, $request->all(), forCreate: true);
        $validator = Validator::make($payload, InventoryEntityRegistry::validationRules($entity, null, $payload));
        $validator->validate();

        /** @var Model $record */
        $record = $model->newQuery()->create(Arr::except($payload, InventoryEntityRegistry::virtualFieldNames($entity)));
        EntityUserProvisioner::provision($entity, $record, $payload);

        return response()->json($record->fresh(), 201);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('inventory.master-data.manage');

        $entity = $this->entity($request);
        $record = $this->findRecord($entity, $this->recordId($request));
        $payload = InventoryEntityRegistry::fillablePayload($entity, $request->all());
        $validator = Validator::make($payload, InventoryEntityRegistry::validationRules($entity, $record, $payload));
        $validator->validate();

        $record->fill(Arr::except($payload, InventoryEntityRegistry::virtualFieldNames($entity)))->save();

        return response()->json($record->fresh());
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('inventory.master-data.manage');

        $entity = $this->entity($request);
        $record = $this->findRecord($entity, $this->recordId($request));
        $record->delete();

        return response()->json([
            'message' => Str::headline($entity) . ' deleted.',
        ]);
    }

    private function entity(Request $request): string
    {
        return (string) $request->route('entity');
    }

    private function recordId(Request $request): int
    {
        return (int) $request->route('recordId');
    }

    private function findRecord(string $entity, int $recordId): Model
    {
        $model = InventoryEntityRegistry::makeModel($entity);

        return $model->newQuery()->findOrFail($recordId);
    }
}
