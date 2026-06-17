<?php

namespace Yoast\WP\SEO\Tests\Unit\MyYoast_Client\User_Interface;

use Brain\Monkey;
use Mockery;
use Yoast\WP\SEO\Conditionals\MyYoast_Connection_Conditional;
use Yoast\WP\SEO\MyYoast_Client\User_Interface\Integrations_Page_Script_Data;
use Yoast\WP\SEO\MyYoast_Client\User_Interface\Status_Presenter;
use Yoast\WP\SEO\Tests\Unit\TestCase;

/**
 * Tests the Integrations_Page_Script_Data provider.
 *
 * @coversDefaultClass \Yoast\WP\SEO\MyYoast_Client\User_Interface\Integrations_Page_Script_Data
 */
final class Integrations_Page_Script_Data_Test extends TestCase {

	/**
	 * The status presenter mock.
	 *
	 * @var Status_Presenter|Mockery\MockInterface
	 */
	private $status_presenter;

	/**
	 * The MyYoast connection conditional mock.
	 *
	 * @var MyYoast_Connection_Conditional|Mockery\MockInterface
	 */
	private $myyoast_connection_conditional;

	/**
	 * The instance under test.
	 *
	 * @var Integrations_Page_Script_Data
	 */
	private $instance;

	/**
	 * Sets up the test fixtures.
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();

		$this->status_presenter               = Mockery::mock( Status_Presenter::class );
		$this->myyoast_connection_conditional = Mockery::mock( MyYoast_Connection_Conditional::class );
		$this->instance                       = new Integrations_Page_Script_Data(
			$this->status_presenter,
			$this->myyoast_connection_conditional,
		);
	}

	/**
	 * Tests the payload is returned when the feature flag is enabled and no
	 * callback outcome transient is pending.
	 *
	 * @covers ::__construct
	 * @covers ::present
	 * @covers ::consume_callback_outcome
	 *
	 * @return void
	 */
	public function test_present_when_enabled() {
		$status = [
			'is_provisioned'    => true,
			'is_registered'     => false,
			'registered_at'     => null,
			'registered_at_iso' => null,
			'redirect_uris'     => [],
		];

		$this->myyoast_connection_conditional->shouldReceive( 'is_met' )->andReturn( true );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $status );

		Monkey\Functions\expect( 'admin_url' )
			->with( 'profile.php' )
			->andReturn( 'https://example.com/wp-admin/profile.php' );
		Monkey\Functions\expect( 'get_current_user_id' )->andReturn( 42 );
		Monkey\Functions\expect( 'get_transient' )
			->with( 'wpseo_myyoast_oauth_outcome_42' )
			->andReturn( false );

		$result = $this->instance->present();

		$this->assertIsArray( $result );
		$this->assertSame( $status, $result['initialStatus'] );
		$this->assertSame( 'https://example.com/wp-admin/profile.php', $result['profileUrl'] );
		$this->assertNull( $result['callbackOutcome'] );
	}

	/**
	 * Tests the callback outcome transient is read and consumed.
	 *
	 * @covers ::present
	 * @covers ::consume_callback_outcome
	 *
	 * @return void
	 */
	public function test_present_consumes_callback_outcome() {
		$status = [
			'is_provisioned'    => true,
			'is_registered'     => true,
			'registered_at'     => 1_731_369_600,
			'registered_at_iso' => '2024-11-12T00:00:00+00:00',
			'redirect_uris'     => [],
		];

		$this->myyoast_connection_conditional->shouldReceive( 'is_met' )->andReturn( true );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $status );

		Monkey\Functions\expect( 'admin_url' )
			->with( 'profile.php' )
			->andReturn( 'https://example.com/wp-admin/profile.php' );
		Monkey\Functions\expect( 'get_current_user_id' )->andReturn( 7 );
		Monkey\Functions\expect( 'get_transient' )
			->with( 'wpseo_myyoast_oauth_outcome_7' )
			->andReturn(
				[
					'kind' => 'success',
					'key'  => 'verify_success',
				],
			);
		Monkey\Functions\expect( 'delete_transient' )
			->once()
			->with( 'wpseo_myyoast_oauth_outcome_7' );

		$result = $this->instance->present();

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				'kind' => 'success',
				'key'  => 'verify_success',
			],
			$result['callbackOutcome'],
		);
	}

	/**
	 * Tests a malformed transient payload is ignored.
	 *
	 * @covers ::consume_callback_outcome
	 *
	 * @return void
	 */
	public function test_present_ignores_malformed_callback_outcome() {
		$status = [
			'is_provisioned'    => true,
			'is_registered'     => false,
			'registered_at'     => null,
			'registered_at_iso' => null,
			'redirect_uris'     => [],
		];

		$this->myyoast_connection_conditional->shouldReceive( 'is_met' )->andReturn( true );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $status );

		Monkey\Functions\expect( 'admin_url' )
			->with( 'profile.php' )
			->andReturn( 'https://example.com/wp-admin/profile.php' );
		Monkey\Functions\expect( 'get_current_user_id' )->andReturn( 9 );
		Monkey\Functions\expect( 'get_transient' )
			->with( 'wpseo_myyoast_oauth_outcome_9' )
			->andReturn( [ 'kind' => 'success' ] );
		Monkey\Functions\expect( 'delete_transient' )
			->once()
			->with( 'wpseo_myyoast_oauth_outcome_9' );

		$result = $this->instance->present();

		$this->assertNull( $result['callbackOutcome'] );
	}

	/**
	 * Tests the callback outcome is not consulted when no user is logged in.
	 *
	 * @covers ::consume_callback_outcome
	 *
	 * @return void
	 */
	public function test_present_skips_transient_lookup_without_user() {
		$status = [
			'is_provisioned'    => true,
			'is_registered'     => false,
			'registered_at'     => null,
			'registered_at_iso' => null,
			'redirect_uris'     => [],
		];

		$this->myyoast_connection_conditional->shouldReceive( 'is_met' )->andReturn( true );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $status );

		Monkey\Functions\expect( 'admin_url' )
			->with( 'profile.php' )
			->andReturn( 'https://example.com/wp-admin/profile.php' );
		Monkey\Functions\expect( 'get_current_user_id' )->andReturn( 0 );

		$result = $this->instance->present();

		$this->assertNull( $result['callbackOutcome'] );
	}

	/**
	 * Tests `null` is returned when the feature flag is disabled.
	 *
	 * @covers ::present
	 *
	 * @return void
	 */
	public function test_present_when_disabled() {
		$this->myyoast_connection_conditional->shouldReceive( 'is_met' )->andReturn( false );
		$this->status_presenter->shouldNotReceive( 'present' );

		$this->assertNull( $this->instance->present() );
	}
}
