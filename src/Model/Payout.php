<?php

namespace Anore\Model;

class Payout extends Model
{
    public function id(): ?string { return $this->str('id'); }
    public function shopId(): ?int { return $this->int('shopId'); }
    public function legacy(): bool { return $this->bool('legacy'); }
    public function status(): ?string { return $this->str('status'); }
    public function amount(): ?float { return $this->float('amount'); }
    public function method(): ?string { return $this->str('methodCode') ?? $this->str('method'); }
    public function address(): ?string { return $this->str('address'); }
    public function bank(): ?string { return $this->str('bank'); }
    public function fee(): ?float { return $this->float('fee'); }
    public function netRub(): ?float { return $this->float('netRub'); }
    public function amountUsdt(): ?float { return $this->float('amountUsdt'); }
    public function rapiraRate(): ?float { return $this->float('rapiraRate'); }
    public function cbrRate(): ?float { return $this->float('cbrRate'); }
    public function settlementRub(): ?float { return $this->float('settlementRub'); }
    public function quoteType(): ?string { return $this->str('quoteType'); }
    public function ratePolicy(): ?string { return $this->str('ratePolicy'); }
    public function rateBasis(): ?string { return $this->str('rateBasis'); }
    public function quoteAppliedAt(): ?string { return $this->str('quoteAppliedAt'); }
    public function manualCorrection(): bool { return $this->bool('manualCorrection'); }
    public function statusRevision(): ?int { return $this->int('statusRevision'); }
    public function externalId(): ?string { return $this->str('externalId'); }
    public function createdAt(): ?string { return $this->str('createdAt'); }
    public function processedAt(): ?string { return $this->str('processedAt'); }
    public function txHash(): ?string { return $this->str('txHash'); }
}
