<?php

declare(strict_types=1);

namespace App\Tool\Edit;

use ParseError;
use Phar;
use PhpToken;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Whether a file's new content still parses, for the kinds of file where
 * Sherpa can tell cheaply and for certain.
 *
 * The benchmark's first reference run scored a rename as passed while it had
 * written `use Boutique.User.UserRepository;` into a controller the tests
 * never load. Nothing about that edit was ambiguous to a parser, and a parser
 * is fifty milliseconds away. So file_patch and file_write refuse a change
 * that turns a valid file into an invalid one, and say why — the model gets
 * the error while it still remembers what it meant to write.
 *
 * PHP and JSON only: both have a parser that is certain and already here. A
 * file that did not parse before is not guarded, so repairing one in several
 * steps stays possible.
 */
class SyntaxGuard
{
    /**
     * @param bool|null $inProcess parse here rather than run `php -l`; by
     *                             default, when running from the single
     *                             executable, where PHP_BINARY is Sherpa itself
     */
    public function __construct(private readonly ?bool $inProcess = null) {}

    /** Why $content is not valid for a file at $path, or null when it is or this cannot tell. */
    public function problem(string $path, string $content): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php'   => $this->php($path, $content),
            'json'  => $this->json($content),
            default => null,
        };
    }

    /**
     * The code about to be written, in a file only its owner can read. In the
     * shared temporary directory, created with the default mode, it was
     * readable by every account on the machine for as long as the lint ran.
     * Created exclusively, so a name planted there in advance is not followed.
     */
    private static function privateCopy(string $content): ?string
    {
        $tmp = sys_get_temp_dir() . '/sherpa-lint-' . bin2hex(random_bytes(6)) . '.php';
        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            return null;
        }

        $written = @chmod($tmp, 0600) && @fwrite($handle, $content) === strlen($content);
        fclose($handle);

        if (!$written) {
            @unlink($tmp);

            return null;
        }

        return $tmp;
    }

    private function php(string $path, string $content): ?string
    {
        if ($this->inProcess ?? Phar::running(false) !== '') {
            return $this->parse($path, $content);
        }

        $tmp = self::privateCopy($content);
        if ($tmp === null) {
            return null; // cannot check, so do not block
        }

        try {
            $lint = new Process([PHP_BINARY, '-n', '-l', $tmp], timeout: 15);
            $lint->run();
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }

        if ($lint->getExitCode() === 0) {
            return null;
        }

        foreach (explode("\n", $lint->getOutput() . "\n" . $lint->getErrorOutput()) as $line) {
            if (stripos($line, 'error') !== false && !str_starts_with(trim($line), 'Errors parsing')) {
                return trim(preg_replace('/\s+/', ' ', str_replace($tmp, $path, $line)) ?? $line);
            }
        }

        return 'PHP syntax error';
    }

    /**
     * PHP's own parser, in this process: the same syntax errors as `php -l`,
     * though not the compile-time ones (a class declared twice) — which a
     * patch rarely introduces, and which the tests would catch.
     */
    private function parse(string $path, string $content): ?string
    {
        try {
            PhpToken::tokenize($content, TOKEN_PARSE);
        } catch (ParseError $e) {
            return sprintf('PHP Parse error: %s in %s on line %d', $e->getMessage(), $path, $e->getLine());
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function json(string $content): ?string
    {
        if (trim($content) === '') {
            return null;
        }

        json_decode($content);

        return json_last_error() === JSON_ERROR_NONE ? null : 'invalid JSON: ' . json_last_error_msg();
    }
}
