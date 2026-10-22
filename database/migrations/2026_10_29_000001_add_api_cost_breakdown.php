<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\ApiLog;
use App\Services\ApiCost;
return new class extends Migration {
    public function up(): void {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->decimal('reported_cost', 20, 10)->nullable();
            $table->decimal('input_price', 20, 10)->nullable();
            $table->decimal('output_price', 20, 10)->nullable();
            $table->string('cost_source')->nullable();
        });
        ApiLog::chunkById(100, function ($logs) {
            foreach ($logs as $log) {
                $usage = json_decode($log->response_body ?? '', true)['usage'] ?? ['cost' => $log->cost];
                $log->timestamps = false;
                $log->update(app(ApiCost::class)->calculate($log->model, $usage));
            }
        });
    }
    public function down(): void {
        \Illuminate\Support\Facades\DB::table('api_logs')->update(['cost' => \Illuminate\Support\Facades\DB::raw('reported_cost')]);
        Schema::table('api_logs', fn (Blueprint $table) => $table->dropColumn(['reported_cost','input_price','output_price','cost_source']));
    }
};
