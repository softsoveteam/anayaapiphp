<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function nextId(): JsonResponse
    {
        return response()->json(['unique_id' => User::nextUniqueId()]);
    }

    public function index(Request $request)
    {
        $query = User::query()
            ->with(['roles', 'activeComputerAssignments.computer'])
            ->orderBy('name');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('unique_id', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($role = $request->string('role')->toString()) {
            $query->role($role);
        }

        return UserResource::collection($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $role = $data['role'] ?? 'employee';
        unset($data['role']);

        if (empty($data['unique_id'])) {
            $data['unique_id'] = User::nextUniqueId();
        }

        if (empty($data['password'])) {
            $data['password'] = Str::password(12);
        }

        $user = User::create($data);
        $user->assignRole($role);
        $user->load(['roles', 'activeComputerAssignments.computer']);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $employee): UserResource
    {
        $employee->load(['roles', 'activeComputerAssignments.computer']);

        return new UserResource($employee);
    }

    public function update(Request $request, User $employee): UserResource
    {
        $data = $this->validated($request, $employee);
        $role = $data['role'] ?? null;
        unset($data['role'], $data['password'], $data['unique_id']);

        $employee->update($data);

        if ($role && $request->user()->hasRole('admin')) {
            $employee->syncRoles([$role]);
        }

        $employee->load(['roles', 'activeComputerAssignments.computer']);

        return new UserResource($employee);
    }

    public function destroy(User $employee): JsonResponse
    {
        if ($employee->id === request()->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $employee->delete();

        return response()->json(['message' => 'Employee deleted.']);
    }

    public function updateStatus(Request $request, User $employee): UserResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(EmployeeStatus::values())],
            'joining_date' => ['nullable', 'date'],
            'interview_date' => ['nullable', 'date'],
        ]);

        $employee->status = $data['status'];

        if (array_key_exists('joining_date', $data)) {
            $employee->joining_date = $data['joining_date'];
        } elseif ($data['status'] === EmployeeStatus::Joined->value && ! $employee->joining_date) {
            $employee->joining_date = now()->toDateString();
        }

        if (array_key_exists('interview_date', $data)) {
            $employee->interview_date = $data['interview_date'];
        }

        $employee->save();
        $employee->load(['roles', 'activeComputerAssignments.computer']);

        return new UserResource($employee);
    }

    public function updatePassword(Request $request, User $employee): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $employee->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password set.']);
    }

    private function validated(Request $request, ?User $employee = null): array
    {
        $roleRule = $request->user()?->hasRole('admin')
            ? Rule::in(['admin', 'manager', 'employee'])
            : Rule::in(['employee']);

        return $request->validate([
            'unique_id' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'unique_id')->ignore($employee?->id),
                Rule::prohibitedIf($employee !== null),
            ],
            'name' => [$employee ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($employee?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(EmployeeStatus::values())],
            'interview_date' => ['nullable', 'date'],
            'joining_date' => ['nullable', 'date'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'password' => [$employee ? 'nullable' : 'nullable', 'string', 'min:6'],
            'role' => ['nullable', $roleRule],
        ]);
    }
}
