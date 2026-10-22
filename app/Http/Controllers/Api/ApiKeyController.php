<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lets a signed-in user manage their API keys and see consumption metrics. */
final class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = $request->user()->apiKeys()->whereNull('revoked_at')->latest('id')->get();
        $since = today()->subDays(29)->toDateString();

        $usage = ApiUsage::query()
            ->whereIn('api_key_id', $keys->pluck('id'))
            ->where('date', '>=', $since)
            ->get()
            ->groupBy('api_key_id');

        return response()->json(['data' => $keys->map(fn (ApiKey $key): array => [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'plan' => $key->plan,
            'limits' => $key->limits(),
            'last_used_at' => $key->last_used_at,
            'created_at' => $key->created_at,
            'usage_30d' => [
                'requests' => (int) ($usage[$key->id] ?? collect())->sum('requests'),
                'analyses' => (int) ($usage[$key->id] ?? collect())->sum('analyses'),
                'errors' => (int) ($usage[$key->id] ?? collect())->sum('errors'),
            ],
            'daily' => ($usage[$key->id] ?? collect())->sortBy('date')->map(fn ($row): array => [
                'date' => $row->date->toDateString(), 'requests' => $row->requests, 'analyses' => $row->analyses,
            ])->values(),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        if ($request->user()->apiKeys()->whereNull('revoked_at')->count() >= 5) {
            return response()->json(['message' => 'Puedes tener hasta 5 API keys activas.'], 422);
        }

        [$key, $plain] = ApiKey::issue($request->user(), $data['name']);

        return response()->json([
            'data' => ['id' => $key->id, 'name' => $key->name, 'prefix' => $key->prefix, 'plan' => $key->plan],
            'key' => $plain,
            'message' => 'Guarda esta clave ahora: no se volverá a mostrar.',
        ], 201);
    }

    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        abort_unless($apiKey->user_id === $request->user()->id, 403);

        $apiKey->forceFill(['revoked_at' => now()])->save();

        return response()->json(['message' => 'API key revocada.']);
    }
}
