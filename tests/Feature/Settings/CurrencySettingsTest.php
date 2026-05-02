<?php

namespace Tests\Feature\Settings;

use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CurrencySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('access-panel');

        return $user;
    }

    public function test_currency_settings_can_be_saved(): void
    {
        $this->actingAs($this->adminUser());

        Volt::test('pages.settings.general')
            ->set('baseCurrency', 'USD')
            ->set('locale', 'en_US')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $settings = app()->make(CurrencySettings::class);
        $this->assertEquals('USD', $settings->base_currency);
        $this->assertEquals('en_US', $settings->locale);
    }
}
