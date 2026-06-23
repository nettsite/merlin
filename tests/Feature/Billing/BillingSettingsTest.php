<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\User;
use Livewire\Volt\Volt;

function billingAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('access-panel');

    return $user;
}

function assetAccount(): Account
{
    return Account::factory()->asset()->create();
}

function liabilityAccount(): Account
{
    return Account::factory()->state([
        'account_group_id' => AccountGroup::factory()->state([
            'account_type_id' => AccountType::factory()->state([
                'code' => '2',
                'name' => 'Liability',
                'normal_balance' => 'credit',
                'sort_order' => 20,
            ]),
        ]),
    ])->create();
}

it('redirects unauthenticated users to login', function (): void {
    $this->get('/settings/billing')->assertRedirect('/login');
});

it('forbids users without access-panel', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.settings.billing')->assertForbidden();
});

it('renders the billing settings page', function (): void {
    $this->actingAs(billingAdminUser());

    Volt::test('pages.settings.billing')
        ->assertOk()
        ->assertSee('Billing Settings');
});

it('saves all settings', function (): void {
    $this->actingAs(billingAdminUser());

    $asset = assetAccount();
    $liability = liabilityAccount();
    $paymentTerm = PaymentTerm::factory()->create(['name' => 'Test Term']);

    Volt::test('pages.settings.billing')
        ->set('defaultReceivableAccountId', $asset->id)
        ->set('defaultBankAccountId', $asset->id)
        ->set('defaultPaymentTermId', $paymentTerm->id)
        ->set('taxLiabilityAccountId', $liability->id)
        ->set('billingPeriodDay', 15)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $settings = app(BillingSettings::class);
    expect($settings->default_receivable_account_id)->toBe($asset->id)
        ->and($settings->default_bank_account_id)->toBe($asset->id)
        ->and($settings->default_payment_term_id)->toBe($paymentTerm->id)
        ->and($settings->tax_liability_account_id)->toBe($liability->id)
        ->and($settings->billing_period_day)->toBe(15);
});

it('loads existing settings on mount', function (): void {
    $asset = assetAccount();
    $paymentTerm = PaymentTerm::factory()->create();

    $settings = app(BillingSettings::class);
    $settings->default_receivable_account_id = $asset->id;
    $settings->default_payment_term_id = $paymentTerm->id;
    $settings->billing_period_day = 10;
    $settings->save();

    $this->actingAs(billingAdminUser());

    Volt::test('pages.settings.billing')
        ->assertSet('defaultReceivableAccountId', $asset->id)
        ->assertSet('defaultPaymentTermId', $paymentTerm->id)
        ->assertSet('billingPeriodDay', 10);
});

it('rejects billingPeriodDay outside 1..28', function (): void {
    $this->actingAs(billingAdminUser());

    Volt::test('pages.settings.billing')
        ->set('billingPeriodDay', 0)
        ->call('save')
        ->assertHasErrors(['billingPeriodDay']);

    Volt::test('pages.settings.billing')
        ->set('billingPeriodDay', 29)
        ->call('save')
        ->assertHasErrors(['billingPeriodDay']);
});

it('rejects non-existent account ids', function (): void {
    $this->actingAs(billingAdminUser());

    Volt::test('pages.settings.billing')
        ->set('defaultReceivableAccountId', '00000000-0000-0000-0000-000000000000')
        ->call('save')
        ->assertHasErrors(['defaultReceivableAccountId']);
});

it('clears nullable fields when blanked', function (): void {
    $this->actingAs(billingAdminUser());

    Volt::test('pages.settings.billing')
        ->set('defaultReceivableAccountId', '')
        ->set('defaultBankAccountId', '')
        ->set('defaultPaymentTermId', '')
        ->set('taxLiabilityAccountId', '')
        ->set('billingPeriodDay', 1)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(BillingSettings::class);
    expect($settings->default_receivable_account_id)->toBeNull()
        ->and($settings->default_bank_account_id)->toBeNull()
        ->and($settings->default_payment_term_id)->toBeNull()
        ->and($settings->tax_liability_account_id)->toBeNull();
});
