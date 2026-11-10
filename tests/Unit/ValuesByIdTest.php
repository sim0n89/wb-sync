<?php

use App\Models\Stock;
use App\Support\ValuesById;
use Illuminate\Database\Grammar;

test('values are turned into one case expression by the model key', function () {
    $sql = ValuesById::toCase(Stock::class, [3 => 5, 7 => 0])->getValue(Mockery::mock(Grammar::class));

    expect($sql)->toBe('CASE stocks.id WHEN 3 THEN 5 WHEN 7 THEN 0 END');
});

test('empty list is rejected', function () {
    ValuesById::toCase(Stock::class, []);
})->throws(InvalidArgumentException::class);
