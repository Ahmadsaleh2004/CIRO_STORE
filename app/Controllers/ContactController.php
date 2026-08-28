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
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundPage'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    #[OA\Post(
        path: '/contact',
        summary: 'إرسال نموذج "اتصل بنا" من الصفحة نفسها',
        description: <<<'TXT'
        النقطة الوحيدة التي كانت مسجَّلة في الراوتر وغائبة عن المواصفة
        (103 من 104). وُثّقت هنا كما تعمل فعلاً.

        تختلف عن POST /contact/send اختلافاً جوهرياً: هذه **لا تُرجع
        JSON**. الدالة تخدم GET وPOST معاً، وتعيد عرض الصفحة كاملةً مع
        رسالة نجاح أو خطأ في متن HTML. ولهذا استُثنيت من beginJsonPost
        صراحةً — الفشل هنا لا يوقف التنفيذ بل يملأ $errorMsg ويُكمل.

        وفشل CSRF لا يُرجع error_code لأن لا عميل JS يقرأ هذه الاستجابة:
        الصفحة تُعرض والرسالة داخلها.

        شرطان للإرسال: مستخدم مسجّل الدخول (الزائر يُرفض)، ورسالة لا تقلّ
        عن عشرة محارف. والاسم والبريد يُقرآن من قاعدة البيانات لا من
        الطلب — تمريرهما في الجسم لا أثر له.
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
                            description: 'علامة وجود النموذج. بلا هذا المفتاح تُعرض الصفحة بلا معالجة.',
                            example: '1'
                        ),
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            minLength: 10,
                            example: 'أرغب بالاستفسار عن توفّر المنتج باللون الأسود.'
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
        responses: [
            new OA\Response(
                response: 200,
                description: 'نتيجة العملية. الحقل success يفصل النجاح عن الفشل — كود HTTP يبقى 200 في الحالتين. وعند فشل CSRF يحمل الجسم error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
        ]
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

        // إنذار كاذب: المطبوع منطقيّ ونصّان ثابتان مكتوبان هنا — لا شيء
        // من الطلب يصل إلى المخرَج إطلاقاً.
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        echo json_encode([
            'success' => (bool)$saved,
            'message' => $saved ? 'Your message has been sent! We will get back to you soon.' : 'Something went wrong, please try again later.',
        ]);
        exit;
    }
}
