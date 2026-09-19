<?php

namespace Elcreator\aPhalcon\Demo;

use Phalcon\Mvc\Model;

/**
 * The CMS's page tree as a Phalcon model, over the same table Eloquent's
 * SiteContent uses. The table prefix is the site's, read from the DI, so the
 * model works on whatever prefix the site was installed with.
 */
final class Document extends Model
{
    public $id;
    public $pagetitle = '';
    public $alias = '';
    public $parent = 0;
    public $published = 0;
    public $deleted = 0;
    public $template = 0;
    public $publishedon = 0;

    public function initialize(): void
    {
        $this->setSource((string) $this->getDI()->get('tablePrefix') . 'site_content');
    }

    /** @return iterable<self> the published, undeleted pages, newest first */
    public static function latest(int $limit = 10): iterable
    {
        return self::find([
            'conditions' => 'published = 1 AND deleted = 0',
            'order' => 'publishedon DESC, id DESC',
            'limit' => $limit,
        ]);
    }
}
