<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-11-05: "Type of Entity: currently a dropdown, from where can we
 * edit the options? Person is not currently an option."
 *
 * The stored value becomes a lookup. The 681 authorities holding PERSON become
 * Person in one statement: through the query builder, so it writes no audit rows
 * and leaves updated_at alone, and a second run changes nothing. The column was
 * varchar(16) with a PERSON default, which an option typed on the new page could
 * outgrow and which would keep bringing the old spelling back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        foreach (['Notary', 'Interventor', 'Person'] as $i => $code) {
            DB::table('entity_types')->insert([
                'code' => $code,
                'label' => $code,
                'sort_order' => ($i + 1) * 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('authorities')->whereRaw('LOWER(entity_type) = ?', ['person'])->update(['entity_type' => 'Person']);

        Schema::table('authorities', function (Blueprint $table): void {
            $table->string('entity_type', 64)->default('Person')->change();
        });
    }

    public function down(): void
    {
        DB::table('authorities')->whereRaw('LOWER(entity_type) = ?', ['person'])->update(['entity_type' => 'PERSON']);
        // Only an option added on the page can be this long; varchar(16) would
        // refuse the whole ALTER on a strict server.
        DB::table('authorities')->whereRaw('LENGTH(entity_type) > 16')->update(['entity_type' => DB::raw('SUBSTR(entity_type, 1, 16)')]);

        Schema::table('authorities', function (Blueprint $table): void {
            $table->string('entity_type', 16)->default('PERSON')->change();
        });

        Schema::dropIfExists('entity_types');
    }
};
