<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the platform-first module boundary declared in config/architecture.php:
 *
 *     Apps\*      ->  Platform\*      allowed
 *     Apps\A      ->  Apps\B          forbidden
 *     Platform\*  ->  Apps\*          forbidden
 *
 * Reads `use App\...;` imports across the scanned trees, resolves each side to a
 * module, and fails on any dependency that breaks the rule and is not in the
 * baseline. A failure prints each offending line ready to paste into the baseline
 * (only do that for genuinely pre-existing debt — new code must obey the rule).
 *
 * Pure file/static analysis: it extends PHPUnit's TestCase directly (no app boot,
 * no database) so it is fast and has no runtime dependencies.
 */
class ArchitectureBoundaryTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @var array<string,string> spec => module, ordered most-specific first */
    private array $specs = [];

    private array $manifest = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifest = require self::ROOT . '/config/architecture.php';

        $flat = [];
        foreach ($this->manifest['modules'] as $module => $specs) {
            foreach ($specs as $spec) {
                $flat[$spec] = $module;
            }
        }
        // Longest spec wins so an exact file overrides a directory prefix.
        uksort($flat, fn ($a, $b) => strlen($b) <=> strlen($a));
        $this->specs = $flat;
    }

    public function test_no_module_boundary_violations(): void
    {
        $baseline = array_flip($this->manifest['baseline'] ?? []);
        $violations = [];

        foreach ($this->sourceFiles() as $rel => $absolute) {
            $srcModule = $this->moduleFor($rel);
            if ($srcModule === null) {
                continue;
            }

            foreach ($this->importsIn($absolute) as $class) {
                $tgtRel = $this->classToRel($class);
                $tgtModule = $this->moduleFor($tgtRel);
                if ($tgtModule === null || $tgtModule === $srcModule) {
                    continue;
                }

                if (! $this->isForbidden($srcModule, $tgtModule)) {
                    continue;
                }

                $key = $rel . ' => ' . $class;
                if (isset($baseline[$key])) {
                    continue;
                }
                $violations[$key] = "{$srcModule}  ({$rel})  -->  {$tgtModule}  ({$class})";
            }
        }

        $this->assertSame(
            [],
            array_values($violations),
            "Module boundary violated. Each line below breaks Apps->Platform-only.\n"
            . "Fix the dependency, or — only if it is genuinely pre-existing debt —\n"
            . "add the '=> ' key to the baseline in config/architecture.php:\n\n"
            . implode("\n", $violations) . "\n"
        );
    }

    /** A dependency is forbidden if it points into an app from the platform, or across apps. */
    private function isForbidden(string $src, string $tgt): bool
    {
        $srcApp = $this->appRoot($src);
        $tgtApp = $this->appRoot($tgt);

        if ($srcApp === null && $tgtApp !== null) {
            return true; // Platform -> Apps
        }
        if ($srcApp !== null && $tgtApp !== null && $srcApp !== $tgtApp) {
            return true; // Apps\A -> Apps\B
        }

        return false;
    }

    /** Returns the app name for an Apps\<Name>\... module, or null for Platform\*. */
    private function appRoot(string $module): ?string
    {
        if (! str_starts_with($module, 'Apps\\')) {
            return null;
        }
        $parts = explode('\\', $module);

        return $parts[1] ?? '*';
    }

    private function moduleFor(string $rel): ?string
    {
        foreach ($this->specs as $spec => $module) {
            if (str_ends_with($spec, '/')) {
                if (str_starts_with($rel, $spec)) {
                    return $module;
                }
            } elseif ($rel === $spec) {
                return $module;
            }
        }

        return null;
    }

    /** App\Foo\Bar -> app/Foo/Bar.php */
    private function classToRel(string $class): string
    {
        return 'app/' . str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php';
    }

    /** @return list<string> fully-qualified App\* classes imported by the file */
    private function importsIn(string $absolute): array
    {
        $src = file_get_contents($absolute);
        preg_match_all(
            '/^\s*use\s+(App\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+[A-Za-z0-9_]+)?\s*;/m',
            $src,
            $m
        );

        return array_values(array_unique($m[1]));
    }

    /** @return array<string,string> rel => absolute for every .php under scan_paths */
    private function sourceFiles(): array
    {
        $out = [];
        foreach ($this->manifest['scan_paths'] as $base) {
            $dir = self::ROOT . '/' . $base;
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $abs = $file->getPathname();
                $rel = $base . '/' . ltrim(str_replace($dir, '', $abs), '/');
                $out[str_replace('\\', '/', $rel)] = $abs;
            }
        }
        ksort($out);

        return $out;
    }
}
