<?php

namespace Anore\Model;

class SubscriptionCharge extends Model
{
    public function success(): bool { return $this->bool('success'); }

    public function subscriptionId(): ?int { return $this->int('subscriptionId'); }

    public function chargeId(): ?string { return $this->str('chargeId'); }

    public function paymentId(): ?int { return $this->int('paymentId'); }

    public function status(): ?string { return $this->str('status'); }
}
