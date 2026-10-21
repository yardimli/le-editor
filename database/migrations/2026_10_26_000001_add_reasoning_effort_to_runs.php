<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::table('editing_runs', fn (Blueprint $table) => $table->string('reasoning_effort')->default('low'));
        DB::table('books')->update(['learn' => true]);
    }
    public function down(): void {
        Schema::table('editing_runs', fn (Blueprint $table) => $table->dropColumn('reasoning_effort'));
    }
};
