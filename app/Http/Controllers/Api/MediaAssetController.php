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
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'application/pdf',
        'video/mp4',
        'video/3gpp',
        'video/webm',
        'video/quicktime',
    ];

    public function store(Request $request, WhatsAppMediaService $mediaService): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20 MB
            'purpose' => ['nullable', 'string', 'max:40'],
        ]);

        $file = $data['file'];
        $mime = strtolower((string) ($file->getMimeType() ?: 'application/octet-stream'));
        $ext = strtolower((string) $file->getClientOriginalExtension());

        $allowedByExt = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp4', '3gp', 'mov', 'webm'], true);
        $allowedByMime = in_array($mime, self::ALLOWED_MIMES, true)
            || str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/');

        if (! $allowedByExt && ! $allowedByMime) {
            return response()->json([
                'message' => 'Formato no permitido. Usa JPG, PNG, WebP, PDF o MP4/3GP/MOV.',
            ], 422);
        }

        $purpose = (string) ($data['purpose'] ?? 'upload');
        if (str_starts_with($mime, 'video/') && ($purpose === '' || $purpose === 'upload')) {
            $purpose = 'flow-media';
        }
        if (in_array($purpose, ['flow_media', 'flowmedia'], true)) {
            $purpose = 'flow-media';
        }

        $asset = $mediaService->storeUploadedFile(
            $request->user()->tenant_id,
            $file,
            $purpose,
            $mime
        );

        return response()->json([
            'data' => [
                'id' => $asset->id,
                'mime' => $asset->mime,
                'size_bytes' => $asset->size_bytes,
                'path' => $asset->path,
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
            [
                'Content-Type' => $mediaAsset->mime ?: 'application/octet-stream',
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }
}
