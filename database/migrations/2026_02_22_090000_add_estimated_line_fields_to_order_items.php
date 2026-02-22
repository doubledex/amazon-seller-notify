<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('estimated_line_net_ex_tax', 12, 2)->nullable();
            $table->string('estimated_line_currency', 3)->nullable();
            $table->string('estimated_line_source', 32)->nullable();
            $table->timestamp('estimated_line_estimated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_line_net_ex_tax',
                'estimated_line_currency',
                'estimated_line_source',
                'estimated_line_estimated_at',
            ]);
        });
    }
};
