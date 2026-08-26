<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SalaryController extends Controller
{
    public function __construct(private PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        $month = $this->month($request);

        return response()->json($this->payroll->forMonth($month));
    }

    public function mine(Request $request): JsonResponse
    {
        $this->ensureEmployeeEarnings($request);
        $month = $this->month($request);

        return response()->json($this->payroll->resolveForEmployee($request->user(), $month));
    }

    public function minePdf(Request $request): SymfonyResponse
    {
        $this->ensureEmployeeEarnings($request);
        $month = $this->month($request);

        return $this->payslipResponse($this->payroll->resolveForEmployee($request->user(), $month));
    }

    public function payslip(Request $request, User $employee): SymfonyResponse
    {
        $month = $this->month($request);

        return $this->payslipResponse($this->payroll->resolveForEmployee($employee, $month));
    }

    public function freeze(Request $request): JsonResponse
    {
        $month = $request->string('month')->toString() ?: $this->payroll->previousMonth();

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw ValidationException::withMessages([
                'month' => ['Use month as YYYY-MM.'],
            ]);
        }

        $frozen = $this->payroll->freezeMonth($month);

        return response()->json([
            'month' => $month,
            'frozen' => $frozen,
            'report' => $this->payroll->forMonth($month),
        ]);
    }

    private function ensureEmployeeEarnings(Request $request): void
    {
        if (AppSetting::employeeEarnings()) {
            return;
        }

        $user = $request->user();
        if ($user && $user->hasAnyRole(['admin', 'manager'])) {
            return;
        }

        abort(403, 'Earnings are not enabled for employees.');
    }

    private function payslipResponse(array $row): Response
    {
        $pdf = Pdf::loadView('payslip', ['row' => $row]);
        $name = sprintf('payslip-%s-%s.pdf', $row['unique_id'] ?? 'employee', $row['month'] ?? 'month');

        return $pdf->download($name);
    }

    private function month(Request $request): string
    {
        $month = $request->string('month')->toString() ?: now(PayrollService::TIMEZONE)->format('Y-m');

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw ValidationException::withMessages([
                'month' => ['Use month as YYYY-MM.'],
            ]);
        }

        return $month;
    }
}
