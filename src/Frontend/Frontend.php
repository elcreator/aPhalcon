<?php

namespace Elcreator\aPhalcon\Frontend;

use Elcreator\aPhalcon\Cms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The front controller that stands in for the CMS's parser.
 *
 * A request path is resolved to a document, the document's template to a
 * view file, and the view is rendered by aLatteX with the document's fields
 * as its variables - no parser pass, no chunks, no snippets. What a template
 * needs beyond the content table it asks Phalcon for, through {phalcon()}.
 *
 * Also the DI's 'site' service, so a Phalcon route can render a document the
 * same way: $app->site->document($id) or ->resolve($path).
 *
 * What the CMS does that this does not: web-user access (privateweb),
 * document caching, and the page-level plugin events. A site that needs
 * those keeps the parser for the documents concerned - see 'fallback'.
 */
final class Frontend
{
    public const HEADER = 'X-Rendered-By';

    /**
     * @param bool $fallback hand a document the parser must render back to the CMS
     *                       (a template with no view file) instead of failing it
     */
    public function __construct(
        private Documents $documents,
        private Cms $cms,
        private bool $fallback = true,
    ) {
    }

    public function documents(): Documents
    {
        return $this->documents;
    }

    /**
     * Answer a request: the document at the path, the site's error page for
     * a miss, a redirect for a weblink.
     *
     * @param array<string, mixed> $query
     */
    public function handle(string $path, array $query = []): SymfonyResponse
    {
        $document = $this->documents->resolve($path, $query);

        if ($document === null || !$this->isLive($document)) {
            return $this->notFound();
        }

        if (($document['type'] ?? 'document') === 'reference') {
            return $this->redirect((string) $document['content']);
        }

        return $this->render($document);
    }

    /** A document by id, rendered - for a route that wants a page. */
    public function document(int $id): SymfonyResponse
    {
        $document = $this->documents->find($id);

        return $document === null || !$this->isLive($document) ? $this->notFound() : $this->render($document);
    }

    /**
     * Render a document through its template's view file.
     *
     * @param array<string, mixed> $document a site_content row
     * @param array<string, mixed> $extra    more variables for the view
     */
    public function render(array $document, array $extra = [], int $status = 200): SymfonyResponse
    {
        $alias = $this->viewFor($document);

        if ($alias === null) {
            if ($this->fallback) {
                // Core::processRoutes() answers this by running the parser.
                throw new NotFoundHttpException('aPhalcon: no view for the document, left to the CMS');
            }

            return new LaravelResponse(
                'aPhalcon: template of document #' . (int) $document['id'] . ' has no view file',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8', self::HEADER => 'aPhalcon'],
            );
        }

        $fields = $document + $this->documents->tvs((int) $document['id'], (int) ($document['template'] ?? 0));
        $data = $fields + $extra + ['documentObject' => $fields, 'frontend' => true];

        $response = new LaravelResponse($this->cms->view($alias, $data), $status);
        $response->headers->set('Content-Type', ((string) (($document['contentType'] ?? '') ?: 'text/html')) . '; charset=UTF-8');
        $response->headers->set(self::HEADER, 'aPhalcon');

        return $response;
    }

    /**
     * The view a document's template resolves to, or null: a template row
     * with an alias the view factory knows a file for.
     */
    public function viewFor(array $document): ?string
    {
        $template = $this->documents->template((int) ($document['template'] ?? 0));
        $alias = (string) ($template['templatealias'] ?? '');

        if ($alias === '' || !$this->cms->hasView($alias)) {
            return null;
        }

        return $alias;
    }

    private function notFound(): SymfonyResponse
    {
        $errorPage = $this->documents->find((int) $this->cms->setting('error_page', 0));

        if ($errorPage !== null && $this->viewFor($errorPage) !== null) {
            return $this->render($errorPage, [], 404);
        }

        if ($this->fallback) {
            throw new NotFoundHttpException('aPhalcon: not a document, left to the CMS');
        }

        return new LaravelResponse('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8', self::HEADER => 'aPhalcon']);
    }

    /** A weblink's target: a URL as given, or a document id as its URL. */
    private function redirect(string $target): SymfonyResponse
    {
        $target = trim($target);

        if (is_numeric($target)) {
            $target = $this->cms->url((int) $target);
        }

        return new RedirectResponse($target !== '' ? $target : '/', 302, [self::HEADER => 'aPhalcon']);
    }

    /** Published, and inside its publication window. */
    private function isLive(array $document): bool
    {
        if ((int) ($document['published'] ?? 0) !== 1 || (int) ($document['deleted'] ?? 0) !== 0) {
            return false;
        }

        $now = time();
        $from = (int) ($document['pub_date'] ?? 0);
        $until = (int) ($document['unpub_date'] ?? 0);

        return ($from === 0 || $from <= $now) && ($until === 0 || $until > $now);
    }
}
