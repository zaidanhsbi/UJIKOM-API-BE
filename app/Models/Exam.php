<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Exam extends Model
{
    use HasFactory, BelongsToSchool;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_FINISHED = 'finished';

    protected $fillable = [
        'school_id',
        'teacher_id',
        'subject_id',
        'question_bank_id',
        'name',
        'academic_year',
        'starts_at',
        'ends_at',
        'duration_minutes',
        'token',
        'shuffle_questions',
        'shuffle_options',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'duration_minutes' => 'integer',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
    ];

    /**
     * Relasi ke sekolah pemilik ujian.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Relasi ke guru pembuat ujian.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Relasi ke mata pelajaran ujian.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Relasi ke bank soal yang digunakan ujian.
     */
    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    /**
     * Relasi ke daftar rombel peserta ujian.
     */
    public function rombels(): BelongsToMany
    {
        return $this->belongsToMany(Rombel::class, 'exam_rombels', 'exam_id', 'rombel_id');
    }

    /**
     * Relasi ke paket soal ujian terbaru.
     */
    public function package(): HasOne
    {
        return $this->hasOne(ExamPackage::class, 'exam_id')->latestOfMany();
    }

    /**
     * Relasi ke seluruh pengumpulan jawaban siswa pada ujian ini.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(ExamSubmission::class, 'exam_id');
    }

    /**
     * Scope untuk memfilter ujian yang tampil pada daftar siswa sesuai rombel.
     */
    public function scopeVisibleToStudent(Builder $query, User $student): Builder
    {
        return $query
            ->forSchool((int) $student->school_id)
            ->when($student->rombel_id, function (Builder $q) use ($student) {
                $q->whereHas('rombels', fn (Builder $rq) => $rq->where('rombels.id', $student->rombel_id));
            })
            ->whereIn('status', [
                self::STATUS_SCHEDULED,
                self::STATUS_ONGOING,
                self::STATUS_FINISHED,
            ]);
    }

    /**
     * Scope untuk memfilter ujian yang siap direkap oleh guru (sudah selesai atau sudah memiliki submission selesai).
     */
    public function scopeRecapReady(Builder $query, int $schoolId, int $teacherId): Builder
    {
        return $query
            ->forSchool($schoolId)
            ->where('teacher_id', $teacherId)
            ->where(function (Builder $q) {
                $q->where('status', self::STATUS_FINISHED)
                    ->orWhereHas('submissions', fn (Builder $sub) => $sub->whereNotNull('finished_at'));
            });
    }
}
