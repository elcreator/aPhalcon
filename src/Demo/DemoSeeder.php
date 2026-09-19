<?php

namespace Elcreator\aPhalcon\Demo;

use EvolutionCMS\Models\Category;
use EvolutionCMS\Models\SiteContent;
use EvolutionCMS\Models\SiteTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Puts the demo into a site and takes it out again.
 *
 * One category, one file-backed template, one document, four view files (a
 * layout, a section, and the two roots that extend them) and two config
 * files. Everything is addressed by name and written only where
 * nothing of the site's own is in the way: a view or config file that is
 * there and differs from what the demo ships is kept, on install and on
 * remove alike.
 */
final class DemoSeeder
{
    public const CATEGORY = 'aPhalcon demo';
    public const TEMPLATE = 'aPhalcon demo';
    public const TEMPLATE_ALIAS = 'aphalcon-demo-page';
    public const DOCUMENT_ALIAS = 'demo';

    /** @var list<string> */
    private array $log = [];

    /** @return list<string> */
    public function install(): array
    {
        $this->log = [];

        $this->installFiles($this->viewsDirectory(), self::views(), 'view');
        $this->installFiles($this->customConfigDirectory(), self::configs(), 'config', self::defaultConfigs());

        $categoryId = $this->categoryId();
        $templateId = $this->installTemplate($categoryId);
        $documentId = $this->installDocument($templateId);

        $this->note(sprintf('template #%d, document #%d', $templateId, $documentId));
        $this->clearCache();

        return $this->log;
    }

    /** @return list<string> */
    public function remove(): array
    {
        $this->log = [];

        $documentIds = SiteContent::withTrashed()
            ->where('alias', self::DOCUMENT_ALIAS)
            ->pluck('id')
            ->all();

        if ($documentIds !== []) {
            DB::table('site_tmplvar_contentvalues')->whereIn('contentid', $documentIds)->delete();
            DB::table('site_content_closure')
                ->whereIn('descendant', $documentIds)
                ->orWhereIn('ancestor', $documentIds)
                ->delete();
            // A hard DELETE: the model's deleting event would only flag it.
            DB::table('site_content')->whereIn('id', $documentIds)->delete();
            $this->note('removed ' . count($documentIds) . ' documents');
        }

        $templates = SiteTemplate::query()->where('templatename', self::TEMPLATE)->delete();
        $this->note('removed ' . $templates . ' templates');

        $this->removeFiles($this->viewsDirectory(), self::views());
        $this->removeFiles($this->customConfigDirectory(), self::configs());
        $this->removeCategoryIfEmpty();
        $this->clearCache();

        return $this->log;
    }

    /** @return array<string, string> file name => contents */
    public static function views(): array
    {
        return self::shipped(dirname(__DIR__, 2) . '/demo/views');
    }

    /** @return array<string, string> file name => contents */
    public static function configs(): array
    {
        return self::shipped(dirname(__DIR__, 2) . '/demo/config');
    }

    /**
     * Config files that count as not configured: the package defaults, as
     * `vendor:publish` copies them into core/custom/config. A site that has
     * only that file has said nothing yet, and the demo may speak for it.
     *
     * @return array<string, string> file name => contents
     */
    public static function defaultConfigs(): array
    {
        return self::shipped(dirname(__DIR__, 2) . '/config');
    }

    /** @return array<string, string> */
    private static function shipped(string $directory): array
    {
        $files = [];
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                $files[basename($path)] = (string) file_get_contents($path);
            }
        }
        ksort($files);

        return $files;
    }

    private function installTemplate(int $categoryId): int
    {
        $model = SiteTemplate::firstOrNew(['templatename' => self::TEMPLATE]);
        $model->fill([
            'description' => 'Rendered from views/' . self::TEMPLATE_ALIAS . '.latte by aLatteX; calls Phalcon services',
            'editor_type' => 0,
            'category' => $categoryId,
            'icon' => '',
            'template_type' => 0,
            'templatealias' => self::TEMPLATE_ALIAS,
            // The file is the template: the code lives in views/, the row
            // only says which engine's file to look for.
            'templatesource' => 'file',
            'templatefileextension' => 'latte',
            'content' => '',
            'locked' => 0,
            'selectable' => 1,
        ])->save();

        $this->note('template ' . self::TEMPLATE);

        return (int) $model->getKey();
    }

    private function installDocument(int $templateId): int
    {
        $now = time();
        $model = SiteContent::withTrashed()->where('alias', self::DOCUMENT_ALIAS)->first();

        $attributes = [
            'type' => 'document',
            'contentType' => 'text/html',
            'pagetitle' => 'aPhalcon demo',
            'longtitle' => 'Phalcon services on a CMS page',
            'menutitle' => 'aPhalcon demo',
            'description' => '',
            'alias' => self::DOCUMENT_ALIAS,
            'link_attributes' => '',
            'published' => 1,
            'pub_date' => 0,
            'unpub_date' => 0,
            'parent' => 0,
            'isfolder' => 0,
            'introtext' => '',
            'content' => '<p>This document\'s template is a <code>.latte</code> file. The greeting above it'
                . ' came from a Phalcon service; the list below it from a Phalcon model. The CMS parser'
                . ' never ran over this page.</p>',
            'richtext' => 1,
            'template' => $templateId,
            'searchable' => 1,
            'cacheable' => 1,
            'createdby' => 1,
            'editedby' => 1,
            'deleted' => 0,
            'deletedby' => 0,
            'publishedon' => $now,
            'publishedby' => 1,
            'hide_from_tree' => 0,
            'privateweb' => 0,
            'privatemgr' => 0,
            'content_dispo' => 0,
            'hidemenu' => 0,
            'alias_visible' => 1,
        ];

        $model = $model === null ? SiteContent::create($attributes) : $model->fill($attributes);
        $model->save();

        $this->note('document ' . self::DOCUMENT_ALIAS);

        return (int) $model->getKey();
    }

    /**
     * @param array<string, string> $files       file name => contents to write
     * @param array<string, string> $replaceable file name => contents that may be overwritten as if absent
     */
    private function installFiles(?string $directory, array $files, string $label, array $replaceable = []): void
    {
        if ($directory === null) {
            $this->note('no ' . $label . ' directory; skipped');

            return;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->note('could not create ' . $directory);

            return;
        }

        foreach ($files as $name => $body) {
            $target = $directory . DIRECTORY_SEPARATOR . $name;

            if (is_file($target)) {
                $current = file_get_contents($target);
                if ($current !== $body && $current !== ($replaceable[$name] ?? null)) {
                    $this->note('kept     ' . $label . ' ' . $name . ' (already there, and different)');

                    continue;
                }
            }

            if (file_put_contents($target, $body) === false) {
                $this->note('could not write ' . $target);

                continue;
            }

            $this->note($label . ' ' . $target);
        }
    }

    /** @param array<string, string> $files */
    private function removeFiles(?string $directory, array $files): void
    {
        if ($directory === null) {
            return;
        }

        foreach ($files as $name => $body) {
            $target = $directory . DIRECTORY_SEPARATOR . $name;

            if (!is_file($target)) {
                continue;
            }

            if (file_get_contents($target) !== $body) {
                $this->note('kept     ' . $name . ' (edited since install)');

                continue;
            }

            if (@unlink($target)) {
                $this->note('removed  ' . $target);
            }
        }
    }

    /** The first configured view path: where the manager scaffolds a template file. */
    private function viewsDirectory(): ?string
    {
        $paths = function_exists('config') ? (array) config('view.paths', []) : [];

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                return rtrim($path, "/\\");
            }
        }

        return defined('EVO_BASE_PATH') ? EVO_BASE_PATH . 'views' : null;
    }

    private function customConfigDirectory(): ?string
    {
        return defined('EVO_CORE_PATH') ? EVO_CORE_PATH . 'custom' . DIRECTORY_SEPARATOR . 'config' : null;
    }

    private function categoryId(): int
    {
        $category = Category::query()->firstOrCreate(['category' => self::CATEGORY], ['rank' => 0]);

        return (int) $category->getKey();
    }

    private function removeCategoryIfEmpty(): void
    {
        $category = Category::query()->where('category', self::CATEGORY)->first();
        if ($category === null) {
            return;
        }

        if (SiteTemplate::query()->where('category', (int) $category->getKey())->exists()) {
            $this->note('kept the "' . self::CATEGORY . '" category: it still has elements in it');

            return;
        }

        $category->delete();
        $this->note('removed the "' . self::CATEGORY . '" category');
    }

    private function clearCache(): void
    {
        if (!function_exists('evo')) {
            return;
        }

        try {
            evo()->clearCache('full');
            $this->note('cleared the site cache');
        } catch (\Throwable $e) {
            $this->note('could not clear the site cache: ' . $e->getMessage());
        }
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }
}
