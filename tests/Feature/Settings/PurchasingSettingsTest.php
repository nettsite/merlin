<?php

namespace Tests\Feature\Settings;

use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PurchasingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('access-panel');

        return $user;
    }

    public function test_purchasing_settings_can_be_saved(): void
    {
        $this->actingAs($this->adminUser());

        Volt::test('pages.settings.purchasing')
            ->set('defaultPayableAccount', '2001')
            ->set('taxDefaultRate', 20.0)
            ->set('taxLabel', 'GST')
            ->set('autopostConfidence', 0.80)
            ->set('amountTolerance', 5.0)
            ->set('descriptionSimilarity', 70.0)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $settings = app()->make(PurchasingSettings::class);
        $this->assertEquals('2001', $settings->default_payable_account);
        $this->assertEquals(20.0, $settings->tax_default_rate);
        $this->assertEquals('GST', $settings->tax_label);
        $this->assertEquals(0.80, $settings->autopost_confidence);
        $this->assertEquals(5.0, $settings->amount_tolerance);
        $this->assertEquals(70.0, $settings->description_similarity);
    }
}
