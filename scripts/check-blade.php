<?php

/**
 * Autnyx V2 — Blade / PHP / Filament v5 footgun linter
 * -------------------------------------------------------------
 * A dependency-free static check (needs only PHP — no vendor/, no DB).
 * It encodes the mistakes that have caused production 500s / build failures,
 * documented in the Claude Project's incidents.md, PLUS the Filament v3 → v5
 * patterns left over from the mid-project upgrade.
 *
 *   INC-001  `use` declarations inside @php blocks in a Blade that also
 *            renders a Blade component  -> fatal compile error / 500
 *   INC-001b bare class references (Str::, Filament::, ...) inside @php
 *            -> "Class not found" at render / 500
 *   INC-002  a Filament Page declaring getActions(): <non-array>
 *            -> declaration incompatible with Page::getActions(): array
 *   INC-003  \Filament\Support\Enums\MaxWidth:: used anywhere
 *            -> invalid enum path in this Filament install
 *   INC-005  operator (?? ?: . etc.) inside {$...} string interpolation
 *            -> hard parse error
 *   FIL-*    Filament v3 namespaces/classes that were removed or moved in v5
 *            -> "Class not found" / fatal at build (filament:cache-components)
 *
 * Run it before every push:   php scripts/check-blade.php
 * Exit 0 = clean, 1 = problems found (fail the push / CI).
 *
 * The authoritative gate is the Laravel Cloud build (view:cache +
 * filament:cache-components); this script catches the same mistakes in
 * seconds, locally, before a commit — with a clear "use X in v5" message.
 */

$root = dirname(__DIR__);
$problems = [];

/* ---------- helpers ---------------------------------------------------- */

function rglob(string $dir, string $ext): array
{
    if (!is_dir($dir)) {
        return [];
    }
    // NB: a plain scandir walk, NOT RecursiveDirectoryIterator. The latter yields
    // ZERO entries on some overlay/fuse filesystems (e.g. the Cowork sandbox),
    // which silently turned every view/app rule below into a no-op and reported a
    // false "clean". scandir works on those filesystems and on CI's ext4 alike.
    $out = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $out = array_merge($out, rglob($path, $ext));
        } elseif (str_ends_with($entry, $ext)) {
            $out[] = $path;
        }
    }
    sort($out);
    return $out;
}

function rel(string $path, string $root): string
{
    return ltrim(str_replace($root, '', $path), '/');
}

function lineOf(string $src, int $offset): int
{
    return substr_count(substr($src, 0, $offset), "\n") + 1;
}

/* ---------- 1 + 1b: Blade @php block checks ---------------------------- */
$mustBeFqcn = [
    'Str::', 'Filament::', 'Investigation::', 'InvestigationResource::',
    'AnomalyResource::', 'Action::', 'Auth::', 'DB::',
];

foreach (rglob($root . '/resources/views', '.blade.php') as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);

    // BLD-001 (WP5.1): inline @php(...) and @php … @endphp blocks in one file.
    // Blade's block regex can pair an inline @php( with a later @endphp and
    // swallow the markup between them. Use one form per file (blocks).
    if (preg_match('/(?<!@)@php\s*\(/', $src) && preg_match('/(?<!@)@php\b(?!\s*\()/', $src)) {
        $problems[] = "[BLD-001] $r: mixes inline @php(...) with @php … @endphp blocks. Convert the inline ones to `@php …; @endphp`.";
    }

    if (preg_match_all('/@php\b(?!\s*\()(.*?)@endphp/s', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $block) {
            [$code, $offset] = $block;
            $lineNo = lineOf($src, $offset);

            if (preg_match('/^\s*use\s+[\\\\A-Za-z]/m', $code)) {
                $problems[] = "[INC-001] $r (@php block ~line $lineNo): "
                    . "`use` declaration inside a @php block. Remove it and use FQCNs.";
            }
            foreach ($mustBeFqcn as $needle) {
                $pattern = '/(?<![\\\\\\w])' . preg_quote($needle, '/') . '/';
                if (preg_match($pattern, $code)) {
                    $problems[] = "[INC-001b] $r (@php block ~line $lineNo): "
                        . "bare `$needle` — must be fully-qualified.";
                }
            }
        }
    }
}

/* ---------- 3: MaxWidth enum path (Blade + PHP) ----------------------- */
$scanMaxWidth = array_merge(
    rglob($root . '/resources/views', '.blade.php'),
    rglob($root . '/app', '.php')
);
foreach ($scanMaxWidth as $file) {
    $src = file_get_contents($file);
    if (str_contains($src, 'Enums\\MaxWidth') || preg_match('/\bMaxWidth::/', $src)) {
        $r = rel($file, $root);
        $problems[] = "[INC-003] $r: references the `MaxWidth` enum (invalid path here). "
            . "Use the global ->maxContentWidth('full') string in AdminPanelProvider.";
    }
}

/* ---------- 5: disallowed operators inside {$...} interpolation ------- */
$interpScan = array_merge(
    rglob($root . '/app', '.php'),
    rglob($root . '/resources/views', '.blade.php')
);
foreach ($interpScan as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);
    if (preg_match_all('/\{\$[^}\n]*?(\?\?|\s\?\s|\|\||&&|(?<![>=!<])\.\s)[^}\n]*\}/', $src, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $hit) {
            [$text, $offset] = $hit;
            $lineNo = lineOf($src, $offset);
            $problems[] = "[INC-005] $r (line $lineNo): operator inside `{\$...}` "
                . "interpolation: " . trim($text) . " — pre-compute into a variable "
                . "before the string (`??`, ternary, `.` are parse errors here).";
        }
    }
}

/* ---------- 2: getActions() override in a Filament Page --------------- */
foreach (rglob($root . '/app/Filament/Pages', '.php') as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);
    if (preg_match('/function\s+getActions\s*\([^)]*\)\s*:\s*([^\{\s]+)/', $src, $mm)) {
        $ret = trim($mm[1]);
        if (strtolower($ret) !== 'array') {
            $problems[] = "[INC-002] $r: declares getActions(): $ret, clashing with "
                . "Filament\\Pages\\Page::getActions(): array. Rename it (e.g. getPaginatedActions()).";
        }
    }
}

/* ---------- FIL: Filament v3 patterns that break on v5 ---------------- */
// Each rule: [regex over raw source, human message]. Confirmed against this
// project's own working v5 code. Scans app/ PHP and Blade views.
$filamentRules = [
    ['/Filament\\\\Tables\\\\Actions\\\\/',
     'Filament\\Tables\\Actions\\* was removed in v5 — use Filament\\Actions\\* '
     . '(Action, EditAction, DeleteAction, BulkAction, BulkActionGroup, DeleteBulkAction).'],

    ['/Filament\\\\Pages\\\\Actions\\\\/',
     'Filament\\Pages\\Actions\\* was removed in v5 — use Filament\\Actions\\*.'],

    ['/Filament\\\\Forms\\\\Components\\\\Actions\\\\Action/',
     'Filament\\Forms\\Components\\Actions\\Action was unified in v5 — use Filament\\Actions\\Action.'],

    ['/Filament\\\\Forms\\\\Form\b/',
     'Filament\\Forms\\Form was replaced in v5 by Filament\\Schemas\\Schema. '
     . 'Signature becomes: public static function form(Schema $schema): Schema.'],

    ['/Filament\\\\Infolists\\\\Infolist\b/',
     'Filament\\Infolists\\Infolist was replaced in v5 by Filament\\Schemas\\Schema. '
     . 'Signature becomes: public static function infolist(Schema $schema): Schema.'],

    ['/Filament\\\\Forms\\\\Components\\\\(Section|Grid|Fieldset|Tabs|Wizard|Split|Group)\b/',
     'Layout components moved to Filament\\Schemas\\Components\\* in v5 '
     . '(Section, Grid, Fieldset, Tabs, Wizard, Split, Group). '
     . 'Input fields (TextInput, Select, Toggle, Repeater, ...) stay in Filament\\Forms\\Components.'],

    ['/Filament\\\\Infolists\\\\Components\\\\(Section|Grid|Fieldset|Tabs|Split|Group)\b/',
     'Infolist layout components moved to Filament\\Schemas\\Components\\* in v5.'],

    // static property types on Resources/Pages (v5 widened these unions)
    ['/protected\s+static\s+\?string\s+\$navigationIcon\b/',
     '$navigationIcon must be typed \\BackedEnum|string|null in v5, not ?string '
     . '(redeclaring narrower than the parent is a fatal type error).'],

    ['/protected\s+static\s+\?string\s+\$navigationGroup\b/',
     '$navigationGroup must be typed \\UnitEnum|string|null in v5, not ?string.'],
];

$filamentScan = array_merge(
    rglob($root . '/app', '.php'),
    rglob($root . '/resources/views', '.blade.php')
);
foreach ($filamentScan as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);
    foreach ($filamentRules as [$regex, $msg]) {
        if (preg_match_all($regex, $src, $mm, PREG_OFFSET_CAPTURE)) {
            // report once per file per rule, at the first hit line
            $lineNo = lineOf($src, $mm[0][0][1]);
            $problems[] = "[FIL-v5] $r (line $lineNo): $msg";
        }
    }
}

/* ---------- 6: Blade directive glued to a preceding word char (\B@) ---- */
// Blade compiles directives with \B@, so a directive glued to a word char
// (e.g. `contracted lead@endif`, `progress@if(...)`) is NOT recognised and is
// emitted as literal text — silently breaking @if/@foreach nesting → a 500 at
// render. Caused the Action Queue + Supplier Prep 500s (2026-09-20).
$gluedDirectives = 'if|elseif|else|endif|unless|endunless|isset|endisset|empty|'
    . 'endempty|foreach|endforeach|forelse|endforelse|for|endfor|while|endwhile|'
    . 'php|endphp|switch|endswitch|section|endsection|once|endonce';
foreach (rglob($root . '/resources/views', '.blade.php') as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);
    if (preg_match_all('/\w@(?:' . $gluedDirectives . ')\b/', $src, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $hit) {
            [$text, $offset] = $hit;
            $lineNo = lineOf($src, $offset);
            $problems[] = "[BLADE-GLUE] $r (line $lineNo): directive glued to a word "
                . "char (`" . trim($text) . "`). Blade uses \\B@, so `word@endif` is not "
                . "compiled and silently breaks @if/@foreach nesting → a 500. Put a space "
                . "before the @directive.";
        }
    }
}

/* ---------- INC-013: inline @php(...) pitfalls ------------------------- */
// Two real Blade compile-breakers — both 500s the directive-balance checks
// above CANNOT see (InvestigateInvestigation page 500, 2026-09-22, INC-013):
//   (a) storePhpBlocks pairs the FIRST `@php` (even an inline `@php(`) with the
//       NEXT `@endphp` anywhere in the file — INCLUDING one written inside a
//       `{{-- comment --}}`, because that pass runs before comments are stripped.
//       If an inline `@php(` sits before an `@endphp`, everything between is
//       stored as a raw block and the wrapping @if/@foreach never compiles →
//       a stray `endif`/`endforeach` → parse error → 500.
//   (b) an inline @php(expr) directive compiles with the expression wrapped in
//       parentheses, so a multi-statement expression (a top-level ';' between
//       the parens) becomes a parse error. Split into one @php() per statement.
foreach (rglob($root . '/resources/views', '.blade.php') as $file) {
    $src = file_get_contents($file);
    $r = rel($file, $root);

    // (a) simulate storePhpBlocks; flag a block that began at an inline `@php(`
    //     or swallowed a control directive.
    if (preg_match_all('/(?<!@)@php(.*?)@endphp/s', $src, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[1] as $i => $body) {
            [$code, $off] = $body;
            $lineNo = lineOf($src, $mm[0][$i][1]);
            if (preg_match('/^\s*\(/', $code)
                || preg_match('/@(if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|for|endfor|while|endwhile|json|switch)\b/', $code)) {
                $problems[] = "[INC-013a] $r (~line $lineNo): a @php…@endphp block swallowed an "
                    . "inline @php( or a control directive. An inline @php( before an @endphp — "
                    . "often an @endphp written inside a {{-- comment --}} — mis-pairs and breaks "
                    . "compilation. Use inline @php(...) only in that region, and never write the "
                    . "@php / @endphp tokens inside a comment.";
            }
        }
    }

    // (b) multi-statement inline @php(...): a top-level ';' between the parens.
    if (preg_match_all('/@php(\((?:[^()]|(?1))*\))/', $src, $inl, PREG_OFFSET_CAPTURE)) {
        foreach ($inl[1] as $g) {
            [$paren, $off] = $g;
            $inner = substr($paren, 1, -1);                       // drop outer ( )
            $stripped = preg_replace('/([\'"]).*?\1/s', '', $inner); // ignore ';' in strings
            if (strpos($stripped, ';') !== false) {
                $lineNo = lineOf($src, $off);
                $problems[] = "[INC-013b] $r (line $lineNo): multi-statement inline @php() "
                    . "directive (a ';' between the parens). Blade wraps the expression in "
                    . "parentheses, so multiple statements become a parse error. "
                    . "Split into one @php() directive per statement.";
            }
        }
    }
}

/* ---------- report ---------------------------------------------------- */

if (empty($problems)) {
    fwrite(STDOUT, "✓ check-blade: no known footguns found (Blade, PHP, Filament v5).\n");
    exit(0);
}

fwrite(STDERR, "✗ check-blade found " . count($problems) . " problem(s):\n\n");
foreach ($problems as $p) {
    fwrite(STDERR, "  - $p\n");
}
fwrite(STDERR, "\nFix these before pushing — each has caused a build failure or 500 before.\n");
exit(1);
