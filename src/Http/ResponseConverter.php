<?php

namespace Elcreator\aPhalcon\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as LaravelResponse;
use Phalcon\Http\ResponseInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * What a Phalcon handler produced, as the response Laravel sends.
 *
 * A handler can end in several ways and Phalcon accepts all of them: return a
 * Response, return a string, return an array, echo, or set status and headers
 * on the DI's 'response' service and return nothing. Each is mapped here, so a
 * handler written for a plain Phalcon site behaves the same behind the CMS.
 */
final class ResponseConverter
{
    /**
     * @param mixed                  $returned what the handler returned
     * @param string                 $echoed   what it printed while running
     * @param ResponseInterface|null $shared   the DI's 'response' service, for status and headers set on it
     */
    public function convert(mixed $returned, string $echoed, ?ResponseInterface $shared): SymfonyResponse
    {
        if ($returned instanceof ResponseInterface) {
            return $this->fromPhalcon($returned, (string) $returned->getContent());
        }

        // Already the CMS's kind of response - what the 'site' service and
        // Cms hand back - and not a Stringable to be printed.
        if ($returned instanceof SymfonyResponse) {
            return $returned;
        }

        if (is_array($returned) || $returned instanceof \JsonSerializable) {
            $response = new JsonResponse($returned);
            $this->applyShared($response, $shared);

            return $response;
        }

        if (is_string($returned) || $returned instanceof \Stringable) {
            // Micro has already printed a string return; Application has not.
            // Either way the string is the body, and anything echoed before it
            // keeps its place.
            $content = (string) $returned;
            $content = str_ends_with($echoed, $content) ? $echoed : $echoed . $content;

            return $this->withShared(new LaravelResponse($content), $shared);
        }

        return $this->withShared(new LaravelResponse($echoed), $shared);
    }

    private function fromPhalcon(ResponseInterface $phalcon, string $content): LaravelResponse
    {
        $response = new LaravelResponse($content, $this->status($phalcon) ?? 200);
        $this->headers($response, $phalcon);

        return $response;
    }

    private function withShared(LaravelResponse $response, ?ResponseInterface $shared): LaravelResponse
    {
        $this->applyShared($response, $shared);

        return $response;
    }

    private function applyShared(SymfonyResponse $response, ?ResponseInterface $shared): void
    {
        if ($shared === null) {
            return;
        }

        $status = $this->status($shared);
        if ($status !== null) {
            $response->setStatusCode($status);
        }

        $this->headers($response, $shared);
    }

    private function status(ResponseInterface $phalcon): ?int
    {
        $code = $phalcon->getStatusCode();

        return is_int($code) && $code > 0 ? $code : null;
    }

    /**
     * Copy the named headers. Phalcon keeps the status line in the same bag
     * as a key with a null value ("HTTP/1.1 404 Not Found" => null) and a
     * 'Status' entry beside it; both are the status, already carried over.
     */
    private function headers(SymfonyResponse $response, ResponseInterface $phalcon): void
    {
        foreach ($phalcon->getHeaders()->toArray() as $name => $value) {
            if ($value === null || strcasecmp((string) $name, 'Status') === 0) {
                continue;
            }

            $response->headers->set((string) $name, (string) $value);
        }
    }
}
