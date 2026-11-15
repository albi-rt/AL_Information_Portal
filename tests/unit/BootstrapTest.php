<?php
declare(strict_types=1);

namespace NSW\Tests\Unit;

final class BootstrapTest extends TestCase {

	public function test_theme_loads_every_inc_file_and_defines_its_version(): void {
		$this->assertSame( '0.11.0', NSW_THEME_VERSION );
		$this->assertTrue( function_exists( 'nsw_theme_contact_create_mantis_issue' ) );
		$this->assertNotEmpty( nsw_test_hook_registrations( 'rest_api_init' ) );
	}
}
