<?php

namespace App\Models;

use App\Core\Model;

/**
 * AboutModel — يوفّر بيانات صفحة "About Us"
 * البيانات الثابتة مُعرَّفة هنا (يمكن ربطها بـ website_settings لاحقاً)
 */
class AboutModel extends Model
{
    /**
     * يُرجع معلومات المتجر الثابتة
     *
     * @return array<string, mixed>
     */
    public function getStoreInfo(): array
    {
        return [
            'founded'      => 2020,
            'location'     => 'Cairo, Egypt',
            'employees'    => 50,
            'phone'        => '+20 123 456 789',
            'workingHours' => 'Sun - Thu: 9 AM - 6 PM',
        ];
    }
}
