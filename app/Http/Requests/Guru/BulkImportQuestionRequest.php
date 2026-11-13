<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

class BulkImportQuestionRequest extends FormRequest
{
    /**
     * Menentukan apakah pengguna berwenang melakukan permintaan ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mendefinisikan aturan validasi untuk impor massal soal.
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.type' => ['required', 'string'],
            'rows.*.text' => ['nullable', 'string'],
            'rows.*.option_a' => ['nullable', 'string'],
            'rows.*.option_b' => ['nullable', 'string'],
            'rows.*.option_c' => ['nullable', 'string'],
            'rows.*.option_d' => ['nullable', 'string'],
            'rows.*.option_e' => ['nullable', 'string'],
            'rows.*.answer' => ['nullable', 'string'],
            'rows.*.weight' => ['nullable'],
        ];
    }
}
