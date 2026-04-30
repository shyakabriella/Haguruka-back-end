<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ReportEvidence extends Model
{
    use HasFactory;

    protected $table = 'report_evidences';

    protected $fillable = [
        'victim_report_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'source',
    ];

    protected $casts = [
        'victim_report_id' => 'integer',
        'file_size'        => 'integer',
    ];

    protected $appends = [
        'file_url',
    ];

    public function victimReport()
    {
        return $this->belongsTo(VictimReport::class, 'victim_report_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk('public')->url($this->file_path);
    }
}