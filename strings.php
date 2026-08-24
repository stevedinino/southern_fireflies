<?php
// Build: 2026-08-21-A
// ============================================================
// Loads customer-facing text from /strings/*.txt files instead of
// having it embedded in PHP. Scope is deliberately narrow (per Steve,
// 2026-08-01): CUSTOMER-FACING copy only - page text, customer
// emails, customer-visible error messages. (Item DESCRIPTIONS moved
// out 2026-08-21: they live in each item's own folder as
// /items/<folder>/description.txt now, read by merch_items.php, so
// dropping in an item folder brings its text along with it. The old
// strings/items/*.txt files are gone.)
// Admin dashboard text (ourmerch.php, JSON error strings, the
// internal manual-quote alert email) stays hardcoded on purpose -
// Steve's the only one who ever sees it, so there's no real payoff to
// externalizing it, and it would just be more files to maintain.
//
// Usage:
//   echo merch_load_string('pages/merch-intro');
//   echo merch_load_string('emails/submission-ack.html', ['name' => $name, 'itemLabel' => $item]);
//   echo merch_load_string('shipping/pickup-invoice-template.md', [...]); // explicit extension
//
// Token syntax in the .txt files is {{tokenName}} - a plain
// str_replace, not a templating engine. Keep it that way; anything
// fancier is overkill for this.
//
// A key maps to strings/{key}.txt BY DEFAULT. If the key already ends
// in a recognized extension (.txt or .md), that's used as-is instead -
// added 2026-08-10 for the pickup-invoice template, which Steve wants
// to open/edit as real markdown, not a .txt file that happens to
// contain markdown syntax. Every existing call site is untouched by
// this - none of them include an extension, so they all still default
// to .txt exactly as before.
//
// Missing files fail LOUD (a visible placeholder + an error_log entry)
// rather than silently rendering blank - a blank customer email is a
// much worse failure mode than an obviously-wrong one that gets
// noticed and fixed before the next send.
// ============================================================

function merch_load_string(string $key, array $tokens = []): string
{
    static $cache = [];

    if (!isset($cache[$key])) {
        $hasExtension = (bool) preg_match('/\.(txt|md)$/', $key);
        $fullKey = $hasExtension ? $key : $key . '.txt';
        $path = __DIR__ . '/strings/' . $fullKey;
        if (!file_exists($path)) {
            error_log("Southern Fireflies: missing string file 'strings/{$fullKey}'");
            $cache[$key] = "[Missing text block: {$key} - strings/{$fullKey} not found on server]";
        } else {
            // rtrim so a trailing newline in the file (most editors
            // add one automatically) doesn't leave stray whitespace at
            // the end of every rendered string.
            $cache[$key] = rtrim(file_get_contents($path), "\r\n");
        }
    }

    $text = $cache[$key];
    foreach ($tokens as $name => $value) {
        $text = str_replace('{{' . $name . '}}', (string) $value, $text);
    }
    return $text;
}
