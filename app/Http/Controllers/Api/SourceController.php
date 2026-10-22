<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Services\Source\SourceProfileService;
use Illuminate\Http\JsonResponse;

final class SourceController extends Controller
{
    public function __construct(private readonly SourceProfileService $profiles) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->profiles->ranking()]);
    }

    public function show(Source $source): JsonResponse
    {
        return response()->json(['data' => $this->profiles->profile($source)]);
    }
}
