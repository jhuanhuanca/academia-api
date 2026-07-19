<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = KnowledgeItem::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'content' => ['required', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'course_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $item = KnowledgeItem::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, KnowledgeItem $knowledgeItem): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $knowledgeItem->tenant_id, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'content' => ['sometimes', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'course_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $knowledgeItem->update($data);

        return response()->json(['data' => $knowledgeItem->fresh()]);
    }

    public function destroy(Request $request, KnowledgeItem $knowledgeItem): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $knowledgeItem->tenant_id, 404);
        $knowledgeItem->delete();

        return response()->json(['message' => 'Knowledge eliminado']);
    }
}
