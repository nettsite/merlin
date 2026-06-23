<?php

use App\Modules\Core\Enums\PaymentTermRule;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\User;
use Livewire\Volt\Volt;

function ptUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('redirects unauthenticated users to login', function (): void {
    $this->get('/payment-terms')->assertRedirect('/login');
});

it('forbids users without payment-terms-view-any', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.payment-terms.index')->assertForbidden();
});

it('renders the payment terms page', function (): void {
    $this->actingAs(ptUserWith(['payment-terms-view-any']));

    Volt::test('pages.payment-terms.index')
        ->assertOk()
        ->assertSee('Payment Terms');
});

it('lists existing terms', function (): void {
    PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => '30 Days Net']);
    $this->actingAs(ptUserWith(['payment-terms-view-any']));

    Volt::test('pages.payment-terms.index')->assertSee('30 Days Net');
});

it('creates a days-after-invoice term', function (): void {
    $this->actingAs(ptUserWith(['payment-terms-view-any', 'payment-terms-create']));

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
});

it('creates an nth-of-following-month term', function (): void {
    $this->actingAs(ptUserWith(['payment-terms-view-any', 'payment-terms-create']));

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
});

it('requires days when rule uses days', function (): void {
    $this->actingAs(ptUserWith(['payment-terms-view-any', 'payment-terms-create']));

    Volt::test('pages.payment-terms.index')
        ->call('create')
        ->set('name', 'Missing Days')
        ->set('rule', PaymentTermRule::DaysAfterInvoice->value)
        ->set('days', null)
        ->call('save')
        ->assertHasErrors(['days']);
});

it('requires dayOfMonth when rule uses it', function (): void {
    $this->actingAs(ptUserWith(['payment-terms-view-any', 'payment-terms-create']));

    Volt::test('pages.payment-terms.index')
        ->call('create')
        ->set('name', 'Missing Day')
        ->set('rule', PaymentTermRule::NthOfFollowingMonth->value)
        ->set('dayOfMonth', null)
        ->call('save')
        ->assertHasErrors(['dayOfMonth']);
});

it('edits a term', function (): void {
    $term = PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => 'Original Name']);
    $this->actingAs(ptUserWith(['payment-terms-view-any', 'payment-terms-update']));

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
});

it('soft-deletes a term', function (): void {
    $term = PaymentTerm::factory()->create();
    $this->actingAs(ptUserWith(['payment-terms-view-any', 'payment-terms-delete']));

    Volt::test('pages.payment-terms.index')
        ->call('delete', $term->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('payment_terms', ['id' => $term->id]);
});

it('filters by search term', function (): void {
    PaymentTerm::factory()->create(['name' => 'Alpha Term']);
    PaymentTerm::factory()->create(['name' => 'Beta Term']);
    $this->actingAs(ptUserWith(['payment-terms-view-any']));

    Volt::test('pages.payment-terms.index')
        ->set('search', 'Alpha')
        ->assertSee('Alpha Term')
        ->assertDontSee('Beta Term');
});
