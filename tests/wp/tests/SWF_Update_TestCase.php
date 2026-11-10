<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress PHPUnit conventions.

abstract class SWF_Update_TestCase extends WP_UnitTestCase
{
    protected int $admin_id;
    public function set_up(): void
    {
        parent::set_up();
        $this->admin_id = self::factory()->user->create(array( 'role' => 'administrator' ));
        wp_set_current_user($this->admin_id);
        $_GET = $_POST = $_REQUEST = array();
    }
    public function tear_down(): void
    {
        $_GET = $_POST = $_REQUEST = array();
        parent::tear_down();
    }
}
