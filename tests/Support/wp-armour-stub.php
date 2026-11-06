<?php

// Contract of WP Armour 2.4.02. Kept outside the unit test directory.
function wpa_check_is_spam($data): bool
{
    if (isset($data[$GLOBALS['wpa_field_name']], $data['alt_s']) && empty($data['alt_s'])) {
        return function_exists('wpae_check_is_spam') ? wpae_check_is_spam($data) : false;
    }
    return true;
}

function do_action(...$arguments): void
{
    $GLOBALS['armour_events'][] = $arguments;
}
