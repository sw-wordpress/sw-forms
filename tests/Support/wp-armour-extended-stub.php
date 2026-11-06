<?php

// Extended 1.41 delegates to a recursive blocked-word check.
function wpae_check_is_spam($data): bool
{
    foreach ($data as $value) {
        if (is_array($value) ? wpae_check_is_spam($value) : (is_string($value) && stripos($value, 'blocked') !== false)) {
            return true;
        }
    }
    return false;
}
