<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            $table->unsignedInteger('ai_edit_count')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->longText('input_text')->nullable();
        });
        DB::table('chunks')->where('status', 'completed')->update(['ai_edit_count' => 1]);
        Schema::table('books', function (Blueprint $table) {
            $table->boolean('learning_requested')->default(false);
            $table->boolean('learning_hint_dismissed')->default(false);
        });
        DB::table('feedback')->where('status', 'pending')->update(['status' => 'awaiting']);
        Schema::table('feedback', function (Blueprint $table) {
            $table->unsignedBigInteger('chunk_id')->nullable()->index();
            $table->string('suggestion_key', 64)->nullable();
        });
        Schema::create('chunk_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chunk_id')->constrained()->cascadeOnDelete();
            $table->longText('text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunk_revisions');
        Schema::table('feedback', fn (Blueprint $table) => $table->dropColumn(['chunk_id', 'suggestion_key']));
        Schema::table('chunks', fn (Blueprint $table) => $table->dropColumn(['ai_edit_count', 'revision', 'input_text']));
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['learning_requested', 'learning_hint_dismissed']));
    }
};
