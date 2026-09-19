<?php

namespace Elcreator\aPhalcon\Demo;

/**
 * A stand-in for a site's own service: something with no CMS in it that a
 * template or a route wants to call.
 */
final class Greeter
{
    public function __construct(private string $siteName)
    {
    }

    public function greet(string $name): string
    {
        return 'Hello, ' . $name . ' - from Phalcon, on ' . $this->siteName;
    }
}
