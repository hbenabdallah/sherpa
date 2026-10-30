<?php

declare(strict_types=1);

namespace App\Tool\Edit;

/** What applying one search-and-replace came to. */
final readonly class PatchOutcome
{
    public function __construct(
        /** The whole file after the change; null when the change could not be placed. */
        public ?string $content,
        /** How the search was found: exact, line endings, doubled backslashes, indentation. */
        public string $how = 'exact',
        public ?string $error = null,
        /** For a failure: what to try instead, quoting the file. */
        public ?string $hint = null,
        /** 1-based line where the replaced text starts. */
        public ?int $line = null,
        /** The text actually replaced, as it was in the file. */
        public string $matched = '',
        /** The text actually written in its place. */
        public string $replacement = '',
    ) {}

    public static function failed(string $error, ?string $hint = null): self
    {
        return new self(content: null, error: $error, hint: $hint);
    }

    public function ok(): bool
    {
        return $this->content !== null;
    }

    /** The error and its hint as one message, for the model. */
    public function message(): string
    {
        return $this->hint === null ? (string) $this->error : "{$this->error}.\n{$this->hint}";
    }
}
