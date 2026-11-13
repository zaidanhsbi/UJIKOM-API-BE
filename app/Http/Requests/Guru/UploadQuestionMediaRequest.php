<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;

class UploadQuestionMediaRequest extends FormRequest
{
    /**
     * Menentukan apakah pengguna berwenang melakukan permintaan ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mendefinisikan aturan validasi untuk unggah media soal.
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Mendefinisikan pesan kesalahan validasi kustom.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'File gambar wajib dipilih.',
            'file.image' => 'File harus berupa gambar yang valid.',
            'file.mimes' => 'Format gambar hanya menerima JPG, JPEG, PNG, atau WEBP.',
            'file.max' => 'Ukuran gambar maksimal 2 MB.',
        ];
    }
}
