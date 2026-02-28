<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcu_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcu_id')->constrained('mcus')->cascadeOnDelete();
            $table->string('identifier_type', 32);
            $table->string('identifier_value', 191);
            $table->string('channel', 32)->default('');
            $table->string('marketplace', 32)->default('');
            $table->string('region', 16)->default('');
            $table->boolean('is_projection_identifier')->default(false);
            $table->string('asin_unique', 191)->nullable()->unique();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['mcu_id', 'identifier_type', 'identifier_value', 'channel', 'marketplace', 'region'],
                'mcu_identifiers_unique_scope'
            );
            $table->index(['identifier_type', 'identifier_value'], 'mcu_identifiers_lookup_idx');
        });

        Schema::table('marketplace_projections', function (Blueprint $table) {
            $table->string('channel', 32)->default('amazon')->after('mcu_id');
            $table->string('external_product_id', 191)->nullable()->after('seller_sku');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE marketplace_projections MODIFY child_asin VARCHAR(32) NULL');
        }

        $projectionRows = DB::table('marketplace_projections')
            ->select('mcu_id', 'marketplace', 'fulfilment_region', 'channel', 'child_asin', 'seller_sku', 'fnsku')
            ->whereNotNull('mcu_id')
            ->get();

        foreach ($projectionRows as $row) {
            $channel = trim((string) ($row->channel ?? 'amazon'));
            $marketplace = trim((string) ($row->marketplace ?? ''));
            $region = '';
            $asin = strtoupper(trim((string) ($row->child_asin ?? '')));
            $sellerSku = trim((string) ($row->seller_sku ?? ''));
            $fnsku = strtoupper(trim((string) ($row->fnsku ?? '')));

            if ($asin !== '') {
                DB::table('mcu_identifiers')->updateOrInsert(
                    [
                        'identifier_type' => 'asin',
                        'asin_unique' => $asin,
                    ],
                    [
                        'mcu_id' => (int) $row->mcu_id,
                        'identifier_value' => $asin,
                        'channel' => $channel,
                        'marketplace' => $marketplace,
                        'region' => $region,
                        'is_projection_identifier' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            if ($sellerSku !== '') {
                DB::table('mcu_identifiers')->updateOrInsert(
                    [
                        'mcu_id' => (int) $row->mcu_id,
                        'identifier_type' => 'seller_sku',
                        'identifier_value' => $sellerSku,
                        'channel' => $channel,
                        'marketplace' => $marketplace,
                        'region' => $region,
                    ],
                    [
                        'is_projection_identifier' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            if ($fnsku !== '') {
                DB::table('mcu_identifiers')->updateOrInsert(
                    [
                        'mcu_id' => (int) $row->mcu_id,
                        'identifier_type' => 'fnsku',
                        'identifier_value' => $fnsku,
                        'channel' => $channel,
                        'marketplace' => $marketplace,
                        'region' => $region,
                    ],
                    [
                        'is_projection_identifier' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        $sellableRows = DB::table('sellable_units')
            ->select('mcu_id', 'barcode')
            ->whereNotNull('mcu_id')
            ->whereNotNull('barcode')
            ->get();

        foreach ($sellableRows as $row) {
            $barcode = trim((string) ($row->barcode ?? ''));
            if ($barcode === '') {
                continue;
            }

            DB::table('mcu_identifiers')->updateOrInsert(
                [
                    'mcu_id' => (int) $row->mcu_id,
                    'identifier_type' => 'barcode',
                    'identifier_value' => $barcode,
                    'channel' => '',
                    'marketplace' => '',
                    'region' => '',
                ],
                [
                    'is_projection_identifier' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('marketplace_projections', function (Blueprint $table) {
            $table->dropColumn('channel');
            $table->dropColumn('external_product_id');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE marketplace_projections MODIFY child_asin VARCHAR(32) NOT NULL');
        }

        Schema::dropIfExists('mcu_identifiers');
    }
};
