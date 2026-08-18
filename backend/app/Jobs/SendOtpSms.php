<?php

namespace App\Jobs;

use App\Services\SmsService;
use App\Support\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a verification OTP by SMS via Semaphore. Retries on transient
 * failures (10s / 30s / 60s); after the final failure the admin is notified
 * through the bell/hub so a customer's code is never silently lost.
 */
class SendOtpSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $phone,
        public string $code,
        public string $message,
    ) {}

    public function handle(): void
    {
        app(SmsService::class)->sendOtp($this->phone, $this->code, $this->message);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('sms')->error('SMS OTP delivery failed permanently', [
            'phone' => $this->phone,
            'error' => $exception?->getMessage(),
        ]);

        AdminNotifier::notify(
            'SMS delivery failed',
            'An OTP message to '.$this->phone.' never reached the customer.',
            'danger',
        );
    }
}