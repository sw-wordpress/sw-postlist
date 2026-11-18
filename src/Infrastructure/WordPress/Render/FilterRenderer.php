<?php
/**
 * The visitor filter form. It is a plain GET form (`swp[key][f][...]`), so filtering works without JavaScript; the
 * filter module only upgrades it. A filter change leaves out the page parameter and therefore resets only this
 * instance to page one. Parameters of other instances and foreign parameters are kept as hidden fields.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

use SW\Postlist\Application\Filter\FilterSelection;
use SW\Postlist\Application\Filter\FilterTypeRegistry;
use SW\Postlist\Application\Filter\VisitorInputParser;
use SW\Postlist\Application\Port\LoggerInterface;

final class FilterRenderer {

	private const MAX_OPTIONS = 100;

	public function __construct( private readonly FilterTypeRegistry $types, private readonly LoggerInterface $logger ) {
	}

	/**
	 * @param string                     $key       Instance key.
	 * @param list<array<string, mixed>> $filters   Active filter configurations.
	 * @param FilterSelection            $selection Selected values.
	 * @param array<string, mixed>       $query     Current GET parameters.
	 */
	public function render( string $key, array $filters, FilterSelection $selection, array $query ): string {
		if ( array() === $filters ) {
			return '';
		}
		$fields = '';
		foreach ( $filters as $config ) {
			$fields .= $this->field( $key, $config, $selection );
		}
		if ( '' === $fields ) {
			return '';
		}
		$hidden = '';
		foreach ( InstanceRegistry::foreign_fields( $query, $key ) as list( $name, $value ) ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		$active = array() !== array_filter( $selection->params );
		$reset  = $active ? '<a class="swp-filters__reset" href="' . esc_url( InstanceRegistry::query_reset( $query, $key ) . '#swp-' . $key ) . '">' . esc_html__( 'Reset filters', 'sw-postlist' ) . '</a>' : '';
		/* translators: %d: number of the embedding on the page */
		$label = InstanceRegistry::landmark_label( $key, __( 'Filter', 'sw-postlist' ), __( 'Filter %d', 'sw-postlist' ) );
		return '<form class="swp-filters" method="get" action="#swp-' . esc_attr( $key ) . '" data-swp-filters aria-label="' . esc_attr( $label ) . '">'
			. $hidden . $fields
			. '<div class="swp-filters__actions"><button type="submit" class="swp-filters__apply">' . esc_html__( 'Apply filters', 'sw-postlist' ) . '</button>' . $reset . '</div>'
			. '</form>';
	}

	/**
	 * @param array<string, mixed> $config Filter configuration.
	 */
	private function field( string $key, array $config, FilterSelection $selection ): string {
		$type   = is_string( $config['type'] ?? null ) ? $config['type'] : '';
		$param  = VisitorInputParser::param_for( $config );
		$name   = 'swp[' . $key . '][f][' . $param . ']';
		$id     = 'swp-' . $key . '-f-' . $param;
		$values = $selection->values( $param );
		switch ( $type ) {
			case 'taxonomy':
				return $this->taxonomy( $config, $name, $id, $values );
			case 'author':
				return $this->author( $config, $name, $id, $values );
			case 'search':
				return $this->search( $config, $name, $id, $values );
			case 'sort':
				return $this->sort( $config, $name, $id, $values );
			default:
				return $this->third_party( $type, $config, $name, $id, $values );
		}
	}

	/**
	 * @param array<string, mixed> $config Filter configuration.
	 * @param list<string>         $values Selected values.
	 */
	private function taxonomy( array $config, string $name, string $id, array $values ): string {
		$taxonomy = is_string( $config['taxonomy'] ?? null ) ? $config['taxonomy'] : '';
		$object   = taxonomy_exists( $taxonomy ) ? get_taxonomy( $taxonomy ) : false;
		if ( false === $object || ! $object->public ) {
			return '';
		}
		$subset = is_array( $config['terms'] ?? null ) ? array_map( 'intval', $config['terms'] ) : array();
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'include'    => array() === $subset ? '' : $subset,
				'number'     => self::MAX_OPTIONS,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}
		$options = array();
		foreach ( $terms as $term ) {
			$options[] = array( (string) $term->term_id, $term->name );
		}
		$label   = $this->label( $config, $object->labels->singular_name );
		$display = is_string( $config['display'] ?? null ) ? $config['display'] : 'buttons';
		return $this->choice( $display, $label, $name, $id, $options, $values, __( 'All', 'sw-postlist' ) );
	}

	/**
	 * @param array<string, mixed> $config Filter configuration.
	 * @param list<string>         $values Selected values.
	 */
	private function author( array $config, string $name, string $id, array $values ): string {
		$users   = get_users(
			array(
				'has_published_posts' => true,
				'fields'              => array( 'ID', 'display_name' ),
				'number'              => self::MAX_OPTIONS,
				'orderby'             => 'display_name',
			)
		);
		$options = array();
		foreach ( $users as $user ) {
			$options[] = array( (string) $user->ID, (string) $user->display_name );
		}
		if ( array() === $options ) {
			return '';
		}
		$display = is_string( $config['display'] ?? null ) ? $config['display'] : 'dropdown';
		return $this->choice( $display, $this->label( $config, __( 'Author', 'sw-postlist' ) ), $name, $id, $options, $values, __( 'All', 'sw-postlist' ) );
	}

	/**
	 * @param array<string, mixed> $config Filter configuration.
	 * @param list<string>         $values Selected values.
	 */
	private function search( array $config, string $name, string $id, array $values ): string {
		$placeholder = is_string( $config['placeholder'] ?? null ) ? $config['placeholder'] : '';
		return '<div class="swp-filter swp-filter--search"><label class="swp-filter__label" for="' . esc_attr( $id ) . '">' . esc_html( $this->label( $config, __( 'Search', 'sw-postlist' ) ) ) . '</label>'
			. '<input type="search" class="swp-filter__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $values[0] ?? '' ) . '" maxlength="100" autocomplete="off"'
			. ( '' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '' ) . '></div>';
	}

	/**
	 * @param array<string, mixed> $config Filter configuration.
	 * @param list<string>         $values Selected values.
	 */
	private function sort( array $config, string $name, string $id, array $values ): string {
		$fields  = array(
			'date'       => __( 'Date', 'sw-postlist' ),
			'modified'   => __( 'Last modified', 'sw-postlist' ),
			'title'      => __( 'Title', 'sw-postlist' ),
			'menu_order' => __( 'Menu order', 'sw-postlist' ),
			'id'         => __( 'ID', 'sw-postlist' ),
			'meta_num'   => __( 'Custom field (number)', 'sw-postlist' ),
			'meta_text'  => __( 'Custom field (text)', 'sw-postlist' ),
		);
		$options = array();
		foreach ( is_array( $config['options'] ?? null ) ? $config['options'] : array() as $field ) {
			if ( is_string( $field ) && isset( $fields[ $field ] ) ) {
				/* translators: %s: sort field, for example "Date" */
				$options[] = array( $field . ':desc', sprintf( __( '%s, descending', 'sw-postlist' ), $fields[ $field ] ) );
				/* translators: %s: sort field, for example "Date" */
				$options[] = array( $field . ':asc', sprintf( __( '%s, ascending', 'sw-postlist' ), $fields[ $field ] ) );
			}
		}
		if ( array() === $options ) {
			return '';
		}
		return $this->choice( 'dropdown', $this->label( $config, __( 'Sort by', 'sw-postlist' ) ), $name, $id, $options, $values, __( 'Default', 'sw-postlist' ) );
	}

	/**
	 * @param array<string, mixed> $config Filter configuration.
	 * @param list<string>         $values Selected values.
	 */
	private function third_party( string $type, array $config, string $name, string $id, array $values ): string {
		$definition = $this->types->get( $type );
		if ( null === $definition || ! is_callable( $definition->render_callback ) ) {
			return '';
		}
		try {
			$html = ( $definition->render_callback )( $config, $values, $name, $id );
		} catch ( \Throwable $error ) {
			$this->logger->warning( 'filter_render_failed', 'A filter render callback failed.', array( 'filter' => $type ) );
			return '';
		}
		// The callback escapes its own output; it is documented in docs/hooks.md.
		return is_string( $html ) ? '<div class="swp-filter swp-filter--' . esc_attr( $type ) . '">' . $html . '</div>' : '';
	}

	/**
	 * @param array<string, mixed> $config Filter configuration.
	 */
	private function label( array $config, string $default ): string {
		return is_string( $config['label'] ?? null ) && '' !== trim( $config['label'] ) ? $config['label'] : $default;
	}

	/**
	 * @param list<array{0: string, 1: string}> $options Value and label pairs.
	 * @param list<string>                      $values  Selected values.
	 */
	private function choice( string $display, string $label, string $name, string $id, array $options, array $values, string $all ): string {
		$head = '<div class="swp-filter swp-filter--' . esc_attr( $display ) . '">';
		if ( 'dropdown' === $display ) {
			$html = $head . '<label class="swp-filter__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select class="swp-filter__select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">'
				. '<option value="">' . esc_html( $all ) . '</option>';
			foreach ( $options as list( $value, $text ) ) {
				$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( in_array( (string) $value, $values, true ), true, false ) . '>' . esc_html( $text ) . '</option>';
			}
			return $html . '</select></div>';
		}
		$html = '<fieldset class="swp-filter swp-filter--' . esc_attr( $display ) . '"><legend class="swp-filter__label">' . esc_html( $label ) . '</legend>';
		if ( 'checkboxes' === $display ) {
			foreach ( $options as list( $value, $text ) ) {
				$checkbox_id = $id . '-' . $value;
				$html       .= '<span class="swp-filter__check"><input type="checkbox" id="' . esc_attr( $checkbox_id ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( (string) $value ) . '"' . checked( in_array( (string) $value, $values, true ), true, false ) . '> '
					. '<label for="' . esc_attr( $checkbox_id ) . '">' . esc_html( $text ) . '</label></span>';
			}
			return $html . '</fieldset>';
		}
		// Buttons: each submits the form with its value ("all" clears the filter). The hidden field keeps the current
		// selection when another filter's button or "Apply" submits the form; the clicked button comes later and wins.
		$html .= array() === $values ? '' : '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( implode( ',', $values ) ) . '">';
		$html .= '<button type="submit" class="swp-filter__button' . ( array() === $values ? ' is-active' : '' ) . '" name="' . esc_attr( $name ) . '" value="" aria-pressed="' . ( array() === $values ? 'true' : 'false' ) . '">' . esc_html( $all ) . '</button>';
		foreach ( $options as list( $value, $text ) ) {
			$active = in_array( (string) $value, $values, true );
			$html  .= '<button type="submit" class="swp-filter__button' . ( $active ? ' is-active' : '' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" aria-pressed="' . ( $active ? 'true' : 'false' ) . '">' . esc_html( $text ) . '</button>';
		}
		return $html . '</fieldset>';
	}
}
