<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WooCommerceWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_webhook_with_an_invalid_signature(): void
    {
        config(['services.woocommerce.webhook_secret' => 'test_secret']);

        $payload = ['id' => 1, 'status' => 'processing'];

        $response = $this->postJson('/api/webhooks/woocommerce', $payload, [
            'X-WC-Webhook-Topic' => 'order.updated',
            'X-WC-Webhook-Signature' => base64_encode('wrong-signature'),
        ]);

        $response->assertStatus(401);
    }

    public function test_it_accepts_a_valid_order_webhook_and_upserts_the_order(): void
    {
        config(['services.woocommerce.webhook_secret' => 'test_secret']);

        $store = Store::create([
            'name' => 'Mythic Books',
            'platform' => 'woocommerce',
            'base_url' => 'http://mythicbooks.test',
        ]);

        $payload = [
            'id' => 500,
            'status' => 'processing',
            'total' => '42.00',
            'currency' => 'USD',
            'date_created' => '2026-07-15T10:00:00',
        ];

        $body = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $body, 'test_secret', true));

        $response = $this->call(
            'POST',
            '/api/webhooks/woocommerce',
            [],
            [],
            [],
            [
                'HTTP_X-WC-Webhook-Topic' => 'order.updated',
                'HTTP_X-WC-Webhook-Signature' => $signature,
                'HTTP_X-WC-Webhook-Source' => 'http://mythicbooks.test',
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'store_id' => $store->id,
            'external_id' => '500',
            'status' => 'processing',
            'total' => 42.00,
            'currency' => 'USD',
        ]);
    }

    public function test_it_rejects_a_signed_webhook_whose_payload_has_no_id(): void
    {
        config(['services.woocommerce.webhook_secret' => 'test_secret']);

        Store::create([
            'name' => 'Mythic Books',
            'platform' => 'woocommerce',
            'base_url' => 'http://mythicbooks.test',
        ]);

        $body = json_encode(['status' => 'processing']);
        $signature = base64_encode(hash_hmac('sha256', $body, 'test_secret', true));

        $response = $this->call('POST', '/api/webhooks/woocommerce', [], [], [], [
            'HTTP_X-WC-Webhook-Topic' => 'order.updated',
            'HTTP_X-WC-Webhook-Signature' => $signature,
            'HTTP_X-WC-Webhook-Source' => 'http://mythicbooks.test',
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(422);
    }
}
