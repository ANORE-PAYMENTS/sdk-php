<?php

namespace Anore\Model;

class SubscriptionCatalog extends Model
{
    public function shopIdRequired(): bool { return $this->bool('shopIdRequired'); }

    public function environment(): ?string { return $this->str('environment'); }

    public function shops(): array { return $this->raw['shops'] ?? []; }
}
