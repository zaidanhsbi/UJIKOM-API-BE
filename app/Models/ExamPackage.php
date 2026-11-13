<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamPackage extends Model
{
    protected $fillable = [
        'exam_id',
        'file_path',
        'sha256',
        'version',
        'status',
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    /**
     * Relasi ke data ujian induk.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
