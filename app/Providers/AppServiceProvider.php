<?php

namespace App\Providers;

use App\Console\Commands\DocsSync;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Billing\Console\GenerateRecurringInvoices;
use App\Modules\Billing\Console\ImportFromNinja;
use App\Modules\Billing\Console\SendReminders;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Models\RecurringInvoiceLine;
use App\Modules\Core\Contracts\ModulePolicy;
use App\Modules\Core\Models\Business;
use App\Modules\Core\Models\ContactAssignment;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentActivity;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\AllModulesPolicy;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\LlmLog;
use App\Modules\Purchasing\Models\PostingRule;
use App\Modules\Purchasing\Services\ExchangeRateService;
use App\Modules\Purchasing\Services\Pdf\MagikaService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->bind(ModulePolicy::class, AllModulesPolicy::class);

        $this->commands([DocsSync::class, GenerateRecurringInvoices::class, ImportFromNinja::class, SendReminders::class]);

        $this->app->singleton(ExchangeRateService::class, function ($app): ExchangeRateService {
            return new ExchangeRateService($app->make(CurrencySettings::class)->base_currency);
        });

        $this->app->singleton(MagikaService::class, fn (): MagikaService => new MagikaService(
            config('documents.magika.binary', 'magika'),
        ));
    }

    public function boot(): void
    {
        $this->configureMorphMap();
        $this->configureDefaults();
        $this->configureAuthorization();

        // Override published NettMail views so they survive composer updates.
        Livewire::addNamespace('nettmail', resource_path('views/vendor/nettmail/livewire'));
    }

    /**
     * Register stable morph aliases so stored type strings survive namespace refactors.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'account' => Account::class,
            'account_group' => AccountGroup::class,
            'account_type' => AccountType::class,
            'business' => Business::class,
            'contact_assignment' => ContactAssignment::class,
            'document' => Document::class,
            'document_activity' => DocumentActivity::class,
            'document_line' => DocumentLine::class,
            'document_relationship' => DocumentRelationship::class,
            'llm_log' => LlmLog::class,
            'party' => Party::class,
            'party_relationship' => PartyRelationship::class,
            'person' => Person::class,
            'billing_email_template' => BillingEmailTemplate::class,
            'payment_term' => PaymentTerm::class,
            'posting_rule' => PostingRule::class,
            'recurring_invoice' => RecurringInvoice::class,
            'recurring_invoice_line' => RecurringInvoiceLine::class,
            'user' => User::class,
        ]);
    }

    /**
     * Grant the Administrator role a pass on all Gate checks.
     *
     * This is the one legitimate use of hasRole() — a Gate::before bypass is
     * infrastructure, not application logic, and cannot use can() without circularity.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if (! method_exists($user, 'hasRole')) {
                return null;
            }

            return $user->hasRole('Administrator') ? true : null;
        });

        Gate::define('portal.view-invoice', function (Person $person, Document $invoice): bool {
            return ContactAssignment::where('person_id', $person->id)
                ->where('party_id', $invoice->party_id)
                ->where('is_active', true)
                ->where('receives_invoices', true)
                ->exists();
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
