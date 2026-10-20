<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class HttpContextTest extends ReporterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function (): void {
            Route::match(['GET', 'POST'], '/_tracker-test/checkout', fn () => throw new RuntimeException('Checkout failed'))
                ->name('checkout.store');
            Route::get('/_tracker-test/reset-password/{token}', fn (string $token) => throw new RuntimeException('Reset failed'))
                ->name('password.reset.test');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function request(): array
    {
        $payload = $this->lastSentPayload();
        $this->assertSame('http', $payload['context']['type']);

        return $payload['context']['request'];
    }

    public function test_route_details_are_sent(): void
    {
        $this->post('/_tracker-test/checkout')->assertServerError();

        $request = $this->request();

        $this->assertSame('POST', $request['method']);
        $this->assertSame(url('/_tracker-test/checkout'), $request['url']);
        $this->assertSame('checkout.store', $request['route']['name']);
        $this->assertSame('_tracker-test/checkout', $request['route']['uri']);
        $this->assertSame('Closure', $request['route']['action']);
        $this->assertContains('web', $request['route']['middleware']);
    }

    public function test_sensitive_headers_are_redacted_and_the_key_kept(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer abc',
            'X-XSRF-TOKEN' => 'xsrf',
            'User-Agent' => 'Tests/1.0',
        ])->post('/_tracker-test/checkout');

        $headers = $this->request()['headers'];

        $this->assertSame('[redacted]', $headers['authorization']);
        $this->assertSame('[redacted]', $headers['x-xsrf-token']);
        $this->assertSame('Tests/1.0', $headers['user-agent']);
    }

    public function test_cookies_are_sent_as_names_only(): void
    {
        $this->withUnencryptedCookie('sample_app_session', 'session-value-123')
            ->post('/_tracker-test/checkout');

        $this->assertContains('sample_app_session', $this->request()['cookies']);
        $this->assertStringNotContainsString('session-value-123', $this->sentContextJson());
    }

    public function test_the_cookie_header_is_redacted(): void
    {
        $this->withHeaders(['Cookie' => 'sample_app_session=session-value-123'])->post('/_tracker-test/checkout');

        $this->assertSame('[redacted]', $this->request()['headers']['cookie']);
    }

    public function test_the_form_body_is_sent_with_secrets_redacted(): void
    {
        $this->post('/_tracker-test/checkout', [
            'name' => 'Priya Shah',
            'password' => 'hunter2',
            '_token' => 'csrf-token',
            'billing' => ['line1' => '14 Mill Lane', 'card_number' => '4242424242424242'],
        ]);

        $body = $this->request()['body'];

        $this->assertSame('form', $body['kind']);
        $this->assertFalse($body['truncated']);
        $this->assertIsInt($body['size']);
        $this->assertSame('Priya Shah', $body['data']['name']);
        $this->assertSame('[redacted]', $body['data']['password']);
        $this->assertSame('[redacted]', $body['data']['_token']);
        $this->assertSame('14 Mill Lane', $body['data']['billing']['line1']);
        $this->assertSame('[redacted]', $body['data']['billing']['card_number']);
        $this->assertStringNotContainsString('hunter2', $this->sentContextJson());
    }

    public function test_a_json_body_is_sent_as_json(): void
    {
        $this->postJson('/_tracker-test/checkout', ['coupon' => 'AUTUMN10', 'api_key' => 'k']);

        $body = $this->request()['body'];

        $this->assertSame('json', $body['kind']);
        $this->assertSame('AUTUMN10', $body['data']['coupon']);
        $this->assertSame('[redacted]', $body['data']['api_key']);
    }

    public function test_uploaded_files_are_sent_as_name_size_and_mime_only(): void
    {
        $this->post('/_tracker-test/checkout', [
            'title' => 'Invoice',
            'attachment' => UploadedFile::fake()->create('invoice.pdf', 12, 'application/pdf'),
        ]);

        $body = $this->request()['body'];

        $this->assertSame('files', $body['kind']);
        $this->assertSame('Invoice', $body['data']['title']);
        $this->assertSame(['name' => 'invoice.pdf', 'size' => 12 * 1024, 'mime' => 'application/pdf'], $body['data']['attachment']);
    }

    public function test_a_get_request_without_a_body_sends_no_body(): void
    {
        $this->get('/_tracker-test/checkout');

        $this->assertNull($this->request()['body']);
    }

    public function test_large_bodies_are_cut_and_flagged(): void
    {
        $this->post('/_tracker-test/checkout', [
            'first' => 'kept',
            'notes' => str_repeat('a', 20_000),
        ]);

        $payload = $this->lastSentPayload();
        $body = $payload['context']['request']['body'];

        $this->assertTrue($body['truncated']);
        $this->assertTrue($payload['truncated']['body']);
        $this->assertSame('kept', $body['data']['first']);
        $this->assertLessThanOrEqual(16_384, strlen((string) json_encode($body['data'])));
    }

    public function test_deeply_nested_bodies_are_cut_at_depth_5(): void
    {
        $this->postJson('/_tracker-test/checkout', ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too deep']]]]]]);

        $body = $this->request()['body'];

        $this->assertSame('[truncated]', $body['data']['a']['b']['c']['d']['e']);
        $this->assertTrue($body['truncated']);
    }

    public function test_query_string_secrets_are_redacted(): void
    {
        $this->get('/_tracker-test/checkout?coupon=AUTUMN10&token=abc&signature=sig&code=oauth&state=xyz&page=2');

        $this->assertSame(
            url('/_tracker-test/checkout').'?coupon=AUTUMN10&token=[redacted]&signature=[redacted]&code=[redacted]&state=[redacted]&page=2',
            $this->request()['url'],
        );
    }

    public function test_sensitive_route_parameters_are_redacted_in_the_url(): void
    {
        $this->get('/_tracker-test/reset-password/reset-token-123');

        $this->assertSame(url('/_tracker-test/reset-password/[redacted]'), $this->request()['url']);
        $this->assertStringNotContainsString('reset-token-123', $this->sentContextJson());
    }

    public function test_the_ip_is_not_sent_by_default(): void
    {
        $this->post('/_tracker-test/checkout');

        $this->assertArrayNotHasKey('ip', $this->request());
    }

    public function test_the_ip_is_sent_when_opted_in(): void
    {
        config(['culprit.send_ip' => true]);

        $this->post('/_tracker-test/checkout');

        $this->assertSame('127.0.0.1', $this->request()['ip']);
    }

    public function test_headers_are_capped_in_number_and_length(): void
    {
        $headers = ['X-Long' => str_repeat('v', 5_000)];
        for ($i = 0; $i < 80; $i++) {
            $headers["X-Extra-{$i}"] = 'x';
        }

        $this->withHeaders($headers)->post('/_tracker-test/checkout');

        $payload = $this->lastSentPayload();
        $sent = $payload['context']['request']['headers'];

        $this->assertLessThanOrEqual(64, count($sent));
        $this->assertTrue($payload['truncated']['headers']);
        foreach ($sent as $value) {
            $this->assertLessThanOrEqual(2_048, strlen($value));
        }
    }
}
