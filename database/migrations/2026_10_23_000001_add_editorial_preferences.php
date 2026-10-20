<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('editorial_genre')->default('fiction');
            $table->boolean('italicize')->default(false);
            $table->json('italics')->nullable();
        });
        Schema::table('editing_runs', fn (Blueprint $table) => $table->boolean('italicize')->default(false));
        Schema::table('manuscript_versions', fn (Blueprint $table) => $table->json('italics')->nullable());
        (new \Database\Seeders\EditorialPresetSeeder)->run();
    }
    public function down(): void
    {
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['editorial_genre', 'italicize', 'italics']));
        Schema::table('editing_runs', fn (Blueprint $table) => $table->dropColumn('italicize'));
        Schema::table('manuscript_versions', fn (Blueprint $table) => $table->dropColumn('italics'));
    }
};
