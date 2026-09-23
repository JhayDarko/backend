<?php

namespace App\Services\AI\Providers;

use App\Contracts\ClassifierProviderInterface;
use App\Exceptions\AiProviderException;
use App\Services\AI\DTO\ClassificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class BertFakeNewsProvider implements ClassifierProviderInterface
{
    private string $baseUrl;

    private int $timeout;

    private string $model;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ai.bert.base_url', 'http://ml-service:8000'), '/');
        $this->timeout = (int) config('ai.timeout', 30);
        $this->model = (string) config('ai.bert.model', 'Juanillaberia/spanish-fake-news-classifier');
    }

    public function classify(string $title, string $content, ?string $description = null): ClassificationResult
    {
        $payload = [
            'title' => $title,
            'content' => $content,
            'description' => $description ?? '',
            'model' => $this->model,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post($this->baseUrl . '/classify', $payload);
        } catch (ConnectionException $exception) {
            throw AiProviderException::connection($exception->getMessage());
        }

        if ($response->failed()) {
            throw AiProviderException::httpError(
                (int) $response->status(),
                $response->body() ?: 'Classification service request failed.'
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw AiProviderException::invalidResponse('Classification response is not a valid JSON object.');
        }

        $label = strtoupper(trim((string) ($data['label'] ?? $data['prediction'] ?? '')));

        if (! in_array($label, ['REAL', 'FAKE'], true)) {
            throw AiProviderException::validation([
                "Unsupported label '{$label}'. Expected REAL or FAKE.",
            ]);
        }

        $confidence = (float) ($data['confidence'] ?? $data['probability'] ?? 0.0);
        $probability = (float) ($data['probability'] ?? $data['confidence'] ?? $confidence);

        if ($confidence < 0.0 || $confidence > 1.0 || $probability < 0.0 || $probability > 1.0) {
            throw AiProviderException::validation([
                'Classification score must be between 0 and 1.',
            ]);
        }

        return ClassificationResult::from([
            'label' => $label,
            'confidence' => $confidence,
            'probability' => $probability,
            'model' => (string) ($data['model'] ?? $this->model),
            'rawData' => $data,
        ]);
    }

    public function getName(): string
    {
        return (string) config('ai.classifier.provider', 'bert');
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
