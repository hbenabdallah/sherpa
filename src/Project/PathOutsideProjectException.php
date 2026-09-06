<?php

declare(strict_types=1);

namespace App\Project;

/**
 * Thrown when a tool is asked to touch a path outside the current project.
 *
 * Toolbox::execute() turns this into an error ToolResult, so the model sees the
 * refusal and can correct itself rather than the session dying.
 */
final class PathOutsideProjectException extends \RuntimeException
{
    public function __construct(
        public readonly string $requested,
        public readonly string $root,
    ) {
        parent::__construct(sprintf(
            'Access denied: "%s" resolves outside the project root (%s). '
            . 'Tools may only touch files inside the current project.',
            $requested,
            $root,
        ));
    }
}
