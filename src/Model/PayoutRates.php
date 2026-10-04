<?php

namespace Anore\Model;

class PayoutRates extends Model
{
    public function shopId(): ?int { return $this->int('shopId'); }
    public function ratePolicy(): ?string { return $this->str('ratePolicy'); }
    public function rapiraUsdtRub(): ?float { return $this->float('rapiraUsdtRub'); }
    public function rapiraMarketUsdtRub(): ?float { return $this->float('rapiraMarketUsdtRub'); }
    public function rapiraMarkupPercent(): ?float { return $this->float('rapiraMarkupPercent'); }
    public function cbrUsdRub(): ?float { return $this->float('cbrUsdRub'); }
    public function rubToUsdt(): ?float { return $this->float('rubToUsdt'); }
    public function usdtToRub(): ?float { return $this->float('usdtToRub'); }
    public function rubToRubSettlement(): ?float { return $this->float('rubToRubSettlement'); }
    public function rapiraFixing(): ?string { return $this->str('rapiraFixing'); }
    public function fees(): array { return is_array($this->raw['fees'] ?? null) ? $this->raw['fees'] : []; }
}
