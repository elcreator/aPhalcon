<?php

namespace Elcreator\aPhalcon\Demo;

use Phalcon\Di\DiInterface;
use Phalcon\Mvc\Micro;

/**
 * What a site lists under aphalcon.routes.handlers: Phalcon routes, answering
 * under the CMS's mount point and rendering the CMS's own view files.
 *
 * With the default prefix these are /app/, /app/documents.json,
 * /app/documents/{id} and /app/page/{id}.
 */
final class DemoRoutes
{
    public function __invoke(Micro $app, DiInterface $di): void
    {
        $app->get('/', function () use ($app) {
            return $app->cms->respond('aphalcon-demo', [
                'documents' => $app->documents->latest(),
                'title' => 'Phalcon route, CMS view',
            ]);
        });

        $app->get('/documents.json', function () use ($app) {
            $rows = [];
            foreach ($app->documents->latest() as $document) {
                $rows[] = [
                    'id' => (int) $document->id,
                    'pagetitle' => $document->pagetitle,
                    'alias' => $document->alias,
                ];
            }

            return $rows;
        });

        // A CMS document, rendered by the front controller from a route:
        // the 'site' service resolves its template to views/<alias>.latte.
        $app->get('/page/{id:[0-9]+}', function (string $id) use ($app) {
            return $app->site->document((int) $id);
        });

        $app->get('/documents/{id:[0-9]+}', function (string $id) use ($app) {
            $document = $app->documents->find((int) $id);
            if ($document === null) {
                // Status set on the shared response and a string returned:
                // the other way a Phalcon handler answers, and the one that
                // does not need a Response object.
                $app->response->setStatusCode(404);

                return $app->cms->view('aphalcon-demo', ['documents' => [], 'title' => 'No document ' . $id]);
            }

            return $app->cms->respond('aphalcon-demo', [
                'documents' => [$document],
                'title' => $document->pagetitle,
            ]);
        });
    }
}
