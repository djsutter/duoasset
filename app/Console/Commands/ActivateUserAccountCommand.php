<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ActivateUserAccountCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:activate-account
                            {email? : The email address of the user account}';

    /**
     * Command aliases.
     *
     * @var list<string>
     */
    protected $aliases = [
        'user:activate',
        'account:activate',
    ];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Activate and verify a user account by email address from the command line';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        if (! $email) {
            if (! $this->input->isInteractive()) {
                $this->error('The email argument is required in non-interactive mode.');

                return self::FAILURE;
            }

            $email = $this->ask('Enter user email address');
        }

        $email = trim((string) $email);

        if ($email === '') {
            $this->error('Email address cannot be empty.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first()
            ?? User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if (! $user) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $wasVerified = $user->hasVerifiedEmail();
        $hadActivationToken = $user->activation_token !== null;

        if ($wasVerified && ! $hadActivationToken) {
            $this->info("Account [{$user->email}] is already active and verified.");

            return self::SUCCESS;
        }

        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'activation_token' => null,
        ])->save();

        if (! $wasVerified) {
            event(new Verified($user));
        }

        Log::info('auth.account_activated_cli', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->info("Account successfully activated for [{$user->email}].");

        return self::SUCCESS;
    }
}
