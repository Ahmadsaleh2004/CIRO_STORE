<?php

/**
 * scripts/normalize-openapi.php
 *
 * Makes the generated OpenAPI document byte-identical whichever operating system produced
 * it. Run automatically as the second half of `composer docs:generate`.
 *
 * ── Why this exists ──────────────────────────────────────────
 *
 * public/docs/openapi.yaml is both generated and committed, and CI proves it is current by
 * regenerating it and diffing. That check can only work if generation is deterministic —
 * and it was not.
 *
 * swagger-php joins the lines of a docblock description with PHP_EOL, which is "\n" on
 * Linux and "\r\n" on Windows, and the result is written *inside* the YAML string as the
 * two-character escape `\r\n`. So the same source produced two different documents:
 *
 *     committed (generated on Linux)   0 occurrences of the escape \r\n, 91 of \n
 *     regenerated on Windows          91 occurrences of \r\n
 *
 * 182 bytes of difference, no change in meaning, and a check that fails on whichever
 * platform did not produce the committed copy. Line-ending settings in git cannot fix it:
 * these are literal backslash-r characters in the file's text, not carriage returns, so
 * `text=auto` never sees them.
 *
 * ── What it does not do ──────────────────────────────────────
 *
 * It rewrites the escape sequence only, inside a document swagger-php has just produced.
 * It does not touch real carriage returns, and it does not reformat, reorder or re-indent
 * anything: a diff after this step is a diff in the API, which is the whole point of
 * committing the file.
 */

$file = $argv[1] ?? __DIR__ . '/../public/docs/openapi.yaml';

if (!is_file($file)) {
    fwrite(STDERR, "normalize-openapi: not found: $file\n");
    exit(1);
}

$before = file_get_contents($file);
if ($before === false) {
    fwrite(STDERR, "normalize-openapi: cannot read: $file\n");
    exit(1);
}

// The escaped form inside YAML strings: a literal backslash-r followed by a literal
// backslash-n. Written as '\\r\\n' in single quotes so PHP passes the four characters
// through rather than interpreting them.
$after = str_replace('\\r\\n', '\\n', $before);

// And the file's own line endings, so a Windows checkout does not commit CRLF into a file
// whose committed form is LF.
$after = str_replace("\r\n", "\n", $after);

if ($after === $before) {
    echo "openapi.yaml is already normalised.\n";
    exit(0);
}

if (file_put_contents($file, $after) === false) {
    fwrite(STDERR, "normalize-openapi: cannot write: $file\n");
    exit(1);
}

printf(
    "openapi.yaml normalised: %d bytes -> %d bytes.\n",
    strlen($before),
    strlen($after)
);
