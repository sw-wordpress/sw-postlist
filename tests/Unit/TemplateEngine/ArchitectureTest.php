<?php
/**
 * The template engine stays independent of WordPress and of the rest of the plugin (PLAN.md §5): it only imports from
 * its own namespace and calls no function that does not exist without WordPress.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit\TemplateEngine;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase {

	/**
	 * @return list<string>
	 */
	private function engine_files(): array {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src/TemplateEngine', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	public function test_engine_files_exist(): void {
		$this->assertGreaterThan( 30, count( $this->engine_files() ) );
	}

	public function test_imports_only_from_the_engine_namespace(): void {
		foreach ( $this->engine_files() as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
			preg_match_all( '/^use\s+([^;]+);/m', $source, $uses );
			foreach ( $uses[1] as $import ) {
				$this->assertStringStartsWith( 'SW\\Postlist\\TemplateEngine\\', $import, basename( $file ) . ' imports ' . $import );
			}
			preg_match_all( '/\\\\?SW\\\\Postlist\\\\([A-Za-z\\\\]+)/', $source, $references );
			foreach ( $references[1] as $reference ) {
				$this->assertStringStartsWith( 'TemplateEngine', $reference, basename( $file ) . ' references SW\\Postlist\\' . $reference );
			}
		}
	}

	public function test_calls_no_function_that_needs_wordpress(): void {
		foreach ( $this->engine_files() as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
			$count  = count( $tokens );
			for ( $index = 0; $index < $count; $index++ ) {
				$token = $tokens[ $index ];
				if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
					continue;
				}
				$previous = $this->neighbour( $tokens, $index, -1 );
				$next     = $this->neighbour( $tokens, $index, 1 );
				if ( '(' !== $next || in_array( $previous, array( '->', '::', '?->', 'function', 'new', 'const' ), true ) ) {
					continue;
				}
				$this->assertTrue( function_exists( $token[1] ), basename( $file ) . ' calls ' . $token[1] . '(), which is not available without WordPress.' );
			}
		}
	}

	/**
	 * @param array<int, mixed> $tokens Tokens.
	 */
	private function neighbour( array $tokens, int $index, int $direction ): string {
		for ( $cursor = $index + $direction; isset( $tokens[ $cursor ] ); $cursor += $direction ) {
			$token = $tokens[ $cursor ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return is_array( $token ) ? $token[1] : $token;
		}
		return '';
	}
}
