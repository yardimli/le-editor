<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $table = 'feedback';
    protected $guarded = ['id'];
    public function book() { return $this->belongsTo(Book::class); }
}
