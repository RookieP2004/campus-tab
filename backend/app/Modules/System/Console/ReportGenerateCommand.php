<?php

namespace App\Modules\System\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * php artisan report:generate [--check]
 *
 * Fills the generated parts of the Application Report (docs/report/) from
 * the code, so they cannot drift:
 *
 *   README.md           <!-- generated:modules -->      the back-end modules
 *   api.md              <!-- generated:routes -->       every API route
 *   data-dictionary.md  <!-- generated:collections -->  every model and its collection
 *
 * Only the text between "<!-- generated:NAME:start -->" and
 * "<!-- generated:NAME:end -->" is replaced; everything written by hand
 * stays. With --check nothing is written and the command fails if any
 * generated section is out of date (CI runs it this way).
 */
class ReportGenerateCommand extends Command
{
    /** Modules whose report page has another name (kept in step with .github/scripts/check-report.mjs). */
    private const PAGE_ALIASES = ['Identity' => 'identity-and-access'];

    protected $signature = 'report:generate
        {--check : Do not write; fail if a generated section is out of date}
        {--path= : Report folder (default: REPORT_PATH or ../docs/report)}';

    protected $description = 'Regenerate the generated sections of the Application Report in docs/report/';

    public function handle(Router $router): int
    {
        $path = rtrim((string) ($this->option('path') ?: config('report.path')), '/');

        if (! is_dir($path)) {
            $this->error("Report folder not found: {$path}");

            return self::FAILURE;
        }

        $sections = [
            'README.md' => ['modules' => $this->modulesTable($router)],
            'api.md' => ['routes' => $this->routesTable($router)],
            'data-dictionary.md' => ['collections' => $this->collectionsTable()],
        ];

        $stale = [];

        foreach ($sections as $file => $blocks) {
            $target = "{$path}/{$file}";
            $before = is_file($target) ? (string) file_get_contents($target) : '# '.Str::headline(pathinfo($file, PATHINFO_FILENAME))."\n";
            $after = $before;

            foreach ($blocks as $name => $content) {
                $after = $this->replaceBlock($after, $name, $content);
            }

            if ($after === $before) {
                $this->line("  up to date  {$file}");

                continue;
            }

            $stale[] = $file;

            if ($this->option('check')) {
                $this->warn("  out of date {$file}");

                continue;
            }

            file_put_contents($target, $after);
            $this->info("  updated     {$file}");
        }

        if ($this->option('check') && $stale !== []) {
            $this->error('Generated report sections are out of date. Run: php artisan report:generate');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function replaceBlock(string $document, string $name, string $content): string
    {
        $start = "<!-- generated:{$name}:start -->";
        $end = "<!-- generated:{$name}:end -->";
        $block = "{$start}\n<!-- Written by `php artisan report:generate`. Do not edit by hand. -->\n\n".trim($content)."\n\n{$end}";

        $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';

        if (preg_match($pattern, $document) === 1) {
            return (string) preg_replace_callback($pattern, fn () => $block, $document, 1);
        }

        return rtrim($document)."\n\n{$block}\n";
    }

    private function modulesTable(Router $router): string
    {
        $rows = [];

        foreach ($this->modules() as $module) {
            $prefix = "App\\Modules\\{$module}\\";
            $routes = collect($router->getRoutes()->getRoutes())
                ->filter(fn (Route $r) => str_starts_with($r->getActionName(), $prefix))
                ->count();
            $page = 'modules/'.(self::PAGE_ALIASES[$module] ?? Str::kebab($module)).'.md';

            $rows[] = "| {$module} | [`backend/app/Modules/{$module}/`](../../backend/app/Modules/{$module}/) | {$routes} | [{$page}]({$page}) |";
        }

        if ($rows === []) {
            return '_No back-end modules yet._';
        }

        return "| Module | Code | API routes | Report page |\n| --- | --- | --- | --- |\n".implode("\n", $rows);
    }

    private function routesTable(Router $router): string
    {
        $rows = collect($router->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/'))
            ->sortBy(fn (Route $r) => $r->uri().' '.implode(',', $r->methods()))
            ->map(function (Route $r): string {
                $methods = implode(', ', array_diff($r->methods(), ['HEAD']));
                $action = $r->getActionName() === 'Closure' ? 'closure' : '`'.class_basename(Str::before($r->getActionName(), '@')).'`';
                $middleware = implode(', ', array_map(fn ($m) => is_string($m) ? $m : 'closure', $r->gatherMiddleware()));

                return "| {$methods} | `/{$r->uri()}` | ".($r->getName() ?? '—')." | {$action} | {$middleware} |";
            })
            ->values();

        if ($rows->isEmpty()) {
            return '_No API routes yet._';
        }

        return "| Method | Path | Name | Handler | Middleware |\n| --- | --- | --- | --- | --- |\n".$rows->implode("\n");
    }

    private function collectionsTable(): string
    {
        $rows = [];

        foreach ($this->modelClasses() as $class) {
            /** @var Model $model */
            $model = new $class;
            $reflection = new ReflectionClass($class);
            $file = Str::after((string) $reflection->getFileName(), base_path().DIRECTORY_SEPARATOR);
            $traits = array_map('class_basename', array_values(class_uses_recursive($class)));
            $fields = implode(', ', array_map(fn ($f) => "`{$f}`", $model->getFillable())) ?: '—';
            $flags = [];

            if (in_array('BelongsToTenant', $traits, true)) {
                $flags[] = 'tenant-scoped';
            }

            if (in_array('SoftDeletes', $traits, true)) {
                $flags[] = 'soft deletes';
            }

            $rows[] = sprintf(
                '| `%s` | [`%s`](../../backend/%s) | %s | %s |',
                $model->getTable(),
                class_basename($class),
                str_replace('\\', '/', $file),
                $fields,
                $flags === [] ? '—' : implode(', ', $flags),
            );
        }

        sort($rows);

        if ($rows === []) {
            return '_No collections yet._';
        }

        return "| Collection | Model | Fillable fields | Flags |\n| --- | --- | --- | --- |\n".implode("\n", $rows);
    }

    /**
     * @return list<string>
     */
    private function modules(): array
    {
        $dirs = glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [];
        $names = array_map('basename', $dirs);
        sort($names);

        return $names;
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $dirs = array_filter([app_path('Models'), ...(glob(app_path('Modules/*/Models'), GLOB_ONLYDIR) ?: [])], 'is_dir');

        if ($dirs === []) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->name('*.php')->in($dirs) as $file) {
            $relative = Str::after($file->getRealPath(), app_path().DIRECTORY_SEPARATOR);
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
