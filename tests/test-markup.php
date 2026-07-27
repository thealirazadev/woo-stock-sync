<?php
/**
 * Unit tests for the price markup adjustment helper (pure logic; no WordPress required).
 *
 * @package WooStockSync
 */

/**
 * Price markup math, rounding, pass-through, and the non-negative guard.
 *
 * @covers ::wss_adjust_price
 */
class Test_Markup extends \PHPUnit\Framework\TestCase {

	public function test_percentage_markup_math() {
		$this->assertSame( '11.50', wss_adjust_price( '10.00', 'percentage', 15 ) );
		$this->assertSame( '12.00', wss_adjust_price( '8.00', 'percentage', 50 ) );
	}

	public function test_fixed_markup_math() {
		$this->assertSame( '15.00', wss_adjust_price( '10.00', 'fixed', 5 ) );
		$this->assertSame( '10.49', wss_adjust_price( '9.99', 'fixed', 0.5 ) );
	}

	public function test_rounding_to_two_decimals() {
		// 9.99 * 1.15 = 11.4885 -> 11.49.
		$this->assertSame( '11.49', wss_adjust_price( '9.99', 'percentage', 15 ) );
		// 3.33 * 1.10 = 3.663 -> 3.66.
		$this->assertSame( '3.66', wss_adjust_price( '3.33', 'percentage', 10 ) );
	}

	public function test_custom_decimal_places() {
		$this->assertSame( '11.4885', wss_adjust_price( '9.99', 'percentage', 15, 4 ) );
		$this->assertSame( '12', wss_adjust_price( '10', 'fixed', 2, 0 ) );
	}

	public function test_zero_value_is_pass_through() {
		// A zero amount returns the raw value untouched, byte for byte.
		$this->assertSame( '10.00', wss_adjust_price( '10.00', 'percentage', 0 ) );
		$this->assertSame( '9.9', wss_adjust_price( '9.9', 'fixed', 0 ) );
	}

	public function test_none_type_is_pass_through() {
		$this->assertSame( '19.99', wss_adjust_price( '19.99', 'none', 15 ) );
		$this->assertSame( '19.99', wss_adjust_price( '19.99', '', 15 ) );
	}

	public function test_negative_result_is_clamped_to_zero() {
		// Fixed markdown larger than the price.
		$this->assertSame( '0.00', wss_adjust_price( '10.00', 'fixed', -25 ) );
		// Percentage markdown beyond -100%.
		$this->assertSame( '0.00', wss_adjust_price( '10.00', 'percentage', -200 ) );
	}

	public function test_negative_markup_reduces_price_without_going_below_zero() {
		$this->assertSame( '9.00', wss_adjust_price( '10.00', 'fixed', -1 ) );
		$this->assertSame( '8.50', wss_adjust_price( '10.00', 'percentage', -15 ) );
	}

	public function test_empty_and_non_numeric_prices_pass_through() {
		$this->assertSame( '', wss_adjust_price( '', 'percentage', 15 ) );
		$this->assertSame( 'not-a-price', wss_adjust_price( 'not-a-price', 'fixed', 5 ) );
	}
}
