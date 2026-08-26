<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;

class FloorController extends Controller
{
    public function show(AttendanceService $attendance): JsonResponse
    {
        return response()->json($attendance->floor());
    }
}
