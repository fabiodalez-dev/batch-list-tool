<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-11-05: "Add Custody Status Types. A new page to be able to
 * add/edit/view/delete."
 *
 * The CHECK on documents.custody_status (2026_05_29_900003, widened on
 * 2026_10_19) fixes the allowed list in the schema, so it has to go: the
 * Document saving guard validates against this table instead. Dropping it does
 * not touch the rows.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const array SEED = [
        'in_box' => 'In Box',
        'not_in_box' => 'Not in Box',
        'mounted_no_box' => 'Mounted; no box',
        'conservation_box' => 'Conservation Box',
    ];

    public function up(): void
    {
        Schema::create('custody_status_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $order = 0;
        foreach (self::SEED as $code => $label) {
            DB::table('custody_status_types')->insert([
                'code' => $code,
                'label' => $label,
                'sort_order' => ++$order * 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->dropCheck();
    }

    public function down(): void
    {
        // The restored CHECK knows only the four seeded codes; same fallback as
        // the 2026_10_19 rollback.
        DB::table('documents')
            ->whereNotIn('custody_status', array_keys(self::SEED))
            ->update(['custody_status' => 'not_in_box']);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", array_keys(self::SEED)));
            DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_custody_status CHECK (custody_status IN ({$list}))");
        }

        Schema::dropIfExists('custody_status_types');
    }

    private function dropCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return; // SQLite test DB: the column is a plain string.
        }

        try {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT chk_documents_custody_status');
        } catch (QueryException) {
            // not present
        }
    }
};
