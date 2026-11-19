<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('books', function (Blueprint $table) {
            $table->string('editorial_stage')->default('ready');
            $table->string('restart_source')->nullable();
            $table->text('editorial_question')->nullable();
        });
        Schema::table('editorial_messages', function (Blueprint $table) {
            $table->json('selected')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('kind')->default('conversation');
            $table->unsignedBigInteger('reply_to')->nullable()->index();
            $table->unsignedInteger('selection_version')->default(1);
        });
    }
    public function down(): void {
        Schema::table('editorial_messages', fn (Blueprint $table) => $table->dropColumn(['selected', 'submitted_at', 'kind', 'reply_to', 'selection_version']));
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['editorial_stage', 'restart_source', 'editorial_question']));
    }
};
