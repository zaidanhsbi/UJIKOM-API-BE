<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RombelRequest extends FormRequest
{
    /**
     * Memastikan hanya admin yang berwenang mengelola rombel.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'admin';
    }

    /**
     * Mendefinisikan aturan validasi untuk data rombel.
     */
    public function rules(): array
    {
        $schoolId = (int) ($this->user()?->school_id ?? 0);
        $rombelParam = $this->route('rombel');
        $rombelId = is_object($rombelParam) ? $rombelParam->id : $rombelParam;

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('rombels', 'name')
                    ->where(fn ($query) => $query->where('school_id', $schoolId))
                    ->ignore($rombelId),
            ],
        ];
    }

    /**
     * Mendefinisikan pesan kesalahan validasi kustom.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama rombel / kelas wajib diisi.',
            'name.max' => 'Nama rombel / kelas maksimal 100 karakter.',
            'name.unique' => 'Nama rombel / kelas sudah digunakan di sekolah ini.',
        ];
    }
}

