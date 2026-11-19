<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', fn (Blueprint $table) => $table->string('reasoning_effort')->default('low'));
        Schema::table('books', function (Blueprint $table) {
            $table->string('author')->nullable();
            $table->json('editorial_plan')->nullable();
        });
        Schema::create('editorial_messages', function (Blueprint $table) {
            $table->id(); $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('role'); $table->text('content'); $table->json('suggestions')->nullable();
            $table->string('status')->default('complete'); $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('editorial_messages');
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['author', 'editorial_plan']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('reasoning_effort'));
    }
};
