<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // accounts
            'accounts-view-any', 'accounts-view', 'accounts-create', 'accounts-update', 'accounts-delete', 'accounts-restore', 'accounts-force-delete',
            // account-groups
            'account-groups-view-any', 'account-groups-view', 'account-groups-create', 'account-groups-update', 'account-groups-delete', 'account-groups-restore', 'account-groups-force-delete',
            // account-types
            'account-types-view-any', 'account-types-view', 'account-types-create', 'account-types-update', 'account-types-delete',
            // addresses
            'addresses-view-any', 'addresses-view', 'addresses-create', 'addresses-update', 'addresses-delete', 'addresses-restore', 'addresses-force-delete',
            // businesses
            'businesses-view-any', 'businesses-view', 'businesses-create', 'businesses-update', 'businesses-delete',
            // contact-assignments
            'contact-assignments-view-any', 'contact-assignments-view', 'contact-assignments-create', 'contact-assignments-update', 'contact-assignments-delete', 'contact-assignments-restore', 'contact-assignments-force-delete',
            // documents
            'documents-view-any', 'documents-view', 'documents-create', 'documents-update', 'documents-delete', 'documents-restore', 'documents-force-delete',
            // document-activities
            'document-activities-view-any', 'document-activities-view', 'document-activities-create', 'document-activities-update', 'document-activities-delete',
            // document-lines
            'document-lines-view-any', 'document-lines-view', 'document-lines-create', 'document-lines-update', 'document-lines-delete', 'document-lines-restore', 'document-lines-force-delete',
            // document-relationships
            'document-relationships-view-any', 'document-relationships-view', 'document-relationships-create', 'document-relationships-update', 'document-relationships-delete',
            // llm-logs
            'llm-logs-view-any', 'llm-logs-view', 'llm-logs-create', 'llm-logs-update', 'llm-logs-delete',
            // parties
            'parties-view-any', 'parties-view', 'parties-create', 'parties-update', 'parties-delete', 'parties-restore', 'parties-force-delete',
            // party-relationships
            'party-relationships-view-any', 'party-relationships-view', 'party-relationships-create', 'party-relationships-update', 'party-relationships-delete', 'party-relationships-restore', 'party-relationships-force-delete',
            // persons
            'persons-view-any', 'persons-view', 'persons-create', 'persons-update', 'persons-delete',
            // users
            'users-view-any', 'users-view', 'users-create', 'users-update', 'users-delete',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
};
