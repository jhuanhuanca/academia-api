<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController extends Controller
{
    public function store(Request $request, WhatsAppMediaService $mediaService): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480', // 20 MB (videos cortos para WhatsApp)
                'mimes:jpg,jpeg,png,webp,pdf,mp4,3gp,mov,webm',
            ],
            'purpose' => ['nullable', 'string', 'max:40'],
        ]);

        $file = $data['file'];
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            return response()->json(['message' => 'No se pudo leer el archivo'], 422);
        }

        $purpose = $data['purpose'] ?? 'upload';
        if (str_starts_with((string) $mime, 'video/') && $purpose === 'upload') {
            $purpose = 'flow-media';
        }

        $asset = $mediaService->storeFromBase64(
            $request->user()->tenant_id,
            'data:'.$mime.';base64,'.base64_encode($binary),
            $purpose,
            $mime
        );

        return response()->json([
            'data' => [
                'id' => $asset->id,
                'mime' => $asset->mime,
                'size_bytes' => $asset->size_bytes,
                'url' => url('/api/media-assets/'.$asset->id),
            ],
        ], 201);
    }

    public function show(Request $request, MediaAsset $mediaAsset, WhatsAppMediaService $mediaService): StreamedResponse|JsonResponse
    {
        abort_if($request->user()->tenant_id !== $mediaAsset->tenant_id, 404);

        $mediaAsset = $mediaService->healPaymentQrAsset($mediaAsset);

        if (! Storage::disk($mediaAsset->disk)->exists($mediaAsset->path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        $name = Str::afterLast($mediaAsset->path, '/') ?: 'archivo';

        return Storage::disk($mediaAsset->disk)->response(
            $mediaAsset->path,
            $name,
            ['Content-Type' => $mediaAsset->mime]
        );
    }
}
