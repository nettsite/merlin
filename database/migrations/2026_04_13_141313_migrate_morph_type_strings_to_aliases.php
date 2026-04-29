<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Map of old fully-qualified class names → stable morph alias keys.
     *
     * Covers both the pre-R1 (App\Models\*) namespace and the post-R1
     * domain-grouped namespaces (App\Modules\Core\*, App\Modules\Accounting\*, etc.) so
     * the migration is safe to run on any tenant that was created before
     * or after the R1 directory refactor.
     *
     * @var array<string, string>
     */
    private array $map = [
        // pre-R1 flat namespace
        'App\\Models\\User' => 'user',
        'App\\Models\\Party' => 'party',
        'App\\Models\\PartyRelationship' => 'party_relationship',
        'App\\Models\\Person' => 'person',
        'App\\Models\\Business' => 'business',
        'App\\Models\\Account' => 'account',
        'App\\Models\\AccountGroup' => 'account_group',
        'App\\Models\\AccountType' => 'account_type',
        'App\\Models\\Document' => 'document',
        'App\\Models\\DocumentLine' => 'document_line',
        'App\\Models\\DocumentActivity' => 'document_activity',
        'App\\Models\\DocumentRelationship' => 'document_relationship',
        'App\\Models\\PostingRule' => 'posting_rule',
        'App\\Models\\LlmLog' => 'llm_log',
        // post-R1 domain-grouped namespaces
        'App\\Modules\\Core\\Models\\User' => 'user',
        'App\\Modules\\Core\\Models\\Party' => 'party',
        'App\\Modules\\Core\\Models\\PartyRelationship' => 'party_relationship',
        'App\\Modules\\Core\\Models\\Person' => 'person',
        'App\\Modules\\Core\\Models\\Business' => 'business',
        'App\\Modules\\Accounting\\Models\\Account' => 'account',
        'App\\Modules\\Accounting\\Models\\AccountGroup' => 'account_group',
        'App\\Modules\\Accounting\\Models\\AccountType' => 'account_type',
        'App\\Modules\\Purchasing\\Models\\Document' => 'document',
        'App\\Modules\\Purchasing\\Models\\DocumentLine' => 'document_line',
        'App\\Modules\\Purchasing\\Models\\DocumentActivity' => 'document_activity',
        'App\\Modules\\Purchasing\\Models\\DocumentRelationship' => 'document_relationship',
        'App\\Modules\\Purchasing\\Models\\PostingRule' => 'posting_rule',
        'App\\Modules\\Purchasing\\Models\\LlmLog' => 'llm_log',
    ];

    public function up(): void
    {
        foreach ($this->map as $old => $new) {
            foreach (['model_has_roles', 'model_has_permissions'] as $table) {
                DB::table($table)->where('model_type', $old)->update(['model_type' => $new]);
            }

            DB::table('media')
                ->where('model_type', $old)
                ->update(['model_type' => $new]);
        }
    }
};
