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
        return response()->json([
            'session_minutes' => AppSetting::sessionMinutes(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_minutes' => ['required', 'integer', 'min:1', 'max:180'],
        ]);

        AppSetting::setValue('session_minutes', $data['session_minutes']);

        return response()->json([
            'message' => 'Work session length saved.',
            'session_minutes' => AppSetting::sessionMinutes(),
        ]);
    }
}
