<?php

namespace Database\Seeders;

use App\Enums\ComputerStatus;
use App\Enums\EmployeeStatus;
use App\Enums\SiteStatus;
use App\Models\Computer;
use App\Models\ComputerAssignment;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkAssignment;
use App\Models\WorkReport;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $password = env('ADMIN_PASSWORD', 'password');

        $admin = User::query()->updateOrCreate(
            ['unique_id' => 'ANAYA-ADMIN'],
            [
                'name' => 'Anaya Admin',
                'email' => 'admin@anaya.local',
                'phone' => '9990001111',
                'status' => EmployeeStatus::Joined,
                'joining_date' => now()->subMonths(6),
                'password' => $password,
            ]
        );
        $admin->syncRoles(['admin']);

        $manager = User::query()->updateOrCreate(
            ['unique_id' => 'ANAYA-MGR'],
            [
                'name' => 'Riya Manager',
                'email' => 'manager@anaya.local',
                'phone' => '9990002222',
                'status' => EmployeeStatus::Joined,
                'joining_date' => now()->subMonths(4),
                'password' => $password,
            ]
        );
        $manager->syncRoles(['manager']);

        $ravi = User::query()->updateOrCreate(
            ['unique_id' => 'ANAYA-0001'],
            [
                'name' => 'Ravi Patel',
                'email' => 'ravi@anaya.local',
                'phone' => '9876500001',
                'address' => 'Ahmedabad',
                'emergency_contact' => '9876500099',
                'status' => EmployeeStatus::Joined,
                'interview_date' => now()->subMonths(2),
                'joining_date' => now()->subMonth(),
                'password' => $password,
            ]
        );
        $ravi->syncRoles(['employee']);

        $neha = User::query()->updateOrCreate(
            ['unique_id' => 'ANAYA-0002'],
            [
                'name' => 'Neha Shah',
                'email' => 'neha@anaya.local',
                'phone' => '9876500002',
                'address' => 'Surat',
                'status' => EmployeeStatus::Joined,
                'interview_date' => now()->subMonths(2),
                'joining_date' => now()->subWeeks(3),
                'password' => $password,
            ]
        );
        $neha->syncRoles(['employee']);

        $interview = User::query()->updateOrCreate(
            ['unique_id' => 'ANAYA-0003'],
            [
                'name' => 'Amit Joshi',
                'email' => 'amit@anaya.local',
                'phone' => '9876500003',
                'status' => EmployeeStatus::InterviewPass,
                'interview_date' => now()->subDays(3),
                'password' => $password,
                'notes' => 'Passed technical interview. Waiting for onboarding.',
            ]
        );
        $interview->syncRoles(['employee']);

        $pc1 = Computer::query()->updateOrCreate(
            ['unique_number' => 'PC-1001'],
            ['label' => 'Desk A1', 'status' => ComputerStatus::Assigned, 'notes' => 'Windows 11']
        );
        $pc2 = Computer::query()->updateOrCreate(
            ['unique_number' => 'PC-1002'],
            ['label' => 'Desk A2', 'status' => ComputerStatus::Assigned]
        );
        $pc3 = Computer::query()->updateOrCreate(
            ['unique_number' => 'PC-1003'],
            ['label' => 'Desk B1', 'status' => ComputerStatus::Assigned]
        );
        Computer::query()->updateOrCreate(
            ['unique_number' => 'PC-1004'],
            ['label' => 'Spare 1', 'status' => ComputerStatus::Available]
        );
        Computer::query()->updateOrCreate(
            ['unique_number' => 'PC-1005'],
            ['label' => 'Repair', 'status' => ComputerStatus::Maintenance]
        );

        $this->assignComputer($pc1, $ravi, $admin);
        $this->assignComputer($pc2, $ravi, $admin);
        $this->assignComputer($pc3, $neha, $admin);

        $siteA = Site::query()->updateOrCreate(
            ['url' => 'https://anaya-health.example'],
            [
                'name' => 'Anaya Health',
                'status' => SiteStatus::Active,
                'notes' => 'Primary brand site',
            ]
        );
        $siteB = Site::query()->updateOrCreate(
            ['url' => 'https://anaya-care.example'],
            [
                'name' => 'Anaya Care',
                'status' => SiteStatus::Active,
            ]
        );

        $kwA1 = Keyword::query()->updateOrCreate(
            ['site_id' => $siteA->id, 'keyword' => 'best health clinic'],
            ['status' => SiteStatus::Active]
        );
        $kwA2 = Keyword::query()->updateOrCreate(
            ['site_id' => $siteA->id, 'keyword' => 'online doctor consult'],
            ['status' => SiteStatus::Active]
        );
        $kwB1 = Keyword::query()->updateOrCreate(
            ['site_id' => $siteB->id, 'keyword' => 'home care services'],
            ['status' => SiteStatus::Active]
        );

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->assignWork($ravi, $siteA, $kwA1, $yesterday, $admin, 400);
        $this->assignWork($ravi, $siteB, $kwB1, $yesterday, $admin, 200);
        $this->assignWork($neha, $siteA, $kwA2, $yesterday, $admin, 350);

        $this->assignWork($ravi, $siteA, $kwA1, $today, $admin, 400, auto: true);
        $this->assignWork($ravi, $siteB, $kwB1, $today, $admin, 200, auto: true);
        $this->assignWork($neha, $siteA, $kwA2, $today, $admin, 350, auto: true);

        $this->reportFor($ravi, $siteA, $kwA1, $yesterday, 412);
        $this->reportFor($ravi, $siteB, $kwB1, $yesterday, 198);
        $this->reportFor($neha, $siteA, $kwA2, $yesterday, 360);
    }

    private function assignComputer(Computer $computer, User $employee, User $by): void
    {
        $existing = ComputerAssignment::query()
            ->where('computer_id', $computer->id)
            ->whereNull('unassigned_at')
            ->first();

        if ($existing) {
            return;
        }

        ComputerAssignment::create([
            'computer_id' => $computer->id,
            'employee_id' => $employee->id,
            'assigned_by' => $by->id,
            'assigned_at' => now()->subWeeks(2),
        ]);
    }

    private function assignWork(
        User $employee,
        Site $site,
        Keyword $keyword,
        string $date,
        User $by,
        int $target,
        bool $auto = false,
    ): WorkAssignment {
        return WorkAssignment::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'site_id' => $site->id,
                'keyword_id' => $keyword->id,
                'work_date' => $date,
            ],
            [
                'target_clicks' => $target,
                'scheduled_by' => $by->id,
                'is_auto_copied' => $auto,
            ]
        );
    }

    private function reportFor(User $employee, Site $site, Keyword $keyword, string $date, int $clicks): void
    {
        $assignment = WorkAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('site_id', $site->id)
            ->where('keyword_id', $keyword->id)
            ->whereDate('work_date', $date)
            ->first();

        if (! $assignment) {
            return;
        }

        WorkReport::query()->updateOrCreate(
            ['work_assignment_id' => $assignment->id],
            [
                'employee_id' => $employee->id,
                'site_id' => $site->id,
                'keyword_id' => $keyword->id,
                'work_date' => $date,
                'click_count' => $clicks,
                'submitted_at' => now()->subDay()->setTime(18, 30),
            ]
        );
    }
}
