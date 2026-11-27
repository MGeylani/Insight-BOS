<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\NumberSequence;
use App\Models\ServiceCategory;
use App\Models\StorageConnection;
use App\Services\ExchangeRates;
use App\Services\NumberSequencer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(1, NumberSequence::where('type', 'invoice')->count());
    }

    public function test_invoice_numbers_are_sequential_and_reset_yearly(): void
    {
        $this->assertSame('INV-2026-0001', NumberSequencer::next('invoice', 2026));
        $this->assertSame('INV-2026-0002', NumberSequencer::next('invoice', 2026));
        $this->assertSame('INV-2027-0001', NumberSequencer::next('invoice', 2027));
    }

    public function test_exchange_rate_falls_back_to_last_known_and_flags_stale(): void
    {
        ExchangeRate::create(['currency_code' => 'TRY', 'date' => '2026-11-25', 'rate' => 42.5]);

        $r = ExchangeRates::rateFor('TRY', Carbon::parse('2026-11-27'));
        $this->assertSame(42.5, $r['rate']);
        $this->assertTrue($r['stale']);
        $this->assertSame(1.0, ExchangeRates::rateFor('USD')['rate']);
        $this->assertNull(ExchangeRates::rateFor('SYP', Carbon::parse('2026-11-27')));
        $this->assertSame(100.0, ExchangeRates::toBase(4250, 'TRY', Carbon::parse('2026-11-25')));
    }

    public function test_rate_entry_requires_permission_and_saves(): void
    {
        $this->actingAs($this->userWith())->post('/settings/currencies/rates', [])->assertForbidden();

        $user = $this->userWith(['settings.exchange_rates' => 'all']);
        $this->actingAs($user)->post('/settings/currencies/rates', [
            'date' => now()->toDateString(),
            'rates' => ['SYP' => 13000, 'TRY' => 42.1],
        ])->assertSessionHas('success');

        $this->assertSame([], ExchangeRates::missingToday());
    }

    public function test_service_category_tax_rate_defaults_to_zero_and_is_editable(): void
    {
        $cat = ServiceCategory::first();
        $this->assertEquals(0, $cat->tax_rate);

        $this->actingAs($this->admin())->put("/settings/service-categories/{$cat->id}", [
            'name' => $cat->name, 'tax_rate' => 5, 'is_active' => true,
        ])->assertSessionHas('success');

        $this->assertEquals(5, $cat->fresh()->tax_rate);
    }

    public function test_only_one_storage_provider_is_active(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings/storage', ['provider' => 'google_drive', 'max_upload_mb' => 100]);
        $this->actingAs($admin)->put('/settings/storage', ['provider' => 'dropbox', 'max_upload_mb' => 50]);

        $this->assertSame(1, StorageConnection::where('is_active', true)->count());
        $this->assertSame('dropbox', StorageConnection::active()->provider);
    }
}
