<?php

namespace App\Mcp;

use App\Runtime\Interrupt;
use Mcp\Client\State\ClientStateInterface;

/**
 * A transport whose waits hear Ctrl+C: see ListeningState. Without an
 * Interrupt — the tests, the /mcp screen — it waits as the SDK does.
 */
trait Listens
{
    private ?Interrupt $interrupt = null;

    public function listenTo(?Interrupt $interrupt): static
    {
        $this->interrupt = $interrupt;

        return $this;
    }

    public function setState(ClientStateInterface $state): void
    {
        parent::setState($this->interrupt === null ? $state : new ListeningState($state, $this->interrupt));
    }
}
