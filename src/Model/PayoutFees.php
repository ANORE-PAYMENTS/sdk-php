<?php

namespace Anore\Model;

class PayoutFees extends Model
{
    public function shopId(): ?int { return $this->int('shopId'); }
    public function currency(): ?string { return $this->str('currency'); }
    public function thresholdRub(): ?float { return $this->float('thresholdRub'); }
    public function minAmountRub(): ?float { return $this->float('minAmountRub'); }
    public function maxAmountRub(): ?float { return $this->float('maxAmountRub'); }

    public function methods(): array
    {
        return is_array($this->raw['methods'] ?? null) ? $this->raw['methods'] : [];
    }
}
