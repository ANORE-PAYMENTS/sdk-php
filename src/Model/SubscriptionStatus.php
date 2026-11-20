<?php

namespace Anore\Model;

class SubscriptionStatus extends Model
{
    public function subscriptionId(): ?int { return $this->int('subscriptionId'); }

    public function shopId(): ?int { return $this->int('shopId'); }

    public function planId(): ?string { return $this->str('planId'); }

    public function planTitle(): ?string { return $this->str('planTitle'); }

    public function status(): ?string { return $this->str('status'); }

    public function customerId(): ?string { return $this->str('customerId'); }

    public function customerEmail(): ?string { return $this->str('customerEmail'); }

    public function amount(): ?float { return $this->float('amount'); }

    public function paymentMethod(): ?string { return $this->str('paymentMethod'); }

    public function environment(): ?string { return $this->str('environment'); }

    public function nextChargeAt(): ?string { return $this->str('nextChargeAt'); }

    public function createdAt(): ?string { return $this->str('createdAt'); }

    public function updatedAt(): ?string { return $this->str('updatedAt'); }
}
