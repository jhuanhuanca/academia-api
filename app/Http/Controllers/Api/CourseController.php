<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courses = Course::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $courses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $course = Course::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
            'uuid' => (string) Str::uuid(),
            'slug' => $data['slug'] ?? Str::slug($data['title']),
        ]);

        return response()->json(['data' => $course], 201);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->assertTenant($request, $course->tenant_id);

        return response()->json(['data' => $course]);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $this->assertTenant($request, $course->tenant_id);
        $data = $this->validated($request, false);
        $course->update($data);

        return response()->json(['data' => $course->fresh()]);
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        $this->assertTenant($request, $course->tenant_id);
        $course->delete();

        return response()->json(['message' => 'Curso eliminado']);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'delivery_type' => ['nullable', 'in:link,credentials,manual'],
            'delivery_payload' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function assertTenant(Request $request, int $tenantId): void
    {
        abort_if($request->user()->tenant_id !== $tenantId, 404);
    }
}
