<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chunk extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['segments' => 'array'];
    public function run() { return $this->belongsTo(EditingRun::class, 'editing_run_id'); }
}
