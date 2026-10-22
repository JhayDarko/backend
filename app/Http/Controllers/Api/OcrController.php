<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOcrRequest;
use App\Http\Resources\ArticleResource;
use App\Services\Article\ArticleIngestionService;
use App\Services\OCR\GeminiImageTextClassifier;
use App\Services\OCR\ImageOCRService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class OcrController extends Controller
{
    public function __construct(
        private readonly ImageOCRService $ocr,
        private readonly GeminiImageTextClassifier $textClassifier,
        private readonly ArticleIngestionService $ingestion,
    ) {}

    public function store(StoreOcrRequest $request): JsonResponse
    {
        $image = $request->file('image');

        try {
            $recognized = $this->ocr->extractText($image);
        } catch (AiProviderException $exception) {
            Log::warning('OCR failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'No se pudo leer la imagen. Verifica que el servicio local PaddleOCR esté activo e inténtalo de nuevo.',
            ], 503);
        }

        if ($recognized['lines'] === []) {
            return response()->json(['message' => 'No se detectó texto suficiente en la imagen.'], 422);
        }

        try {
            $classification = $this->textClassifier->classify($recognized['lines']);
        } catch (AiProviderException $exception) {
            Log::warning('Gemini OCR text classification failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'No se pudo identificar automáticamente el texto de la noticia. Verifica la configuración de Gemini e inténtalo de nuevo.',
            ], 503);
        }

        $content = $classification['news_text'];
        if (mb_strlen($content) < 30) {
            return response()->json(['message' => 'No se detectó texto suficiente en la imagen.'], 422);
        }
        if (mb_strlen($content) > 65000) {
            return response()->json(['message' => 'El texto reconocido supera el límite de 65 000 caracteres.'], 422);
        }

        $path = $image->store('ocr-images');

        $article = $this->ingestion->ingestText(
            content: $content,
            title: $classification['headline'],
            userId: $request->user()->id,
            metadata: [
                'submission_type' => 'image_ocr',
                'ocr_image_path' => $path,
                'ocr_image_sha256' => hash_file('sha256', $image->getRealPath()),
                'ocr_image_text' => $classification['image_text'],
                'ocr_profile_text' => $classification['profile_text'],
                'ocr_other_text' => $classification['other_text'],
                'ocr_text_classifier' => 'gemini',
            ],
        );

        return (new ArticleResource($article->load('source')))->response()->setStatusCode(201);
    }
}
