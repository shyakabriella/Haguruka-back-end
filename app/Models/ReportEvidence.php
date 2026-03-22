<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function victimReport()
    {
        return $this->belongsTo(VictimReport::class, 'victim_report_id');
    }
}