<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MediaAssetController extends Controller
{
    private const ALLOWED_EXTS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf',
        'mp4', '3gp', 'mov', 'webm', 'm4v',
    ];

    public function store(Request $request, WhatsAppMediaService $mediaService): JsonResponse
    {
        $file = $this->resolveUploadedFile($request);

        if (! $file) {
            return response()->json([
                'message' => 'No llegó el archivo. Revisa que el campo se llame "file" y que el tamaño no supere el límite del servidor (PHP upload_max_filesize / post_max_size).',
                'errors' => [
                    'file' => ['El archivo es obligatorio o el servidor rechazó la subida (límite PHP).'],
                ],
                'debug' => [
                    'has_file' => $request->hasFile('file'),
                    'content_type' => $request->header('Content-Type'),
                    'keys' => array_keys($request->allFiles()),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                ],
            ], 422);
        }

        if (! $file->isValid()) {
            $code = $file->getError();
            $hint = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite de PHP (upload_max_filesize/post_max_size). Sube uno más liviano o sube el límite a 20M.',
                UPLOAD_ERR_PARTIAL => 'La subida se interrumpió. Intenta de nuevo.',
                UPLOAD_ERR_NO_FILE => 'No se recibió el archivo.',
                default => $file->getErrorMessage(),
            };

            return response()->json([
                'message' => $hint,
                'errors' => ['file' => [$hint]],
                'debug' => [
                    'upload_error' => $code,
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                ],
            ], 422);
        }

        // ~20 MB (en bytes). No usamos rule "max:" de Laravel aquí para poder dar mejor mensaje.
        $maxBytes = 20 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return response()->json([
                'message' => 'El archivo supera 20 MB. Usa una imagen más liviana o un video más corto.',
                'errors' => ['file' => ['Máximo 20 MB']],
            ], 422);
        }

        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'));
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === '' && str_contains($file->getClientOriginalName(), '.')) {
            $ext = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        }

        $allowedByExt = $ext !== '' && in_array($ext, self::ALLOWED_EXTS, true);
        $allowedByMime = str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || $mime === 'application/pdf'
            || $mime === 'application/octet-stream'; // algunos móviles no reportan mime real

        // octet-stream solo si la extensión es conocida
        if ($mime === 'application/octet-stream' && ! $allowedByExt) {
            $allowedByMime = false;
        }

        if (! $allowedByExt && ! $allowedByMime) {
            return response()->json([
                'message' => "Formato no permitido ({$mime}" . ($ext !== '' ? ", .{$ext}" : '') . '). Usa JPG, PNG, WebP, PDF o MP4.',
                'errors' => ['file' => ['Formato no permitido']],
            ], 422);
        }

        // Normaliza mime genérico según extensión
        if ($mime === 'application/octet-stream' || $mime === '') {
            $mime = match ($ext) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'pdf' => 'application/pdf',
                'mp4', 'm4v' => 'video/mp4',
                'webm' => 'video/webm',
                'mov' => 'video/quicktime',
                '3gp' => 'video/3gpp',
                default => 'image/jpeg',
            };
        }

        $purpose = (string) $request->input('purpose', 'upload');
        if ($purpose === '' || $purpose === 'upload') {
            $purpose = str_starts_with($mime, 'video/') ? 'flow-media' : 'uploads';
        }
        if (in_array($purpose, ['flow_media', 'flowmedia', 'flow-media'], true)) {
            $purpose = 'flow-media';
        }

        try {
            $asset = $mediaService->storeUploadedFile(
                (int) $request->user()->tenant_id,
                $file,
                $purpose,
                $mime
            );
        } catch (Throwable $e) {
            Log::error('media-assets store falló', [
                'error' => $e->getMessage(),
                'mime' => $mime,
                'ext' => $ext,
                'purpose' => $purpose,
                'size' => $file->getSize(),
            ]);

            return response()->json([
                'message' => 'No se pudo guardar el archivo en el servidor: '.$e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

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

    private function resolveUploadedFile(Request $request): ?UploadedFile
    {
        foreach (['file', 'image', 'media', 'video'] as $key) {
            if ($request->hasFile($key)) {
                $uploaded = $request->file($key);
                if ($uploaded instanceof UploadedFile) {
                    return $uploaded;
                }
            }
        }

        return null;
    }
}
