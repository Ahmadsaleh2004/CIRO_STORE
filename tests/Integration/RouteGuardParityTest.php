<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * تكافؤ الحُرّاس — بين جدول المسارات وأجسام الأفعال.
 *
 * الحراسة في هذا المشروع مُعلَنة في موضعين الآن:
 *
 *   1. جدول المسارات في public/index.php  →  ->middleware('perm:x')
 *   2. جسم الفعل في الكنترولر             →  Middleware::requirePermission('x')
 *
 * الازدواج **مقصود ومؤقّت**. نقل الحراسة إلى المسار هو الاتجاه الصحيح
 * (الحارس يعمل قبل بناء الكنترولر لا بعده)، لكن حذف الفحص الداخلي في
 * الخطوة نفسها كان سيجعل أي خطأ في النقل ثغرةً صامتة. وبإبقاء
 * الاثنين، لا يمكن للحارس الجديد أن يكون **أضعف** من القديم — أسوأ ما
 * قد يحدث أن يكون أشدّ، وذلك يظهر فوراً كصفحة 403.
 *
 * وهذا الاختبار هو ما يجعل الازدواج آمناً بدل أن يكون خطراً: يشتقّ
 * الطرفين من مصدريهما ويقارنهما. فإن انحرف أحدهما — أضيف مسار بلا
 * حارس، أو غُيّرت صلاحية الفعل بلا تغيير المسار — يفشل البناء.
 *
 * حين تُحذف الفحوص الداخلية لاحقاً، يبقى النصف الأول من هذا الملف
 * حارساً على أن كل مسار إداري يُعلن صلاحيته.
 */
final class RouteGuardParityTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * الصلاحية التي يعلنها كل فعل في جسمه.
     *
     * @return array<string, string> "Controller::action" => "perm:x" | "auth"
     */
    private static function guardsInActionBodies(): array
    {
        $out = [];

        foreach (glob(self::root() . '/app/Controllers/*.php') as $file) {
            $src = (string) file_get_contents($file);
            $class = basename($file, '.php');

            $parts = preg_split('/\n    public function (\w+)\s*\(/', $src, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) {
                continue;
            }

            for ($i = 1; $i < count($parts); $i += 2) {
                $action = $parts[$i];
                $body   = $parts[$i + 1] ?? '';

                if (preg_match("/Middleware::requirePermission\('([^']+)'\)/", $body, $m)) {
                    $out["{$class}::{$action}"] = 'perm:' . $m[1];
                } elseif (str_contains($body, 'Middleware::requireLogin()')) {
                    $out["{$class}::{$action}"] = 'auth';
                }
            }
        }

        return $out;
    }

    /**
     * الحارس المُعلَن في جدول المسارات لكل فعل.
     *
     * @return array<string, string>
     */
    private static function guardsInRouteTable(): array
    {
        $src = (string) file_get_contents(self::root() . '/public/index.php');

        preg_match_all(
            "/->(?:get|post|put|patch|delete)\(\s*'[^']+'\s*,\s*\[(\w+)::class,\s*'(\w+)'\]\s*\)"
            . "(\s*\n?\s*->middleware\('([^']+)'\))?/",
            $src,
            $matches,
            PREG_SET_ORDER
        );

        $out = [];
        foreach ($matches as $m) {
            // المجموعة 4 هي اسم الحارس، وتوجد فقط حين طابق الجزء
            // الاختياري ->middleware(...). فحص `!== ''` بعد isset كان
            // شرطاً لا يتحقّق: النمط لا يقبل اسماً فارغاً أصلاً.
            if (!isset($m[4])) {
                continue;
            }

            $out[$m[1] . '::' . $m[2]] = $m[4];
        }

        return $out;
    }

    /**
     * كل فعل يعلن صلاحية في جسمه، يعلنها مساره أيضاً — وبالقيمة نفسها.
     */
    public function testRouteTableDeclaresTheSameGuardTheActionEnforces(): void
    {
        $inBody  = self::guardsInActionBodies();
        $inTable = self::guardsInRouteTable();

        $this->assertGreaterThan(40, count($inBody), 'قارئ أجسام الأفعال لم يجد حُرّاساً كافية.');

        $problems = [];
        foreach ($inBody as $action => $guard) {
            if (!isset($inTable[$action])) {
                $problems[] = "{$action} — الفعل يفرض [{$guard}] والمسار لا يعلن شيئاً.";
                continue;
            }
            if ($inTable[$action] !== $guard) {
                $problems[] = sprintf(
                    '%s — المسار يعلن [%s] والفعل يفرض [%s].',
                    $action,
                    $inTable[$action],
                    $guard
                );
            }
        }

        $this->assertSame(
            [],
            $problems,
            "انحراف بين جدول المسارات وأجسام الأفعال:\n  " . implode("\n  ", $problems)
        );
    }

    /**
     * والعكس: لا مسار يعلن حارساً لا يفرضه فعله.
     *
     * هذا الاتجاه يمسك الحالة الأخطر على الاستعمال: مسار يعلن صلاحية
     * أشدّ ممّا يحتاجه الفعل فعلاً، فيُمنع أدمن من صفحة يملك حقّها.
     */
    public function testNoRouteDeclaresAGuardItsActionDoesNotEnforce(): void
    {
        $inBody  = self::guardsInActionBodies();
        $inTable = self::guardsInRouteTable();

        $extra = [];
        foreach ($inTable as $action => $guard) {
            // الخنق مُعلَن في المسار وحده بلا مقابل في الجسم، وهذا تصميمه
            // لا سهو فيه. قاعدة التكافؤ أعلاه تخصّ ازدواج **التخويل**
            // (perm/auth) وهو ازدواج مؤقّت مقصود يُحذف نصفه لاحقاً؛ أمّا
            // الخنق فوُلد في المسار من أوّل يوم: مكانه الصحيح قبل بناء
            // الكنترولر، لأنه يعدّ الطلبات لا نتائجها — ونسخة منه داخل
            // الجسم كانت ستعدّ الطلب مرّتين.
            if (str_starts_with($guard, 'throttle:')) {
                continue;
            }

            if (!isset($inBody[$action])) {
                $extra[] = "{$action} — المسار يعلن [{$guard}] ولا أثر له في جسم الفعل.";
            }
        }

        $this->assertSame([], $extra, "حُرّاس مُعلَنة بلا مقابل:\n  " . implode("\n  ", $extra));
    }

    /**
     * كل حارس خنق مكتوب بالصيغة التي يفهمها الراوتر، وبحدود معقولة.
     *
     * Router::runMiddleware يرمي عند صيغة مشوّهة — لكن وقت الطلب. وخطأ
     * مطبعي في رقم («throttle:login,5» بلا نافذة، أو نافذة صفر) يعني
     * إمّا صفحة 500 لكل زائر، وإمّا حارساً يمرّ كل شيء. كلاهما يُكتشَف
     * هنا لا هناك.
     */
    public function testEveryThrottleGuardIsWellFormed(): void
    {
        $problems = [];

        foreach (self::guardsInRouteTable() as $action => $guard) {
            if (!str_starts_with($guard, 'throttle:')) {
                continue;
            }

            $args = explode(',', substr($guard, 9));

            if (count($args) !== 3) {
                $problems[] = "{$action} → [{$guard}] — الصيغة throttle:bucket,max,windowMinutes.";
                continue;
            }

            [$bucket, $max, $window] = $args;

            if (!preg_match('/^[a-z0-9-]+$/', $bucket)) {
                $problems[] = "{$action} → اسم دلو غير صالح [{$bucket}].";
            }
            if ((int)$max < 1) {
                $problems[] = "{$action} → حدّ [{$max}] لا يمنع شيئاً.";
            }
            if ((int)$window < 1) {
                $problems[] = "{$action} → نافذة [{$window}] دقيقة تُفرغ العدّاد فوراً.";
            }
        }

        $this->assertSame([], $problems, "حُرّاس خنق مشوّهة:\n  " . implode("\n  ", $problems));
    }

    /**
     * كل نقطة دخول حسّاسة مخنوقة — لا واحدة منسيّة.
     *
     * القائمة مكتوبة بأسمائها عمداً بدل اشتقاقها: الاشتقاق يجيب عن
     * «ما المخنوق؟» بينما السؤال الذي يحرس هو «ما الذي **يجب** أن
     * يُخنق؟». من يضيف نقطة دخول جديدة ولا يخنقها لن يكسر اشتقاقاً،
     * لكنه سيصطدم بهذه القائمة حين يضيف اسمه إليها — وهو الموضع الصحيح
     * لاتخاذ القرار.
     */
    public function testEverySensitiveEntryPointIsThrottled(): void
    {
        $mustBeThrottled = [
            'ContactController::send',
            'AuthController::login',
            'AuthController::register',
            'AuthController::forgot',
            'AuthController::resetSubmit',
            'AdminAuthController::login',
            'AdminAuthController::verify2FALogin',
            'AdminAuthController::forgotPassword',
            'AdminAuthController::reauth',
        ];

        $inTable = self::guardsInRouteTable();

        $missing = [];
        foreach ($mustBeThrottled as $action) {
            $guard = $inTable[$action] ?? '';
            if (!str_starts_with($guard, 'throttle:')) {
                $missing[] = $action;
            }
        }

        $this->assertSame([], $missing, "نقاط دخول حسّاسة بلا خنق:\n  " . implode("\n  ", $missing));
    }

    /**
     * أسماء الحُرّاس المستعملة كلها معروفة للراوتر.
     *
     * Router::runMiddleware يرمي عند اسم مجهول — وهو السلوك الصحيح، إذ
     * حارس مكتوب خطأً يعني مساراً بلا حماية. لكن الرمي يحدث **وقت
     * الطلب**، أي أن أول من يكتشفه زائر. هذا الاختبار يكتشفه وقت البناء.
     */
    public function testEveryDeclaredGuardNameIsRecognised(): void
    {
        $unknown = [];

        foreach (self::guardsInRouteTable() as $action => $guard) {
            $known = $guard === 'auth'
                || $guard === 'admin'
                || str_starts_with($guard, 'perm:')
                || str_starts_with($guard, 'throttle:');

            if (!$known) {
                $unknown[] = "{$action} → [{$guard}]";
            }
        }

        $this->assertSame([], $unknown, "أسماء حُرّاس لا يعرفها الراوتر:\n  " . implode("\n  ", $unknown));
    }

    /**
     * أسماء الصلاحيات موجودة فعلاً في جدول admin_permissions.
     *
     * صلاحية مكتوبة خطأً (can_manage_order بدل can_manage_orders) تمرّ
     * صامتة: hasPermission تقرأ مفتاحاً غير موجود فتُرجع false، فيُمنع
     * كل أدمن عدا رتبة A — وتبدو المشكلة «صلاحيات لا تعمل» لا «خطأ
     * إملائي».
     */
    public function testPermissionNamesExistInTheDatabaseSchema(): void
    {
        $schema = (string) file_get_contents(self::root() . '/tests/fixtures/schema.sql');

        $unknown = [];
        foreach (self::guardsInRouteTable() as $action => $guard) {
            if (!str_starts_with($guard, 'perm:')) {
                continue;
            }

            $permission = substr($guard, 5);
            if (!str_contains($schema, '`' . $permission . '`')) {
                $unknown[] = "{$action} → [{$permission}]";
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "أسماء صلاحيات لا عمود لها في admin_permissions:\n  " . implode("\n  ", $unknown)
        );
    }
}
