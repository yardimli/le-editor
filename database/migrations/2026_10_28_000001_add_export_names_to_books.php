<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('books', function (Blueprint $table) {
            $table->string('original_filename')->nullable();
            $table->date('export_date')->nullable();
            $table->unsignedTinyInteger('export_count')->default(0);
        });
    }
    public function down(): void {
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['original_filename', 'export_date', 'export_count']));
    }
};
