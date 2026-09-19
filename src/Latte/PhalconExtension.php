<?php

namespace Elcreator\aPhalcon\Latte;

use Closure;
use Latte\Extension;
use Phalcon\Di\DiInterface;

/**
 * The DI as a Latte function, for the templates aLatteX renders.
 *
 *   {phalcon('greeter')->greet($pagetitle)}    a service
 *   {var $di = phalcon()}                       the container itself
 *   {foreach phalcon('articles')->latest() as $a}
 *
 * The container is resolved on first use, not when the extension is built:
 * aLatteX makes its engine early, and the DI - with the site's providers and
 * a database adapter behind it - should cost nothing on a page that never
 * asks for it.
 */
final class PhalconExtension extends Extension
{
    /** @param Closure(): DiInterface $di */
    public function __construct(
        private Closure $di,
        private string $function = 'phalcon',
    ) {
    }

    public function getFunctions(): array
    {
        if ($this->function === '') {
            return [];
        }

        return [$this->function => [$this, 'resolve']];
    }

    public function resolve(?string $service = null): mixed
    {
        $di = ($this->di)();

        return $service === null ? $di : $di->get($service);
    }
}
