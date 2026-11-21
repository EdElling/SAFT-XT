<?php

declare(strict_types=1);

return [
    'title' => 'Global settings',
    'branding' => 'Branding',
    'app_name' => 'Display name of the application',
    'app_name_help' => 'Shown in the header, the browser tab, on the sign-in page and as the sender of emails.',
    'logo' => 'Logo',
    'logo_help' => 'Shown next to the name in the header and on the login page. PNG, JPG or WebP, at most 1 MB, ideally square. Without an own logo SAFT shows the juice drop.',
    'logo_as_favicon' => 'Also use the logo as the icon in the browser tab',
    'logo_as_favicon_help' => 'Otherwise the tab shows the SAFT juice drop.',
    'appearance' => 'Appearance',
    'color_scheme' => 'Colour scheme',
    'color_scheme_help' => 'Main colour for everyone: buttons, active menu entries, links and the marker of the selected row. Background and text stay unchanged.',
    'swatch_button' => 'Buttons',
    'swatch_highlight' => 'Selected row',
    'color_schemes' => [
        'indigo' => 'Indigo',
        'hfh_red' => 'Red (HfH)',
        'green' => 'Green',
        'gray' => 'Grey',
    ],
];
