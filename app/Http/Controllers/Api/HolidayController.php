<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    public function __construct(private PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        $query = Holiday::query()->orderBy('date');

        if ($month = $request->string('month')->toString()) {
            [$start, $end] = $this->payroll->monthBounds($month);
            $query->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString());
        }

        return response()->json([
            'data' => $query->get()->map(fn (Holiday $h) => $this->payroll->serializeHoliday($h)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $holiday = Holiday::query()->create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payroll->serializeHoliday($holiday)], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date', Rule::unique('holidays', 'date')->ignore($holiday->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $holiday->update($data);

        return response()->json(['data' => $this->payroll->serializeHoliday($holiday)]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(['message' => 'Holiday deleted.']);
    }
}
