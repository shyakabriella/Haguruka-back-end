<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaseFollowUpTask extends Model
{
    use HasFactory;

    protected $table = 'case_follow_up_tasks';

    protected $fillable = [
        'victim_report_id',
        'created_by',
        'assigned_to',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'completed_at',
    ];

    protected $casts = [
        'victim_report_id' => 'integer',
        'created_by'       => 'integer',
        'assigned_to'      => 'integer',
        'due_date'         => 'date',
        'completed_at'     => 'datetime',
    ];

    public function victimReport()
    {
        return $this->belongsTo(VictimReport::class, 'victim_report_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}