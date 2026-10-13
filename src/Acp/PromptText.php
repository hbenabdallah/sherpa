<?php

namespace App\Acp;

/**
 * An editor's prompt — text, and the files the user pointed at — as the one
 * message the model reads. A file mentioned is named by its path in the
 * project, which the model can read; a file sent whole (an editor's
 * selection, an unsaved buffer) is given as it was sent.
 */
final class PromptText
{
    /** @param array<int, mixed> $blocks */
    public static function from(array $blocks, string $directory): string
    {
        $text = '';
        $attached = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            switch ($block['type'] ?? '') {
                case 'text':
                    $text .= is_string($block['text'] ?? null) ? $block['text'] : '';
                    break;

                case 'resource_link':
                    $uri = is_string($block['uri'] ?? null) ? $block['uri'] : '';
                    $name = is_string($block['name'] ?? null) ? $block['name'] : $uri;
                    $text = self::spaced($text, '@' . (self::path($uri, $directory) ?? $name));
                    break;

                case 'resource':
                    $resource = is_array($block['resource'] ?? null) ? $block['resource'] : [];
                    $uri = is_string($resource['uri'] ?? null) ? $resource['uri'] : '';
                    $where = self::path($uri, $directory) ?? $uri;

                    $attached[] = is_string($resource['text'] ?? null)
                        ? "<file path=\"{$where}\">\n" . rtrim($resource['text'], "\n") . "\n</file>"
                        : "(attached: {$where}, not text)";
                    break;

                case 'image':
                case 'audio':
                    $attached[] = '(an ' . $block['type'] . ' was attached, which Sherpa cannot read)';
                    break;
            }
        }

        return $attached === [] ? $text : rtrim($text) . "\n\n" . implode("\n\n", $attached);
    }

    /** A file URI as a path in the project, or null for anything else. */
    private static function path(string $uri, string $directory): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }

        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $root = rtrim($directory, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private static function spaced(string $text, string $word): string
    {
        return $text === '' || preg_match('/\s$/', $text) === 1 ? $text . $word . ' ' : "{$text} {$word} ";
    }
}
