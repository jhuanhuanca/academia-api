<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController extends Controller
{
    public function show(Request $request, MediaAsset $mediaAsset): StreamedResponse|JsonResponse
    {
        abort_if($request->user()->tenant_id !== $mediaAsset->tenant_id, 404);

        if (! Storage::disk($mediaAsset->disk)->exists($mediaAsset->path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        return Storage::disk($mediaAsset->disk)->response(
            $mediaAsset->path,
            'comprobante',
            ['Content-Type' => $mediaAsset->mime]
        );
    }
}
