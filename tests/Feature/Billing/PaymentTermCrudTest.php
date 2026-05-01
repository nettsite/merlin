<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\PaymentTermRule;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PaymentTermCrudTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get('/payment-terms')->assertRedirect('/login');
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('pages.payment-terms.index')->assertForbidden();
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->userWith(['payment-terms-view-any']));

        Volt::test('pages.payment-terms.index')
            ->assertOk()
            ->assertSee('Payment Terms');
    }

    public function test_existing_terms_are_listed(): void
    {
        PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => '30 Days Net']);
        $this->actingAs($this->userWith(['payment-terms-view-any']));

        Volt::test('pages.payment-terms.index')
            ->assertSee('30 Days Net');
    }

    public function test_can_create_days_after_invoice_term(): void
    {
        $this->actingAs($this->userWith(['payment-terms-view-any', 'payment-terms-create']));

        Volt::test('pages.payment-terms.index')
            ->call('create')
            ->assertSet('showForm', true)
            ->set('name', '30 Days')
            ->set('rule', PaymentTermRule::DaysAfterInvoice->value)
            ->set('days', 30)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('payment_terms', [
            'name' => '30 Days',
            'rule' => PaymentTermRule::DaysAfterInvoice->value,
            'days' => 30,
        ]);
    }

    public function test_can_create_nth_of_following_month_term(): void
    {
        $this->actingAs($this->userWith(['payment-terms-view-any', 'payment-terms-create']));

        Volt::test('pages.payment-terms.index')
            ->call('create')
            ->set('name', '25th of Month')
            ->set('rule', PaymentTermRule::NthOfFollowingMonth->value)
            ->set('dayOfMonth', 25)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('payment_terms', [
            'name' => '25th of Month',
            'rule' => PaymentTermRule::NthOfFollowingMonth->value,
            'day_of_month' => 25,
        ]);
    }

    public function test_days_required_when_rule_uses_days(): void
    {
        $this->actingAs($this->userWith(['payment-terms-view-any', 'payment-terms-create']));

        Volt::test('pages.payment-terms.index')
            ->call('create')
            ->set('name', 'Missing Days')
            ->set('rule', PaymentTermRule::DaysAfterInvoice->value)
            ->set('days', null)
            ->call('save')
            ->assertHasErrors(['days']);
    }

    public function test_day_of_month_required_when_rule_uses_it(): void
    {
        $this->actingAs($this->userWith(['payment-terms-view-any', 'payment-terms-create']));

        Volt::test('pages.payment-terms.index')
            ->call('create')
            ->set('name', 'Missing Day')
            ->set('rule', PaymentTermRule::NthOfFollowingMonth->value)
            ->set('dayOfMonth', null)
            ->call('save')
            ->assertHasErrors(['dayOfMonth']);
    }

    public function test_can_edit_term(): void
    {
        $term = PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => 'Original Name']);
        $this->actingAs($this->userWith(['payment-terms-view-any', 'payment-terms-update']));

        Volt::test('pages.payment-terms.index')
            ->call('edit', $term->id)
            ->assertSet('showForm', true)
            ->assertSet('name', 'Original Name')
            ->set('name', 'Updated Name')
            ->set('days', 45)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('payment_terms', [
            'id' => $term->id,
            'name' => 'Updated Name',
            'days' => 45,
        ]);
    }

    public function test_can_delete_term(): void
    {
        $term = PaymentTerm::factory()->create();
        $this->actingAs($this->userWith(['payment-terms-view-any', 'payment-terms-delete']));

        Volt::test('pages.payment-terms.index')
            ->call('delete', $term->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('payment_terms', ['id' => $term->id]);
    }

    public function test_search_filters_results(): void
    {
        PaymentTerm::factory()->create(['name' => 'Alpha Term']);
        PaymentTerm::factory()->create(['name' => 'Beta Term']);
        $this->actingAs($this->userWith(['payment-terms-view-any']));

        Volt::test('pages.payment-terms.index')
            ->set('search', 'Alpha')
            ->assertSee('Alpha Term')
            ->assertDontSee('Beta Term');
    }
}
