<?php

namespace Elcreator\aPhalcon\Demo;

/** The 'documents' service: the queries the demo needs, over the Document model. */
final class Documents
{
    /** @return iterable<Document> */
    public function latest(int $limit = 10): iterable
    {
        return Document::latest($limit);
    }

    /** A page by id, unless it is in the recycle bin. */
    public function find(int $id): ?Document
    {
        return Document::findFirst([
            'conditions' => 'id = :id: AND deleted = 0',
            'bind' => ['id' => $id],
        ]);
    }
}
