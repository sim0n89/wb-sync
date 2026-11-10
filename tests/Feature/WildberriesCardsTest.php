<?php

use App\Exceptions\Wildberries\RateLimitExceededException;
use App\Services\Wildberries\Data\CardsPageDto;
use App\Services\Wildberries\WildberriesClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
});

function wbCard(int $nmId, array $chrtIds): array
{
    return [
        'nmID' => $nmId,
        'vendorCode' => "art-{$nmId}",
        'title' => "Товар {$nmId}",
        'sizes' => array_map(fn (int $chrtId) => ['chrtID' => $chrtId, 'techSize' => 'M', 'skus' => ["46{$chrtId}"]], $chrtIds),
        'updatedAt' => '2026-11-10T10:00:00.12345Z',
    ];
}

test('cards are read from the content host with sort and cursor', function () {
    Http::fake(['content.test/*' => Http::response([
        'cards' => [wbCard(11, [101, 102])],
        'cursor' => ['updatedAt' => '2026-11-10T10:00:00.12345Z', 'nmID' => 11, 'total' => 1],
    ])]);

    $client = new WildberriesClient('token', 'https://wb.test', 'https://content.test');
    $page = $client->getCards('2026-11-09T08:00:00.5Z', 7);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://content.test/content/v2/get/cards/list'
            && $request->header('Authorization') === ['token']
            && $request->data() === ['settings' => [
                'sort' => ['ascending' => true],
                'filter' => ['withPhoto' => -1],
                'cursor' => ['limit' => 100, 'updatedAt' => '2026-11-09T08:00:00.5Z', 'nmID' => 7],
            ]];
    });

    $card = $page->cards[0];

    expect($card->nmId)->toBe(11)
        ->and($card->vendorCode)->toBe('art-11')
        ->and($card->title)->toBe('Товар 11')
        ->and(array_map(fn ($size) => [$size->chrtId, $size->techSize, $size->skus], $card->sizes))
        ->toBe([[101, 'M', ['46101']], [102, 'M', ['46102']]])
        ->and($page->cursorUpdatedAt)->toBe('2026-11-10T10:00:00.12345Z')
        ->and($page->cursorNmId)->toBe(11)
        ->and($page->total)->toBe(1);
});

test('first page is requested without cursor and limit is capped', function () {
    Http::fake(['content.test/*' => Http::response(['cards' => [], 'cursor' => ['total' => 0]])]);

    (new WildberriesClient('token', 'https://wb.test', 'https://content.test'))->getCards(limit: 500);

    Http::assertSent(fn (Request $request) => $request->data()['settings']['cursor'] === ['limit' => 100]);
});

test('page is the last one when wildberries returns fewer cards than asked', function () {
    $full = new CardsPageDto([], '2026-11-10T10:00:00Z', 11, 100);
    $short = new CardsPageDto([], '2026-11-10T10:00:00Z', 11, 99);
    $empty = CardsPageDto::fromWildberries(['cards' => [], 'cursor' => ['total' => 0]]);

    expect($full->isLast(100))->toBeFalse()
        ->and($short->isLast(100))->toBeTrue()
        ->and($empty->isLast(100))->toBeTrue();
});

test('429 on cards gives rate limit exception', function () {
    Http::fake(['content.test/*' => Http::response([], 429, ['X-Ratelimit-Retry' => '7'])]);

    expect(fn () => (new WildberriesClient('token', 'https://wb.test', 'https://content.test'))->getCards())
        ->toThrow(fn (RateLimitExceededException $e) => expect($e->retryAfter)->toBe(7));
});

test('content requests wait for their own slower limit', function () {
    Http::fake(['content.test/*' => Http::response(['cards' => [], 'cursor' => ['total' => 0]])]);
    $client = new WildberriesClient('token', 'https://wb.test', 'https://content.test');
    $start = now();

    $client->getCards();
    $client->getCards();
    $client->getCards();

    expect((int) $start->diffInSeconds(now()))->toBe(2);
});

test('busy marketplace limit does not delay content requests and the other way round', function () {
    Http::fake([
        'wb.test/*' => Http::response(['orders' => [], 'next' => 0]),
        'content.test/*' => Http::response(['cards' => [], 'cursor' => ['total' => 0]]),
    ]);
    $client = new WildberriesClient('token', 'https://wb.test', 'https://content.test', requestsPerSecond: 2);
    $start = now();

    $client->getOrders();
    $client->getOrders();
    $client->getCards();

    expect((int) $start->diffInSeconds(now()))->toBe(0);

    $client->getOrders();

    expect((int) $start->diffInSeconds(now()))->toBe(1);
});
