<?php

namespace App\Skills;

final class Skill
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $content,
        public readonly string $path,
    ) {}

    /**
     * The skill run as a command, "/<skill> [request]", as the message the
     * model gets: its text, then what was asked.
     */
    public function applyTo(string $request): string
    {
        $request = trim($request);

        return "Apply the skill \"{$this->name}\" below"
            . ($request !== '' ? " to this request:\n\n{$request}" : ' to the current work.')
            . "\n\n<skill name=\"{$this->name}\">\n" . trim($this->content) . "\n</skill>";
    }
}
