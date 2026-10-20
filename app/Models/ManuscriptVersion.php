<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManuscriptVersion extends Model
{
    protected $casts = ['italics' => 'array'];
    protected $guarded = ['id'];
}
