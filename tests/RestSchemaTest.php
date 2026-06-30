<?php

use PHPUnit\Framework\TestCase;

class RestSchemaTest extends TestCase {

    public function test_validate_read_status_allows_known_values() {
        $this->assertTrue(Redaquest_REST_Schema::validate_read_status('publish'));
        $this->assertTrue(Redaquest_REST_Schema::validate_read_status('any'));
    }

    public function test_validate_read_status_rejects_unknown_values() {
        $this->assertFalse(Redaquest_REST_Schema::validate_read_status('private'));
        $this->assertFalse(Redaquest_REST_Schema::validate_read_status('trash'));
    }

    public function test_validate_write_status_allows_known_values() {
        $this->assertTrue(Redaquest_REST_Schema::validate_write_status('draft'));
        $this->assertTrue(Redaquest_REST_Schema::validate_write_status('publish'));
    }

    public function test_validate_per_page_caps_at_100() {
        $this->assertTrue(Redaquest_REST_Schema::validate_per_page(100));
        $this->assertFalse(Redaquest_REST_Schema::validate_per_page(101));
    }
}
