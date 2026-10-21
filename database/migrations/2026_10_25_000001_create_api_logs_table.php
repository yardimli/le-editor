<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->string('model');
            $table->string('status');
            $table->longText('request_body');
            $table->longText('response_body')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('prompt_tokens')->nullable();
            $table->unsignedBigInteger('completion_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->decimal('cost', 20, 10)->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['book_id', 'id']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE books ALTER COLUMN chunk_size SET DEFAULT 1000');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE books ALTER COLUMN chunk_size SET DEFAULT 2500');
        }
    }
};
