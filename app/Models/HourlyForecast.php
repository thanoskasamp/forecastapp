<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HourlyForecast extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'collected',
        'provider',
        'lat',
        'lon',
        'time',
        'temp',
        'prec',
    ];

    // Preserve fractional response receipt times when saving dates.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'collected' => 'datetime',
            'lat' => 'decimal:6',
            'lon' => 'decimal:6',
            'time' => 'datetime',
            'temp' => 'decimal:2',
            'prec' => 'decimal:2',
        ];
    }
}
