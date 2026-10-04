<?php

namespace Anore\Model;

class WebhookEvent extends Model
{

    public function event(): ?string
    {
        return $this->str('event');
    }

    public function id(): ?string
    {
        return $this->str('id');
    }

    public function orderId(): ?string
    {
        return $this->str('orderId');
    }

    public function amount(): ?float
    {
        return $this->float('amount');
    }

    public function rubAmount(): ?float { return $this->float('rubAmount'); }

    public function currency(): ?string
    {
        return $this->str('currency');
    }

    public function currencyRate(): ?float { return $this->float('currencyRate'); }

    public function status(): ?string
    {
        return $this->str('status');
    }

    public function description(): ?string
    {
        return $this->str('description');
    }

    public function shop(): ?string
    {
        return $this->str('shop');
    }

    public function createdAt(): ?string
    {
        return $this->str('createdAt');
    }

    public function isSucceeded(): bool
    {
        return in_array($this->event(), ['payment.succeeded', 'payout.succeeded'], true);
    }

    public function isPayout(): bool { return strpos($this->event() ?? '', 'payout.') === 0; }

    public function externalId(): ?string { return $this->str('externalId'); }

    public function shopId(): ?int { return $this->int('shopId'); }

    public function method(): ?string { return $this->str('method'); }

    public function fee(): ?float { return $this->float('fee'); }

    public function netRub(): ?float { return $this->float('netRub'); }

    public function amountUsdt(): ?float { return $this->float('amountUsdt'); }

    public function settlementRub(): ?float { return $this->float('settlementRub'); }

    public function processedAt(): ?string { return $this->str('processedAt'); }

    public function txHash(): ?string { return $this->str('txHash'); }

    public function manualCorrection(): bool { return $this->bool('manualCorrection'); }

    public function statusRevision(): ?int { return $this->int('statusRevision'); }
}
