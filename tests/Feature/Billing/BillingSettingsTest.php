<?php

namespace Tests\Feature\Billing;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('access-panel');

        return $user;
    }

    private function assetAccount(): Account
    {
        return Account::factory()->asset()->create();
    }

    private function liabilityAccount(): Account
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

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get('/settings/billing')->assertRedirect('/login');
    }

    public function test_user_without_access_panel_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('pages.settings.billing')->assertForbidden();
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->adminUser());

        Volt::test('pages.settings.billing')
            ->assertOk()
            ->assertSee('Billing Settings');
    }

    public function test_saves_all_settings(): void
    {
        $this->actingAs($this->adminUser());

        $assetAccount = $this->assetAccount();
        $liabilityAccount = $this->liabilityAccount();
        $paymentTerm = PaymentTerm::factory()->create(['name' => 'Test Term']);

        Volt::test('pages.settings.billing')
            ->set('defaultReceivableAccountId', $assetAccount->id)
            ->set('defaultBankAccountId', $assetAccount->id)
            ->set('defaultPaymentTermId', $paymentTerm->id)
            ->set('taxLiabilityAccountId', $liabilityAccount->id)
            ->set('billingPeriodDay', 15)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $settings = app()->make(BillingSettings::class);
        $this->assertEquals($assetAccount->id, $settings->default_receivable_account_id);
        $this->assertEquals($assetAccount->id, $settings->default_bank_account_id);
        $this->assertEquals($paymentTerm->id, $settings->default_payment_term_id);
        $this->assertEquals($liabilityAccount->id, $settings->tax_liability_account_id);
        $this->assertEquals(15, $settings->billing_period_day);
    }

    public function test_settings_loaded_on_mount(): void
    {
        $assetAccount = $this->assetAccount();
        $paymentTerm = PaymentTerm::factory()->create();

        $settings = app(BillingSettings::class);
        $settings->default_receivable_account_id = $assetAccount->id;
        $settings->default_payment_term_id = $paymentTerm->id;
        $settings->billing_period_day = 10;
        $settings->save();

        $this->actingAs($this->adminUser());

        Volt::test('pages.settings.billing')
            ->assertSet('defaultReceivableAccountId', $assetAccount->id)
            ->assertSet('defaultPaymentTermId', $paymentTerm->id)
            ->assertSet('billingPeriodDay', 10);
    }

    public function test_billing_period_day_must_be_between_1_and_28(): void
    {
        $this->actingAs($this->adminUser());

        Volt::test('pages.settings.billing')
            ->set('billingPeriodDay', 0)
            ->call('save')
            ->assertHasErrors(['billingPeriodDay']);

        Volt::test('pages.settings.billing')
            ->set('billingPeriodDay', 29)
            ->call('save')
            ->assertHasErrors(['billingPeriodDay']);
    }

    public function test_account_ids_must_exist(): void
    {
        $this->actingAs($this->adminUser());

        Volt::test('pages.settings.billing')
            ->set('defaultReceivableAccountId', '00000000-0000-0000-0000-000000000000')
            ->call('save')
            ->assertHasErrors(['defaultReceivableAccountId']);
    }

    public function test_nullable_fields_can_be_cleared(): void
    {
        $this->actingAs($this->adminUser());

        Volt::test('pages.settings.billing')
            ->set('defaultReceivableAccountId', '')
            ->set('defaultBankAccountId', '')
            ->set('defaultPaymentTermId', '')
            ->set('taxLiabilityAccountId', '')
            ->set('billingPeriodDay', 1)
            ->call('save')
            ->assertHasNoErrors();

        $settings = app()->make(BillingSettings::class);
        $this->assertNull($settings->default_receivable_account_id);
        $this->assertNull($settings->default_bank_account_id);
        $this->assertNull($settings->default_payment_term_id);
        $this->assertNull($settings->tax_liability_account_id);
    }
}
