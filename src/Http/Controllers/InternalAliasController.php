<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Http\Controllers;

use DreamFactory\Core\AI\Models\AiModelAlias;
use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sysadmin CRUD for ai_model_alias, under /_internal/ai/aliases.
 *
 * DELETE deactivates the row. Aliases appear in past usage rows, and an
 * inactive alias already stops resolving, so nothing needs a hard delete.
 */
class InternalAliasController extends Controller
{
    private const EDITABLE = ['name', 'service_id', 'physical_model', 'label', 'description', 'is_active'];

    public function index(): JsonResponse
    {
        if ($denied = $this->denyNonAdmin()) {
            return $denied;
        }

        return response()->json(['resource' => AiModelAlias::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyNonAdmin()) {
            return $denied;
        }

        $data = $request->only(self::EDITABLE);
        if ($invalid = $this->invalid($data)) {
            return $invalid;
        }

        return response()->json(AiModelAlias::create($data)->fresh(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denyNonAdmin()) {
            return $denied;
        }

        $alias = AiModelAlias::find((int) $id);
        if (!$alias) {
            return $this->error(404, 'Alias not found.');
        }

        $data = array_merge($alias->only(self::EDITABLE), $request->only(self::EDITABLE));
        if ($invalid = $this->invalid($data, $alias->id)) {
            return $invalid;
        }

        $alias->update($data);

        return response()->json($alias->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        if ($denied = $this->denyNonAdmin()) {
            return $denied;
        }

        $alias = AiModelAlias::find((int) $id);
        if (!$alias) {
            return $this->error(404, 'Alias not found.');
        }
        $alias->update(['is_active' => false]);

        return response()->json($alias->fresh());
    }

    private function denyNonAdmin(): ?JsonResponse
    {
        return Session::isSysAdmin() ? null : $this->error(403, 'Admin access required.');
    }

    private function error(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $status, 'message' => $message]], $status);
    }

    private function invalid(array $data, ?int $ignoreId = null): ?JsonResponse
    {
        $unique = 'unique:ai_model_alias,name' . ($ignoreId ? ",{$ignoreId}" : '');
        $validator = Validator::make($data, [
            'name'           => "required|string|max:128|{$unique}",
            'service_id'     => 'required|integer',
            'physical_model' => 'required|string|max:128',
            'label'          => 'nullable|string|max:256',
            'description'    => 'nullable|string',
            'is_active'      => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->error(400, $validator->errors()->first());
        }

        $isAiConnection = DB::table('service')
            ->where('id', (int) $data['service_id'])
            ->where('type', 'ai_connection')
            ->exists();

        return $isAiConnection ? null : $this->error(400, 'service_id must be an AI Connection.');
    }
}
