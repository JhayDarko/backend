<?php

namespace App\Http\Requests;

use App\Exceptions\ArticleInputException;
use Illuminate\Foundation\Http\FormRequest;

class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $contentType = strtolower((string) $this->header('Content-Type'));

        if (str_starts_with($contentType, 'text/plain')) {
            $this->merge([
                'content' => trim($this->getContent()),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'url' => [
                'required_without:content',
                'nullable',
                'url',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! is_string($value)) {
                        $fail('La URL no es válida.');

                        return;
                    }

                    try {
                        $parts = parse_url($value);
                    } catch (\ValueError) {
                        $fail('La URL no es válida.');

                        return;
                    }

                    if (! is_array($parts)) {
                        $fail('La URL no es válida.');

                        return;
                    }

                    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                    $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));
                    $port = $parts['port'] ?? null;
                    $hasCredentials = isset($parts['user']) || isset($parts['pass']);

                    if (! in_array($scheme, ['http', 'https'], true)
                        || $host === ''
                        || $hasCredentials
                        || ($port !== null && ! in_array($port, [80, 443], true))
                        || $host === 'localhost'
                        || str_ends_with($host, '.localhost')
                        || str_ends_with($host, '.local')) {
                        $fail(ArticleInputException::BLOCKED_URL_VALIDATION_MESSAGE);

                        return;
                    }

                    if (filter_var($host, FILTER_VALIDATE_IP)
                        && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        $fail(ArticleInputException::BLOCKED_URL_VALIDATION_MESSAGE);
                    }
                },
            ],
            'content' => [
                'required_without:url',
                'nullable',
                'string',
                'min:30',
                'max:65000',
            ],
            'title' => [
                'nullable',
                'string',
                'max:255',
            ],
            'author_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }
}
