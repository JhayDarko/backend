<?php

namespace App\Services\OCR;

use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Transcribes text from an uploaded news image using the local PaddleOCR service. */
final class ImageOCRService
{
    /**
     * @return array{
     *   text: string,
     *   lines: list<array{text: string, box: ?array{int, int, int, int}, score: ?float}>,
     *   width: ?int,
     *   height: ?int
     * }
     */
    public function extractText(UploadedFile $image): array
    {
        $contents = file_get_contents($image->getRealPath());
        if ($contents === false) {
            throw AiProviderException::invalidResponse('Unable to read the uploaded image.');
        }

        $baseUrl = rtrim((string) config('ai.ocr.base_url'), '/');
        $timeout = (int) config('ai.ocr.timeout', 240);
        $connectTimeout = (int) config('ai.ocr.connect_timeout', 10);

        try {
            $response = Http::timeout($timeout)->connectTimeout($connectTimeout)->acceptJson()
                ->post($baseUrl.'/ocr', [
                    'image_base64' => base64_encode($contents),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('PaddleOCR service connection failed', ['error' => $exception->getMessage()]);

            throw AiProviderException::connection('Local PaddleOCR service is unavailable.');
        }

        if ($response->status() === 429) {
            throw AiProviderException::rateLimit();
        }

        if ($response->failed()) {
            Log::warning('PaddleOCR service request failed', ['status' => $response->status()]);

            throw AiProviderException::httpError($response->status(), 'Local PaddleOCR service request failed.');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_string($payload['text'] ?? null)) {
            throw AiProviderException::invalidResponse('Local PaddleOCR response is malformed.');
        }

        $lines = $payload['lines'] ?? null;
        if ($lines === null) {
            $text = trim($payload['text']);
            $lines = $text === ''
                ? []
                : [['text' => $text, 'box' => null, 'score' => null]];
        }
        if (! is_array($lines)) {
            throw AiProviderException::invalidResponse('Local PaddleOCR line data is malformed.');
        }

        $normalizedLines = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw AiProviderException::invalidResponse('Local PaddleOCR line data is malformed.');
            }
            $box = $line['box'] ?? null;
            $score = $line['score'] ?? null;
            if (
                ! is_string($line['text'] ?? null)
                || ($box !== null && (! is_array($box) || count($box) !== 4 || count(array_filter($box, 'is_int')) !== 4))
                || ($score !== null && ! is_numeric($score))
            ) {
                throw AiProviderException::invalidResponse('Local PaddleOCR line data is malformed.');
            }

            $normalizedLines[] = [
                'text' => trim($line['text']),
                'box' => $box,
                'score' => $score === null ? null : (float) $score,
            ];
        }

        return [
            'text' => trim($payload['text']),
            'lines' => $normalizedLines,
            'width' => is_int($payload['width'] ?? null) ? $payload['width'] : null,
            'height' => is_int($payload['height'] ?? null) ? $payload['height'] : null,
        ];
    }
}
