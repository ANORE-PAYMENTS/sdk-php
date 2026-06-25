<?php

namespace Anore\Exception;

/** The API responded with a non-2xx status. */
class ApiException extends AnoreException
{
    /** @var int */
    protected $status;
    /** @var string|null */
    protected $requestId;

    public function __construct(string $message, int $status, ?string $requestId = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->requestId = $requestId;
    }

    /** HTTP status code returned by the API. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Value of the X-Request-Id response header, if any. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** Map an HTTP status to the most specific ApiException subclass. */
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
