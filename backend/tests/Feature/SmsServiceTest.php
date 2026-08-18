<?php

namespace Tests\Feature;

use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SmsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the real driver: the dev/default log driver would skip the
        // network entirely, which is not what these HTTP-path tests exercise.
        config()->set('services.semaphore.driver', 'semaphore');
        config()->set('services.semaphore.api_key', 'semaphore_dummy_key');
    }

    public function test_available_is_false_without_a_key(): void
    {
        config()->set('services.semaphore.api_key', '');

        $this->assertFalse(app(SmsService::class)->available());
    }

    public function test_available_is_true_with_a_key(): void
    {
        $this->assertTrue(app(SmsService::class)->available());
    }

    public function test_available_is_true_in_log_mode_without_a_key(): void
    {
        config()->set('services.semaphore.driver', 'log');
        config()->set('services.semaphore.api_key', '');

        $this->assertTrue(app(SmsService::class)->available());
    }

    public function test_semaphore_mode_without_a_key_throws(): void
    {
        config()->set('services.semaphore.api_key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SEMAPHORE_API_KEY is not configured');

        app(SmsService::class)->sendOtp('09171234567', '123456');
    }

    public function test_normalize_phone_accepts_all_philippine_formats(): void
    {
        $service = app(SmsService::class);

        $this->assertSame('639171234567', $service->normalizePhone('09171234567'));
        $this->assertSame('639171234567', $service->normalizePhone('639171234567'));
        $this->assertSame('639171234567', $service->normalizePhone('+639171234567'));
        $this->assertSame('639171234567', $service->normalizePhone('+63 917 123 4567'));
    }

    public function test_normalize_phone_rejects_invalid_numbers(): void
    {
        $service = app(SmsService::class);

        foreach (['', '12345', '091717', '07171234567'] as $invalid) {
            try {
                $service->normalizePhone($invalid);
                $this->fail('Expected InvalidArgumentException for '.$invalid);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_send_otp_posts_the_expected_payload(): void
    {
        Http::fake([
            'api.semaphore.co/api/v4/otp' => Http::response([
                [
                    'message_id' => 12345,
                    'recipient' => '639171234567',
                    'status' => 'Pending',
                    'code' => 123456,
                ],
            ]),
        ]);

        app(SmsService::class)->sendOtp('09171234567', '123456');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.semaphore.co/api/v4/otp'
                && $request['apikey'] === 'semaphore_dummy_key'
                && $request['number'] === '639171234567'
                && $request['code'] === '123456'
                && $request['sendername'] === 'GW-SYSTEM'
                && str_contains((string) $request['message'], '{otp}');
        });
    }

    public function test_send_otp_throws_when_semaphore_rejects_the_message(): void
    {
        Http::fake([
            'api.semaphore.co/api/v4/otp' => Http::response([
                ['status' => 'Failed', 'message' => 'request refused'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);

        app(SmsService::class)->sendOtp('09171234567', '123456');
    }

    public function test_send_otp_throws_on_http_error(): void
    {
        Http::fake([
            'api.semaphore.co/api/v4/otp' => Http::response(['errors' => []], 500),
        ]);

        $this->expectException(RuntimeException::class);

        app(SmsService::class)->sendOtp('09171234567', '123456');
    }

    public function test_log_driver_writes_the_code_and_sends_nothing(): void
    {
        config()->set('services.semaphore.driver', 'log');
        config()->set('services.semaphore.api_key', '');

        $path = storage_path('logs/testing/sms-log-driver.log');
        config()->set('logging.channels.sms.path', $path);
        @unlink($path);

        app(SmsService::class)->sendOtp('0917 123 4567', '123456');

        Http::assertNothingSent();

        $this->assertFileExists($path);
        $written = (string) file_get_contents($path);
        $this->assertStringContainsString('SMS OTP (log driver — not sent)', $written);
        $this->assertStringContainsString('639171234567', $written);
        $this->assertStringContainsString('123456', $written);
        $this->assertStringContainsString(
            'GW-System verification code: 123456. Expires in 5 minutes. Do not share.',
            $written
        );

        @unlink($path);
    }

    public function test_log_driver_still_rejects_invalid_phone_numbers(): void
    {
        config()->set('services.semaphore.driver', 'log');
        config()->set('services.semaphore.api_key', '');

        $this->expectException(InvalidArgumentException::class);

        app(SmsService::class)->sendOtp('12345', '123456');
    }
}