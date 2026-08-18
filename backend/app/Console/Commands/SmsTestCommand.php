<?php

namespace App\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sends one OTP through the active SMS driver so the flow can be exercised
 * end-to-end without a Semaphore account or credits. Mirrors the
 * `paymongo:simulate-payment` "command as a manual test harness" pattern:
 * in log mode it writes the code to storage/logs/sms.log; in semaphore mode
 * it sends a real SMS (2 credits on the OTP route) to the given PH number.
 * Harmless in production — it just sends one real message if run there.
 */
class SmsTestCommand extends Command
{
    protected $signature = 'sms:test {number? : PH mobile number — defaults to 09171234567}
        {--code= : fixed 6-digit code — defaults to a random one}';

    protected $description = "Sends one SMS OTP through the active driver (log = written to storage/logs/sms.log, semaphore = real SMS).";

    public function handle(): int
    {
        $number = (string) $this->argument('number');
        $number = $number === '' ? '09171234567' : $number;

        $code = (string) $this->option('code');
        $code = $code === '' ? (string) random_int(100000, 999999) : $code;

        $service = app(SmsService::class);

        try {
            $service->sendOtp($number, $code);
        } catch (InvalidArgumentException $e) {
            $this->error('Invalid number: '.$e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error('SMS send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($service->driver() === 'log') {
            $this->info('Code written to storage/logs/sms.log — open that file to read it.');
        } else {
            $this->info('Sent via Semaphore (per normal).');
        }

        return self::SUCCESS;
    }
}