<?php

namespace Anore\Exception;

class ApiException extends AnoreException
{
    protected $status;
    protected $requestId;
    protected $responseBody;

    public function __construct(string $message, int $status, ?string $requestId = null, array $responseBody = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->requestId = $requestId;
        $this->responseBody = $responseBody;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    public static function forStatus(
        int $status,
        string $message,
        ?string $requestId = null,
        array $responseBody = []
    ): ApiException {
        switch ($status) {
            case 400:
                return new ValidationException($message, $status, $requestId, $responseBody);
            case 401:
                return new AuthenticationException($message, $status, $requestId, $responseBody);
            case 403:
                return new ForbiddenException($message, $status, $requestId, $responseBody);
            case 404:
                return new NotFoundException($message, $status, $requestId, $responseBody);
            default:
                if ($status >= 500) {
                    return new ServerException($message, $status, $requestId, $responseBody);
                }
                return new self($message, $status, $requestId, $responseBody);
        }
    }
}
