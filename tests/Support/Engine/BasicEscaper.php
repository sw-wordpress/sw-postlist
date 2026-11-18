<?php
/**
 * Escaper double based on htmlspecialchars and the engine's URL policy.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support\Engine;

use SW\Postlist\TemplateEngine\Port\EscaperInterface;
use SW\Postlist\TemplateEngine\UrlPolicy;

final class BasicEscaper implements EscaperInterface {

	public function text( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	public function attribute( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	public function url( string $value ): ?string {
		$value = trim( $value );
		return UrlPolicy::is_safe( $value ) ? $value : null;
	}

	public function color( string $value ): ?string {
		return 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : null;
	}
}
