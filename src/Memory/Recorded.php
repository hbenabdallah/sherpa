<?php

namespace App\Memory;

/**
 * What actually happened to a fact that was handed to the store.
 *
 * Without it, a write that replaced a fact and one that changed nothing look
 * the same to the caller, the model and whoever reads the screen.
 */
enum Recorded
{
    case Created;
    case Unchanged;
    case Superseded;
}
