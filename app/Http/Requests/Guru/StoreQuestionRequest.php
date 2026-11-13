<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    /**
     * Menentukan apakah pengguna berwenang melakukan permintaan ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mendefinisikan aturan validasi untuk menyimpan soal baru.
     */
    public function rules(): array
    {
        $schoolId = (int) $this->user()?->school_id;

        return [
            'bank_id' => ['nullable', 'integer'],
            'new_bank' => ['nullable', 'array'],
            'new_bank.name' => ['required_with:new_bank', 'string', 'max:255'],
            'new_bank.subject_id' => [
                'required_with:new_bank',
                'integer',
                Rule::exists('subjects', 'id')->where('school_id', $schoolId),
            ],
            'new_bank.grade' => ['required_with:new_bank', 'string', 'max:50'],
            'new_bank.visibility' => ['nullable', Rule::in(['private', 'school'])],
            'type' => ['required', Rule::in(['pg', 'pgk', 'bs', 'jd', 'ur'])],
            'difficulty' => ['required', Rule::in(['mudah', 'sedang', 'sukar'])],
            'weight' => ['required', 'integer', 'min:1', 'max:100'],
            'explanation' => ['nullable', 'string'],
            'content' => ['required', 'array'],
        ];
    }
}
