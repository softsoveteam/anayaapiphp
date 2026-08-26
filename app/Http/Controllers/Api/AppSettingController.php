<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_minutes' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'multiple_keywords' => ['sometimes', 'boolean'],
            'employee_earnings' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('session_minutes', $data)) {
            AppSetting::setValue('session_minutes', $data['session_minutes']);
        }

        if (array_key_exists('multiple_keywords', $data)) {
            AppSetting::setValue('multiple_keywords', $data['multiple_keywords'] ? '1' : '0');
        }

        if (array_key_exists('employee_earnings', $data)) {
            AppSetting::setValue('employee_earnings', $data['employee_earnings'] ? '1' : '0');
        }

        return response()->json([
            'message' => 'Settings saved.',
            ...$this->payload(),
        ]);
    }

    private function payload(): array
    {
        return [
            'session_minutes' => AppSetting::sessionMinutes(),
            'multiple_keywords' => AppSetting::multipleKeywords(),
            'employee_earnings' => AppSetting::employeeEarnings(),
        ];
    }
}
