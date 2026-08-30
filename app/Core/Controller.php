<?php

namespace App\Core;

abstract class Controller
{
    /** The supported layouts — any other value is a programming error, not a runtime choice. */
    private const LAYOUTS = ['store', 'admin', 'bare'];

    /**
     * Renders a view inside one of three layouts.
     *
     * ┌─────────┬──────────────────────────────────────────────────┐
     * │ store   │ inc/head + inc/navbar + view + inc/footer        │
     * │ admin   │ admin/inc/head + admin/inc/navbar + view + …     │
     * │ bare    │ the view alone — a standalone page that builds its own tags │
     * └─────────┴──────────────────────────────────────────────────┘
     *
     * The `bare` pages (admin sign-in, re-authentication, password reset) use
     * `inc/head-bare.php` and `inc/footer-bare.php` rather than writing
     * `<!DOCTYPE html>` by hand.
     *
     * @param string $view   The view path under app/views without an extension,
     *                       such as 'home' or 'admin/orders/index'
     * @param array<string, mixed> $data Variables extracted for the view and the layout files
     * @param string $layout 'store' | 'admin' | 'bare'
     */
    protected function view(string $view, array $data = [], string $layout = 'store'): void
    {
        if (!in_array($layout, self::LAYOUTS, true)) {
            throw new \InvalidArgumentException(
                "Unknown layout [{$layout}]. Expected: " . implode(' | ', self::LAYOUTS)
            );
        }

        // The local variables are prefixed with __ because extract($data) below writes
        // into the same scope — a key named view or layout would have overwritten them.
        $__layout   = $layout;
        $__viewFile = APPROOT . '/views/' . $view . '.php';

        // The check comes before any output. The old version emitted the head and
        // navbar and then discovered the view was missing, at which point sending a 404
        // is impossible (the headers have already gone) and half a broken page comes
        // out.
        if (!is_file($__viewFile)) {
            ErrorPage::notFound('missing view: ' . $__viewFile);
        }

        // The user activity heartbeat — throttled to once every 15 minutes (see
        // touchUserActivity in auth_helper.php). Confined to the store layout
        // deliberately: the admin session is separate in both name and contents, and its
        // activity is tracked in AdminModel. Do not move this into the bootstrap — there
        // a PHPSESSID session would start before startAdminSession sets the admin
        // session name.
        if ($__layout === 'store') {
            touchUserActivity();
        }

        // Extract the variables out of $data so the view can print them directly
        extract($data);

        // require rather than require_once: the latter stops the same file rendering
        // twice in one request, which is a latent fault that surfaces the moment a view
        // renders the same partial twice. The admin layout has used require from the
        // start without trouble.
        switch ($__layout) {
            case 'store':
                require APPROOT . '/views/inc/head.php';
                require APPROOT . '/views/inc/navbar.php';
                require $__viewFile;
                require APPROOT . '/views/inc/footer.php';
                break;

            case 'admin':
                require APPROOT . '/views/admin/inc/head.php';
                require APPROOT . '/views/admin/inc/navbar.php';
                require $__viewFile;
                require APPROOT . '/views/admin/inc/footer.php';
                break;

            case 'bare':
                require $__viewFile;
                break;
        }
    }

    // Note: the 404 page itself lives in App\Core\ErrorPage, not here. There used to
    // be a local copy in this class, but Router needs it too and does not extend
    // Controller (and should not), so it would have been copied twice.

    // ═══════════════════════════════════════════════════════════
    // JSON responses — shared across every controller
    // ═══════════════════════════════════════════════════════════
    //
    // respond() used to be copied verbatim into 16 controllers (two copies differing
    // only in whitespace). It was moved here once: the store controllers inherit it
    // directly, and the admin controllers through AdminController, which extends this
    // class.

    /**
     * Prints a uniformly shaped JSON response and halts.
     *
     * The shape is fixed: {success, message, ...$extra}. The front end depends on it
     * in js/core/utils.js and the rest of the feature files, so the key names must not
     * change.
     *
     * Note: it does not set the Content-Type header — some endpoints set it themselves
     * before calling, and some call respond() after output has already begun.
     *
     * @param array<string, mixed> $extra
     */
    protected function respond(bool $success, string $message, array $extra = []): never
    {
        echo json_encode(
            array_merge(['success' => $success, 'message' => $message], $extra),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * A shorthand for a failure response with no extra data.
     */
    protected function jsonError(string $message): never
    {
        $this->respond(false, $message);
    }

    /**
     * The preamble for any JSON endpoint accepting a POST: it sets the response
     * header, refuses anything that is not a POST, and verifies the CSRF token — with
     * the same messages that used to be written by hand in dozens of places.
     *
     * These three lines were repeated in exactly the same wording: 61 times to set the
     * header, 40 times to check the method, and 24 times to check CSRF with the same
     * message.
     *
     * The order of the checks is deliberate: the header first so the refusal message
     * is itself JSON, then the method, then CSRF — because verifying a token outside a
     * POST is meaningless.
     *
     * Note: this is for endpoints returning JSON only. Pages that redirect on failure
     * (AdminBrandingController::save, for instance) have their own handling and do not
     * use it.
     *
     * A CSRF failure carries an explicit error_code (ERR_CSRF_INVALID). js/core/csrf.js
     * detects it by that, fetches a fresh token, and retries once — the message is now
     * for display alone.
     *
     * @param bool $requireCsrf Pass false for public endpoints that do not hold a token
     *                          yet (fetching the token itself, for instance).
     */
    protected function beginJsonPost(bool $requireCsrf = true): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respond(false, 'Method not allowed.');
        }

        if ($requireCsrf && !verifyCsrfToken($this->requestData()['csrf_token'] ?? '')) {
            $this->respondCsrfFailure();
        }
    }

    /**
     * The CSRF failure code — a contract between the server and js/core/csrf.js.
     *
     * Why a code rather than text? The wrapper used to detect the failure with
     *     message.startsWith('Invalid CSRF token')
     * so any endpoint wording its message differently lost the retry **silently**.
     * That happened three times in fact: WishlistController::notify ('Invalid
     * session…'), ContactController::send ('Invalid request…'), and six endpoints that
     * survived by chance because their wording happened to start with the same prefix.
     *
     * The code separates what the machine reads from what the user reads: the text is
     * free to change, and the code is fixed.
     */
    public const ERR_CSRF_INVALID = 'csrf_invalid';

    /**
     * The unified CSRF failure response. Called from beginJsonPost and from the few
     * endpoints that bypass it while still returning JSON.
     *
     * @param array<string, mixed> $extra Extra data — reauth, for instance, returns a fresh token
     */
    protected function respondCsrfFailure(array $extra = []): never
    {
        $this->respond(
            false,
            'Invalid CSRF token, please refresh and try again.',
            array_merge(['error_code' => self::ERR_CSRF_INVALID], $extra)
        );
    }

    /**
     * Validates the request input and returns the normalised values — or responds with
     * the first error and exits.
     *
     * Two lines binding Validator to the request:
     *
     *     $input = $this->validate([
     *         'full_address' => 'required|string|min:5|max:255',
     *         'label'        => 'string|default:Home',
     *         'city'         => 'nullable|string',
     *     ]);
     *
     * ── Why it responds and exits rather than returning a result ──
     *
     * Because this **is the existing behaviour, to the letter**. Every action today writes:
     *
     *     if (!$full) { $this->respond(false, 'Full address is required.'); }
     *
     * and respond ends the request. So the change alters nothing about the flow — it
     * only unifies how it is written. Had a result been returned instead, every caller
     * would have to remember to check it, and that is precisely what gets forgotten.
     *
     * ⚠️ It must be called **after** beginJsonPost: that sets the JSON header, and
     * without the header the error message arrives as raw text the client cannot read.
     *
     * The validation logic itself lives in App\Core\Validator and is entirely pure —
     * it neither reads the request nor prints — so it can be tested with no server and
     * no session.
     *
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validate(array $rules): array
    {
        $validator = (new Validator($this->requestData()))->check($rules);

        if ($validator->fails()) {
            $this->respond(false, (string) $validator->firstError());
        }

        return $validator->validated();
    }

    /**
     * Unified request input: $_POST merged with the JSON body, if there is one.
     *
     * Why? Some of the project's endpoints receive FormData and some receive JSON
     * (cart.js and account.js, for instance, send JSON through fetchWithCsrfRetry). The
     * endpoints taking JSON used to build `array_merge($_POST, $body)` themselves and
     * then read the token out of it — nine endpoints repeating the same two lines.
     *
     * And that is what prevented unifying them: beginJsonPost read from $_POST alone,
     * so converting them would have broken every endpoint whose token arrives in a JSON
     * body.
     *
     * The result is computed once per request: php://input is a stream that can be
     * read once, and this may be called from beginJsonPost and then from the action body.
     *
     * @return array<string,mixed>
     */
    protected function requestData(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $body = json_decode(file_get_contents('php://input') ?: '', true);
        return $cache = array_merge($_POST, is_array($body) ? $body : []);
    }
}
