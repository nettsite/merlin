<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentActivity;
use App\Modules\Core\Models\DocumentLine;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * Detects a Document/DocumentLine row that changed without a matching
 * activity log entry — the signature of a direct database write bypassing
 * the app (LogsActivity records every model-layer save, so a gap here means
 * something else touched the row). This is a detection layer, not
 * prevention: nothing in SQL stops a privileged DB user from editing
 * documents directly, so the app's job is to notice when it happens.
 */
class VerifyAuditTrailCommand extends Command
{
    private const CACHE_KEY = 'documents:verify-audit-trail:last-run';

    protected $signature = 'documents:verify-audit-trail';

    protected $description = 'Flag Document/DocumentLine rows changed since the last run with no matching activity log entry';

    public function handle(): int
    {
        $since = Cache::get(self::CACHE_KEY, now()->subDay());
        $startedAt = now();

        $orphans = [
            ...$this->findOrphans(Document::query()->where('updated_at', '>=', $since), 'document', fn (Model $doc) => $doc->getKey()),
            ...$this->findOrphans(DocumentLine::withTrashed()->where('updated_at', '>=', $since), 'document_line', fn (Model $line) => $line->document_id),
        ];

        Cache::forever(self::CACHE_KEY, $startedAt);

        if ($orphans === []) {
            $this->info('No unaudited changes found.');

            return self::SUCCESS;
        }

        foreach ($orphans as $orphan) {
            $this->error(sprintf(
                '%s %s updated_at=%s has no activity log entry since %s',
                $orphan['type'],
                $orphan['id'],
                $orphan['updated_at'],
                $since->toDateTimeString(),
            ));

            Log::warning('Unaudited row change detected', $orphan);
        }

        $this->error(sprintf('%d row(s) changed without a matching audit entry.', count($orphans)));

        return self::FAILURE;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  \Closure(Model): string  $documentIdFor  Resolves the document_id a DocumentActivity row would be filed under for this row (itself, for a Document; its parent, for a DocumentLine).
     * @return array<int, array{type: string, id: string, updated_at: string}>
     */
    private function findOrphans($query, string $morphAlias, \Closure $documentIdFor): array
    {
        $orphans = [];

        $query->chunk(200, function ($rows) use (&$orphans, $morphAlias, $documentIdFor): void {
            foreach ($rows as $row) {
                // A real save logs its Spatie activity row in the same
                // request, at essentially the same instant as updated_at —
                // the two-second tolerance only absorbs ordinary
                // clock/flush skew within one request. saveQuietly()
                // suppresses that, but every trusted saveQuietly() path in
                // DocumentService (transition(), recordPayment(),
                // applyCreditNote()) pairs it with a DocumentActivity row
                // instead — the app's older, purpose-built audit trail for
                // status/balance changes — so that counts as evidence too.
                // Only a write with neither is a genuine orphan.
                $latestActivityAt = Activity::query()
                    ->where('subject_type', $morphAlias)
                    ->where('subject_id', $row->getKey())
                    ->max('created_at');

                $latestDocumentActivityAt = DocumentActivity::query()
                    ->where('document_id', $documentIdFor($row))
                    ->max('created_at');

                $latest = collect([$latestActivityAt, $latestDocumentActivityAt])->filter()->max();

                $hasActivity = $latest !== null
                    && Carbon::parse($latest)->addSeconds(2)->gte($row->updated_at);

                if (! $hasActivity) {
                    $orphans[] = [
                        'type' => $morphAlias,
                        'id' => (string) $row->getKey(),
                        'updated_at' => $row->updated_at->toDateTimeString(),
                    ];
                }
            }
        });

        return $orphans;
    }
}
