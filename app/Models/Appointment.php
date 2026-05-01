<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'victim_report_id',
        'created_by',
        'assigned_to',
        'client_name',
        'appointment_type',
        'district',
        'scheduled_at',
        'status',
        'notes',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'victim_report_id' => 'integer',
        'created_by'      => 'integer',
        'assigned_to'     => 'integer',
        'scheduled_at'    => 'datetime',
        'completed_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
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