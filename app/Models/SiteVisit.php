<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteVisit extends Model
{
    protected $fillable = [
        'visited_on',
        'visitor_hash',
        'path',
        'referrer_host',
        'country_code',
        'region',
        'city',
    ];

    protected $casts = [
        'visited_on' => 'date',
    ];
}
