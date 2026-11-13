<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionRequest extends FormRequest
{
    /**
     * Menentukan apakah pengguna berwenang melakukan permintaan ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mendefinisikan aturan validasi untuk memperbarui soal.
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::in(['pg', 'pgk', 'bs', 'jd', 'ur'])],
            'difficulty' => ['sometimes', 'required', Rule::in(['mudah', 'sedang', 'sukar'])],
            'weight' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'explanation' => ['nullable', 'string'],
            'content' => ['sometimes', 'required', 'array'],
        ];
    }
}
