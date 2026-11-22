<?php

namespace Elcreator\aPhalcon\Latte;

use Closure;
use Latte\Extension;
use Latte\Runtime\Html;
use Phalcon\Di\DiInterface;

/**
 * The DI as a Latte function, for the templates aLatteX renders.
 *
 *   {phalcon('greeter')->greet($pagetitle)}    a service
 *   {var $di = phalcon()}                       the container itself
 *   {foreach phalcon('articles')->latest() as $a}
 *   {$content|links}                            rich text with [~id~] links resolved
 *   {$content|evo}                              rich text run through the CMS parser
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
        private string $linksFilter = 'links',
        private string $parseFilter = 'evo',
    ) {
    }

    /**
     * Two filters for rich-text fields, both through the DI's 'cms', both
     * returning markup (the field is HTML the manager's editor wrote), so it
     * is not escaped again: links resolves the CMS's link tags only, evo runs
     * the CMS parser over the field.
     */
    public function getFilters(): array
    {
        $filters = [];
        if ($this->linksFilter !== '') {
            $filters[$this->linksFilter] = [$this, 'links'];
        }
        if ($this->parseFilter !== '') {
            $filters[$this->parseFilter] = [$this, 'parse'];
        }

        return $filters;
    }

    public function parse(mixed $html): Html
    {
        return new Html(($this->di)()->get('cms')->parse((string) $html));
    }

    public function links(mixed $html): Html
    {
        return new Html(($this->di)()->get('cms')->links((string) $html));
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
