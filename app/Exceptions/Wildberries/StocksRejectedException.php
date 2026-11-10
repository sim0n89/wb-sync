<?php

namespace App\Exceptions\Wildberries;

use App\Enums\StocksErrorType;
use Exception;
use Illuminate\Http\Client\Response;
use Throwable;

class StocksRejectedException extends Exception
{
    public const array TEMPORARY_CODES = [
        'StoreIsProcessing',
        'UploadDataLimit',
    ];

    /**
     * @param  array<int, array{code: string, message: string}>  $itemErrors  chrtId => error
     */
    public function __construct(
        public readonly StocksErrorType $type,
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $itemErrors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function isRejection(Response $response): bool
    {
        return in_array($response->status(), [400, 404, 406, 409], true);
    }

    public static function fromResponse(Response $response, ?Throwable $previous = null): self
    {
        $groups = self::getGroups($response);
        $status = $response->status();
        $type = self::getType($status, $groups);

        $main = null;
        $itemErrors = [];

        foreach ($groups as $group) {
            if ($type === StocksErrorType::Item && in_array($group['code'], self::TEMPORARY_CODES, true)) {
                continue;
            }

            $main ??= $group;

            foreach ($group['chrtIds'] as $chrtId) {
                $itemErrors[$chrtId] ??= ['code' => $group['code'], 'message' => $group['message']];
            }
        }

        $main ??= $groups[0];

        return new self($type, $status, $main['code'], $main['message'], $itemErrors, $previous);
    }

    /**
     * @return array<int, array{code: string, message: string, chrtIds: array<int, int>}>
     */
    private static function getGroups(Response $response): array
    {
        $errors = $response->json();

        if (! is_array($errors) || $errors === []) {
            return [['code' => 'Unknown', 'message' => $response->body(), 'chrtIds' => []]];
        }

        if (! array_is_list($errors)) {
            $errors = [$errors];
        }

        $groups = [];

        foreach ($errors as $error) {
            $error = is_array($error) ? $error : [];

            $groups[] = [
                'code' => (string) ($error['code'] ?? 'Unknown'),
                'message' => (string) ($error['message'] ?? $response->body()),
                'chrtIds' => self::getDataChrtIds($error['data'] ?? []),
            ];
        }

        return $groups;
    }

    /**
     * @return array<int, int>
     */
    private static function getDataChrtIds(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (! array_is_list($data)) {
            $data = [$data];
        }

        $chrtIds = [];

        foreach ($data as $entry) {
            if (is_array($entry) && is_numeric($entry['chrtId'] ?? null)) {
                $chrtIds[] = (int) $entry['chrtId'];
            }
        }

        return $chrtIds;
    }

    /**
     * @param  array<int, array{code: string, message: string, chrtIds: array<int, int>}>  $groups
     */
    private static function getType(int $status, array $groups): StocksErrorType
    {
        if ($status === 406) {
            return StocksErrorType::Temporary;
        }

        $allTemporary = true;

        foreach ($groups as $group) {
            if (! in_array($group['code'], self::TEMPORARY_CODES, true)) {
                $allTemporary = false;
            }
        }

        if ($allTemporary) {
            return StocksErrorType::Temporary;
        }

        if ($status === 409) {
            return StocksErrorType::Item;
        }

        return StocksErrorType::Request;
    }
}
