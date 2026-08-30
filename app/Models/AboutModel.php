<?php

namespace App\Models;

use App\Core\Model;

/**
 * AboutModel — supplies the data for the "About Us" page.
 * The static details are defined here (they can be tied to website_settings later).
 */
class AboutModel extends Model
{
    /**
     * Returns the store's static details.
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
