<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
namespace Yoast\WP\SEO\Tests\Unit\AI\Generator\Domain\Usage_Parameters;

use Mockery;
use WP_User;
use Yoast\WP\SEO\AI\Generator\Domain\Usage_Parameters;
use Yoast\WP\SEO\Tests\Unit\TestCase;

/**
 * Tests the Usage_Parameters constructor and getters.
 *
 * @group ai-generator
 *
 * @covers \Yoast\WP\SEO\AI\Generator\Domain\Usage_Parameters::__construct
 * @covers \Yoast\WP\SEO\AI\Generator\Domain\Usage_Parameters::get_user
 * @covers \Yoast\WP\SEO\AI\Generator\Domain\Usage_Parameters::is_unlimited
 */
final class Usage_Parameters_Test extends TestCase {

	/**
	 * Tests the constructor populates all fields and the getters return them.
	 *
	 * @return void
	 */
	public function test_constructor_populates_all_fields() {
		$user = Mockery::mock( WP_User::class );

		$parameters = new Usage_Parameters( $user, true );

		$this->assertSame( $user, $parameters->get_user() );
		$this->assertTrue( $parameters->is_unlimited() );
	}

	/**
	 * Tests the is_unlimited getter returns false when set to false.
	 *
	 * @return void
	 */
	public function test_is_unlimited_returns_false_when_set_false() {
		$user = Mockery::mock( WP_User::class );

		$parameters = new Usage_Parameters( $user, false );

		$this->assertFalse( $parameters->is_unlimited() );
	}
}
