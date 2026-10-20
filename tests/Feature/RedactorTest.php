<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use TheAaronLawrence\Culprit\Redactor;
use TheAaronLawrence\Culprit\Tests\TestCase;

final class RedactorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sensitiveKeys(): array
    {
        return [
            'password' => ['password'],
            'confirmation' => ['password_confirmation'],
            'current password' => ['current_password'],
            'csrf field' => ['_token'],
            'token substring' => ['remember_token'],
            'secret' => ['client_secret'],
            'api key' => ['api_key'],
            'apikey' => ['ApiKey'],
            'authorization' => ['Authorization'],
            'proxy authorization' => ['proxy-authorization'],
            'cookie' => ['cookie'],
            'set-cookie' => ['Set-Cookie'],
            'xsrf header' => ['x-xsrf-token'],
            'csrf header' => ['X-CSRF-TOKEN'],
            'basic auth password' => ['php-auth-pw'],
            'card number' => ['card_number'],
            'cvv' => ['cvv'],
            'cvc' => ['CVC'],
            'iban' => ['iban'],
            'ssn' => ['ssn'],
        ];
    }

    #[DataProvider('sensitiveKeys')]
    public function test_sensitive_keys_are_redacted_and_kept(string $key): void
    {
        $this->assertSame([$key => '[redacted]', 'name' => 'Priya'], app(Redactor::class)->redact([$key => 'value', 'name' => 'Priya']));
    }

    public function test_nested_values_are_redacted(): void
    {
        $redacted = app(Redactor::class)->redact([
            'billing' => ['card_number' => '4242', 'line1' => '14 Mill Lane'],
            'items' => [['sku' => 'A1', 'secret' => 's']],
        ]);

        $this->assertSame([
            'billing' => ['card_number' => '[redacted]', 'line1' => '14 Mill Lane'],
            'items' => [['sku' => 'A1', 'secret' => '[redacted]']],
        ], $redacted);
    }

    public function test_a_sensitive_key_holding_an_array_is_redacted_whole(): void
    {
        $this->assertSame(['card' => '[redacted]'], app(Redactor::class)->redact(['card' => ['number' => '4242']]));
    }

    public function test_the_list_can_be_extended_in_config(): void
    {
        config(['culprit.redact' => [...config('culprit.redact'), 'passport']]);

        $this->assertSame(['passport_number' => '[redacted]'], app(Redactor::class)->redact(['passport_number' => 'X1']));
    }

    public function test_query_only_keys_are_not_redacted_in_bodies(): void
    {
        $this->assertSame(['state' => 'Kent', 'code' => 'ME1'], app(Redactor::class)->redact(['state' => 'Kent', 'code' => 'ME1']));
    }

    public function test_query_strings_are_redacted(): void
    {
        $this->assertSame(
            'https://example.com/a?coupon=X&token=[redacted]&Signature=[redacted]&code=[redacted]&state=[redacted]&list[]=1#top',
            app(Redactor::class)->url('https://example.com/a?coupon=X&token=t&Signature=s&code=c&state=x&list[]=1#top'),
        );
    }

    public function test_urls_without_a_query_string_are_unchanged(): void
    {
        $this->assertSame('https://example.com/a', app(Redactor::class)->url('https://example.com/a'));
    }

    public function test_command_options_are_redacted(): void
    {
        $this->assertSame(
            'php artisan users:create --password=[redacted] --token [redacted] --name=Priya -v',
            app(Redactor::class)->commandLine('php artisan users:create --password=hunter2 --token abc --name=Priya -v'),
        );
    }

    public function test_quoted_command_option_values_are_redacted(): void
    {
        $this->assertSame(
            "php artisan x --password=[redacted] --name='Priya Shah'",
            app(Redactor::class)->commandLine("php artisan x --password='hunter 2' --name='Priya Shah'"),
        );
    }
}
