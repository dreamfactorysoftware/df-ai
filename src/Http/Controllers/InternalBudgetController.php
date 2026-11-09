<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Http\Controllers;

use DreamFactory\Core\AI\Models\AiBudget;
use DreamFactory\Core\AI\Services\BudgetAccess;
use DreamFactory\Core\AI\Services\BudgetEnforcer;
use DreamFactory\Core\AI\Services\WebhookGuard;
use DreamFactory\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Admin CRUD for ai_budget rules, under /_internal/ai/budgets. BudgetAccess
 * decides who may call it.
 *
 * alert_webhook_auth_header is write-only. Responses carry
 * has_alert_webhook_auth instead. An empty value on PATCH keeps the stored
 * header; clear_alert_webhook_auth=true removes it.
 */
class InternalBudgetController extends Controller
{
    private const EDITABLE = [
        'dimension_type', 'dimension_id', 'period', 'budget_usd', 'hard_stop',
        'alert_webhook_url', 'label', 'is_active',
    ];

    /** Dimension type => [table, name column]. */
    private const DIMENSIONS = [
        'service' => ['service', 'label'],
        'role'    => ['role', 'name'],
        'user'    => ['user', 'email'],
        'app'     => ['app', 'name'],
    ];

    /** Changing one of these makes it a new cap, so its alerts can fire again. */
    private const ALERT_RESET_FIELDS = ['dimension_type', 'dimension_id', 'period', 'budget_usd'];

    public function index(): JsonResponse
    {
        if ($denied = $this->denyNonAdmin()) {
            return $denied;
        }

        $budgets = AiBudget::query()->orderBy('label')->get();

        // ponytail: one spend query per rule. Rules number in the tens. Group
        // by dimension if an install ever has thousands.
        return response()->json([
            'resource'              => $budgets->map(fn(AiBudget $b) => $this->present($b))->all(),
            // Spend comes from ai_usage_log, so with logging off no cap can trip.
            'usage_logging_enabled' => (bool) config('df-ai.usage_logging.enabled', true),
            // Off by default. While off, a rule cannot carry a webhook URL.
            'alert_webhooks_enabled' => WebhookGuard::enabled(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyNonAdmin()) {
            return $denied;
        }

        $data = $this->validated($request->only(self::EDITABLE), $request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $budget = new AiBudget($data);
        $this->applyAuthHeader($budget, $request);
        $budget->save();

        return response()->json($this->present($budget->fresh()), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denyNonAdmin()) {
            return $denied;
        }

        $budget = AiBudget::find((int) $id);
        if (!$budget) {
            return $this->error(404, 'Budget not found.');
        }

        $data = $this->validated(
            // Rows written before this API can have no label. That must not
            // block an unrelated PATCH, such as disabling the rule.
            array_merge(
                $budget->only(self::EDITABLE),
                ['label' => $budget->label ?: BudgetEnforcer::describeDimension($budget)],
                $request->only(self::EDITABLE),
            ),
            $request,
        );
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $budget->fill($data);
        if ($budget->isDirty(self::ALERT_RESET_FIELDS)) {
            $budget->last_alerted_50_at = $budget->last_alerted_80_at = $budget->last_alerted_100_at = null;
        }
        $this->applyAuthHeader($budget, $request);
        $budget->save();

        return response()->json($this->present($budget->fresh()));
    }

    public function destroy(string $id): JsonResponse
    {
        if ($denied = $this->denyNonAdmin()) {
            return $denied;
        }

        $budget = AiBudget::find((int) $id);
        if (!$budget) {
            return $this->error(404, 'Budget not found.');
        }
        $budget->delete();

        return response()->json(['id' => (int) $id]);
    }

    private function denyNonAdmin(): ?JsonResponse
    {
        $reason = BudgetAccess::denial();

        return $reason === null ? null : $this->error(403, $reason);
    }

    private function error(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $status, 'message' => $message]], $status);
    }

    /**
     * @return array<string, mixed>|JsonResponse clean fields, or a 400 response
     */
    private function validated(array $data, Request $request): array|JsonResponse
    {
        $validator = Validator::make($data + ['alert_webhook_auth_header' => $request->input('alert_webhook_auth_header')], [
            'dimension_type'            => 'required|in:global,service,role,user,app',
            'period'                    => 'required|in:monthly,daily,hourly',
            'budget_usd'                => 'required|numeric|min:0.0001|max:99999999.9999',
            'label'                     => 'required|string|max:256',
            'hard_stop'                 => 'sometimes|boolean',
            'is_active'                 => 'sometimes|boolean',
            'alert_webhook_url'         => 'nullable|string|max:1024|url:http,https',
            'alert_webhook_auth_header' => 'nullable|string|max:4096',
        ]);
        if ($validator->fails()) {
            return $this->error(400, $validator->errors()->first());
        }

        $type = $data['dimension_type'];
        $dimensionId = $data['dimension_id'] ?? null;

        if ($type === 'global') {
            if ($dimensionId !== null) {
                return $this->error(400, 'dimension_id must be null for a global budget.');
            }
        } elseif (!$this->dimensionExists($type, $dimensionId)) {
            return $this->error(400, "dimension_id does not match an existing {$type}.");
        }

        $data['dimension_id'] = $type === 'global' ? null : (int) $dimensionId;
        $data['alert_webhook_url'] = ($data['alert_webhook_url'] ?? null) ?: null;

        // Only a URL this request sends is checked. A stored URL must not
        // block an unrelated PATCH, and it is checked again before each send.
        if ($request->filled('alert_webhook_url')
            && ($refused = WebhookGuard::rejection((string) $data['alert_webhook_url']))) {
            return $this->error(400, $refused);
        }

        return $data;
    }

    private function dimensionExists(string $type, mixed $id): bool
    {
        if (filter_var($id, FILTER_VALIDATE_INT) === false) {
            return false;
        }

        $query = DB::table(self::DIMENSIONS[$type][0])->where('id', (int) $id);
        if ($type === 'service') {
            $query->where('type', 'ai_connection');
        }

        return $query->exists();
    }

    private function applyAuthHeader(AiBudget $budget, Request $request): void
    {
        if ($request->boolean('clear_alert_webhook_auth')) {
            $budget->alert_webhook_auth_header = null;
        } elseif ((string) $request->input('alert_webhook_auth_header', '') !== '') {
            $budget->alert_webhook_auth_header = (string) $request->input('alert_webhook_auth_header');
        }
    }

    /** @return array<string, mixed> */
    private function present(AiBudget $budget): array
    {
        $cap = (float) $budget->budget_usd;
        $spent = BudgetEnforcer::spendForBudget($budget);

        return $budget->toArray() + [
            'dimension_name'         => $this->dimensionName($budget),
            'has_alert_webhook_auth' => $budget->hasAlertWebhookAuth(),
            'spent_usd'              => round($spent, 6),
            'remaining_usd'          => round(max(0, $cap - $spent), 6),
            // A row written straight to the database can have a zero cap.
            'utilization'            => $cap > 0 ? round($spent / $cap, 4) : 0.0,
            'blocking'               => $budget->is_active && $budget->hard_stop && $spent >= $cap,
        ];
    }

    private function dimensionName(AiBudget $budget): ?string
    {
        if ($budget->dimension_type === 'global') {
            return null;
        }

        [$table, $column] = self::DIMENSIONS[$budget->dimension_type];

        return DB::table($table)->where('id', $budget->dimension_id)->value($column);
    }
}
