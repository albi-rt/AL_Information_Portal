<?php
/**
 * Base class for the Information Portal unit tests.
 *
 * Starts and stops Brain Monkey around every test, so each test begins with no
 * WordPress function stand-ins left over from the one before. Translation and escaping
 * functions return their input unchanged unless a test replaces them. The theme is
 * loaded on the first test of each PHP process (see nsw_test_load_theme()).
 */

declare(strict_types=1);

namespace NSW\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		nsw_test_load_theme();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
