<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectRequest extends FormRequest
{
    /**
     * Memastikan hanya admin yang berwenang mengelola mata pelajaran.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'admin';
    }

    /**
     * Mendefinisikan aturan validasi untuk mata pelajaran.
     */
    public function rules(): array
    {
        $schoolId = (int) ($this->user()?->school_id ?? 0);
        $subjectParam = $this->route('subject');
        $subjectId = is_object($subjectParam) ? $subjectParam->id : $subjectParam;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('subjects', 'code')
                    ->where(fn ($query) => $query->where('school_id', $schoolId))
                    ->ignore($subjectId),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
            ],
        ];
    }

    /**
     * Mendefinisikan pesan kesalahan validasi kustom.
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode mata pelajaran wajib diisi.',
            'code.max' => 'Kode mata pelajaran maksimal 50 karakter.',
            'code.unique' => 'Kode mata pelajaran sudah digunakan di sekolah ini.',
            'name.required' => 'Nama mata pelajaran wajib diisi.',
            'name.max' => 'Nama mata pelajaran maksimal 150 karakter.',
        ];
    }
}

