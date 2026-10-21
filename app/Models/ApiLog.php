<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['cost' => 'decimal:10'];
    public function book() { return $this->belongsTo(Book::class); }
}
