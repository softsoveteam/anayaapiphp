<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComputerStatus;
use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\ComputerAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComputerController extends Controller
{
    public function index(Request $request)
    {
        $query = Computer::query()
            ->with(['currentAssignment.employee'])
            ->orderBy('unique_number');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('unique_number', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->get()->map(fn (Computer $c) => $this->serialize($c)),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $computers = Computer::query()
            ->whereHas('currentAssignment', fn ($q) => $q->where('employee_id', $request->user()->id))
            ->with(['currentAssignment.employee'])
            ->get();

        return response()->json([
            'data' => $computers->map(fn (Computer $c) => $this->serialize($c)),
        ]);
    }

    public function nextNumber(): JsonResponse
    {
        $next = Computer::nextUniqueNumber();

        return response()->json([
            'unique_number' => $next,
            'label' => $next,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unique_number' => ['nullable', 'string', 'max:100', 'unique:computers,unique_number'],
            'label' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(ComputerStatus::values())],
            'notes' => ['nullable', 'string'],
        ]);

        $next = Computer::nextUniqueNumber();
        $data['unique_number'] = $data['unique_number'] ?: $next;
        $data['label'] = $data['label'] ?: $data['unique_number'];

        $computer = Computer::create($data);
        $computer->load(['currentAssignment.employee']);

        return response()->json(['data' => $this->serialize($computer)], 201);
    }

    public function update(Request $request, Computer $computer): JsonResponse
    {
        $data = $request->validate([
            'unique_number' => ['sometimes', 'string', 'max:100', Rule::unique('computers', 'unique_number')->ignore($computer->id)],
            'label' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(ComputerStatus::values())],
            'notes' => ['nullable', 'string'],
        ]);

        $computer->update($data);
        $computer->load(['currentAssignment.employee']);

        return response()->json(['data' => $this->serialize($computer)]);
    }

    public function destroy(Computer $computer): JsonResponse
    {
        $computer->delete();

        return response()->json(['message' => 'Computer deleted.']);
    }

    public function assign(Request $request, Computer $computer): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:users,id'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $employee = User::findOrFail($data['employee_id']);
        $activeCount = $employee->activeComputerAssignments()->count();

        if ($activeCount >= 3 && ! ($data['force'] ?? false)) {
            return response()->json([
                'message' => 'This employee already has 3 computers assigned.',
                'over_limit' => true,
                'current_count' => $activeCount,
            ], 422);
        }

        if ($computer->currentAssignment) {
            throw ValidationException::withMessages([
                'computer' => ['This computer is already assigned. Unassign it first.'],
            ]);
        }

        $assignment = ComputerAssignment::create([
            'computer_id' => $computer->id,
            'employee_id' => $employee->id,
            'assigned_by' => $request->user()->id,
            'assigned_at' => now(),
        ]);

        $computer->update(['status' => ComputerStatus::Assigned]);
        $computer->load(['currentAssignment.employee']);

        return response()->json([
            'data' => $this->serialize($computer),
            'assignment_id' => $assignment->id,
            'over_limit' => $activeCount >= 3,
        ]);
    }

    public function unassign(Request $request, Computer $computer): JsonResponse
    {
        $assignment = $computer->currentAssignment;
        if (! $assignment) {
            throw ValidationException::withMessages([
                'computer' => ['This computer is not assigned.'],
            ]);
        }

        $assignment->update(['unassigned_at' => now()]);
        $computer->update(['status' => ComputerStatus::Available]);
        $computer->load(['currentAssignment.employee']);

        return response()->json(['data' => $this->serialize($computer)]);
    }

    private function serialize(Computer $computer): array
    {
        $assignment = $computer->currentAssignment;

        return [
            'id' => $computer->id,
            'unique_number' => $computer->unique_number,
            'label' => $computer->label,
            'status' => $computer->status?->value,
            'notes' => $computer->notes,
            'assigned_to' => $assignment ? [
                'assignment_id' => $assignment->id,
                'employee_id' => $assignment->employee_id,
                'name' => $assignment->employee?->name,
                'unique_id' => $assignment->employee?->unique_id,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            ] : null,
            'created_at' => $computer->created_at?->toIso8601String(),
        ];
    }
}
