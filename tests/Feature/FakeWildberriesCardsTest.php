<?php

use App\Services\FakeWildberries\FakeCardStore;
use App\Services\Wildberries\WildberriesClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function fakeCardsPage(string $token, array $cursor)
{
    return test()->postJson('/fake-wb/content/v2/get/cards/list', ['settings' => ['cursor' => $cursor]], ['Authorization' => $token]);
}

test('cards request without token or with a bad limit is rejected', function () {
    $this->postJson('/fake-wb/content/v2/get/cards/list', ['settings' => ['cursor' => ['limit' => 10]]])->assertStatus(401);
    fakeCardsPage('token', ['limit' => 101])->assertStatus(400)->assertJsonPath('error', true);
    $this->postJson('/fake-wb/content/v2/get/cards/list', ['settings' => ['cursor' => 'oops']], ['Authorization' => 'token'])->assertStatus(400);
    fakeCardsPage('token', ['limit' => 10, 'nmID' => 5])->assertStatus(400);
});

test('emulator cards use the demo catalog numbering', function () {
    app(FakeCardStore::class)->addCards('token', 1);

    fakeCardsPage('token', ['limit' => 10])
        ->assertOk()
        ->assertJsonPath('cards.0.nmID', 500_000_001)
        ->assertJsonPath('cards.0.vendorCode', 'demo-500000001')
        ->assertJsonPath('cards.0.sizes.0.chrtID', 40_000_001)
        ->assertJsonPath('cards.0.sizes.2.chrtID', 40_000_003)
        ->assertJsonPath('cards.0.sizes.0.skus.0', '4600040000001')
        ->assertJsonPath('cursor.total', 1);
});

test('client reads all emulator cards page by page through the cursor', function () {
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
    app(FakeCardStore::class)->addCards('token', 5);

    Http::fake(function (Request $request) {
        $response = $this->call($request->method(), parse_url($request->url(), PHP_URL_PATH), [], [], [], [
            'HTTP_AUTHORIZATION' => $request->header('Authorization')[0],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $request->body());

        return Http::response($response->getContent(), $response->getStatusCode());
    });

    $client = new WildberriesClient('token', 'http://fake.test/fake-wb', 'http://fake.test/fake-wb');
    $nmIds = [];
    $updatedAt = null;
    $nmId = null;

    do {
        $page = $client->getCards($updatedAt, $nmId, 2);
        array_push($nmIds, ...array_map(fn ($card) => $card->nmId, $page->cards));
        [$updatedAt, $nmId] = [$page->cursorUpdatedAt, $page->cursorNmId];
    } while (! $page->isLast(2));

    expect($nmIds)->toBe(range(500_000_001, 500_000_005));
});

test('trashed card leaves the list and comes back after restore', function () {
    app(FakeCardStore::class)->addCards('token', 2);

    $this->artisan('app:fake-wb-trash-card', ['token' => 'token', 'nm_id' => 500_000_001])
        ->expectsOutput('Card 500000001 is in the trash.')
        ->assertSuccessful();

    fakeCardsPage('token', ['limit' => 10])->assertJsonCount(1, 'cards')->assertJsonPath('cards.0.nmID', 500_000_002);

    $this->travel(1)->seconds();
    $this->artisan('app:fake-wb-trash-card', ['token' => 'token', 'nm_id' => 500_000_001, '--off' => true])->assertSuccessful();

    fakeCardsPage('token', ['limit' => 10])->assertJsonPath('cards.1.nmID', 500_000_001);
});

test('cards commands check their input', function () {
    $this->artisan('app:fake-wb-add-cards', ['token' => 'token', 'count' => 0])->assertFailed();
    $this->artisan('app:fake-wb-trash-card', ['token' => 'token', 'nm_id' => 1])->assertFailed();

    $this->artisan('app:fake-wb-add-cards', ['token' => 'token', 'count' => 3])
        ->expectsOutput('Added 3 card(s), 3 in total for this token.')
        ->assertSuccessful();
});
