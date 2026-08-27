<?php

namespace App\Core;

/**
 * Route — مسار واحد مسجَّل، وواجهة ضبطه.
 *
 * وُجد هذا الكلاس كي يصير التسجيل قابلاً للسلسلة:
 *
 *     $r->post('/admin/products/delete', [C::class, 'delete'])
 *       ->middleware('perm:can_manage_products')
 *       ->name('admin.products.delete');
 *
 * كان الراوتر يخزّن المسارات مصفوفاتٍ ترابطية عارية، فلا مكان يُعلَّق
 * عليه شيء: لا حارس ولا اسم. والمصفوفة لا تُخطئ خطأً مفهوماً — مفتاح
 * مكتوب خطأً يصير مفتاحاً جديداً صامتاً، بينما استدعاء دالة غير موجودة
 * على كائن يرمي فوراً.
 */
final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    /**
     * @param string               $method  GET | POST | PUT | PATCH | DELETE
     * @param string               $path    مسار بنمط /admin/users أو /product/{id}
     * @param callable|array{class-string,string} $handler
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly mixed $handler
    ) {
    }

    /**
     * يضيف حارساً أو أكثر يعمل **قبل** بناء الكنترولر.
     *
     * الترتيب مقصود: الحارس يعمل قبل `new $controllerClass()`. وهذا فرق
     * جوهري عن استدعاء Middleware داخل جسم الفعل — هناك يكون الكنترولر
     * قد بُني وربما نفّذ عملاً في الباني قبل أن يُسأل عن الصلاحية.
     *
     * الأسماء المدعومة:
     *   'auth'                   → مستخدم مسجّل الدخول
     *   'admin'                  → أدمن مسجّل الدخول
     *   'perm:<permission_name>' → أدمن يملك الصلاحية (رتبة A تتجاوزها)
     */
    public function middleware(string ...$names): self
    {
        foreach ($names as $name) {
            $this->middleware[] = $name;
        }

        return $this;
    }

    /**
     * يسمّي المسار كي يُبنى رابطه من الاسم لا من نصّ مكتوب.
     *
     * لماذا؟ لأن URLROOT . '/admin/users' مكتوبة في 63 ملفاً. تغيير
     * مسار واحد يعني تعقّبه في كل موضع، وما يُنسى منها يصير رابطاً
     * مكسوراً لا يكتشفه إلا زائر.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /** @return list<string> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
