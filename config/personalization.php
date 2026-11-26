<?php

return [
    /*
     * Private contact aliases (a name only its author sees). Off: everybody
     * sees the official name of the contact (App\Support\Personalization\ViewerAlias).
     */
    'contact_aliases' => (bool) env('CONTACT_ALIASES', false),
];
