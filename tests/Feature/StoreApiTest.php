<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_credentials_are_never_exposed_by_the_api(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Store::create([
            'name' => 'Brew & Co.',
            'platform' => 'prestashop',
            'base_url' => 'http://brewco.test',
            'api_key' => 'super-secret-key',
            'api_secret' => 'super-secret-value',
        ]);

        $response = $this->getJson('/api/stores');

        $response->assertOk()
            ->assertJsonMissingPath('0.api_key')
            ->assertJsonMissingPath('0.api_secret');

        $this->assertStringNotContainsString('super-secret', $response->getContent());
    }

    public function test_store_credentials_are_encrypted_at_rest(): void
    {
        $store = Store::create([
            'name' => 'Brew & Co.',
            'platform' => 'prestashop',
            'base_url' => 'http://brewco.test',
            'api_key' => 'plain-key',
        ]);

        $raw = DB::table('stores')->where('id', $store->id)->value('api_key');

        $this->assertNotSame('plain-key', $raw);
        $this->assertSame('plain-key', $store->fresh()->api_key);
    }
}
