<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('users', fn (Blueprint $t) => $t->json('analysis_prompts')->nullable());
  Schema::create('book_analyses', function (Blueprint $t) {
   $t->id(); $t->foreignId('book_id')->constrained()->cascadeOnDelete();
   $t->string('kind'); $t->string('model'); $t->unsignedInteger('word_count')->default(300);
   $t->text('prompt'); $t->longText('input_text'); $t->unsignedInteger('book_version');
   $t->string('status')->default('queued'); $t->uuid('token')->nullable();
   $t->longText('result')->nullable(); $t->string('error')->nullable();
   $t->timestamps(); $t->index(['book_id','id']);
  });
 }
 public function down(): void { Schema::dropIfExists('book_analyses'); Schema::table('users',fn (Blueprint $t)=>$t->dropColumn('analysis_prompts')); }
};
