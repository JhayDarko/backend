<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOcrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Adjunta una imagen de la noticia.',
            'image.image' => 'El archivo debe ser una imagen.',
            'image.mimes' => 'Formatos permitidos: JPG, PNG o WebP.',
            'image.max' => 'La imagen no puede superar 5 MB.',
        ];
    }
}
