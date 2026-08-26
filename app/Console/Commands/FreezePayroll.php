<?php

namespace App\Console\Commands;

use App\Services\PayrollService;
use Illuminate\Console\Command;

class FreezePayroll extends Command
{
    protected $signature = 'anaya:freeze-payroll {--month=}';

    protected $description = 'Freeze payroll snapshots for a past month (defaults to previous month)';

    public function handle(PayrollService $payroll): int
    {
        $month = $this->option('month') ?: $payroll->previousMonth();

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Use month as YYYY-MM.');

            return self::FAILURE;
        }

        if ($payroll->isCurrentMonth($month)) {
            $this->warn('The current month stays live until it ends.');

            return self::SUCCESS;
        }

        $frozen = $payroll->freezeMonth($month);
        $this->info("Froze {$frozen} payroll snapshot(s) for {$month}.");

        return self::SUCCESS;
    }
}
