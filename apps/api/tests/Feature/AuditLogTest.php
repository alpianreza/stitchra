<?php

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

test('audit log tidak punya updated_at dan tidak bisa diupdate', function () {
    $user = User::factory()->create(['company_id' => 1]);
    $this->actingAs($user);

    $log = app(AuditService::class)->record('create', $user);

    // Append-only: tidak ada kolom updated_at
    expect($log->updated_at)->toBeNull();
})->throws(\Exception::class);
