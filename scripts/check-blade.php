<?php

/**
 * Autnyx V2 — Blade & Filament footgun linter
 * -------------------------------------------------------------
 * A dependency-free static check (needs only PHP — no vendor/, no DB).
 * It encodes the exact mistakes that have caused production 500s so far,
 * documented in the Claude Project's incidents.md:
 *
 *   INC-001  `use` declarations inside @php blocks in a Blade that also
 *            renders a Blade component  -> fatal compile error / 500 on the page
 *   INC-001b bare class references (Str::, Filament::, Investigation::, ...)
 *            inside Blade @php blocks    -> "Class not found" at render / 500
 *   INC-002  a Filament Page subclass declaring getActions(): <non-array>
 *            -> declaration incompatible with Page::getActions(): array / fatal
 *   INC-003  \Filament\Support\Enums\MaxWidth:: used anywhere
 *            -> invalid enum path in this Filament install / deploy failure
 *
 * Run it before every push:   php scripts/check-blade.php
 * Exit code 0 = clean, 1 = problems found (fail the push / CI).
 *
 * This is the fast first line of defence. The authoritative compile gate
 * runs on Laravel Cloud during the build (view:cache + filament:cache-components);
 * this script just catches the same mistakes in seconds, locally, with no build.
 */

$root = dirname(__DIR__);
$problems = [];

/* ---------- helpers ---------------------------------------------------- */

function rglob(string $dir, string $ext): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), $ext)) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

function rel(string $path, string $root): string
{
    return ltrim(str_replace($root, '', $path), '/');
}

/* ---------- 1 + 1b: Blade @php block checks ---------------------------- */
// Bare class references we know break inside @php blocks. Each must be
// fully-qualified (leading backslash) when used in Blade.
$mustBeFqcn = [
    'Str::',
    'Filament::',
    'Investigation::',
    'InvestigationResource::',
    'AnomalyResource::',
    'Action::',
    'Auth::',
    'DB::',
];

foreach (rglob($root . '/resources/views', '.blade.php') as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);

    // Grab every @php ... @endphp block (and inline @php(...) is fine — skip those).
    if (preg_match_all('/@php\b(?!\s*\()(.*?)@endphp/s', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $block) {
            [$code, $offset] = $block;
            $lineNo = substr_count(substr($src, 0, $offset), "\n") + 1;

            // INC-001: `use` statement inside a @php block.
            if (preg_match('/^\s*use\s+[\\\\A-Za-z]/m', $code)) {
                $problems[] = "[INC-001] $r (@php block ~line $lineNo): "
                    . "`use` declaration inside a @php block. Remove it and use "
                    . "fully-qualified class names instead.";
            }

            // INC-001b: bare class references that must be FQCN.
            foreach ($mustBeFqcn as $needle) {
                // match the needle NOT preceded by a backslash or word char
                $pattern = '/(?<![\\\\\\w])' . preg_quote($needle, '/') . '/';
                if (preg_match($pattern, $code)) {
                    $problems[] = "[INC-001b] $r (@php block ~line $lineNo): "
                        . "bare `$needle` — must be fully-qualified "
                        . "(e.g. `\\Illuminate\\Support\\Str::`, `\\App\\Models\\Action::`).";
                }
            }
        }
    }
}

/* ---------- 3: MaxWidth enum path (Blade + PHP) ----------------------- */
$scanForMaxWidth = array_merge(
    rglob($root . '/resources/views', '.blade.php'),
    rglob($root . '/app', '.php')
);
foreach ($scanForMaxWidth as $file) {
    $src = file_get_contents($file);
    if (str_contains($src, 'Enums\\MaxWidth') || preg_match('/\bMaxWidth::/', $src)) {
        $r = rel($file, $root);
        $problems[] = "[INC-003] $r: references the `MaxWidth` enum. That enum "
            . "path is invalid in this Filament install. Use the global "
            . "->maxContentWidth('full') string in AdminPanelProvider instead.";
    }
}

/* ---------- 4: disallowed operators inside {$...} interpolation ------- */
// INC-005: PHP string/heredoc interpolation `{$...}` only allows a variable
// expression (property/array/method access). Operators like ?? ?: . + - and
// the ternary `?` are a hard parse error. This broke AnomalyInvestigationService.
$interpScan = array_merge(
    rglob($root . '/app', '.php'),
    rglob($root . '/resources/views', '.blade.php')
);
foreach ($interpScan as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);
    // {$ ... <operator> ... } on a single line. Conservative: needs a leading
    // {$ and one of the disallowed operators before the closing }.
    if (preg_match_all('/\{\$[^}\n]*?(\?\?|\s\?\s|\|\||&&|(?<![>=!<])\.\s)[^}\n]*\}/', $src, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $hit) {
            [$text, $offset] = $hit;
            $lineNo = substr_count(substr($src, 0, $offset), "\n") + 1;
            $problems[] = "[INC-005] $r (line $lineNo): operator inside `{\$...}` "
                . "interpolation: " . trim($text) . " — pre-compute the value into a "
                . "variable before the string (`??`, ternary, `.` etc. are parse errors here).";
        }
    }
}

/* ---------- 2: getActions() override in a Filament Page --------------- */
foreach (rglob($root . '/app/Filament/Pages', '.php') as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);
    // any function named getActions with a non-array return type is a red flag.
    if (preg_match('/function\s+getActions\s*\([^)]*\)\s*:\s*([^\{\s]+)/', $src, $mm)) {
        $ret = trim($mm[1]);
        if (strtolower($ret) !== 'array') {
            $problems[] = "[INC-002] $r: declares getActions(): $ret. This clashes "
                . "with Filament\\Pages\\Page::getActions(): array. Rename the method "
                . "(e.g. getPaginatedActions()).";
        }
    }
}

/* ---------- report ---------------------------------------------------- */

if (empty($problems)) {
    fwrite(STDOUT, "✓ check-blade: no known footguns found.\n");
    exit(0);
}

fwrite(STDERR, "✗ check-blade found " . count($problems) . " problem(s):\n\n");
foreach ($problems as $p) {
    fwrite(STDERR, "  - $p\n");
}
fwrite(STDERR, "\nFix these before pushing — each has caused a production 500 before.\n");
exit(1);
