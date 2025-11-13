<?php

namespace Tests\Feature;

use App\Models\RusleUserConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RusleConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    public static function setUpBeforeClass(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            static::markTestSkipped('SQLite driver is not available in the test environment.');
        }

        parent::setUpBeforeClass();
    }

    public function test_admin_can_fetch_rusle_configuration(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin, ['*'], 'sanctum');

        $response = $this->getJson('/api/admin/rusle/config');

        $response->assertOk()
            ->assertJsonStructure([
                'defaults_version',
                'defaults',
                'overrides',
                'effective',
            ]);
    }

    public function test_admin_can_update_overrides(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin, ['*'], 'sanctum');

        $payload = [
            'overrides' => [
                'k_factor' => [
                    'sand_fraction_multiplier' => 0.35,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/rusle/config', $payload);

        $response->assertOk()
            ->assertJsonPath('overrides.k_factor.sand_fraction_multiplier', 0.35);

        $config = RusleUserConfig::where('user_id', $admin->id)->firstOrFail();

        $this->assertSame(0.35, $config->overrides['k_factor']['sand_fraction_multiplier']);
        $this->assertSame(config('rusle.version'), $config->defaults_version);
    }

    public function test_admin_can_reset_overrides(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin, ['*'], 'sanctum');

        // Create initial override
        $this->postJson('/api/admin/rusle/config', [
            'overrides' => ['r_factor' => ['coefficient' => 0.7]],
        ])->assertOk();

        $this->assertDatabaseHas('rusle_user_configs', ['user_id' => $admin->id]);

        // Reset
        $resetResponse = $this->postJson('/api/admin/rusle/config', ['reset' => true]);
        $resetResponse->assertOk()
            ->assertJsonPath('overrides', []);

        $config = RusleUserConfig::where('user_id', $admin->id)->firstOrFail();
        $this->assertSame([], $config->overrides);
    }

    public function test_non_admin_cannot_access_configuration_routes(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        Sanctum::actingAs($user, ['*'], 'sanctum');

        $this->getJson('/api/admin/rusle/config')->assertForbidden();
        $this->postJson('/api/admin/rusle/config', ['reset' => true])->assertForbidden();
    }
}

