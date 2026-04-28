<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserState;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DefaultUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('eh.default_user.email', 'admin@local');
        $password = config('eh.default_user.password', 'change-me');
        $name = config('eh.default_user.name', 'Keeper');

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        UserState::firstOrCreate(['user_id' => $user->id], ['blobs' => []]);
    }
}
