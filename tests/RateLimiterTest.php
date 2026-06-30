<?php

use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase {

    protected function setUp(): void {
        global $transients, $redaquest_filters;
        $transients = array();
        $redaquest_filters = array();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    public function test_allows_requests_under_limit() {
        add_filter('redaquest_rate_limit_per_minute', function () {
            return 5;
        });

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(Redaquest_Rate_Limiter::check('under-limit-key'));
        }
    }

    public function test_blocks_requests_over_limit() {
        add_filter('redaquest_rate_limit_per_minute', function () {
            return 2;
        });

        $this->assertTrue(Redaquest_Rate_Limiter::check('over-limit-key'));
        $this->assertTrue(Redaquest_Rate_Limiter::check('over-limit-key'));
        $result = Redaquest_Rate_Limiter::check('over-limit-key');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_disabled_when_limit_zero() {
        add_filter('redaquest_rate_limit_per_minute', function () {
            return 0;
        });

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(Redaquest_Rate_Limiter::check('disabled-key'));
        }
    }
}
