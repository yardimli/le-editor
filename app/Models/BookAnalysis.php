<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookAnalysis extends Model {
 protected $guarded=['id'];
 protected $hidden=['input_text','token'];
 public function book() { return $this->belongsTo(Book::class); }
}
