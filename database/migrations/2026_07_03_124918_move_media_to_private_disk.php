<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class extends Migration
{
    /**
     * Relocate uploaded documents off the web-served public disk onto the
     * private disk, closing unauthenticated access via /storage/{id}/file.pdf.
     *
     * The whole storage/app/public tree is moved (not only DB-referenced
     * files) because singleFile() reprocessing left orphaned copies behind
     * that were equally exposed. Paths within a media folder are identical on
     * both disks, so moving the {id} directories preserves every lookup.
     */
    public function up(): void
    {
        $public = storage_path('app/public');
        $private = storage_path('app/private');

        if (is_dir($public)) {
            File::ensureDirectoryExists($private);

            foreach (File::directories($public) as $dir) {
                $target = $private.DIRECTORY_SEPARATOR.basename($dir);

                if (! is_dir($target)) {
                    File::moveDirectory($dir, $target);
                }
            }
        }

        DB::table('media')->where('disk', 'public')->update(['disk' => 'local']);
        DB::table('media')->where('conversions_disk', 'public')->update(['conversions_disk' => 'local']);
    }
};
