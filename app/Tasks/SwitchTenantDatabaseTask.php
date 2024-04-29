<?php

namespace App\Tasks;

use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask  as SpatieSwitchTenantDatabaseTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Exceptions\InvalidConfiguration;

class SwitchTenantDatabaseTask extends SpatieSwitchTenantDatabaseTask
{
    protected function setTenantConnectionDatabaseName(?string $databaseName)
    {
        parent::setTenantConnectionDatabaseName($databaseName);

        $tenantConnectionName = is_null($databaseName)
            ? $this->landlordDatabaseConnectionName()
            : $this->tenantDatabaseConnectionName();

        DB::setDefaultConnection($tenantConnectionName);

        return;
        $tenantConnectionName = $this->tenantDatabaseConnectionName();

        if ($tenantConnectionName === $this->landlordDatabaseConnectionName()) {
            throw InvalidConfiguration::tenantConnectionIsEmptyOrEqualsToLandlordConnection();
        }

        if (is_null(config("database.connections.{$tenantConnectionName}"))) {
            throw InvalidConfiguration::tenantConnectionDoesNotExist($tenantConnectionName);
        }

        config([
            "database.connections.{$tenantConnectionName}.database" => $databaseName,
        ]);

        // app('db')->extend($tenantConnectionName, function ($config, $name) use ($databaseName) {
        //     $config['database'] = $databaseName;

        //     return app('db.factory')->make($config, $name);
        // });

        DB::setDefaultConnection($tenantConnectionName);  // Replacement for closure in parent method

        DB::purge($tenantConnectionName);

        // Octane will have an old `db` instance in the Model::$resolver.
        Model::setConnectionResolver(app('db'));
    }
}