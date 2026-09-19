<?php

namespace Elcreator\aPhalcon;

use Closure;
use Phalcon\Http\Response;

/**
 * The CMS as a Phalcon service, registered in the DI as 'cms'.
 *
 * A Phalcon handler reaches the site through it: `$this->cms->view()` in a
 * Micro closure, `$this->di->get('cms')` anywhere else. The views it renders
 * are the site's own views/<alias>.latte files - the same files the manager's
 * "Template code: In a file" switch creates - so a page and a Phalcon route can
 * share one template.
 *
 * Built on closures rather than on the container so it can be constructed in
 * a test without a CMS: the provider hands it the view factory and evo().
 */
final class Cms
{
    /**
     * @param Closure(string, array<string, mixed>): string $render   renders a view name with data
     * @param Closure(string): bool                          $exists   whether a view name resolves
     * @param Closure(): object                              $evo      the core, resolved when first asked for
     */
    public function __construct(
        private Closure $render,
        private Closure $exists,
        private Closure $evo,
    ) {
    }

    /**
     * Render views/<alias>.latte (or any other extension the view factory
     * knows) to a string.
     *
     * @param array<string, mixed> $data
     */
    public function view(string $alias, array $data = []): string
    {
        return ($this->render)($alias, $data);
    }

    public function hasView(string $alias): bool
    {
        return ($this->exists)($alias);
    }

    /**
     * The same, as a Phalcon response a handler can return.
     *
     * @param array<string, mixed> $data
     */
    public function respond(string $alias, array $data = [], int $status = 200): Response
    {
        $response = new Response();
        $response->setStatusCode($status);
        $response->setContentType('text/html', 'UTF-8');
        $response->setContent($this->view($alias, $data));

        return $response;
    }

    /** The core: evo(). */
    public function evo(): object
    {
        return ($this->evo)();
    }

    /**
     * A document's URL, spelled the way the site spells them.
     *
     * @param array<string, mixed> $args query parameters
     */
    public function url(int $id, array $args = []): string
    {
        return (string) $this->evo()->makeUrl($id, '', $args === [] ? '' : http_build_query($args));
    }

    /** A system setting. */
    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->evo()->getConfig($name, $default);
    }

    /**
     * A snippet's return value - what a Phalcon route wants from a snippet is
     * data, not markup, and this is the call that gives it.
     *
     * @param array<string, mixed> $params
     */
    public function snippet(string $name, array $params = []): mixed
    {
        return $this->evo()->runSnippet($name, $params);
    }
}
