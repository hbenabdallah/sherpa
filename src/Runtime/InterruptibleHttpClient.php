<?php

namespace App\Runtime;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A request that does not return before the server has answered, as any
 * other, but listens meanwhile: a page or an MCP server can take a minute to
 * send its first byte, and that minute was deaf to Ctrl+C and still on screen.
 * The wait goes through Interrupt::requested(), which hears the cancel and
 * keeps the "running" line turning.
 */
final class InterruptibleHttpClient implements HttpClientInterface
{
    use DecoratorTrait {
        DecoratorTrait::__construct as private decorate;
    }

    private const POLL_SECONDS = 0.1;

    public function __construct(HttpClientInterface $client, private readonly Interrupt $interrupt)
    {
        $this->decorate($client);
    }

    /** @throws TransportException when the user cut the wait short */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = $this->client->request($method, $url, $options);

        // stream() ends at its first silence: asked again until the headers.
        $interrupted = false;
        try {
            for ($waiting = true; $waiting && !$interrupted;) {
                $waiting = false;
                foreach ($this->client->stream($response, self::POLL_SECONDS) as $chunk) {
                    if ($chunk->isTimeout()) {
                        $waiting = true;
                        $interrupted = $this->interrupt->requested();
                    }
                    break; // the headers, or a silence: the rest is read as the caller reads it
                }
            }
        } catch (TransportExceptionInterface) {
            // A failure to connect: the caller hears of it from the response.
        }

        if ($interrupted) {
            $response->cancel();

            throw new TransportException('Interrupted by the user.');
        }

        return $response;
    }
}
