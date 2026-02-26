<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('us_fc_locations', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('country_code');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->string('location_source', 32)->nullable()->after('lng');
            $table->index(['country_code', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('us_fc_locations', function (Blueprint $table) {
            $table->dropIndex(['country_code', 'state']);
            $table->dropColumn(['lat', 'lng', 'location_source']);
        });
    }
};
