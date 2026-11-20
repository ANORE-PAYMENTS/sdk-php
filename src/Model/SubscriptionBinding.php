<?php

namespace Anore\Model;

class SubscriptionBinding extends Model
{
    public function success(): bool { return $this->bool('success'); }

    public function subscriptionId(): ?int { return $this->int('subscriptionId'); }

    public function shopId(): ?int { return $this->int('shopId'); }

    public function status(): ?string { return $this->str('status'); }

    public function qrUrl(): ?string { return $this->str('qrUrl'); }

    public function environment(): ?string { return $this->str('environment'); }
}
