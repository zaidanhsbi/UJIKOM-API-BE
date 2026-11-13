<?php

namespace App\Http\Requests\Siswa;

use Illuminate\Foundation\Http\FormRequest;

class StartExamRequest extends FormRequest
{
    /**
     * Memastikan pengguna yang mengakses memiliki peran siswa.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'siswa';
    }

    /**
     * Aturan validasi payload untuk memulai sesi ujian.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }
}
