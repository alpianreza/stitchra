<?php

use LogicException;
use Modules\Core\Models\AuditLog;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;

/**
 * BR-016: audit log append-only — who/what/when/before/after/IP/device.
 */
test('audit log mencatat create dengan after', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $this->actingAs($user);

    app(AuditService::class)->record('create', $user, after: ['name' => $user->name]);

    $log = AuditLog::withoutGlobalScopes()->latest('id')->first();

    expect($log->action)->toBe('create');
    expect($log->document_type)->toBe('users');
    expect($log->user_id)->toBe($user->id);
    expect($log->after)->toBe(['name' => $user->name]);
});

test('audit log update menyimpan before dan after', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $this->actingAs($user);

    app(AuditService::class)->record(
        'update', $user,
        before: ['name' => 'Lama'], after: ['name' => 'Baru'],
    );

    $log = AuditLog::withoutGlobalScopes()->latest('id')->first();

    expect($log->before)->toBe(['name' => 'Lama']);
    expect($log->after)->toBe(['name' => 'Baru']);
});

test('audit log tidak dapat diubah', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $this->actingAs($user);

    $log = app(AuditService::class)->record('create', $user);
    $log->action = 'tampered';
    $log->save();
})->throws(LogicException::class, 'append-only');

test('audit log tidak dapat dihapus', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $this->actingAs($user);

    app(AuditService::class)->record('create', $user)->delete();
})->throws(LogicException::class, 'append-only');

test('audit log meredaksi field sensitif termasuk nested payload', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $this->actingAs($user);

    $log = app(AuditService::class)->record('update', $user, after: [
        'name' => 'Agen',
        'password' => 'plain-secret',
        'integration' => ['api_token' => 'token-value', 'label' => 'GitHub'],
    ]);

    expect($log->after)->toBe([
        'name' => 'Agen',
        'password' => '[REDACTED]',
        'integration' => ['api_token' => '[REDACTED]', 'label' => 'GitHub'],
    ]);
});
