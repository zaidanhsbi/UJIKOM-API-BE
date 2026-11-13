<?php

namespace App\Http\Requests\Siswa;

use Illuminate\Foundation\Http\FormRequest;

class SubmitExamRequest extends FormRequest
{
    /**
     * Memastikan pengguna yang mengumpulkan ujian memiliki peran siswa.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'siswa';
    }

    /**
     * Aturan validasi payload pengumpulan jawaban ujian siswa.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.answer' => ['nullable'],
            'answers.*.ver' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
