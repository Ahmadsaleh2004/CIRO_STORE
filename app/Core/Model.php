<?php

namespace App\Core;

use PDO;

/**
 * Model — the shared base for every model in the project.
 *
 * It does exactly one thing: it gives the models a single way in to the database
 * connection. That alone removes 156 direct calls to `Database::connect()` that
 * were scattered across sixteen files.
 *
 * ── Why this shape, rather than full constructor injection ──
 *
 * Full injection — turning the models into objects that receive a PDO — was the
 * first proposal, and it has been **measured and rejected twice**:
 *
 *   · once before, with the decision recorded in Database.php: there is a single
 *     choke point, and Database::setConnection() is enough to swap the entire data
 *     source.
 *   · and once now, after actually measuring the scope: 156 calls inside the models
 *     and **302** calls to their methods from the controllers. That is 458 sites to
 *     change, against a gain the code does not need today.
 *
 * The practical proof that swapping already works: every integration test in this
 * project runs against a `*_test` database rather than the development one,
 * through Database::setConnection() alone, without a single line changed in the
 * models.
 *
 * So what remained of the complaint was not coupling but **repetition**: the line
 * `$db = Database::connect();` written 156 times. That is what this file addresses.
 *
 * ── What changes if we do want full injection later ──
 *
 * One place: self::db(). Anyone wanting the models to carry their own connection
 * starts here rather than at 156 scattered sites. Which is to say this step brings
 * full injection **closer** rather than blocking the way to it.
 */
abstract class Model
{
    /**
     * The database connection for this model.
     *
     * `static` rather than `self` in the call is not incidental: a model that one
     * day needs a different connection (a read replica, say) overrides this method
     * alone, and everything else in the file follows with no change.
     */
    protected static function db(): PDO
    {
        return Database::connect();
    }
}
