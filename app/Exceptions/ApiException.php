<?php

namespace App\Exceptions;

use Exception;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

/**
 * A domain error that should be rendered as a JSON API error response.
 * In GraphQL it is client safe and exposes { code, status, errors } as extensions.
 */
class ApiException extends Exception implements ClientAware, ProvidesExtensions
{
    public function __construct(
        string $message,
        protected int $status = 422,
        protected array $errors = [],
        protected ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $message, ?string $code = null): self
    {
        return new self($message, 409, [], $code);
    }

    public static function unprocessable(string $message, array $errors = [], ?string $code = null): self
    {
        return new self($message, 422, $errors, $code);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self($message, 404);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getExtensions(): array
    {
        return array_filter([
            'code' => $this->errorCode,
            'status' => $this->status,
            'errors' => $this->errors ?: null,
        ], fn ($v) => $v !== null);
    }
}
