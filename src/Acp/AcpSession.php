<?php

namespace App\Acp;

use App\Agent\MessageBag;
use App\Config\ModelProfile;

/**
 * One conversation an editor holds open. The services behind a turn hold one
 * conversation at a time — the session file being written, the excerpts the
 * history points to — so each keeps its own copy here while another runs.
 */
final class AcpSession
{
    /** @var array{0: ?string, 1: ?\DateTimeImmutable, 2: ?string, 3: ?int} where its file is written */
    public array $cursor = [null, null, null, null];

    /** @var list<array{id: int, tool: string, content: string, created_at: string}> */
    public array $excerpts = [];

    public bool $planning = false;

    /** The model its turns run on: each conversation keeps the one chosen for it. */
    public ?ModelProfile $profile = null;

    /** Messages its facts were last taken from: grown past it, it has more to give. */
    public int $remembered = 0;

    /** @var list<string> tools allowed "for this session": this one, not every thread of the editor */
    public array $grants = [];

    public function __construct(
        public readonly string $id,
        /** The project directory, canonical. */
        public readonly string $directory,
        public readonly MessageBag $bag,
    ) {}
}
