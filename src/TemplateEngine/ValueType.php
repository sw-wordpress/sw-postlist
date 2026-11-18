<?php
/**
 * Data types of placeholders and formatter inputs/outputs. The compiler type-checks formatter chains with them.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

enum ValueType: string {
	case Text   = 'text';
	case Number = 'number';
	case Date   = 'date';
	case List   = 'list';
	case Url    = 'url';
	case Html   = 'html';
	case Flag   = 'flag';
	case Color  = 'color';
	case Image  = 'image';
}
