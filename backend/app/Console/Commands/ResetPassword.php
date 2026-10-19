<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetPassword extends Command
{
    protected $signature = 'sis:reset-password {email : Email tài khoản} {--password= : Mật khẩu mới (bỏ trống để tự sinh)}';

    protected $description = 'Đặt lại mật khẩu cho một tài khoản bất kỳ (dùng trên server khi quên mật khẩu)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Không tìm thấy tài khoản: '.$this->argument('email'));

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(16, symbols: false);
        $user->update(['password' => Hash::make($password)]);
        // Đăng xuất mọi thiết bị đang dùng mật khẩu cũ
        $user->tokens()->delete();

        $this->info("Đã đặt lại mật khẩu: {$user->email} / {$password}");

        return self::SUCCESS;
    }
}
