<?php

namespace Anore\Model;

/** A parsed (and verified) webhook payload. */
class WebhookEvent extends Model
{
    /** e.g. "payment.succeeded". */
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

    public function currency(): ?string
    {
        return $this->str('currency');
    }

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
        return $this->event() === 'payment.succeeded';
    }
}
