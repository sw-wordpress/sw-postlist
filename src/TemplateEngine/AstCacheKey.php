<?php
/**
 * Cache key of a compiled template: everything that changes the compiled result is part of it, so stale entries are
 * never reused (template, CSS, engine version, profile, placeholder schema incl. meta allowlist, formatter signatures,
 * scope id and the host's invalidation generations).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class AstCacheKey {

	public static function build( string $template, string $css, CompileContext $context, int|string $template_id, string $generations = '' ): string {
		$parts = array(
			'template'    => $template,
			'css'         => $css,
			'engine'      => EngineProfile::ENGINE_VERSION,
			'profile'     => $context->profile->fingerprint(),
			'schema'      => $context->schema->fingerprint( $context->role ),
			'formatters'  => $context->formatters->fingerprint(),
			'role'        => $context->role,
			'template_id' => (string) $template_id,
			'generations' => $generations,
		);
		return 'swp_ast_' . hash( 'sha256', (string) json_encode( $parts ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free engine.
	}
}
