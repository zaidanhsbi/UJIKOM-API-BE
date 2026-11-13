<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    /**
     * Memastikan hanya admin yang berwenang mengelola data siswa.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'admin';
    }

    /**
     * Mendefinisikan aturan validasi untuk data siswa.
     */
    public function rules(): array
    {
        $schoolId = (int) ($this->user()?->school_id ?? 0);
        $studentParam = $this->route('student');
        $studentId = is_object($studentParam) ? $studentParam->id : $studentParam;
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'nisn' => [
                'required',
                'string',
                'max:50',
                Rule::unique('users', 'nisn')
                    ->where(fn ($query) => $query->where('school_id', $schoolId))
                    ->ignore($studentId),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'rombel_id' => [
                'required',
                'integer',
                Rule::exists('rombels', 'id')
                    ->where(fn ($query) => $query->where('school_id', $schoolId)),
            ],
            'password' => [
                $isUpdate ? 'nullable' : 'required',
                'string',
                'min:4',
            ],
        ];
    }

    /**
     * Mendefinisikan pesan kesalahan validasi kustom.
     */
    public function messages(): array
    {
        return [
            'nisn.required' => 'NISN siswa wajib diisi.',
            'nisn.max' => 'NISN maksimal 50 karakter.',
            'nisn.unique' => 'NISN sudah terdaftar di sekolah ini.',
            'name.required' => 'Nama lengkap siswa wajib diisi.',
            'name.max' => 'Nama lengkap siswa maksimal 150 karakter.',
            'rombel_id.required' => 'Rombel / kelas wajib dipilih.',
            'rombel_id.exists' => 'Rombel / kelas yang dipilih tidak ditemukan di sekolah ini.',
            'password.required' => 'Kata sandi siswa wajib diisi.',
            'password.min' => 'Kata sandi minimal 4 karakter.',
        ];
    }
}

