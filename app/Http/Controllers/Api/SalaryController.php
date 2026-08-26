<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        $month = $this->month($request);

        return response()->json($this->payroll->forEmployee($request->user(), $month));
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
