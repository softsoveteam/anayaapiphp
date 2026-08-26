<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function __construct(private PayrollService $payroll) {}

    public function calendar(Request $request): JsonResponse
    {
        $month = $request->string('month')->toString() ?: now(PayrollService::TIMEZONE)->format('Y-m');
        [$start, $end] = $this->payroll->monthBounds($month);
        $user = $request->user();
        $staff = $user->hasAnyRole(['admin', 'manager']);

        $holidays = Holiday::query()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->get();

        $leaves = LeaveRequest::query()
            ->with(['employee', 'reviewer'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->when(! $staff, fn ($q) => $q->where('employee_id', $user->id))
            ->orderBy('start_date')
            ->get();

        return response()->json([
            'month' => $month,
            'work_start' => PayrollService::WORK_START,
            'work_end' => PayrollService::WORK_END,
            'paid_leave_quota' => PayrollService::PAID_LEAVE_PER_MONTH,
            'holidays' => $holidays->map(fn (Holiday $h) => $this->payroll->serializeHoliday($h)),
            'leaves' => $leaves->map(fn (LeaveRequest $l) => $this->payroll->serializeLeave($l)),
            'pending' => $staff
                ? LeaveRequest::query()
                    ->with(['employee', 'reviewer'])
                    ->where('status', LeaveRequest::STATUS_PENDING)
                    ->orderBy('start_date')
                    ->get()
                    ->map(fn (LeaveRequest $l) => $this->payroll->serializeLeave($l))
                : [],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $month = $request->string('month')->toString() ?: now(PayrollService::TIMEZONE)->format('Y-m');
        [$start, $end] = $this->payroll->monthBounds($month);

        $query = LeaveRequest::query()
            ->with(['employee', 'reviewer'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderBy('start_date');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($employeeId = $request->integer('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        return response()->json([
            'month' => $month,
            'data' => $query->get()->map(fn (LeaveRequest $l) => $this->payroll->serializeLeave($l)),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $leaves = LeaveRequest::query()
            ->with(['employee', 'reviewer'])
            ->where('employee_id', $request->user()->id)
            ->orderByDesc('start_date')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $leaves->map(fn (LeaveRequest $l) => $this->payroll->serializeLeave($l)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $tz = PayrollService::TIMEZONE;
        $today = now($tz)->startOfDay();
        $start = Carbon::parse($data['start_date'], $tz)->startOfDay();
        $end = Carbon::parse($data['end_date'], $tz)->startOfDay();

        if ($start->lt($today)) {
            throw ValidationException::withMessages([
                'start_date' => ['Leave can only be applied for today or a future date.'],
            ]);
        }

        $holidays = $this->payroll->holidayDates($start, $end);
        $dates = $this->payroll->workingDatesInRange($start, $end, $holidays);

        if ($dates === []) {
            throw ValidationException::withMessages([
                'start_date' => ['Those dates are Sunday or holidays. Pick a working day (Mon–Sat).'],
            ]);
        }

        if ($this->payroll->overlappingLeave($user->id, $start->toDateString(), $end->toDateString())) {
            throw ValidationException::withMessages([
                'start_date' => ['You already have pending or approved leave on one of these dates.'],
            ]);
        }

        $leave = LeaveRequest::query()->create([
            'employee_id' => $user->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => count($dates),
            'reason' => $data['reason'] ?? null,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
        $leave->load(['employee', 'reviewer']);

        return response()->json(['data' => $this->payroll->serializeLeave($leave)], 201);
    }

    public function destroy(Request $request, LeaveRequest $leave): JsonResponse
    {
        if ($leave->employee_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only cancel your own leave.'], 403);
        }

        if (! $leave->isPending()) {
            throw ValidationException::withMessages([
                'leave' => ['Only pending leave can be cancelled.'],
            ]);
        }

        $leave->delete();

        return response()->json(['message' => 'Leave request cancelled.']);
    }

    public function review(Request $request, LeaveRequest $leave): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED])],
        ]);

        if (! $leave->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['This leave request was already reviewed.'],
            ]);
        }

        $leave->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $leave->load(['employee', 'reviewer']);

        return response()->json(['data' => $this->payroll->serializeLeave($leave)]);
    }
}
