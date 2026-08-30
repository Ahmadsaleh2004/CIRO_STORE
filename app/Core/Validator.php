<?php

namespace App\Core;

/**
 * Validator — extracting and validating input in one place.
 *
 * ── What came before it ─────────────────────────────────────
 *
 * Extraction and validation written by hand in every action. Measured:
 *
 *     $_POST[...] ?? ...    88 sites
 *     trim($_POST[...])     38
 *     (int)$_POST[...]      26
 *
 * The consequence of that repetition is not length but **drift**: a field trimmed
 * in one place and not in another, its length checked here and unchecked there.
 * And a single rule applied in nine places out of ten is not a rule.
 *
 * ── The design ─────────────────────────────────────────────
 *
 * This class is **pure**: it does not read $_POST, does not print, and does not
 * halt execution. It takes an array and returns a result. Which is why it can be
 * tested with no server, no session and no network.
 *
 * The binding to the request lives in Controller::validate() — two lines that pass
 * requestData() through and respond with the first error. The separation is
 * deliberate: validation logic must be testable on its own, and being scattered
 * through action bodies was precisely what prevented that.
 */
final class Validator
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, string> field name => its first error */
    private array $errors = [];

    /** @var array<string, mixed> the values after normalisation */
    private array $clean = [];

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Validates the input against the rules.
     *
     * A rule is a |-separated string, such as: 'required|string|min:2|max:50'
     *
     * Supported rules:
     *   required      rejects absence, and an empty string after trimming
     *   nullable      accepts absence and yields null
     *   string        trims both ends
     *   int           cast to an integer; anything non-numeric is rejected
     *   numeric       a number (decimals accepted)
     *   email         a valid address
     *   bool          cast: '1' 'true' 'on' 'yes' => true
     *   array         an array
     *   min:N         minimum length for a string, or minimum value for a number
     *   max:N         maximum length for a string, or maximum value for a number
     *   in:a,b,c      one of a list
     *   default:X     a value when absent (applied before the other rules)
     *
     * @param array<string, string> $rules
     */
    public function check(array $rules): self
    {
        foreach ($rules as $field => $ruleString) {
            $parts = array_filter(array_map('trim', explode('|', $ruleString)));
            $this->applyField($field, $parts);
        }

        return $this;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** The first error message, or null if validation passed. */
    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }

        return null;
    }

    /**
     * The normalised values — the fields that passed, and only those.
     *
     * ⚠️ It returns only what was validated, not the whole input. A field not named
     * in the rules does not appear here. That is deliberate: passing unvalidated
     * input on to the model is precisely the door the unexpected comes through.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->clean;
    }

    /** @param list<string> $rules */
    private function applyField(string $field, array $rules): void
    {
        $names = [];
        $args  = [];
        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $names[] = $name;
            $args[$name] = $arg;
        }

        $value = $this->data[$field] ?? null;

        // default before everything: the absent-value default is validated like any other value.
        if (($value === null || $value === '') && isset($args['default'])) {
            $value = $args['default'];
        }

        $present = $value !== null && $value !== '';

        if (!$present) {
            if (in_array('required', $names, true)) {
                $this->errors[$field] = $this->label($field) . ' is required.';
                return;
            }

            // nullable, or an optional field with no value: recorded as null and not validated.
            $this->clean[$field] = null;
            return;
        }

        foreach ($names as $name) {
            $arg = $args[$name];

            switch ($name) {
                case 'string':
                    if (is_array($value)) {
                        $this->errors[$field] = $this->label($field) . ' must be text.';
                        return;
                    }
                    $value = trim((string) $value);
                    if ($value === '' && in_array('required', $names, true)) {
                        $this->errors[$field] = $this->label($field) . ' is required.';
                        return;
                    }
                    break;

                case 'int':
                    // filter_var rather than ctype_digit: the latter rejects negatives, and
                    // a bare (int) turns 'abc' into 0 silently — which is the worst thing a
                    // validator can do.
                    $asInt = filter_var($value, FILTER_VALIDATE_INT);
                    if ($asInt === false) {
                        $this->errors[$field] = $this->label($field) . ' must be a whole number.';
                        return;
                    }
                    $value = $asInt;
                    break;

                case 'numeric':
                    if (!is_numeric($value)) {
                        $this->errors[$field] = $this->label($field) . ' must be a number.';
                        return;
                    }
                    $value = $value + 0;
                    break;

                case 'email':
                    $value = trim((string) $value);
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->errors[$field] = 'Please enter a valid email address.';
                        return;
                    }
                    break;

                case 'bool':
                    $value = in_array(
                        strtolower(trim((string) $value)),
                        ['1', 'true', 'on', 'yes'],
                        true
                    );
                    break;

                case 'array':
                    if (!is_array($value)) {
                        $this->errors[$field] = $this->label($field) . ' must be a list.';
                        return;
                    }
                    break;

                case 'min':
                    if (!$this->checkSize($field, $value, (float) $arg, true)) {
                        return;
                    }
                    break;

                case 'max':
                    if (!$this->checkSize($field, $value, (float) $arg, false)) {
                        return;
                    }
                    break;

                case 'in':
                    $allowed = explode(',', (string) $arg);
                    if (!in_array((string) $value, $allowed, true)) {
                        $this->errors[$field] = $this->label($field) . ' is not a valid choice.';
                        return;
                    }
                    break;
            }
        }

        $this->clean[$field] = $value;
    }

    /** The bound compares length for strings and value for numbers — which is what a reader expects. */
    private function checkSize(string $field, mixed $value, float $limit, bool $isMin): bool
    {
        if (is_int($value) || is_float($value)) {
            $ok = $isMin ? $value >= $limit : $value <= $limit;
            if (!$ok) {
                $this->errors[$field] = sprintf(
                    '%s must be %s %s.',
                    $this->label($field),
                    $isMin ? 'at least' : 'at most',
                    rtrim(rtrim(number_format($limit, 2, '.', ''), '0'), '.')
                );
            }
            return $ok;
        }

        if (is_array($value)) {
            $length = count($value);
        } else {
            // mb_strlen rather than strlen: a five-character Arabic name is ten bytes
            // in UTF-8. Measuring in bytes rejects valid names.
            $length = mb_strlen((string) $value);
        }

        $ok = $isMin ? $length >= $limit : $length <= $limit;
        if (!$ok) {
            $this->errors[$field] = sprintf(
                '%s must be %s %d characters.',
                $this->label($field),
                $isMin ? 'at least' : 'at most',
                (int) $limit
            );
        }

        return $ok;
    }

    /** 'full_address' => 'Full address' — a message a person reads, not a raw key. */
    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
