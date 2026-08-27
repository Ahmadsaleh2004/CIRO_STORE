<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ContactModel;
use App\Models\UserModel;
use OpenApi\Attributes as OA;

/**
 * ContactController — يعالج صفحة /contact (GET + POST)
 * منقول من PageController::contact()
 */
class ContactController extends Controller
{
    #[OA\Get(
        path: '/contact',
        summary: 'صفحة "اتصل بنا"',
        tags: ['Store - Pages'],
        responses: [new OA\Response(response: 200, description: 'صفحة HTML')]
    )]
    public function contact(): void
    {
        // بيانات التواصل الثابتة
        $phone        = '+20 123 456 789';
        $workingHours = 'Sun - Thu: 9 AM - 6 PM';
        $email        = 'info@cairostore.com';

        $successMsg = '';
        $errorMsg   = '';

        // معالجة إرسال الفورم (POST)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
            $token = $_POST['csrf_token'] ?? '';

            // استُثني من beginJsonPost: لا يفشل أصلاً — يضع النص في
            // $errorMsg ويُكمل عرض الصفحة. الدالة تخدم GET وPOST معاً.
            if (!verifyCsrfToken($token)) {
                $errorMsg = 'Invalid request, please refresh the page and try again.';
            } else {
                $msgText  = trim($_POST['message']   ?? '');
                $userId   = getCurrentUserId();

                // الزوار غير المسجلين لا يمكنهم الإرسال إطلاقًا
                if (!$userId) {
                    $errorMsg = 'You must be logged in to send a message.';
                } else {
                    // للمستخدمين المسجلين: تجاهل full_name/email من الـ POST، واستخدم بيانات الجلسة/قاعدة البيانات
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
        summary: 'إرسال رسالة من نموذج "اتصل بنا"',
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
        responses: [new OA\Response(response: 200, description: 'JSON — {success, message}')]
    )]
    public function send(): void
    {
        // كانت ترد عند فشل التوكن بـ'Invalid request, please refresh the
        // page and try again.' — ولا تبدأ بالبادئة التي يفحصها
        // js/core/csrf.js (startsWith('Invalid CSRF token'))، فكانت إعادة
        // المحاولة معطّلة لنموذج «اتصل بنا» رغم أن contact.js يستدعي
        // fetchWithCsrfRetry. نفس عطل WishlistController::notify.
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

        echo json_encode([
            'success' => (bool)$saved,
            'message' => $saved ? 'Your message has been sent! We will get back to you soon.' : 'Something went wrong, please try again later.',
        ]);
        exit;
    }
}
