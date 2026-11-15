<?php
/**
 * Unit tests for inc/hero-media.php: the background video and poster image of the home
 * page hero.
 *
 * The theme ships a default video and poster. They are copied once into the Media
 * Library, so editors can replace them, and the Media Library numbers are kept in the
 * settings. WordPress and the Media Library are never used: their functions are
 * simulated in each test.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * section 2.3.3 (the stored video, poster and import flag), and from the Software
 * Development Package (SDP), sections 2.5 (the shipped video and image) and 5.4, step 9
 * (the import on the first administration page load).
 *
 * The copying itself cannot run in a unit test: it loads WordPress administration files
 * (wp-admin/includes/file.php and image.php) that are not part of the theme.
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\TestDox;

final class HeroMediaTest extends TestCase {

	private const THEME_VIDEO  = 'https://nsw.test/wp-content/themes/nsw-theme/assets/video/hero.mp4';
	private const THEME_POSTER = 'https://nsw.test/wp-content/themes/nsw-theme/assets/images/hero/hero-bg.jpg';

	/** Simulates the stored settings: option name => value. Missing options are false. */
	private function with_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => array_key_exists( $name, $options ) ? $options[ $name ] : $default
		);
	}

	/* ------------------------------------------------------------ the background */

	/** Source: TSD 2.3.3, nsw_theme_hero_video_id, "Home video and poster". */
	#[TestDox('The home page background is the video stored in the Media Library')]
	public function test_background_from_media_library(): void {
		$this->with_options( array( 'nsw_theme_hero_video_id' => '31' ) );
		Functions\expect( 'wp_get_attachment_url' )->once()->with( 31 )->andReturn( 'https://nsw.test/wp-content/uploads/2026/08/nsw-hero-background.mp4' );
		Functions\expect( 'get_post_mime_type' )->once()->with( 31 )->andReturn( 'video/mp4' );

		$this->assertSame(
			array(
				'url'  => 'https://nsw.test/wp-content/uploads/2026/08/nsw-hero-background.mp4',
				'id'   => 31,
				'mime' => 'video/mp4',
			),
			nsw_theme_hero_media_default()
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An image chosen as the home page background is used as an image')]
	public function test_background_image(): void {
		$this->with_options( array( 'nsw_theme_hero_video_id' => 32 ) );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://nsw.test/wp-content/uploads/2026/09/port.jpg' );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );

		$media = nsw_theme_hero_media_default();
		$this->assertSame( 'image/jpeg', $media['mime'] );
		$this->assertSame( 32, $media['id'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A stored background without a known file type is treated as a video')]
	public function test_background_without_type(): void {
		$this->with_options( array( 'nsw_theme_hero_video_id' => 33 ) );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://nsw.test/wp-content/uploads/2026/09/clip' );
		Functions\when( 'get_post_mime_type' )->justReturn( false );

		$this->assertSame( 'video/mp4', nsw_theme_hero_media_default()['mime'] );
	}

	/** Source: SDP 2.5, the video "assets/video/hero.mp4" is shipped with the theme. */
	#[TestDox('When the stored background was deleted from the Media Library, the video shipped with the theme is shown')]
	public function test_background_deleted_uses_theme_video(): void {
		$this->with_options( array( 'nsw_theme_hero_video_id' => 34 ) );
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		Functions\expect( 'get_post_mime_type' )->never();

		$this->assertSame( array( 'url' => self::THEME_VIDEO, 'id' => 0, 'mime' => 'video/mp4' ), nsw_theme_hero_media_default() );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/video/hero.mp4' );
	}

	/** Source: SDP 2.5, the video "assets/video/hero.mp4" is shipped with the theme. */
	#[TestDox('When no background was ever stored, the video shipped with the theme is shown')]
	public function test_no_background_uses_theme_video(): void {
		$this->with_options( array() );
		Functions\expect( 'wp_get_attachment_url' )->never();

		$this->assertSame( array( 'url' => self::THEME_VIDEO, 'id' => 0, 'mime' => 'video/mp4' ), nsw_theme_hero_media_default() );
	}

	/* ---------------------------------------------------------------- the poster */

	/** Source: TSD 2.3.3, nsw_theme_hero_poster_id, "Home video and poster". */
	#[TestDox('The poster shown before the video plays is the image stored in the Media Library')]
	public function test_poster_from_media_library(): void {
		$this->with_options( array( 'nsw_theme_hero_poster_id' => '35' ) );
		Functions\expect( 'wp_get_attachment_url' )->once()->with( 35 )->andReturn( 'https://nsw.test/wp-content/uploads/2026/08/nsw-hero-poster.jpg' );

		$this->assertSame( 'https://nsw.test/wp-content/uploads/2026/08/nsw-hero-poster.jpg', nsw_theme_hero_poster_url() );
	}

	/** Source: SDP 2.5, the image "assets/images/hero/hero-bg.jpg, also the video poster". */
	#[TestDox('Without a stored poster, the home page image shipped with the theme is the poster')]
	public function test_no_poster_uses_theme_image(): void {
		$this->with_options( array() );
		Functions\expect( 'wp_get_attachment_url' )->never();

		$this->assertSame( self::THEME_POSTER, nsw_theme_hero_poster_url() );
		$this->assertFileExists( NSW_TEST_THEME_DIR . 'assets/images/hero/hero-bg.jpg' );
	}

	/** Source: SDP 2.5, the image "assets/images/hero/hero-bg.jpg, also the video poster". */
	#[TestDox('When the stored poster was deleted from the Media Library, the image shipped with the theme is the poster')]
	public function test_deleted_poster_uses_theme_image(): void {
		$this->with_options( array( 'nsw_theme_hero_poster_id' => 36 ) );
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );

		$this->assertSame( self::THEME_POSTER, nsw_theme_hero_poster_url() );
	}

	/* ---------------------------------------------------------------- the import */

	/** Source: SDP 5.4, step 9, "Open wp-admin ... (first installation: also the hero media import)". */
	#[TestDox('The home page media are imported when the administration area is opened')]
	public function test_import_runs_on_administration_load(): void {
		$callbacks = array_column( nsw_test_hook_registrations( 'admin_init' ), 'callback' );
		$this->assertContains( 'nsw_theme_import_hero_media', $callbacks );
	}

	/** Source: TSD 2.3.3, nsw_theme_hero_media_imported, "import flag". */
	#[TestDox('Once the home page media were imported, they are not imported again')]
	public function test_import_runs_once(): void {
		$asked = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$asked ) {
				$asked[] = $name;
				return 'nsw_theme_hero_media_imported' === $name ? 1 : $default;
			}
		);
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_upload_bits' )->never();
		Functions\expect( 'wp_insert_attachment' )->never();

		nsw_theme_import_hero_media();

		$this->assertSame( array( 'nsw_theme_hero_media_imported' ), $asked );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A theme file that does not exist is not copied to the Media Library')]
	public function test_missing_file_not_copied(): void {
		Functions\expect( 'wp_upload_bits' )->never();

		$this->assertSame( 0, nsw_theme_sideload_file( NSW_TEST_THEME_DIR . 'assets/video/missing-example.mp4', 'missing-example.mp4', 'video/mp4' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A theme file that cannot be read is not copied to the Media Library')]
	public function test_unreadable_file_not_copied(): void {
		Functions\when( 'is_readable' )->justReturn( false );
		Functions\expect( 'wp_upload_bits' )->never();

		$this->assertSame( 0, nsw_theme_sideload_file( NSW_TEST_THEME_DIR . 'assets/video/hero.mp4', 'nsw-hero-background.mp4', 'video/mp4' ) );
	}
}
