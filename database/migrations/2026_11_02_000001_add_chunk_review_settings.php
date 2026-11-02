<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('chunks', function (Blueprint $table) {
            $table->json('review_settings')->nullable();
            $table->string('review_error')->nullable();
        });
    }
    public function down(): void {
        Schema::table('chunks', fn (Blueprint $table) => $table->dropColumn(['review_settings', 'review_error']));
    }
};
