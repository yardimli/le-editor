<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EditingRun extends Model
{
    protected $guarded = ['id'];
    public function book() { return $this->belongsTo(Book::class); }
    public function chunks() { return $this->hasMany(Chunk::class)->orderBy('position'); }
    public function isActive(): bool { return in_array($this->status, ['queued', 'running']); }
}
