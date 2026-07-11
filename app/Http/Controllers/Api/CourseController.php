<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courses = Course::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('paymentQr')
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

        return response()->json(['data' => $course->load('paymentQr')], 201);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->assertTenant($request, $course->tenant_id);

        return response()->json(['data' => $course->load('paymentQr')]);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $this->assertTenant($request, $course->tenant_id);
        $data = $this->validated($request, false);
        $course->update($data);

        return response()->json(['data' => $course->fresh()->load('paymentQr')]);
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        $this->assertTenant($request, $course->tenant_id);
        $course->delete();

        return response()->json(['message' => 'Curso eliminado']);
    }

    public function uploadPaymentQr(Request $request, Course $course, WhatsAppMediaService $mediaService): JsonResponse
    {
        $this->assertTenant($request, $course->tenant_id);

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'image/jpeg';
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            return response()->json(['message' => 'No se pudo leer la imagen'], 422);
        }

        $asset = $mediaService->storeFromBase64(
            $request->user()->tenant_id,
            'data:'.$mime.';base64,'.base64_encode($binary),
            'payment-qr',
            $mime
        );

        $course->update(['payment_qr_media_asset_id' => $asset->id]);

        return response()->json([
            'data' => $course->fresh()->load('paymentQr'),
            'message' => 'QR de cobro guardado. Se enviará por WhatsApp al cobrar.',
        ]);
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
            'payment_qr_media_asset_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function assertTenant(Request $request, int $tenantId): void
    {
        abort_if($request->user()->tenant_id !== $tenantId, 404);
    }
}
