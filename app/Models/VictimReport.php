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

        // Case final action fields
        'withdraw_reason',
        'withdrawn_at',
        'withdrawn_by',
        'closed_reason',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'user_id'      => 'integer',
        'latitude'     => 'decimal:7',
        'longitude'    => 'decimal:7',
        'withdrawn_at' => 'datetime',
        'withdrawn_by' => 'integer',
        'closed_at'    => 'datetime',
        'closed_by'    => 'integer',
    ];

    public function evidences()
    {
        return $this->hasMany(ReportEvidence::class, 'victim_report_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function withdrawnBy()
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function followUpTasks()
    {
        return $this->hasMany(CaseFollowUpTask::class, 'victim_report_id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'victim_report_id');
    }
}