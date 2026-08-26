<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unique_id' => $this->unique_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'interview_date' => $this->interview_date?->toDateString(),
            'joining_date' => $this->joining_date?->toDateString(),
            'monthly_salary' => $this->monthly_salary !== null ? (float) $this->monthly_salary : null,
            'notes' => $this->notes,
            'role' => $this->primaryRole(),
            'roles' => $this->getRoleNames()->values(),
            'computers' => $this->whenLoaded('activeComputerAssignments', function () {
                return $this->activeComputerAssignments->map(fn ($a) => [
                    'assignment_id' => $a->id,
                    'computer_id' => $a->computer_id,
                    'unique_number' => $a->computer?->unique_number,
                    'label' => $a->computer?->label,
                    'assigned_at' => $a->assigned_at?->toIso8601String(),
                ]);
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
