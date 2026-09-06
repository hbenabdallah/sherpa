<?php

namespace App\Memory;

/**
 * What actually happened to a fact that was handed to the store.
 *
 * remember() used to return nothing, so a write that destroyed an unrelated
 * fact and a write that changed nothing at all looked identical from the
 * outside — to the caller, to the model, and to whoever was reading the screen.
 */
enum Recorded
{
    case Created;
    case Unchanged;
    case Superseded;
}
