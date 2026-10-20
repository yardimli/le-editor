<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('openrouter_api_key')->nullable();
            $table->string('locale', 2)->default('tr');
            $table->string('theme')->default('light');
            $table->string('default_model')->nullable();
        });
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug')->nullable()->unique();
            $table->json('names');
            $table->json('instructions');
            $table->string('genre');
            $table->string('intensity');
            $table->timestamps();
        });
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('language', 2)->default('tr');
            $table->string('original_path');
            $table->longText('original_text');
            $table->longText('text');
            $table->text('prompt')->nullable();
            $table->text('context')->nullable();
            $table->unsignedInteger('prompt_version')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('chunk_size')->default(2500);
            $table->boolean('learn')->default(true);
            $table->string('model')->nullable();
            $table->unsignedBigInteger('active_run_id')->nullable();
            $table->string('metadata_status')->default('pending');
            $table->text('metadata_error')->nullable();
            $table->timestamps();
        });
        Schema::create('editing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->text('prompt');
            $table->text('context')->nullable();
            $table->string('model');
            $table->string('language', 2);
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('completed_chunks')->default(0);
            $table->unsignedBigInteger('tokens')->default(0);
            $table->decimal('cost', 14, 8)->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('editing_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->longText('source');
            $table->longText('output')->nullable();
            $table->json('segments')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['editing_run_id', 'position']);
        });
        Schema::create('manuscript_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->longText('text');
            $table->string('reason');
            $table->timestamps();
        });
        Schema::create('prompt_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('reason');
            $table->timestamps();
        });
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->text('before');
            $table->text('after');
            $table->string('decision');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        foreach (['jobs', 'feedback', 'prompt_revisions', 'manuscript_versions', 'chunks', 'editing_runs', 'books', 'prompt_templates'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['openrouter_api_key', 'locale', 'theme', 'default_model']));
    }
};
