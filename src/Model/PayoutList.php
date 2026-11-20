<?php

namespace Anore\Model;

class PayoutList extends Model implements \IteratorAggregate
{
    public function shopId(): ?int { return $this->int('shopId'); }

    public function limit(): ?int { return $this->int('limit'); }

    /** @return Payout[] */
    public function payouts(): array
    {
        return array_map(function (array $item): Payout { return new Payout($item); }, $this->raw['items'] ?? []);
    }

    public function hasMore(): bool { return $this->bool('hasMore'); }

    public function nextCursor(): ?string { return $this->str('nextCursor'); }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->payouts());
    }
}
