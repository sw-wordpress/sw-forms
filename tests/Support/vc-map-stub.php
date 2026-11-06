<?php

/** Captures the optional WPBakery API contract without installing the editor in CI. */
function vc_map(array $definition): void
{
    $GLOBALS['swf_test_vc_map'] = $definition;
}
