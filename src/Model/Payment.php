<?php

namespace Anore\Model;

class Payment extends Model
{
    public function success(): bool
    {
        return $this->bool('success');
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

    public function baseAmount(): ?float { return $this->float('baseAmount'); }

    public function currency(): ?string
    {
        return $this->str('currency');
    }

    public function currencyRate(): ?float { return $this->float('currencyRate'); }

    public function rubAmount(): ?float { return $this->float('rubAmount'); }

    public function description(): ?string { return $this->str('description'); }

    public function status(): ?string
    {
        return $this->str('status');
    }

    public function paid(): bool
    {
        return $this->bool('paid');
    }

    public function test(): bool { return $this->bool('test'); }

    public function paymentUrl(): ?string
    {
        return $this->str('paymentUrl');
    }

    public function sbpUrl(): ?string { return $this->str('sbpUrl'); }

    public function method(): ?string { return $this->str('method'); }

    public function createdAt(): ?string { return $this->str('createdAt'); }

    public function paidAt(): ?string { return $this->str('paidAt'); }

    public function expiresIn(): ?int
    {
        return $this->int('expiresIn');
    }
}
