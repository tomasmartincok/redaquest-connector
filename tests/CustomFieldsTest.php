<?php

use PHPUnit\Framework\TestCase;

class CustomFieldsTest extends TestCase {

    public function test_rejects_private_meta_keys() {
        $this->assertFalse(Redaquest_Custom_Fields::is_exportable_meta_key('_secret'));
    }

    public function test_rejects_sensitive_substrings() {
        $this->assertFalse(Redaquest_Custom_Fields::is_exportable_meta_key('client_api_key'));
        $this->assertFalse(Redaquest_Custom_Fields::is_exportable_meta_key('user_password'));
    }

    public function test_allows_public_meta_keys() {
        $this->assertTrue(Redaquest_Custom_Fields::is_exportable_meta_key('subtitle'));
        $this->assertTrue(Redaquest_Custom_Fields::is_exportable_meta_key('hero_text'));
    }

    public function test_sanitize_value_preserves_arrays() {
        $input = array('a' => 'hello', 'b' => array('nested' => 'world'));
        $result = Redaquest_Custom_Fields::sanitize_value($input);
        $this->assertSame('hello', $result['a']);
        $this->assertSame('world', $result['b']['nested']);
    }

    public function test_sanitize_value_strips_html_when_not_allowed() {
        $result = Redaquest_Custom_Fields::sanitize_scalar('plain text');
        $this->assertSame('plain text', $result);
    }
}
