<?php

namespace Anore\Exception;

class ApiException extends AnoreException
{

    protected $status;

    protected $requestId;

    public function __construct(string $message, int $status, ?string $requestId = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->requestId = $requestId;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public static function forStatus(int $status, string $message, ?string $requestId = null): ApiException
    {
        switch ($status) {
            case 400:
                return new ValidationException($message, $status, $requestId);
            case 401:
                return new AuthenticationException($message, $status, $requestId);
            case 403:
                return new ForbiddenException($message, $status, $requestId);
            case 404:
                return new NotFoundException($message, $status, $requestId);
            default:
                if ($status >= 500) {
                    return new ServerException($message, $status, $requestId);
                }
                return new self($message, $status, $requestId);
        }
    }
}
