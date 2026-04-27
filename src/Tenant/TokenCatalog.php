<?php
declare(strict_types=1);

namespace App\Tenant;

/**
 * Single source of truth for which tokens the template editor advertises
 * in its "Insert token" menu and which are used in the simulator's sample
 * data.
 *
 * Adding a Graph property to GraphClient::USER_SELECT exposes it as
 * `{snake_case}` automatically; adding it here surfaces it as a labelled
 * entry in the editor menu.
 */
final class TokenCatalog
{
    /**
     * @return list<array{group:string,items:list<array{token:string,label:string}>}>
     */
    public static function all(): array
    {
        return [
            [
                'group' => 'Identity',
                'items' => [
                    ['token' => '{display_name}',         'label' => 'Display name'],
                    ['token' => '{first_name}',           'label' => 'First name (given_name)'],
                    ['token' => '{last_name}',            'label' => 'Last name (surname)'],
                    ['token' => '{middle_name}',          'label' => 'Middle name'],
                    ['token' => '{employee_id}',          'label' => 'Employee ID'],
                    ['token' => '{employee_type}',        'label' => 'Employee type'],
                ],
            ],
            [
                'group' => 'Job',
                'items' => [
                    ['token' => '{job_title}',            'label' => 'Job title (raw)'],
                    ['token' => '{job_title_line}',       'label' => 'Job title (display variant)'],
                    ['token' => '{department}',           'label' => 'Department'],
                    ['token' => '{company_name}',         'label' => 'Company name'],
                    ['token' => '{office_location}',      'label' => 'Office / location'],
                ],
            ],
            [
                'group' => 'Contact',
                'items' => [
                    ['token' => '{email}',                'label' => 'Email (display)'],
                    ['token' => '{mail}',                 'label' => 'Primary mail (raw Graph value)'],
                    ['token' => '{user_principal_name}',  'label' => 'User principal name (UPN)'],
                    ['token' => '{mobile_phone}',         'label' => 'Mobile phone'],
                    ['token' => '{business_phones}',      'label' => 'Business phones (comma-joined)'],
                    ['token' => '{fax_number}',           'label' => 'Fax number'],
                    ['token' => '{phone_lines}',          'label' => 'Phone lines (formatted: Tel/Mob)'],
                ],
            ],
            [
                'group' => 'Address',
                'items' => [
                    ['token' => '{street_address}',       'label' => 'Street'],
                    ['token' => '{postal_code}',          'label' => 'Postal code'],
                    ['token' => '{city}',                 'label' => 'City'],
                    ['token' => '{state}',                'label' => 'State'],
                    ['token' => '{country}',              'label' => 'Country'],
                    ['token' => '{full_address}',         'label' => 'Full address (one line)'],
                ],
            ],
            [
                'group' => 'Other',
                'items' => [
                    ['token' => '{preferred_language}',   'label' => 'Preferred language (BCP-47)'],
                    ['token' => '{about_me}',             'label' => 'About me'],
                    ['token' => '{usage_location}',       'label' => 'Usage location'],
                ],
            ],
        ];
    }
}
