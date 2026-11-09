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
 * header; clear_alert_webhook_auth=true removes it, and so does moving the
 * URL to another host without sending a new header.
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
        self::resolveAuthHeader(
            $budget,
            $request->boolean('clear_alert_webhook_auth'),
            (string) $request->input('alert_webhook_auth_header', ''),
        );
    }

    /**
     * Decide the stored webhook auth header after the other fields are
     * filled. The header is write-only, so nobody who edits a rule can read
     * it. It was entered for one receiver: when the URL moves to another
     * scheme, host or port and no new header comes with it, the stored one
     * is dropped. Otherwise an editor could point the URL at a server of
     * their own and collect a secret they were never shown.
     */
    public static function resolveAuthHeader(AiBudget $budget, bool $clear, string $newHeader): void
    {
        if ($clear) {
            $budget->alert_webhook_auth_header = null;
        } elseif ($newHeader !== '') {
            $budget->alert_webhook_auth_header = $newHeader;
        } elseif ($budget->exists && $budget->hasAlertWebhookAuth()
            && self::origin($budget->getOriginal('alert_webhook_url')) !== self::origin($budget->alert_webhook_url)) {
            $budget->alert_webhook_auth_header = null;
        }
    }

    /** scheme://host:port of a URL, lower-case, or '' when there is none. */
    private static function origin(?string $url): string
    {
        $parts = parse_url((string) $url) ?: [];
        if (empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
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
