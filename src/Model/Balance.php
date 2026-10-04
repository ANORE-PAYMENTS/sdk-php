<?php

namespace Anore\Model;

class Balance extends Model
{
    public function currency(): ?string { return $this->str('currency'); }
    public function available(): ?float { return $this->float('available'); }
    public function shopLocalBalance(): ?float { return $this->float('shopLocalBalance'); }
    public function shopLocalAvailable(): ?float { return $this->float('shopLocalAvailable'); }
    public function accountAvailable(): ?float { return $this->float('accountAvailable'); }
    public function hold(): ?float { return $this->float('hold'); }
    public function frozen(): ?float { return $this->float('frozen'); }
    public function matured(): ?float { return $this->float('matured'); }
    public function paidAmount(): ?float { return $this->float('paidAmount'); }
    public function withdrawn(): ?float { return $this->float('withdrawn'); }
    public function reserved(): ?float { return $this->float('reserved'); }
    public function legacyPayoutsUnassigned(): bool { return $this->bool('legacyPayoutsUnassigned'); }
    public function fee(): array { return is_array($this->raw['fee'] ?? null) ? $this->raw['fee'] : []; }
    public function minAmount(): ?float { return $this->float('minAmount'); }
    public function maxAmount(): ?float { return $this->float('maxAmount'); }
    public function usdtRateRub(): ?float { return $this->float('usdtRateRub'); }
    public function rapiraMarketUsdtRub(): ?float { return $this->float('rapiraMarketUsdtRub'); }
    public function rapiraMarkupPercent(): ?float { return $this->float('rapiraMarkupPercent'); }
    public function cbrRateRub(): ?float { return $this->float('cbrRateRub'); }
    public function sbpBanks(): array { return is_array($this->raw['sbpBanks'] ?? null) ? $this->raw['sbpBanks'] : []; }
}
