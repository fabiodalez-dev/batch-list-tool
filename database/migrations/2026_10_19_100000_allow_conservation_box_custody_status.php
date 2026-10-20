<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Client 2026-10-19: "Conservation Box" is a custody status in her documents
 * sheet, next to In box, Not in box and Mounted; no box. The MySQL CHECK on
 * documents.custody_status allowed only the first three.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replaceCheck(['in_box', 'not_in_box', 'mounted_no_box', 'conservation_box']);
    }

    public function down(): void
    {
        DB::table('documents')->where('custody_status', 'conservation_box')->update(['custody_status' => 'not_in_box']);
        $this->replaceCheck(['in_box', 'not_in_box', 'mounted_no_box']);
    }

    /**
     * @param list<string> $values
     */
    private function replaceCheck(array $values): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return; // SQLite test DB: the column is a plain string.
        }

        try {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT chk_documents_custody_status');
        } catch (QueryException) {
            // not present
        }
        $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
        DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_custody_status CHECK (custody_status IN ({$list}))");
    }
};
