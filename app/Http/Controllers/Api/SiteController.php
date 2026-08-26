<?php

namespace App\Http\Controllers\Api;

use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\Keyword;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    public function index(): JsonResponse
    {
        $sites = Site::query()->with('keywords')->orderBy('name')->get();

        return response()->json([
            'data' => $sites->map(fn (Site $site) => $this->serialize($site)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(SiteStatus::values())],
            'notes' => ['nullable', 'string'],
        ]);

        $site = Site::create($data);
        $site->load('keywords');

        return response()->json(['data' => $this->serialize($site)], 201);
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(SiteStatus::values())],
            'notes' => ['nullable', 'string'],
        ]);

        $site->update($data);
        $site->load('keywords');

        return response()->json(['data' => $this->serialize($site)]);
    }

    public function destroy(Site $site): JsonResponse
    {
        $site->delete();

        return response()->json(['message' => 'Site deleted.']);
    }

    public function storeKeyword(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:255', Rule::unique('keywords', 'keyword')->where('site_id', $site->id)],
            'status' => ['nullable', Rule::in(SiteStatus::values())],
        ]);

        $keyword = $site->keywords()->create($data);
        $site->load('keywords');

        return response()->json([
            'data' => $this->serializeKeyword($keyword),
            'site' => $this->serialize($site),
        ], 201);
    }

    public function updateKeyword(Request $request, Keyword $keyword): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:255', Rule::unique('keywords', 'keyword')->where('site_id', $keyword->site_id)->ignore($keyword->id)],
            'status' => ['nullable', Rule::in(SiteStatus::values())],
        ]);

        $keyword->update($data);

        return response()->json(['data' => $this->serializeKeyword($keyword)]);
    }

    public function destroyKeyword(Keyword $keyword): JsonResponse
    {
        $keyword->delete();

        return response()->json(['message' => 'Keyword deleted.']);
    }

    private function serialize(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'url' => $site->url,
            'status' => $site->status?->value,
            'notes' => $site->notes,
            'keywords' => $site->keywords->map(fn (Keyword $k) => $this->serializeKeyword($k))->values(),
            'created_at' => $site->created_at?->toIso8601String(),
        ];
    }

    private function serializeKeyword(Keyword $keyword): array
    {
        return [
            'id' => $keyword->id,
            'site_id' => $keyword->site_id,
            'keyword' => $keyword->keyword,
            'status' => $keyword->status?->value,
        ];
    }
}
