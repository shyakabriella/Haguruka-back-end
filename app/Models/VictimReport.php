<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VictimReport extends Model
{
    use HasFactory;

    protected $table = 'victim_reports';

    protected $fillable = [
        'user_id',
        'language',
        'reporter_role',
        'urgency',
        'case_type',
        'input_mode',
        'details',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'user_id'   => 'integer',
        'latitude'  => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function evidences()
    {
        return $this->hasMany(ReportEvidence::class, 'victim_report_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}