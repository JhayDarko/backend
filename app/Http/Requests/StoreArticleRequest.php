<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
                    if (empty($value)) {
                        return;
                    }

                    $host = strtolower((string) parse_url($value, PHP_URL_HOST));

                    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
                        $fail('The URL host is not allowed.');

                        return;
                    }

                    if (filter_var($host, FILTER_VALIDATE_IP)
                        && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        $fail('The URL host is not allowed.');
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