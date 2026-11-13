<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherRequest extends FormRequest
{
    /**
     * Memastikan hanya admin yang berwenang mengelola data guru.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'admin';
    }

    /**
     * Mendefinisikan aturan validasi untuk data guru.
     */
    public function rules(): array
    {
        $schoolId = (int) ($this->user()?->school_id ?? 0);
        $teacherParam = $this->route('teacher');
        $teacherId = is_object($teacherParam) ? $teacherParam->id : $teacherParam;
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'username' => [
                'required',
                'string',
                'max:100',
                Rule::unique('users', 'username')
                    ->where(fn ($query) => $query->where('school_id', $schoolId))
                    ->ignore($teacherId),
            ],
            'name' => ['required', 'string', 'max:150'],
            'password' => [$isUpdate ? 'nullable' : 'required', 'string', 'min:4'],
            'subject_ids' => ['nullable', 'array'],
            'subject_ids.*' => [
                'integer',
                Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)),
            ],
            'rombel_ids' => ['nullable', 'array'],
            'rombel_ids.*' => [
                'integer',
                Rule::exists('rombels', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)),
            ],
        ];
    }

    /**
     * Mendefinisikan pesan kesalahan validasi kustom.
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Username / NUPTK guru wajib diisi.',
            'username.max' => 'Username / NUPTK maksimal 100 karakter.',
            'username.unique' => 'Username / NUPTK sudah terdaftar di sekolah ini.',
            'name.required' => 'Nama guru wajib diisi.',
            'name.max' => 'Nama guru maksimal 150 karakter.',
            'password.required' => 'Kata sandi guru wajib diisi.',
            'password.min' => 'Kata sandi minimal 4 karakter.',
            'subject_ids.*.exists' => 'Mata pelajaran yang dipilih tidak ditemukan.',
            'rombel_ids.*.exists' => 'Kelas / rombel yang dipilih tidak ditemukan.',
        ];
    }
}
