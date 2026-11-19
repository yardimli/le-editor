<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EditorialMessage extends Model {
    protected $guarded = ['id'];
    protected $casts = ['suggestions' => 'array'];
    public function book() { return $this->belongsTo(Book::class); }
}
