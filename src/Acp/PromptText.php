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
    /** What providers take: PNG, JPEG, GIF and WebP, 5 MB at most (Anthropic's limit, the strictest). */
    private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param array<int, mixed> $blocks
     * @param bool              $seesImages whether the model reads images: if not, each is
     *                                      said to be there and left out
     */
    public static function from(array $blocks, string $directory, bool $seesImages = false): string
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
                    $refused = self::refused($block);
                    if ($refused !== null) {
                        $attached[] = "(an image was attached, which was not sent: {$refused})";
                    } elseif (!$seesImages) {
                        $attached[] = '(an image was attached, which this model cannot see)';
                    }
                    break;

                case 'audio':
                    $attached[] = '(an audio clip was attached, which Sherpa cannot hear)';
                    break;
            }
        }

        return $attached === [] ? $text : rtrim($text) . "\n\n" . implode("\n\n", $attached);
    }

    /**
     * The images to send with the text: those a provider takes.
     *
     * @param array<int, mixed> $blocks
     *
     * @return list<array{mimeType: string, data: string}>
     */
    public static function images(array $blocks): array
    {
        $images = [];
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'image' && self::refused($block) === null) {
                $images[] = ['mimeType' => $block['mimeType'], 'data' => $block['data']];
            }
        }

        return $images;
    }

    /**
     * Why an image cannot be sent, or null when it can.
     *
     * @param array<string, mixed> $block
     */
    private static function refused(array $block): ?string
    {
        $type = is_string($block['mimeType'] ?? null) ? $block['mimeType'] : '';
        if (!in_array($type, self::IMAGE_TYPES, true)) {
            return ($type === '' ? 'no type given' : $type) . ', where PNG, JPEG, GIF and WebP are taken';
        }

        $data = is_string($block['data'] ?? null) ? $block['data'] : '';
        $bytes = base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            return 'its data is not base64';
        }

        if (strlen($bytes) > self::IMAGE_MAX_BYTES) {
            return sprintf('%.1f MB, past the 5 MB providers take', strlen($bytes) / 1048576);
        }

        return null;
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
