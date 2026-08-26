<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AssignmentController;
use Illuminate\Console\Command;

class CopyYesterdayAssignments extends Command
{
    protected $signature = 'anaya:copy-yesterday-assignments {--date=}';

    protected $description = 'Copy yesterday work assignments for joined employees who have no schedule today';

    public function handle(AssignmentController $assignments): int
    {
        $date = $this->option('date') ? \Illuminate\Support\Carbon::parse($this->option('date')) : now();
        $copied = $assignments->copyForDate($date);

        $this->info("Copied {$copied} assignment(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
