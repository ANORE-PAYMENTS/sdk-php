<?php

namespace Anore\Model;

class PaymentList extends Model
{
    public function success(): bool
    {
        return $this->bool('success');
    }

    public function shopId(): ?int
    {
        return $this->int('shopId');
    }

    public function payments(): array
    {
        return array_map(function (array $item): Payment {
            return new Payment($item);
        }, $this->raw['payments'] ?? []);
    }

    public function total(): int
    {
        return $this->int('total') ?? 0;
    }

    public function limit(): int
    {
        return $this->int('limit') ?? 0;
    }

    public function offset(): int
    {
        return $this->int('offset') ?? 0;
    }
}
