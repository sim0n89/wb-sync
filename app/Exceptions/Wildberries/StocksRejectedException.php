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
     * @param  array<int, int>  $chrtIds
     */
    public function __construct(
        public readonly StocksErrorType $type,
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $chrtIds = [],
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
        $errors = $response->json();

        if (! is_array($errors) || ! array_is_list($errors)) {
            $errors = [$errors];
        }

        $error = is_array($errors[0] ?? null) ? $errors[0] : [];
        $code = (string) ($error['code'] ?? 'Unknown');
        $chrtIds = [];

        foreach ($errors as $item) {
            foreach ((array) ($item['data'] ?? []) as $data) {
                $chrtId = is_array($data) ? ($data['chrtId'] ?? null) : $data;

                if (is_numeric($chrtId)) {
                    $chrtIds[] = (int) $chrtId;
                }
            }
        }

        return new self(
            self::getType($response->status(), $code),
            $response->status(),
            $code,
            (string) ($error['message'] ?? $response->body()),
            array_values(array_unique($chrtIds)),
            $previous,
        );
    }

    private static function getType(int $status, string $code): StocksErrorType
    {
        if ($status === 406 || in_array($code, self::TEMPORARY_CODES, true)) {
            return StocksErrorType::Temporary;
        }

        if ($status === 409) {
            return StocksErrorType::Item;
        }

        return StocksErrorType::Request;
    }
}
