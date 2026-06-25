<?php

namespace Anore\Model;

/** A payment / invoice — response of Client::createPayment and Client::getPayment. */
class Payment extends Model
{
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

    public function currency(): ?string
    {
        return $this->str('currency');
    }

    /** "new" | "paid" | "expired". */
    public function status(): ?string
    {
        return $this->str('status');
    }

    public function paid(): bool
    {
        return $this->bool('paid');
    }

    public function paymentUrl(): ?string
    {
        return $this->str('paymentUrl');
    }

    public function expiresIn(): ?int
    {
        return $this->int('expiresIn');
    }
}
