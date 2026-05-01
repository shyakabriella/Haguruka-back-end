<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServicePoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'district',
        'sector',
        'phone',
        'email',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'latitude'        => 'decimal:7',
        'longitude'       => 'decimal:7',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}