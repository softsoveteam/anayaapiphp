<?php

namespace App\Console\Commands;

use App\Services\WorkSessionService;
use Illuminate\Console\Command;

class CompleteDueWorkSessions extends Command
{
    protected $signature = 'anaya:complete-due-sessions';

    protected $description = 'Finish expired work sessions and award 1 click per assigned site';

    public function handle(WorkSessionService $sessions): int
    {
        $completed = $sessions->completeAllDue();
        $this->info("Completed {$completed} due work session(s).");

        return self::SUCCESS;
    }
}
