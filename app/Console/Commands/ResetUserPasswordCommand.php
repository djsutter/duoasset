<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ResetUserPasswordCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:reset-password
                            {email? : The email address of the user account}
                            {--password= : The new password to assign (non-interactive)}
                            {--generate : Generate a cryptographically secure random password}';

    /**
     * Command aliases.
     *
     * @var list<string>
     */
    protected $aliases = [
        'user:reset-password',
        'password:reset',
    ];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset a user account password by email address from the command line';

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

        $isGenerated = false;
        $password = null;

        if ($this->option('generate')) {
            $password = Str::password(16);
            $isGenerated = true;
        } elseif ($this->option('password') !== null) {
            $password = (string) $this->option('password');
            if (strlen($password) < 8) {
                $this->error('The password must be at least 8 characters.');

                return self::FAILURE;
            }
        } else {
            if (! $this->input->isInteractive()) {
                $this->error('In non-interactive mode, provide either --password=<password> or --generate.');

                return self::FAILURE;
            }

            $password = (string) $this->secret('Enter new password');

            if (strlen($password) < 8) {
                $this->error('The password must be at least 8 characters.');

                return self::FAILURE;
            }

            $confirmation = (string) $this->secret('Confirm new password');

            if ($password !== $confirmation) {
                $this->error('Password confirmation does not match.');

                return self::FAILURE;
            }
        }

        $oldRememberToken = $user->remember_token;

        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();

        if (method_exists($user, 'resetTwoFactorCode')) {
            $user->resetTwoFactorCode();
        }

        if (Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        event(new PasswordReset($user));

        Log::info('auth.password_reset_cli', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->info("Password successfully reset for [{$user->email}].");

        if ($isGenerated) {
            $this->line("Temporary password: <comment>{$password}</comment>");
            $this->comment('Advise the user to log in and change their password upon login.');
        }

        return self::SUCCESS;
    }
}
