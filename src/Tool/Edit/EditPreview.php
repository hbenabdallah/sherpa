<?php

declare(strict_types=1);

namespace App\Tool\Edit;

use App\Agent\Tool\ToolCall;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;

/** The edit a tool call would make, for the calls that edit a file. */
class EditPreview
{
    public function __construct(
        private readonly FileWriteTool $fileWrite,
        private readonly FilePatchTool $filePatch,
    ) {}

    /** null for a call that edits nothing, or whose edit cannot be worked out: it will fail. */
    public function of(ToolCall $call): ?ProposedEdit
    {
        $args = $call->arguments;
        $string = static fn(string $key): string => is_string($args[$key] ?? null) ? $args[$key] : '';

        return match ($call->name) {
            'file_write' => $this->fileWrite->proposal($string('path'), $string('content')),
            'file_patch' => $this->filePatch->proposal($string('path'), $string('search'), $string('replace')),
            default      => null,
        };
    }
}
