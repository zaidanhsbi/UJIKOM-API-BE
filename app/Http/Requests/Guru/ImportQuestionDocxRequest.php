<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

class ImportQuestionDocxRequest extends FormRequest
{
    /**
     * Menentukan apakah pengguna berwenang melakukan permintaan ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mendefinisikan aturan validasi untuk impor soal dari DOCX.
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:docx', 'max:10240'],
        ];
    }
}
