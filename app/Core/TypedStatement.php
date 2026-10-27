<?php

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * A prepared statement whose execute() sends an integer as an integer.
 *
 * ── The fault it exists for ─────────────────────────────────
 *
 * The connection emulates prepares, to send each query in one round trip instead of two
 * (see Database::connect). Under emulation PDO writes the values into the SQL itself, and
 * `$stmt->execute([...])` binds every one of them as a string: `3` reaches MySQL as '3'.
 *
 * In most places MySQL does not care — '5' = 5 in a WHERE. But LEAST() compares its
 * arguments as strings as soon as one of them is a string, and as strings '10' < '3'. The
 * cart caps a quantity with LEAST(?, stock, 99), so on MySQL — which is what Aiven runs —
 * adding 3 of a product with 10 in stock stored 10, and the stock ceiling stopped working:
 * eleven cart tests failed in CI (MySQL 8.4) while passing on the MariaDB of the
 * development machine, which compares the same expression as numbers.
 *
 * Fixing that one query would leave the next LEAST, GREATEST or comparison of two
 * parameters to fail the same way, unseen until it reached a customer. So the rule is made
 * once, here, for every statement: integers are bound as PDO::PARAM_INT, null as
 * PDO::PARAM_NULL, everything else as a string — exactly what execute() did before for
 * everything that is not an integer.
 *
 * Installed through PDO::ATTR_STATEMENT_CLASS in Database::connect and in tests/bootstrap.php,
 * so no call site changes.
 */
class TypedStatement extends PDOStatement
{
    /** PDO creates the statement itself; the constructor must exist and not be public. */
    protected function __construct()
    {
    }

    /**
     * @param array<int|string, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        if ($params === null || $params === []) {
            return parent::execute();
        }

        foreach ($params as $key => $value) {
            // Positional placeholders are numbered from 1, and the array from 0. A named one
            // is given with or without its colon, as execute() accepts both.
            $param = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);

            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };

            $this->bindValue($param, $value, $type);
        }

        return parent::execute();
    }
}
