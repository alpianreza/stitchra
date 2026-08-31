<?php

use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\User;

/**
 * BR-111: login security — lockout setelah 5x gagal, rate limit, token Sanctum.
 */
test('login berhasil mengembalikan scoped token dengan expiry dan payload user', function () {
    $user = User::factory()->create([
        'company_id' => 1,
        'email' => 'test@stitchra.local',
        'password' => 'Secret123!',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@stitchra.local',
        'password' => 'Secret123!',
        'device_name' => 'playwright-test',
    ])->assertOk()->assertJsonStructure([
        'token', 'token_type', 'expires_at',
        'user' => ['id', 'email', 'roles', 'permissions'],
    ]);

    $token = $user->tokens()->latest('id')->first();

    expect($response->json('token_type'))->toBe('Bearer');
    expect($token->name)->toBe('playwright-test');
    expect($token->abilities)->toBe(['api:access']);
    expect($token->expires_at)->not->toBeNull();
    expect($token->expires_at->isFuture())->toBeTrue();
});

test('password tersimpan ter-hash (tidak plain text)', function () {
    $user = User::factory()->create(['company_id' => 1, 'password' => 'Secret123!']);

    expect($user->password)->not->toBe('Secret123!');
    expect(Hash::check('Secret123!', $user->password))->toBeTrue();
});

test('akun terkunci setelah 5x gagal login', function () {
    $user = User::factory()->create([
        'company_id' => 1,
        'email' => 'lock@stitchra.local',
        'password' => 'Secret123!',
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/login', ['email' => 'lock@stitchra.local', 'password' => 'salah']);
    }

    expect($user->fresh()->locked_until)->not->toBeNull();

    $this->postJson('/api/auth/login', ['email' => 'lock@stitchra.local', 'password' => 'Secret123!'])
        ->assertStatus(423);
});

test('user nonaktif tidak bisa login', function () {
    User::factory()->create([
        'company_id' => 1,
        'email' => 'off@stitchra.local',
        'password' => 'Secret123!',
        'is_active' => false,
    ]);

    $this->postJson('/api/auth/login', ['email' => 'off@stitchra.local', 'password' => 'Secret123!'])
        ->assertUnprocessable();
});
