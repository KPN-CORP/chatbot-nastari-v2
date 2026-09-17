<?php

namespace App\Http\Controllers;

use App\Services\EmployeeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * HTTP fallback for the scheduled tasks.
 *
 * Production runs on a split docroot and its only existing cache-clear
 * mechanism is an unauthenticated /fix-route endpoint, which suggests the
 * scheduler may not be wired up at all. This gives an external pinger or the
 * hosting panel's cron a way to drive the daily sync, without the sync logic
 * living anywhere but the artisan command.
 *
 * Protected by a shared token compared with hash_equals, rate limited, and
 * disabled outright when no token is configured. It is not a substitute for
 * real authentication, so it exposes exactly one idempotent action.
 */
class TaskRunnerController extends Controller
{
    private const RATE_LIMIT_KEY = 'task_runner_employee_sync';

    public function employeeSync(Request $request, EmployeeSyncService $sync): JsonResponse
    {
        $configured = (string) config('nastari.tasks.token', '');

        if ($configured === '') {
            return response()->json([
                'ok'      => false,
                'message' => 'Task runner is disabled: TASK_RUNNER_TOKEN is not set.',
            ], 404);
        }

        $provided = (string) ($request->query('token') ?? $request->header('X-Task-Token') ?? '');

        if (! hash_equals($configured, $provided)) {
            Log::warning('TASK_RUNNER_UNAUTHORISED', ['task' => 'employee-sync', 'ip' => $request->ip()]);

            return response()->json(['ok' => false, 'message' => 'Unauthorised.'], 401);
        }

        // One call per hour is plenty for a daily job, and it stops a
        // misconfigured pinger from hammering the remote employee master.
        if (Cache::has(self::RATE_LIMIT_KEY)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Rate limited: this task accepts at most one call per hour.',
            ], 429);
        }

        Cache::put(self::RATE_LIMIT_KEY, true, 3600);

        $result = $sync->sync('http', false, false);

        return response()->json([
            'ok'     => in_array($result['status'], ['ok', 'skipped'], true),
            'status' => $result['status'],
            'result' => $result,
        ], $result['status'] === 'failed' ? 500 : 200);
    }
}
