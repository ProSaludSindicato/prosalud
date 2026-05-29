<?php

namespace App\Exceptions;

use Exception;

class ProSanetApiException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $requestPayload
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $responseBody = null,
        ?\Throwable $previous = null,
        public readonly ?string $requestMethod = null,
        public readonly ?string $requestUrl = null,
        public readonly ?array $requestPayload = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function contextForLog(): array
    {
        return array_filter([
            'http_status' => $this->httpStatus,
            'request_method' => $this->requestMethod,
            'request_url' => $this->requestUrl,
            'request_payload' => $this->requestPayload,
            'response_body' => $this->responseBody,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
