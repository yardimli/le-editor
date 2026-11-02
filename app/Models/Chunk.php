<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chunk extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['review_settings' => 'array', 'segments' => 'array', 'revision' => 'integer', 'ai_edit_count' => 'integer'];
    public function run() { return $this->belongsTo(EditingRun::class, 'editing_run_id'); }
}
