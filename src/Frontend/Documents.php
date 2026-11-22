<?php

namespace Elcreator\aPhalcon\Frontend;

use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;

/**
 * The CMS's document tree, read through Phalcon's db adapter.
 *
 * What the front controller needs of the site and nothing more: a document by
 * id or by the path a URL spells, its template row, and its template
 * variables. Every query goes through the same adapter a site's models use,
 * so replacing the CMS's parser costs no second connection.
 *
 * Path resolution follows the CMS's own rules, read from its settings:
 * friendly_url_prefix / _suffix are stripped, use_alias_path walks the tree
 * segment by segment, and without it the last segment's alias is looked up
 * wherever it is in the tree. '' is site_start, and ?id=N is honoured whether
 * or not friendly URLs are on, since that is what an unfriendly site links.
 */
final class Documents
{
    /**
     * @param \Closure(string, mixed): mixed            $setting a CMS setting by name, with a default
     * @param (\Closure(string, string, int): mixed)|null $binding evaluates a TV value that starts with "@"
     *                                                          (@INHERIT, @CHUNK, @SELECT, ...): value, TV name, document id
     */
    public function __construct(
        private AdapterInterface $db,
        private string $prefix,
        private \Closure $setting,
        private ?\Closure $binding = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->table('site_content') . ' WHERE id = ? AND deleted = 0',
            Enum::FETCH_ASSOC,
            [$id],
        );

        return $row ?: null;
    }

    /**
     * The document a request path names, or null.
     *
     * @param array<string, mixed> $query the query string, for ?id=
     * @return array<string, mixed>|null
     */
    public function resolve(string $path, array $query = []): ?array
    {
        if (isset($query['id']) && is_numeric($query['id'])) {
            return $this->find((int) $query['id']);
        }

        $path = trim($path, '/');
        if ($path === '' || $path === 'index.php') {
            return $this->find((int) $this->setting('site_start', 1));
        }

        $path = $this->stripAffixes($path);
        if ($path === '') {
            return $this->find((int) $this->setting('site_start', 1));
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return null;
        }

        if ((bool) $this->setting('use_alias_path', 1)) {
            return $this->byAliasPath($segments);
        }

        return $this->byAlias(end($segments), null);
    }

    /**
     * The template a document uses: templatealias, templatesource and
     * templatefileextension, as the manager saved them.
     *
     * @return array<string, mixed>|null
     */
    public function template(int $templateId): ?array
    {
        if ($templateId <= 0) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT id, templatename, templatealias, templatesource, templatefileextension FROM '
            . $this->table('site_templates') . ' WHERE id = ?',
            Enum::FETCH_ASSOC,
            [$templateId],
        );

        return $row ?: null;
    }

    /**
     * The template variables attached to a template, with the document's
     * value or, failing one, the TV's default - flat, name => value, the way
     * a template reads them as {$name}. A value that is an @ binding is
     * evaluated, as the CMS evaluates it when it outputs the TV.
     *
     * @return array<string, mixed>
     */
    public function tvs(int $documentId, int $templateId): array
    {
        return $this->values($this->tvRows($documentId, $templateId), $documentId);
    }

    /**
     * The same TVs the way the core keeps them in documentObject:
     * name => [name, value, display, display_params, type], value unevaluated.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public function tvRows(int $documentId, int $templateId): array
    {
        if ($templateId <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT tv.name, tv.type, tv.display, tv.display_params, tv.default_text, v.value FROM ' . $this->table('site_tmplvars') . ' tv'
            . ' INNER JOIN ' . $this->table('site_tmplvar_templates') . ' tt ON tt.tmplvarid = tv.id'
            . ' LEFT JOIN ' . $this->table('site_tmplvar_contentvalues') . ' v ON v.tmplvarid = tv.id AND v.contentid = ?'
            . ' WHERE tt.templateid = ?'
            . ' ORDER BY tv.rank, tv.id',
            Enum::FETCH_ASSOC,
            [$documentId, $templateId],
        );

        $tvs = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            // An empty value falls back to the default, as in the core's documentObject.
            $value = (string) ($row['value'] ?? '');
            if ($value === '') {
                $value = (string) ($row['default_text'] ?? '');
            }
            $tvs[$name] = [$name, $value, (string) ($row['display'] ?? ''), (string) ($row['display_params'] ?? ''), (string) ($row['type'] ?? '')];
        }

        return $tvs;
    }

    /**
     * Flat values of tvRows(), @ bindings evaluated for the given document.
     *
     * @param array<string, array{0: string, 1: string}> $rows
     * @return array<string, mixed>
     */
    public function values(array $rows, int $documentId): array
    {
        $values = [];
        foreach ($rows as $name => $row) {
            $value = $row[1];
            if ($this->binding !== null && str_starts_with(ltrim($value), '@')) {
                $value = ($this->binding)($value, (string) $name, $documentId);
            }
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * The live children of a document in menu order: what a menu, a docs
     * sidebar or a list of cards is built from. Unpublished, deleted and
     * out-of-window documents are left out, and with $menuOnly so are the
     * ones marked "hide from menu".
     *
     * @return list<array<string, mixed>>
     */
    public function children(int $parent, bool $menuOnly = false): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->table('site_content')
            . ' WHERE parent = ? AND published = 1 AND deleted = 0'
            . ($menuOnly ? ' AND hidemenu = 0' : '')
            . ' ORDER BY menuindex, id',
            Enum::FETCH_ASSOC,
            [$parent],
        );

        $now = time();

        return array_values(array_filter($rows, static function (array $row) use ($now): bool {
            $from = (int) ($row['pub_date'] ?? 0);
            $until = (int) ($row['unpub_date'] ?? 0);

            return ($from === 0 || $from <= $now) && ($until === 0 || $until > $now);
        }));
    }

    /**
     * The ids above a document, its parent first, up to the top level: for
     * breadcrumbs and for marking the active menu section.
     *
     * @return list<int>
     */
    public function ancestors(int $id): array
    {
        $ids = [];
        $parent = $this->parentOf($id);

        // A cycle in damaged data must not hang the page.
        while ($parent > 0 && !in_array($parent, $ids, true) && count($ids) < 50) {
            $ids[] = $parent;
            $parent = $this->parentOf($parent);
        }

        return $ids;
    }

    private function parentOf(int $id): int
    {
        $row = $this->db->fetchOne(
            'SELECT parent FROM ' . $this->table('site_content') . ' WHERE id = ?',
            Enum::FETCH_ASSOC,
            [$id],
        );

        return $row ? (int) $row['parent'] : 0;
    }

    /**
     * The URL path of a document, the way the site spells it: the alias, or
     * the alias path with use_alias_path, with the prefix and suffix on.
     */
    public function path(array $document): string
    {
        if ((int) $document['id'] === (int) $this->setting('site_start', 1)) {
            return '';
        }

        $segments = [(string) $document['alias']];

        if ((bool) $this->setting('use_alias_path', 1)) {
            $parent = (int) ($document['parent'] ?? 0);
            $guard = 0;
            while ($parent > 0 && $guard++ < 50) {
                $row = $this->db->fetchOne(
                    'SELECT id, alias, parent FROM ' . $this->table('site_content') . ' WHERE id = ?',
                    Enum::FETCH_ASSOC,
                    [$parent],
                );
                if (!$row) {
                    break;
                }
                array_unshift($segments, (string) $row['alias']);
                $parent = (int) $row['parent'];
            }
        }

        return (string) $this->setting('friendly_url_prefix', '')
            . implode('/', $segments)
            . (string) $this->setting('friendly_url_suffix', '');
    }

    /** @param list<string> $segments */
    private function byAliasPath(array $segments): ?array
    {
        $parent = 0;
        $document = null;

        foreach ($segments as $segment) {
            $document = $this->byAlias($segment, $parent);
            if ($document === null) {
                return null;
            }
            $parent = (int) $document['id'];
        }

        return $document;
    }

    /** @return array<string, mixed>|null */
    private function byAlias(string $alias, ?int $parent): ?array
    {
        $sql = 'SELECT * FROM ' . $this->table('site_content') . ' WHERE alias = ? AND deleted = 0';
        $bind = [$alias];

        if ($parent !== null) {
            $sql .= ' AND parent = ?';
            $bind[] = $parent;
        }

        $sql .= ' ORDER BY id LIMIT 1';

        $row = $this->db->fetchOne($sql, Enum::FETCH_ASSOC, $bind);

        return $row ?: null;
    }

    private function stripAffixes(string $path): string
    {
        $prefix = (string) $this->setting('friendly_url_prefix', '');
        $suffix = trim((string) $this->setting('friendly_url_suffix', ''), '/');

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }
        if ($suffix !== '' && str_ends_with($path, $suffix)) {
            $path = substr($path, 0, -strlen($suffix));
        }

        return trim($path, '/');
    }

    private function setting(string $name, mixed $default = null): mixed
    {
        $value = ($this->setting)($name, $default);

        return $value === null || $value === '' ? $default : $value;
    }

    private function table(string $name): string
    {
        return $this->prefix . $name;
    }
}
