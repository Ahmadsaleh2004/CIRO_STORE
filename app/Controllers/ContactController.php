<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ContactModel;
use App\Models\UserModel;
use OpenApi\Attributes as OA;

/**
 * ContactController — serves the /contact page (GET + POST).
 * Moved out of PageController::contact().
 */
class ContactController extends Controller
{
    #[OA\Get(
        path: '/contact',
        summary: 'Contact page',
        tags: ['Store - Pages'],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    #[OA\Post(
        path: '/contact',
        summary: 'Submit the contact form from the page itself',
        description: <<<'TXT'
        The one endpoint that was registered in the router and missing from the
        spec (103 of 104). Documented here as it actually behaves.

        It differs from POST /contact/send in a fundamental way: this one **does
        not return JSON**. The method serves both GET and POST, and re-renders the
        whole page with a success or error message inside the HTML body. That is
        why it is deliberately excluded from beginJsonPost — a failure here does
        not halt execution, it fills $errorMsg and carries on.

        A CSRF failure returns no error_code either, because no JavaScript client
        reads this response: the page is rendered with the message inside it.

        Two conditions to send: a logged-in user (a visitor is refused), and a
        message of at least ten characters. The name and email are read from the
        database, not from the request — passing them in the body has no effect.
        TXT,
        tags: ['Store - Pages'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['send_message', 'message', 'csrf_token'],
                    properties: [
                        new OA\Property(
                            property: 'send_message',
                            type: 'string',
                            description: 'Marks the form as present. Without this key the page renders with no processing.',
                            example: '1'
                        ),
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            minLength: 10,
                            example: 'I would like to ask whether this product is available in black.'
                        ),
                        new OA\Property(property: 'csrf_token', ref: '#/components/schemas/CsrfToken'),
                    ],
                    type: 'object'
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function contact(): void
    {
        // Static contact details
        $phone        = '+20 123 456 789';
        $workingHours = 'Sun - Thu: 9 AM - 6 PM';
        $email        = 'info@cairostore.com';

        $successMsg = '';
        $errorMsg   = '';

        // Form submission handling (POST)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
            $token = $_POST['csrf_token'] ?? '';

            // Deliberately not using beginJsonPost: this never aborts — it puts the
            // text in $errorMsg and carries on rendering the page. The same method
            // serves GET and POST.
            if (!verifyCsrfToken($token)) {
                $errorMsg = 'Invalid request, please refresh the page and try again.';
            } else {
                $msgText  = trim($_POST['message']   ?? '');
                $userId   = getCurrentUserId();

                // Signed-out visitors cannot send at all
                if (!$userId) {
                    $errorMsg = 'You must be logged in to send a message.';
                } else {
                    // For logged-in users: ignore full_name/email from the POST and use the session and database instead
                    $user = UserModel::findById($userId);
                    $fullName = $user['full_name'] ?? '';
                    $msgEmail = $user['email'] ?? '';

                    if (strlen($msgText) < 10) {
                        $errorMsg = 'Message is too short (at least 10 characters).';
                    } else {
                        $saved = ContactModel::save($userId, $fullName, $msgEmail, $msgText);
                        if ($saved) {
                            $successMsg = 'Your message has been sent! We will get back to you soon.';
                        } else {
                            $errorMsg = 'Something went wrong, please try again later.';
                        }
                    }
                }
            }
        }

        $userLoggedIn = isUserLoggedIn();

        $this->view('page/contact', [
            'title'        => 'Contact Us',
            'desc'         => 'Get in touch with Cairo Store for support and inquiries.',
            'activePage'   => 'contact',
            'extraScripts' => '',
            'phone'        => $phone,
            'workingHours' => $workingHours,
            'email'        => $email,
            'csrf'         => generateCsrfToken(),
            'prefillName'  => $_SESSION['user_name']  ?? '',
            'prefillEmail' => $_SESSION['user_email'] ?? '',
            'successMsg'   => $successMsg,
            'errorMsg'     => $errorMsg,
            'userLoggedIn' => $userLoggedIn,
            'userName'     => $_SESSION['user_name'] ?? '',
        ]);
    }

    #[OA\Post(
        path: '/contact/send',
        summary: 'Send a message from the contact form',
        tags: ['Store - Pages'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['name', 'email', 'message', 'csrf_token'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'csrf_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
    )]
    public function send(): void
    {
        // On a token failure this used to answer 'Invalid request, please refresh
        // the page and try again.' — which does not start with the prefix
        // js/core/csrf.js checks for (startsWith('Invalid CSRF token')), so the retry
        // was dead for the contact form even though contact.js calls
        // fetchWithCsrfRetry. The same fault as WishlistController::notify.
        $this->beginJsonPost();

        $userId = getCurrentUserId();
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'You must be logged in to send a message.']);
            exit;
        }

        $msgText = trim($_POST['message'] ?? '');
        if (strlen($msgText) < 10) {
            echo json_encode(['success' => false, 'message' => 'Message is too short (at least 10 characters).']);
            exit;
        }

        $user     = UserModel::findById($userId);
        $fullName = $user['full_name'] ?? '';
        $msgEmail = $user['email']     ?? '';

        $saved = ContactModel::save($userId, $fullName, $msgEmail, $msgText);

        // False positive: what is printed is a boolean and two literal strings written
        // right here — nothing from the request reaches the output at all.
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        echo json_encode([
            'success' => (bool)$saved,
            'message' => $saved ? 'Your message has been sent! We will get back to you soon.' : 'Something went wrong, please try again later.',
        ]);
        exit;
    }
}
