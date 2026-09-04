<?php

use Modules\Core\Models\Company;
use Modules\Core\Models\User;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Currency;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\ExchangeRate;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\Sales\Services\SalesOrderService;

function salesOrderCurrencyFixture(): array
{
    Company::query()->findOrFail(1)->update(['base_currency' => 'USD']);

    $user = User::factory()->create(['company_id' => 1]);
    $customer = Customer::create([
        'company_id' => 1,
        'code' => 'BUY-'.uniqid(),
        'name' => 'Currency Test Buyer',
    ]);
    $style = Style::create([
        'company_id' => 1,
        'style_no' => 'CUR-'.uniqid(),
        'category' => 'WOVEN',
    ]);
    $color = Color::create([
        'company_id' => 1,
        'code' => 'CLR-'.substr(uniqid(), -6),
        'name' => 'Navy',
    ]);
    $colorway = Colorway::create([
        'company_id' => 1,
        'style_id' => $style->id,
        'color_id' => $color->id,
    ]);
    $size = Size::create([
        'company_id' => 1,
        'code' => 'SZ-'.substr(uniqid(), -6),
    ]);

    $line = [[
        'style_id' => $style->id,
        'colorway_id' => $colorway->id,
        'size_id' => $size->id,
        'qty' => 100,
        'price' => 10,
    ]];

    return [$user, $customer, $line];
}

test('SO tanpa currency memakai base USD dengan snapshot rate satu', function () {
    [$user, $customer, $lines] = salesOrderCurrencyFixture();

    $so = app(SalesOrderService::class)->create(1, [
        'customer_id' => $customer->id,
        'order_date' => '2026-09-04',
    ], $lines, $user);

    expect($so->currency_id)->toBeNull()
        ->and($so->exchange_rate)->toBe('1.000000000000');
});

test('SO explicit USD tetap memakai snapshot rate satu', function () {
    [$user, $customer, $lines] = salesOrderCurrencyFixture();
    $usd = Currency::create([
        'company_id' => 1,
        'code' => 'USD',
        'name' => 'US Dollar',
        'symbol' => '$',
    ]);

    $so = app(SalesOrderService::class)->create(1, [
        'customer_id' => $customer->id,
        'currency_id' => $usd->id,
        'order_date' => '2026-09-04',
    ], $lines, $user);

    expect($so->currency_id)->toBe($usd->id)
        ->and($so->exchange_rate)->toBe('1.000000000000');
});

test('SO IDR menyimpan reciprocal rate yang diberikan operator', function () {
    [$user, $customer, $lines] = salesOrderCurrencyFixture();
    $idr = Currency::create([
        'company_id' => 1,
        'code' => 'IDR',
        'name' => 'Indonesian Rupiah',
        'symbol' => 'Rp',
    ]);

    $so = app(SalesOrderService::class)->create(1, [
        'customer_id' => $customer->id,
        'currency_id' => $idr->id,
        'exchange_rate' => '0.000061728395',
        'order_date' => '2026-09-04',
    ], $lines, $user);

    expect($so->currency_id)->toBe($idr->id)
        ->and($so->exchange_rate)->toBe('0.000061728395');
});

test('SO IDR memakai master rate terakhir pada tanggal order jika rate tidak diberikan', function () {
    [$user, $customer, $lines] = salesOrderCurrencyFixture();
    $idr = Currency::create([
        'company_id' => 1,
        'code' => 'IDR',
        'name' => 'Indonesian Rupiah',
        'symbol' => 'Rp',
    ]);
    ExchangeRate::create([
        'company_id' => 1,
        'currency_id' => $idr->id,
        'rate_date' => '2026-09-01',
        'rate' => '0.000062500000',
    ]);
    ExchangeRate::create([
        'company_id' => 1,
        'currency_id' => $idr->id,
        'rate_date' => '2026-09-05',
        'rate' => '0.000060000000',
    ]);

    $so = app(SalesOrderService::class)->create(1, [
        'customer_id' => $customer->id,
        'currency_id' => $idr->id,
        'order_date' => '2026-09-04',
    ], $lines, $user);

    expect($so->exchange_rate)->toBe('0.000062500000');
});

test('SO foreign currency gagal tertutup ketika rate belum tersedia', function () {
    [$user, $customer, $lines] = salesOrderCurrencyFixture();
    $idr = Currency::create([
        'company_id' => 1,
        'code' => 'IDR',
        'name' => 'Indonesian Rupiah',
        'symbol' => 'Rp',
    ]);

    app(SalesOrderService::class)->create(1, [
        'customer_id' => $customer->id,
        'currency_id' => $idr->id,
        'order_date' => '2026-09-04',
    ], $lines, $user);
})->throws(RuntimeException::class, 'belum tersedia');

test('SO base currency menolak rate selain satu', function () {
    [$user, $customer, $lines] = salesOrderCurrencyFixture();
    $usd = Currency::create([
        'company_id' => 1,
        'code' => 'USD',
        'name' => 'US Dollar',
        'symbol' => '$',
    ]);

    app(SalesOrderService::class)->create(1, [
        'customer_id' => $customer->id,
        'currency_id' => $usd->id,
        'exchange_rate' => '0.500000000000',
        'order_date' => '2026-09-04',
    ], $lines, $user);
})->throws(RuntimeException::class, 'wajib menggunakan exchange rate 1');
