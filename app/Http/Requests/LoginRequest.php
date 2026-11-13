<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Menentukan apakah pengguna berwenang melakukan permintaan login.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mendefinisikan aturan validasi untuk login.
     */
    public function rules(): array
    {
        return [
            'npsn' => ['nullable', 'string', 'max:50'],
            'identifier' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:100'],
            'nisn' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Mengambil pengenal login dari input yang tersedia.
     */
    public function getLoginIdentifier(): string
    {
        return (string) ($this->input('identifier') ?? $this->input('email') ?? $this->input('username') ?? $this->input('nisn') ?? '');
    }

    /**
     * Mendefinisikan pesan kesalahan validasi kustom.
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Kata sandi wajib diisi.',
        ];
    }
}
