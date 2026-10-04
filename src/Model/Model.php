<?php

namespace Anore\Model;

abstract class Model
{

    protected $raw;

    public function __construct(array $raw)
    {
        $this->raw = $raw;
    }

    public function raw(): array
    {
        return $this->raw;
    }

    protected function str(string $key): ?string
    {
        return isset($this->raw[$key]) ? (string) $this->raw[$key] : null;
    }

    protected function float(string $key): ?float
    {
        return isset($this->raw[$key]) && is_numeric($this->raw[$key]) ? (float) $this->raw[$key] : null;
    }

    protected function int(string $key): ?int
    {
        return isset($this->raw[$key]) && is_numeric($this->raw[$key]) ? (int) $this->raw[$key] : null;
    }

    protected function bool(string $key): bool
    {
        return !empty($this->raw[$key]);
    }
}
