<?php
/**
 * Role of a template in a view.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Template;

enum TemplateRole: string {
	case Container = 'container';
	case Entry     = 'entry';
}
