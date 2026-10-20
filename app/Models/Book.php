<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['learn' => 'boolean'];
    protected $hidden = ['original_text', 'original_path'];

    public function user() { return $this->belongsTo(User::class); }
    public function runs() { return $this->hasMany(EditingRun::class); }
    public function activeRun() { return $this->belongsTo(EditingRun::class, 'active_run_id'); }
    public function versions() { return $this->hasMany(ManuscriptVersion::class); }
    public function promptRevisions() { return $this->hasMany(PromptRevision::class); }
}
