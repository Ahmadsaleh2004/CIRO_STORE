<?php

namespace App\Core;

/**
 * Validator — استخراج المدخلات والتحقّق منها في موضع واحد.
 *
 * ── ما كان قبله ─────────────────────────────────────────────
 *
 * الاستخراج والتحقّق مكتوبان يدوياً في كل فعل، مقيساً:
 *
 *     $_POST[...] ?? ...    88 موضعاً
 *     trim($_POST[...])     38
 *     (int)$_POST[...]      26
 *
 * ونتيجة التكرار ليست الطول بل **التفرّق**: حقل يُقصّ في موضع ولا
 * يُقصّ في آخر، ويُفحص طوله هنا ولا يُفحص هناك. والقاعدة الوحيدة التي
 * تُطبَّق في تسعة مواضع من عشرة ليست قاعدة.
 *
 * ── التصميم ────────────────────────────────────────────────
 *
 * هذا الكلاس **نقيّ**: لا يقرأ $_POST، ولا يطبع، ولا يوقف التنفيذ.
 * يأخذ مصفوفة ويُرجع نتيجة. ولهذا يُختبَر بلا خادم ولا جلسة ولا شبكة.
 *
 * والربط بالطلب في Controller::validate() — سطران يمرّران requestData()
 * ويردّان بأول خطأ. الفصل مقصود: منطق التحقّق يجب أن يكون قابلاً
 * للاختبار وحده، وقد كان هذا بالضبط ما يمنع اختباره حين كان مبعثراً
 * في أجسام الأفعال.
 */
final class Validator
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, string> اسم الحقل => أول خطأ فيه */
    private array $errors = [];

    /** @var array<string, mixed> القيم بعد التطبيع */
    private array $clean = [];

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * يفحص المدخلات وفق القواعد.
     *
     * صيغة القاعدة نصّ مفصول بـ| مثل: 'required|string|min:2|max:50'
     *
     * القواعد المدعومة:
     *   required      لا يقبل الغياب ولا النصّ الفارغ بعد القصّ
     *   nullable      يقبل الغياب ويُرجع null
     *   string        يُقصّ الطرفان
     *   int           يُحوَّل إلى صحيح، ويُرفض ما ليس رقماً
     *   numeric       رقم (يقبل العشري)
     *   email         بريد صالح
     *   bool          يُحوَّل: '1' 'true' 'on' 'yes' => true
     *   array         مصفوفة
     *   min:N         الطول الأدنى للنصّ، أو القيمة الدنيا للرقم
     *   max:N         الطول الأقصى للنصّ، أو القيمة القصوى للرقم
     *   in:a,b,c      من قائمة
     *   default:X     قيمة عند الغياب (تُطبَّق قبل بقية القواعد)
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

    /** أول رسالة خطأ، أو null إن نجح الفحص. */
    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }

        return null;
    }

    /**
     * القيم بعد التطبيع — الحقول التي مرّت وحدها.
     *
     * ⚠️ تُرجع ما فُحص فقط، لا كل المدخلات. حقل لم يُذكر في القواعد لا
     * يظهر هنا. وهذا مقصود: تمرير ما لم يُفحص إلى المودل هو بالضبط
     * الباب الذي يدخل منه ما لم يُتوقّع.
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

        // default قبل كل شيء: قيمة الغياب تُفحص كأي قيمة أخرى.
        if (($value === null || $value === '') && isset($args['default'])) {
            $value = $args['default'];
        }

        $present = $value !== null && $value !== '';

        if (!$present) {
            if (in_array('required', $names, true)) {
                $this->errors[$field] = $this->label($field) . ' is required.';
                return;
            }

            // nullable أو حقل اختياري بلا قيمة: يُسجَّل null ولا يُفحص.
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
                    // filter_var لا ctype_digit: الأخيرة ترفض السالب،
                    // و(int) وحدها تحوّل 'abc' إلى 0 بصمت — وهو أسوأ ما
                    // يمكن أن يفعله تحقّق.
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

    /** الحدّ يقارن الطول للنصوص والقيمة للأرقام — وهذا ما يتوقّعه القارئ. */
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
            // mb_strlen لا strlen: الاسم العربي «أحمد» خمسة محارف
            // وعشرة بايتات. الفحص بالبايت يرفض أسماء صالحة.
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

    /** 'full_address' => 'Full address' — رسالة تُقرأ لا مفتاح خام. */
    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
