<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('books', fn (Blueprint $table) => $table->json('original_formatting')->nullable()); }
    public function down(): void { Schema::table('books', fn (Blueprint $table) => $table->dropColumn('original_formatting')); }
};
