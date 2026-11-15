<?php
/**
 * Unit tests for inc/data.php: the helpers that read the website content for the
 * public pages.
 *
 * They read the agencies, partners, documents, events and FAQ items from the
 * database and shape them for the page templates, pick the text in the right
 * language, read the interface texts (English source, translated by Polylang) and
 * turn image paths into theme addresses. The database and Polylang are never used:
 * every WordPress reply is simulated.
 *
 * Expected behaviour comes from the Information Portal Technical Specification (TSD),
 * sections 2.1, 2.2.2, 2.2.3, 2.3.1 and 2.3.3. Where today's code differs from the TSD,
 * the test expects the TSD and is in the group "tsd-difference".
 *
 * Each list keeps its result for the rest of the page view, so the tests that read a
 * whole list run in a fresh PHP process. So do the tests that need Polylang, so that
 * its stand-in does not stay behind for the other tests.
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;

final class DataTest extends TestCase {

	/** The address of the theme folder, as set in the test bootstrap. */
	private const THEME = 'https://nsw.test/wp-content/themes/nsw-theme/';

	/** Uploads folder used for invented media addresses. */
	private const UPLOADS = 'https://nsw.test/wp-content/uploads/2026/11/';

	/** @var array<int, array<string, mixed>> Every list query, in the order it was made. */
	private array $queries = array();

	private bool $had_preview_locale = false;

	/** @var mixed */
	private $preview_locale_before = null;

	protected function setUp(): void {
		parent::setUp();
		$this->had_preview_locale    = array_key_exists( 'nsw_theme_preview_locale', $GLOBALS );
		$this->preview_locale_before = $GLOBALS['nsw_theme_preview_locale'] ?? null;
	}

	protected function tearDown(): void {
		if ( $this->had_preview_locale ) {
			$GLOBALS['nsw_theme_preview_locale'] = $this->preview_locale_before;
		} else {
			unset( $GLOBALS['nsw_theme_preview_locale'] );
		}
		parent::tearDown();
	}

	/* ------------------------------------------------------------------ helpers */

	private function post( int $id, array $fields = array() ): \WP_Post {
		return new \WP_Post( array_merge( array( 'ID' => $id ), $fields ) );
	}

	/**
	 * Serves post meta from a table [post id => [meta key => value]], the way WordPress
	 * does: a single value (or '') when asked for one, otherwise a list.
	 */
	private function given_meta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key = '', $single = false ) use ( $meta ) {
				$value = $meta[ $post_id ][ $key ] ?? '';
				if ( $single ) {
					return $value;
				}
				return '' === $value ? array() : array( $value );
			}
		);
	}

	/**
	 * Featured images [post id => full-size address]. Asking for another size gives a
	 * different address, so a test notices when the wrong size is used.
	 */
	private function given_featured_images( array $urls ): void {
		Functions\when( 'has_post_thumbnail' )->alias( static fn( $post_id ) => isset( $urls[ $post_id ] ) );
		Functions\when( 'get_the_post_thumbnail_url' )->alias(
			static function ( $post_id, $size = 'post-thumbnail' ) use ( $urls ) {
				if ( ! isset( $urls[ $post_id ] ) ) {
					return false;
				}
				return 'full' === $size ? $urls[ $post_id ] : 'https://nsw.test/wrong-image-size.jpg';
			}
		);
	}

	/** Category slugs [post id => slugs] for one taxonomy; any other request gets a marker. */
	private function given_terms( string $taxonomy, array $slugs ): void {
		Functions\when( 'wp_get_object_terms' )->alias(
			static function ( $post_id, $tax, $args = array() ) use ( $taxonomy, $slugs ) {
				if ( $tax !== $taxonomy || 'slugs' !== ( $args['fields'] ?? '' ) ) {
					return array( 'wrong-request' );
				}
				return $slugs[ $post_id ] ?? array();
			}
		);
	}

	/** Today's date on the site, as WordPress gives it. */
	private function given_today( string $date ): void {
		Functions\when( 'current_time' )->alias(
			static fn( $type ) => 'Y-m-d' === $type ? $date : $date . ' 09:00:00'
		);
	}

	/** The language of each post [post id => sq|en], as Polylang would say. */
	private function given_post_languages( array $languages ): void {
		Functions\when( 'nsw_theme_post_locale' )->alias( static fn( int $post_id ) => $languages[ $post_id ] ?? 'sq' );
	}

	/**
	 * Every content type exists and the database returns the given posts per type.
	 * Each query is recorded in $this->queries.
	 */
	private function given_database( array $posts_by_type ): void {
		$this->queries = array();
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( $posts_by_type ) {
				$this->queries[] = $args;
				return $posts_by_type[ $args['post_type'] ] ?? array();
			}
		);
	}

	/** Neutral replies for everything the lists read about each post. */
	private function given_plain_posts(): void {
		$this->given_meta( array() );
		$this->given_featured_images( array() );
		Functions\when( 'wp_get_object_terms' )->justReturn( array() );
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		Functions\when( 'wp_strip_all_tags' )->returnArg( 1 );
		$this->given_today( '2026-11-15' );
		$this->given_post_languages( array() );
	}

	/** Two posts of each listed type, with their address names. */
	private function two_posts_of_each_type(): array {
		return array(
			'nsw_agency'   => array( $this->post( 1, array( 'post_name' => 'agency-a' ) ), $this->post( 2, array( 'post_name' => 'agency-b' ) ) ),
			'nsw_partner'  => array( $this->post( 3, array( 'post_name' => 'partner-a' ) ), $this->post( 4, array( 'post_name' => 'partner-b' ) ) ),
			'nsw_document' => array( $this->post( 5, array( 'post_name' => 'document-a' ) ), $this->post( 6, array( 'post_name' => 'document-b' ) ) ),
			'nsw_event'    => array( $this->post( 7, array( 'post_name' => 'event-a' ) ), $this->post( 8, array( 'post_name' => 'event-b' ) ) ),
			'nsw_faq'      => array( $this->post( 9, array( 'post_name' => 'faq-a' ) ), $this->post( 10, array( 'post_name' => 'faq-b' ) ) ),
		);
	}

	/** Reads all five public lists once. */
	private function read_all_lists(): array {
		return array(
			'nsw_agency'   => nsw_theme_get_agencies(),
			'nsw_partner'  => nsw_theme_get_partners(),
			'nsw_document' => nsw_theme_get_documents(),
			'nsw_event'    => nsw_theme_get_events(),
			'nsw_faq'      => nsw_theme_get_faq(),
		);
	}

	/** The query made for one content type (the only one expected). */
	private function query_for( string $type ): array {
		$found = array_values( array_filter( $this->queries, static fn( $q ) => $q['post_type'] === $type ) );
		$this->assertCount( 1, $found, "Expected one query for $type." );
		return $found[0];
	}

	/** The English interface texts as shipped with the theme. */
	private function english_texts(): array {
		return (array) require NSW_TEST_THEME_DIR . 'languages/content-en.php';
	}

	/* -------------------------------------------------------- text per language */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A plain text that is not split by language is shown as it is')]
	public function test_plain_text_unchanged(): void {
		$this->assertSame( 'Customs declarations', nsw_theme_localized( 'Customs declarations', 'en' ) );
		$this->assertSame( '', nsw_theme_localized( '', 'sq' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The text in the requested language is shown')]
	public function test_text_in_requested_language(): void {
		$text = array( 'sq' => 'Agjencitë pjesëmarrëse', 'en' => 'Participating agencies' );
		$this->assertSame( 'Participating agencies', nsw_theme_localized( $text, 'en' ) );
		$this->assertSame( 'Agjencitë pjesëmarrëse', nsw_theme_localized( $text, 'sq' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When the requested language has no text, the Albanian text is shown')]
	public function test_falls_back_to_albanian(): void {
		$this->assertSame( 'Udhëzues', nsw_theme_localized( array( 'sq' => 'Udhëzues', 'en' => '' ), 'en' ) );
		$this->assertSame( 'Udhëzues', nsw_theme_localized( array( 'sq' => 'Udhëzues' ), 'en' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When neither the requested language nor Albanian has text, the English text is shown')]
	public function test_falls_back_to_english(): void {
		$this->assertSame( 'Guide', nsw_theme_localized( array( 'sq' => '', 'en' => 'Guide' ), 'sq' ) );
		$this->assertSame( 'Guide', nsw_theme_localized( array( 'en' => 'Guide' ), 'fr' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('When only another language has text, that text is shown, and an empty record shows nothing')]
	public function test_falls_back_to_any_language(): void {
		$this->assertSame( 'Guide pratique', nsw_theme_localized( array( 'fr' => 'Guide pratique' ), 'en' ) );
		$this->assertSame( '', nsw_theme_localized( array(), 'en' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Without a language given, the text in the language of the current page is shown')]
	public function test_uses_page_language_by_default(): void {
		$text = array( 'sq' => 'Ngjarje', 'en' => 'Events' );
		nsw_theme_set_preview_locale( 'en' );
		$this->assertSame( 'Events', nsw_theme_localized( $text ) );
		nsw_theme_set_preview_locale( 'sq' );
		$this->assertSame( 'Ngjarje', nsw_theme_localized( $text ) );
	}

	/* ------------------------------------------------------------------ agencies */

	/** Source: TSD 2.3.1, agency "id, name, colour, website, logo". */
	#[TestDox('An agency is read with its identifier, abbreviation, logo, name, description, documents, colour and website')]
	public function test_agency_record(): void {
		$this->given_meta(
			array(
				11 => array(
					'_nsw_theme_agency_id'      => 'agency-a',
					'_nsw_theme_agency_abbr'    => 'EFA',
					'_nsw_theme_agency_name'    => 'Example Food Agency',
					'_nsw_theme_agency_desc'    => 'Checks food imports at the border.',
					'_nsw_theme_agency_docs'    => "Import permit\nHealth certificate",
					'_nsw_theme_agency_color'   => '#1E6B52',
					'_nsw_theme_agency_website' => 'https://agency-a.example.com',
				),
			)
		);
		$this->given_featured_images( array( 11 => self::UPLOADS . 'efa-logo.svg' ) );

		$agency = nsw_theme_agency_post_to_array(
			$this->post( 11, array( 'post_name' => 'example-food-agency', 'post_title' => 'Title of the entry' ) )
		);

		$this->assertSame(
			array(
				'id'           => 'agency-a',
				'abbreviation' => 'EFA',
				'image'        => self::UPLOADS . 'efa-logo.svg',
				'name'         => 'Example Food Agency',
				'description'  => 'Checks food imports at the border.',
				'documents'    => array( 'Import permit', 'Health certificate' ),
				'color'        => '#1E6B52',
				'website'      => 'https://agency-a.example.com',
			),
			$agency
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An agency without its own identifier or name uses the short name and the title of its entry')]
	public function test_agency_identifier_and_name_fallback(): void {
		$this->given_meta( array() );
		$this->given_featured_images( array() );

		$agency = nsw_theme_agency_post_to_array(
			$this->post( 12, array( 'post_name' => 'example-standards-office', 'post_title' => 'Example Standards Office' ) )
		);

		$this->assertSame( 'example-standards-office', $agency['id'] );
		$this->assertSame( 'Example Standards Office', $agency['name'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An agency without a logo has an empty logo address and empty texts for the fields not filled in')]
	public function test_agency_without_logo_or_optional_fields(): void {
		$this->given_meta( array() );
		$this->given_featured_images( array() );
		Functions\expect( 'get_the_post_thumbnail_url' )->never();

		$agency = nsw_theme_agency_post_to_array( $this->post( 13, array( 'post_name' => 'agency-c', 'post_title' => 'Agency C' ) ) );

		$this->assertSame( '', $agency['image'] );
		$this->assertSame( '', $agency['abbreviation'] );
		$this->assertSame( '', $agency['description'] );
		$this->assertSame( '', $agency['color'] );
		$this->assertSame( '', $agency['website'] );
		$this->assertSame( array(), $agency['documents'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The documents of an agency are split at line breaks, semicolons and vertical bars')]
	public function test_agency_documents_split(): void {
		$this->given_meta(
			array( 14 => array( '_nsw_theme_agency_docs' => "Import permit; Health certificate|Export licence\r\nTransit declaration" ) )
		);
		$this->given_featured_images( array() );

		$agency = nsw_theme_agency_post_to_array( $this->post( 14, array( 'post_name' => 'agency-d' ) ) );

		$this->assertSame( array( 'Import permit', 'Health certificate', 'Export licence', 'Transit declaration' ), $agency['documents'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A comma inside a document name does not split it')]
	public function test_agency_document_with_comma_kept_whole(): void {
		$this->given_meta( array( 15 => array( '_nsw_theme_agency_docs' => 'Permit for oils, fats and waxes' ) ) );
		$this->given_featured_images( array() );

		$agency = nsw_theme_agency_post_to_array( $this->post( 15, array( 'post_name' => 'agency-e' ) ) );

		$this->assertSame( array( 'Permit for oils, fats and waxes' ), $agency['documents'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Blank lines and spaces around document names are left out')]
	public function test_agency_documents_blank_parts_dropped(): void {
		$this->given_meta( array( 16 => array( '_nsw_theme_agency_docs' => "\n   Import permit   \n\n;;  Health certificate ;\n | " ) ) );
		$this->given_featured_images( array() );

		$agency = nsw_theme_agency_post_to_array( $this->post( 16, array( 'post_name' => 'agency-f' ) ) );

		$this->assertSame( array( 'Import permit', 'Health certificate' ), $agency['documents'] );
	}

	/* ------------------------------------------------------------------ partners */

	/** Source: TSD 2.3.3, "Partner id, type (international or government), texts, colour, website, logo flag". */
	#[TestDox('A partner is read with its identifier, type, name, description, colour, website, logo and logo background')]
	public function test_partner_record(): void {
		$this->given_meta(
			array(
				21 => array(
					'_nsw_theme_partner_id'      => 'partner-x',
					'_nsw_theme_partner_type'    => 'international',
					'_nsw_theme_partner_name'    => 'Example Development Fund',
					'_nsw_theme_partner_desc'    => 'Funds trade facilitation projects.',
					'_nsw_theme_partner_color'   => '#003399',
					'_nsw_theme_partner_website' => 'https://fund.example.org',
					'_nsw_theme_partner_logo_bg' => '1',
				),
			)
		);
		$this->given_featured_images( array( 21 => self::UPLOADS . 'fund-logo.png' ) );

		$partner = nsw_theme_partner_post_to_array(
			$this->post( 21, array( 'post_name' => 'example-development-fund', 'post_title' => 'Title of the entry' ) )
		);

		$this->assertSame(
			array(
				'id'          => 'partner-x',
				'type'        => 'international',
				'name'        => 'Example Development Fund',
				'description' => 'Funds trade facilitation projects.',
				'color'       => '#003399',
				'website'     => 'https://fund.example.org',
				'logo'        => self::UPLOADS . 'fund-logo.png',
				'logoBg'      => true,
			),
			$partner
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A partner without its own identifier, name or logo uses the short name and the title of its entry and has no logo')]
	public function test_partner_fallbacks(): void {
		$this->given_meta( array( 22 => array( '_nsw_theme_partner_type' => 'government' ) ) );
		$this->given_featured_images( array() );
		Functions\expect( 'get_the_post_thumbnail_url' )->never();

		$partner = nsw_theme_partner_post_to_array(
			$this->post( 22, array( 'post_name' => 'example-ministry', 'post_title' => 'Example Ministry' ) )
		);

		$this->assertSame( 'example-ministry', $partner['id'] );
		$this->assertSame( 'Example Ministry', $partner['name'] );
		$this->assertSame( 'government', $partner['type'] );
		$this->assertSame( '', $partner['logo'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('The logo background of a partner is on only when the option is ticked')]
	public function test_partner_logo_background_flag(): void {
		$this->given_meta(
			array(
				23 => array( '_nsw_theme_partner_logo_bg' => '1' ),
				24 => array( '_nsw_theme_partner_logo_bg' => '' ),
				25 => array( '_nsw_theme_partner_logo_bg' => '0' ),
				26 => array( '_nsw_theme_partner_logo_bg' => 'yes' ),
			)
		);
		$this->given_featured_images( array() );

		$this->assertTrue( nsw_theme_partner_post_to_array( $this->post( 23 ) )['logoBg'] );
		$this->assertFalse( nsw_theme_partner_post_to_array( $this->post( 24 ) )['logoBg'] );
		$this->assertFalse( nsw_theme_partner_post_to_array( $this->post( 25 ) )['logoBg'] );
		$this->assertFalse( nsw_theme_partner_post_to_array( $this->post( 26 ) )['logoBg'] );
	}

	/* ----------------------------------------------------------------- documents */

	/** Source: TSD 2.3.3, "Document media item, or external URL; type and size labels". */
	#[TestDox('A document is read with its category, title, description, link, file type, size and language')]
	public function test_document_record(): void {
		$this->given_meta(
			array(
				31 => array(
					'_nsw_theme_doc_file_id'   => '501',
					'_nsw_theme_doc_file_type' => 'PDF',
					'_nsw_theme_doc_size'      => '2.4 MB',
				),
			)
		);
		Functions\when( 'wp_get_attachment_url' )->alias(
			static fn( $id ) => 501 === $id ? self::UPLOADS . 'importer-guide.pdf' : false
		);
		$this->given_terms( 'nsw_document_category', array( 31 => array( 'guides' ) ) );
		$this->given_post_languages( array( 31 => 'sq' ) );

		$document = nsw_theme_document_post_to_array(
			$this->post( 31, array( 'post_name' => 'udhezues-per-importuesit', 'post_title' => 'Udhëzues për importuesit', 'post_excerpt' => 'Hapat kryesorë.' ) )
		);

		$this->assertSame(
			array(
				'id'          => 'udhezues-per-importuesit',
				'category'    => 'guides',
				'title'       => array( 'sq' => 'Udhëzues për importuesit' ),
				'description' => array( 'sq' => 'Hapat kryesorë.' ),
				'url'         => self::UPLOADS . 'importer-guide.pdf',
				'fileType'    => 'PDF',
				'size'        => '2.4 MB',
				'language'    => 'sq',
			),
			$document
		);
	}

	/** Source: TSD 2.3.3, "Document media item, or external URL". */
	#[TestDox('A document without an uploaded file links to its external address')]
	public function test_document_external_link(): void {
		$this->given_meta( array( 32 => array( '_nsw_theme_doc_external_url' => 'https://example.com/trade-law.pdf' ) ) );
		Functions\expect( 'wp_get_attachment_url' )->never();
		$this->given_terms( 'nsw_document_category', array() );
		$this->given_post_languages( array( 32 => 'en' ) );

		$document = nsw_theme_document_post_to_array( $this->post( 32, array( 'post_name' => 'trade-law' ) ) );

		$this->assertSame( 'https://example.com/trade-law.pdf', $document['url'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A document with both an uploaded file and an external address links to the uploaded file')]
	public function test_document_uploaded_file_wins(): void {
		$this->given_meta(
			array(
				33 => array(
					'_nsw_theme_doc_file_id'      => '502',
					'_nsw_theme_doc_external_url' => 'https://example.com/old-copy.pdf',
				),
			)
		);
		Functions\when( 'wp_get_attachment_url' )->alias( static fn( $id ) => 502 === $id ? self::UPLOADS . 'tariff.pdf' : false );
		$this->given_terms( 'nsw_document_category', array() );
		$this->given_post_languages( array() );

		$document = nsw_theme_document_post_to_array( $this->post( 33, array( 'post_name' => 'tariff' ) ) );

		$this->assertSame( self::UPLOADS . 'tariff.pdf', $document['url'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A document is shown in its own language, even on a page in the other language')]
	public function test_document_in_its_own_language(): void {
		nsw_theme_set_preview_locale( 'sq' );
		$this->given_meta( array() );
		$this->given_terms( 'nsw_document_category', array() );
		Functions\expect( 'nsw_theme_post_locale' )->once()->with( 34 )->andReturn( 'en' );

		$document = nsw_theme_document_post_to_array(
			$this->post( 34, array( 'post_name' => 'importer-guide', 'post_title' => 'Importer guide', 'post_excerpt' => 'Main steps.' ) )
		);

		$this->assertSame( array( 'en' => 'Importer guide' ), $document['title'] );
		$this->assertSame( array( 'en' => 'Main steps.' ), $document['description'] );
		$this->assertSame( 'en', $document['language'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A document with several categories is filed under the first one, and one without a category has none')]
	public function test_document_category(): void {
		$this->given_meta( array() );
		$this->given_terms( 'nsw_document_category', array( 35 => array( 'legal', 'templates' ) ) );
		$this->given_post_languages( array() );

		$this->assertSame( 'legal', nsw_theme_document_post_to_array( $this->post( 35, array( 'post_name' => 'd35' ) ) )['category'] );
		$this->assertSame( '', nsw_theme_document_post_to_array( $this->post( 36, array( 'post_name' => 'd36' ) ) )['category'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A document whose categories cannot be read has no category')]
	public function test_document_category_error(): void {
		$this->given_meta( array() );
		Functions\when( 'wp_get_object_terms' )->justReturn( new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) );
		$this->given_post_languages( array() );

		$this->assertSame( '', nsw_theme_document_post_to_array( $this->post( 37, array( 'post_name' => 'd37' ) ) )['category'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A document without a short name gets an identifier made from its number')]
	public function test_document_identifier_fallback(): void {
		$this->given_meta( array() );
		$this->given_terms( 'nsw_document_category', array() );
		$this->given_post_languages( array() );

		$this->assertSame( 'cpt-38', nsw_theme_document_post_to_array( $this->post( 38, array( 'post_name' => '' ) ) )['id'] );
	}

	/* -------------------------------------------------------------------- events */

	/** Source: TSD 2.3.3, "Event date, time, location, type". */
	#[TestDox('An event is read with its title, date, time, location, type, description and image')]
	public function test_event_record(): void {
		$this->given_meta(
			array(
				41 => array(
					'_nsw_theme_event_date'     => '2026-12-03',
					'_nsw_theme_event_time'     => '10:00',
					'_nsw_theme_event_location' => 'Example Conference Centre',
					'_nsw_theme_event_type'     => 'workshop',
				),
			)
		);
		$this->given_featured_images( array( 41 => self::UPLOADS . 'workshop.jpg' ) );
		$this->given_today( '2026-11-15' );
		$this->given_post_languages( array( 41 => 'en' ) );

		$event = nsw_theme_event_post_to_array(
			$this->post( 41, array( 'post_name' => 'trade-workshop', 'post_title' => 'Trade facilitation workshop', 'post_excerpt' => 'An introduction for traders.' ) )
		);

		$this->assertSame(
			array(
				'id'          => 'trade-workshop',
				'title'       => array( 'en' => 'Trade facilitation workshop' ),
				'date'        => '2026-12-03',
				'time'        => '10:00',
				'location'    => array( 'en' => 'Example Conference Centre' ),
				'type'        => 'workshop',
				'isPast'      => false,
				'description' => array( 'en' => 'An introduction for traders.' ),
				'image'       => self::UPLOADS . 'workshop.jpg',
			),
			$event
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An event dated before today is marked as past')]
	public function test_event_before_today_is_past(): void {
		$this->given_meta( array( 42 => array( '_nsw_theme_event_date' => '2026-11-14' ) ) );
		$this->given_featured_images( array() );
		$this->given_today( '2026-11-15' );
		$this->given_post_languages( array() );

		$this->assertTrue( nsw_theme_event_post_to_array( $this->post( 42, array( 'post_name' => 'e42' ) ) )['isPast'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An event dated today is still shown as upcoming')]
	public function test_event_today_is_not_past(): void {
		$this->given_meta( array( 43 => array( '_nsw_theme_event_date' => '2026-11-15' ) ) );
		$this->given_featured_images( array() );
		$this->given_today( '2026-11-15' );
		$this->given_post_languages( array() );

		$this->assertFalse( nsw_theme_event_post_to_array( $this->post( 43, array( 'post_name' => 'e43' ) ) )['isPast'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An event dated after today, also in a later year, is upcoming')]
	public function test_event_after_today_is_not_past(): void {
		$this->given_meta(
			array(
				44 => array( '_nsw_theme_event_date' => '2026-11-16' ),
				45 => array( '_nsw_theme_event_date' => '2027-01-05' ),
			)
		);
		$this->given_featured_images( array() );
		$this->given_today( '2026-11-15' );
		$this->given_post_languages( array() );

		$this->assertFalse( nsw_theme_event_post_to_array( $this->post( 44, array( 'post_name' => 'e44' ) ) )['isPast'] );
		$this->assertFalse( nsw_theme_event_post_to_array( $this->post( 45, array( 'post_name' => 'e45' ) ) )['isPast'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An event without an image or a short name has an empty image and an identifier made from its number')]
	public function test_event_without_image_or_name(): void {
		$this->given_meta( array( 46 => array( '_nsw_theme_event_date' => '2026-12-01' ) ) );
		$this->given_featured_images( array() );
		$this->given_today( '2026-11-15' );
		$this->given_post_languages( array() );
		Functions\expect( 'get_the_post_thumbnail_url' )->never();

		$event = nsw_theme_event_post_to_array( $this->post( 46, array( 'post_name' => '' ) ) );

		$this->assertSame( '', $event['image'] );
		$this->assertSame( 'cpt-46', $event['id'] );
	}

	/* --------------------------------------------------------------- whole lists */

	/** Source: TSD 2.1, content "is published only after administrator approval". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The public lists show every published agency, partner, document, event and FAQ item, and nothing unpublished')]
	public function test_lists_show_all_published_items_only(): void {
		nsw_theme_set_preview_locale( 'sq' );
		$this->given_database( $this->two_posts_of_each_type() );
		$this->given_plain_posts();

		$lists = $this->read_all_lists();

		foreach ( array( 'nsw_agency', 'nsw_partner', 'nsw_document', 'nsw_event', 'nsw_faq' ) as $type ) {
			$query = $this->query_for( $type );
			$this->assertSame( 'publish', $query['post_status'], "$type: only published items" );
			$this->assertSame( -1, $query['posts_per_page'], "$type: no page limit" );
			$this->assertCount( 2, $lists[ $type ], "$type: every item returned" );
		}
		$this->assertSame( array( 'agency-a', 'agency-b' ), array_column( $lists['nsw_agency'], 'id' ) );
		$this->assertSame( array( 'partner-a', 'partner-b' ), array_column( $lists['nsw_partner'], 'id' ) );
		$this->assertSame( array( 'document-a', 'document-b' ), array_column( $lists['nsw_document'], 'id' ) );
		$this->assertSame( array( 'event-a', 'event-b' ), array_column( $lists['nsw_event'], 'id' ) );
		$this->assertSame( array( 'faq-a', 'faq-b' ), array_column( $lists['nsw_faq'], 'id' ) );
	}

	/** Source: TSD 2.2.3, "Albanian (default) and English for all public pages". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The public lists are not tied to one language, so each page lists the items in its own language')]
	public function test_lists_follow_page_language(): void {
		nsw_theme_set_preview_locale( 'en' );
		$this->given_database( $this->two_posts_of_each_type() );
		$this->given_plain_posts();

		$this->read_all_lists();

		$this->assertCount( 5, $this->queries );
		foreach ( $this->queries as $query ) {
			// No fixed language in the query: Polylang limits it to the page language.
			$this->assertArrayNotHasKey( 'lang', $query, $query['post_type'] );
		}
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Each public list is read from the database only once per page view')]
	public function test_lists_read_once_per_page_view(): void {
		nsw_theme_set_preview_locale( 'sq' );
		$this->given_database( $this->two_posts_of_each_type() );
		$this->given_plain_posts();

		$first  = $this->read_all_lists();
		$second = $this->read_all_lists();

		$this->assertCount( 5, $this->queries );
		$this->assertSame( $first, $second );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('A list whose content type is not available is empty')]
	public function test_lists_empty_without_content_type(): void {
		nsw_theme_set_preview_locale( 'sq' );
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\expect( 'get_posts' )->never();

		$this->assertSame(
			array( 'nsw_agency' => array(), 'nsw_partner' => array(), 'nsw_document' => array(), 'nsw_event' => array(), 'nsw_faq' => array() ),
			$this->read_all_lists()
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Agencies are listed in the order set by the editors, then alphabetically by title')]
	public function test_agencies_order(): void {
		$this->given_database( $this->two_posts_of_each_type() );
		$this->given_plain_posts();

		$ids   = array_column( nsw_theme_get_agencies(), 'id' );
		$query = $this->query_for( 'nsw_agency' );

		$this->assertSame( 'menu_order title', $query['orderby'] );
		$this->assertSame( 'ASC', $query['order'] );
		$this->assertSame( array( 'agency-a', 'agency-b' ), $ids, 'The database order is kept.' );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Partners are listed in the order set by the editors, then alphabetically by title')]
	public function test_partners_order(): void {
		$this->given_database( $this->two_posts_of_each_type() );
		$this->given_plain_posts();

		$ids   = array_column( nsw_theme_get_partners(), 'id' );
		$query = $this->query_for( 'nsw_partner' );

		$this->assertSame( 'menu_order title', $query['orderby'] );
		$this->assertSame( 'ASC', $query['order'] );
		$this->assertSame( array( 'partner-a', 'partner-b' ), $ids, 'The database order is kept.' );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Documents are listed newest first')]
	public function test_documents_order(): void {
		$this->given_database( $this->two_posts_of_each_type() );
		$this->given_plain_posts();

		$ids   = array_column( nsw_theme_get_documents(), 'id' );
		$query = $this->query_for( 'nsw_document' );

		$this->assertSame( 'date', $query['orderby'] );
		$this->assertSame( 'DESC', $query['order'] );
		$this->assertSame( array( 'document-a', 'document-b' ), $ids, 'The database order is kept.' );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Events are listed by event date, earliest first')]
	public function test_events_order(): void {
		$this->given_database( $this->two_posts_of_each_type() );
		$this->given_plain_posts();

		$ids   = array_column( nsw_theme_get_events(), 'id' );
		$query = $this->query_for( 'nsw_event' );

		$this->assertSame( '_nsw_theme_event_date', $query['meta_key'] );
		$this->assertSame( 'meta_value', $query['orderby'] );
		$this->assertSame( 'ASC', $query['order'] );
		$this->assertSame( array( 'event-a', 'event-b' ), $ids, 'The database order is kept.' );
	}

	/** Source: TSD 2.3.3, "Undated events are not listed." */
	#[Group('tsd-difference')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('An event saved with an empty date is not listed')]
	public function test_event_with_empty_date_not_listed(): void {
		// The date is stored but empty, as the content API stores an empty value; the
		// database query still returns such an event.
		$this->given_database(
			array(
				'nsw_event' => array(
					$this->post( 51, array( 'post_name' => 'dated-workshop' ) ),
					$this->post( 52, array( 'post_name' => 'undated-workshop' ) ),
				),
			)
		);
		$this->given_meta(
			array(
				51 => array( '_nsw_theme_event_date' => '2026-12-01' ),
				52 => array( '_nsw_theme_event_date' => '' ),
			)
		);
		$this->given_featured_images( array() );
		$this->given_today( '2026-11-15' );
		$this->given_post_languages( array() );

		$this->assertSame( array( 'dated-workshop' ), array_column( nsw_theme_get_events(), 'id' ) );
	}

	/** Source: TSD 2.3.3, "Undated events are not listed." */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('An event that has no date saved at all is not listed')]
	public function test_event_without_saved_date_not_listed(): void {
		// The wp-admin form removes the date when it is cleared. Like WordPress, the
		// simulated database returns only the posts that have the field the query names.
		$meta  = array( 53 => array( '_nsw_theme_event_date' => '2026-12-01' ) );
		$posts = array(
			$this->post( 53, array( 'post_name' => 'dated-meeting' ) ),
			$this->post( 54, array( 'post_name' => 'meeting-without-date' ) ),
		);
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( $posts, $meta ) {
				if ( 'nsw_event' !== $args['post_type'] ) {
					return array();
				}
				if ( empty( $args['meta_key'] ) ) {
					return $posts;
				}
				return array_values(
					array_filter( $posts, static fn( $p ) => array_key_exists( $args['meta_key'], $meta[ $p->ID ] ?? array() ) )
				);
			}
		);
		$this->given_meta( $meta );
		$this->given_featured_images( array() );
		$this->given_today( '2026-11-15' );
		$this->given_post_languages( array() );

		$this->assertSame( array( 'dated-meeting' ), array_column( nsw_theme_get_events(), 'id' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('FAQ items are listed in the order set by the editors, then oldest first')]
	public function test_faq_order(): void {
		$this->given_database( $this->two_posts_of_each_type() );
		$this->given_plain_posts();

		$ids   = array_column( nsw_theme_get_faq( 'sq' ), 'id' );
		$query = $this->query_for( 'nsw_faq' );

		$this->assertSame( 'menu_order date', $query['orderby'] );
		$this->assertSame( 'ASC', $query['order'] );
		$this->assertSame( array( 'faq-a', 'faq-b' ), $ids, 'The database order is kept.' );
	}

	/** Source: TSD 2.3.1, FAQ item "question, answer, FAQ category". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('An FAQ item is read with its category, its question and its answer as plain text')]
	public function test_faq_item_record(): void {
		$this->given_database(
			array(
				'nsw_faq' => array(
					$this->post( 61, array( 'post_name' => 'how-to-register', 'post_title' => 'How do I register?', 'post_content' => "<p>Use the <strong>online form</strong>.</p>\n" ) ),
					$this->post( 62, array( 'post_name' => '', 'post_title' => 'Which fees apply?', 'post_content' => 'See the fees page.' ) ),
				),
			)
		);
		$this->given_terms( 'nsw_faq_category', array( 61 => array( 'registration', 'general' ) ) );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $html ) => "<p>Use the <strong>online form</strong>.</p>\n" === $html ? "  Use the online form.\n" : $html
		);

		$faq = nsw_theme_get_faq( 'en' );

		$this->assertSame(
			array(
				array( 'id' => 'how-to-register', 'category' => 'registration', 'question' => 'How do I register?', 'answer' => 'Use the online form.' ),
				array( 'id' => 'cpt-62', 'category' => '', 'question' => 'Which fees apply?', 'answer' => 'See the fees page.' ),
			),
			$faq
		);
	}

	/* ------------------------------------------------------- interface texts */

	/** Source: TSD 2.2.2, "Interface texts: English source in the theme". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The English interface texts are the ones built into the theme')]
	public function test_english_interface_texts_from_theme(): void {
		$texts = nsw_theme_get_content( 'en' );

		$this->assertSame( $this->english_texts(), $texts );
		$this->assertSame( 'Learn More', $texts['common']['learnMore'] );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Without the translation plugin, Albanian pages get the English interface texts')]
	public function test_albanian_without_polylang_gets_english(): void {
		$this->assertSame( $this->english_texts(), nsw_theme_get_content( 'sq' ) );
	}

	/** Source: TSD 2.2.2, "Interface texts: English source in the theme, translated in Polylang". */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('With the translation plugin, every interface text is translated into the language of the page')]
	public function test_interface_texts_translated_by_polylang(): void {
		Functions\when( 'pll_translate_string' )->alias( static fn( $text, $lang ) => "$lang:$text" );
		nsw_theme_set_preview_locale( 'sq' );

		$texts = nsw_theme_get_content();

		$this->assertSame( 'sq:Learn More', $texts['common']['learnMore'] );
		$this->assertSame( 'sq:LPCO Process', $texts['contactPage']['form']['categoryOptions']['lpco'] );
		$this->assertSame( array_keys( $this->english_texts() ), array_keys( $texts ) );
		array_walk_recursive(
			$texts,
			function ( $value, $key ) {
				if ( is_string( $value ) && '' !== $value ) {
					$this->assertStringStartsWith( 'sq:', $value, "Text '$key' is not translated." );
				}
			}
		);
		$this->assertSame( $this->english_texts(), nsw_theme_get_content( 'en' ), 'English stays the source.' );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('The translated interface texts are prepared only once per language and page view')]
	public function test_translated_texts_prepared_once(): void {
		$calls = 0;
		Functions\when( 'pll_translate_string' )->alias(
			static function ( $text, $lang ) use ( &$calls ) {
				++$calls;
				return "$lang:$text";
			}
		);

		$first = nsw_theme_get_content( 'sq' );
		$after_first = $calls;
		$second = nsw_theme_get_content( 'sq' );

		$this->assertGreaterThan( 0, $after_first );
		$this->assertSame( $after_first, $calls );
		$this->assertSame( $first, $second );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('When the English interface texts cannot be read, there are no interface texts')]
	public function test_unreadable_texts_give_empty_catalog(): void {
		Functions\when( 'is_readable' )->justReturn( false );

		$this->assertSame( array(), nsw_theme_get_content( 'en' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[TestDox('Translation reaches texts inside nested groups and leaves empty texts and other values unchanged')]
	public function test_translation_of_nested_texts(): void {
		$seen = array();
		Functions\when( 'pll_translate_string' )->alias(
			static function ( $text, $lang ) use ( &$seen ) {
				$seen[] = array( $text, $lang );
				return strtoupper( $text );
			}
		);

		$out = nsw_theme_translate_content_tree(
			array(
				'title' => 'Our partners',
				'group' => array( 'label' => 'Search', 'empty' => '', 'deeper' => array( 'hint' => 'Type here' ) ),
				'count' => 3,
				'flag'  => true,
				'none'  => null,
			),
			'sq'
		);

		$this->assertSame(
			array(
				'title' => 'OUR PARTNERS',
				'group' => array( 'label' => 'SEARCH', 'empty' => '', 'deeper' => array( 'hint' => 'TYPE HERE' ) ),
				'count' => 3,
				'flag'  => true,
				'none'  => null,
			),
			$out
		);
		$this->assertSame( array( array( 'Our partners', 'sq' ), array( 'Search', 'sq' ), array( 'Type here', 'sq' ) ), $seen );
	}

	/* ------------------------------------------------------------ image paths */

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An empty or blank image path gives no address')]
	public function test_asset_url_empty(): void {
		$this->assertSame( '', nsw_theme_asset_url( '' ) );
		$this->assertSame( '', nsw_theme_asset_url( "  \t " ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A full web address is kept as it is')]
	public function test_asset_url_absolute_kept(): void {
		$this->assertSame( self::UPLOADS . 'logo.png', nsw_theme_asset_url( self::UPLOADS . 'logo.png' ) );
		$this->assertSame( 'http://example.com/logo.png', nsw_theme_asset_url( 'http://example.com/logo.png' ) );
		$this->assertSame( '//cdn.example.com/logo.svg', nsw_theme_asset_url( '//cdn.example.com/logo.svg' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('An agency emblem path points to the agency images folder of the theme')]
	public function test_asset_url_agency_emblem(): void {
		$this->assertSame( self::THEME . 'assets/images/agencies/agency-a.svg', nsw_theme_asset_url( '/agencies/agency-a.svg' ) );
		$this->assertSame( self::THEME . 'assets/images/agencies/agency-a.svg', nsw_theme_asset_url( 'agencies/agency-a.svg' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A partner logo path points to the partner images folder of the theme')]
	public function test_asset_url_partner_logo(): void {
		$this->assertSame( self::THEME . 'assets/images/partners/fund.png', nsw_theme_asset_url( '/partners/fund.png' ) );
		$this->assertSame( self::THEME . 'assets/images/partners/fund.png', nsw_theme_asset_url( ' /partners/fund.png ' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A general image path points to the images folder of the theme')]
	public function test_asset_url_general_image(): void {
		$this->assertSame( self::THEME . 'assets/images/hero/hero-bg.jpg', nsw_theme_asset_url( '/images/hero/hero-bg.jpg' ) );
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('A path already inside the theme assets is not changed twice')]
	public function test_asset_url_idempotent(): void {
		$once = nsw_theme_asset_url( '/assets/images/logos/nsw-logo.svg' );

		$this->assertSame( self::THEME . 'assets/images/logos/nsw-logo.svg', $once );
		$this->assertSame( $once, nsw_theme_asset_url( $once ) );
		$this->assertSame(
			self::THEME . 'assets/images/agencies/agency-a.svg',
			nsw_theme_asset_url( nsw_theme_asset_url( '/agencies/agency-a.svg' ) )
		);
	}

	/** Source: theme behaviour, not specified in the TSD. */
	#[TestDox('Any other image path is placed under the theme folder')]
	public function test_asset_url_other_path(): void {
		$this->assertSame( self::THEME . 'icons/arrow.svg', nsw_theme_asset_url( '/icons/arrow.svg' ) );
		$this->assertSame( self::THEME . 'icons/arrow.svg', nsw_theme_asset_url( 'icons/arrow.svg' ) );
	}
}
