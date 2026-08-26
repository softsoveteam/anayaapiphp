<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkAssignment;
use App\Models\WorkReport;
use App\Models\WorkSession;
use Illuminate\Support\Facades\DB;

class WorkSessionService
{
    public function completeDueForUser(User $user): ?WorkSession
    {
        return DB::transaction(function () use ($user) {
            $session = WorkSession::query()
                ->where('employee_id', $user->id)
                ->where('status', 'running')
                ->lockForUpdate()
                ->first();

            return $this->finishIfDue($session);
        });
    }

    public function completeAllDue(): int
    {
        $ids = WorkSession::query()
            ->where('status', 'running')
            ->where('ends_at', '<=', now())
            ->pluck('id');

        $completed = 0;

        foreach ($ids as $id) {
            $finished = DB::transaction(function () use ($id) {
                $session = WorkSession::query()->whereKey($id)->lockForUpdate()->first();

                return $this->finishIfDue($session);
            });

            if ($finished && $finished->status === 'completed') {
                $completed++;
            }
        }

        return $completed;
    }

    public function finishIfDue(?WorkSession $session): ?WorkSession
    {
        if (! $session) {
            return null;
        }

        if ($session->status === 'running' && $session->isDue()) {
            $this->awardAndFinish($session);
        }

        return $session->fresh();
    }

    public function awardAndFinish(WorkSession $session): void
    {
        if ($session->status !== 'running') {
            return;
        }

        $sites = collect($session->sites ?? []);
        $awarded = 0;

        foreach ($sites as $site) {
            $assignment = null;

            if (! empty($site['assignment_id'])) {
                $assignment = WorkAssignment::query()->find($site['assignment_id']);
            }

            if (! $assignment && ! empty($site['site_id'])) {
                $assignment = WorkAssignment::query()
                    ->where('employee_id', $session->employee_id)
                    ->where('site_id', $site['site_id'])
                    ->whereDate('work_date', $session->started_at?->toDateString() ?? now()->toDateString())
                    ->orderBy('id')
                    ->first();
            }

            if (! $assignment) {
                continue;
            }

            $report = WorkReport::query()->firstOrNew([
                'work_assignment_id' => $assignment->id,
            ]);

            $report->employee_id = $assignment->employee_id;
            $report->site_id = $assignment->site_id;
            $report->keyword_id = $assignment->keyword_id;
            $report->work_date = $assignment->work_date;
            $report->click_count = ((int) $report->click_count) + 1;
            $report->submitted_at = now();
            $report->save();
            $awarded++;
        }

        $session->update([
            'status' => 'completed',
            'finished_at' => now(),
            'clicks_awarded' => $awarded,
            'site_count' => $sites->count(),
        ]);
    }
}
