<?php

use MediaWiki\MediaWikiServices;

class SearchParserFunction {

	/**
	 * Main hook
	 *
	 * @param Parser $parser Parser object
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'search', [ self::class, 'onFunctionHook' ] );
	}

	/**
	 * Determine the title, parameters, API endpoint and format
	 *
	 * @param Parser $parser Parser object
	 * @param string $query Search query
	 * @return string Search results
	 */
	public static function onFunctionHook( Parser $parser, $query = '' ) {
		// This is required unless we come up with a good fallback or default
		$query = trim( $query );
		if ( !$query ) {
			return self::error( 'searchparserfunction-no-query' );
		}

		// Get and process params
		$params = array_slice( func_get_args(), 2 );
		$params = self::parseParams( $params );

		// Build the query
		$search = MediaWikiServices::getInstance()->getSearchEngineFactory()->create();

		$namespace = $params['namespace'] ?? null;
		if ( $namespace ) {
			$namespaces = explode( ',', $namespace );
			$search->setNamespaces( $namespaces );
		}

		$sort = $params['sort'] ?? null;
		if ( $sort ) {
			$search->setSort( $sort );
		}

		$limit = $params['limit'] ?? null;
		$offset = $params['offset'] ?? null;
		if ( $limit || $offset ) {
			$limit = (int)$limit;
			$offset = (int)$offset;
			$search->setLimitOffset( $limit, $offset );
		}

		$rewrite = $params['rewrite'] ?? null;
		if ( $rewrite ) {
			$rewrite = (bool)$rewrite;
			$search->setFeatureData( 'rewrite', $rewrite );
		}

		$interwiki = $params['interwiki'] ?? null;
		if ( $interwiki ) {
			$interwiki = (bool)$interwiki;
			$search->setFeatureData( 'interwiki', $interwiki );
		}

		// Allow others to modify the query
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		$hookContainer->run( 'SearchParserFunctionQuery', [ &$search, &$params ] );

		// Do the search
		$what = $params['what'] ?? null;
		if ( $what === 'title' ) {
			$results = $search->searchTitle( $query );
		} else {
			$results = $search->searchText( $query );
		}

		if ( !$results ) {
			return;
		}

		if ( $results instanceof Status ) {
			$status = $results;
			$results = $status->getValue();
		}

		if ( !$results ) {
			return;
		}

		// Filter the current page
		$titles = $results->extractTitles();
		$titles = array_filter( $titles, static function ( $title ) use ( $parser ) {
			return !$parser->getTitle()->equals( $title );
		} );

		// Build the output according to the preferred format
		$output = '';
		$format = $params['format'] ?? null;
		switch ( $format ) {

			default:
				$links = $params['links'] ?? true;
				$links = filter_var( $links, FILTER_VALIDATE_BOOLEAN );
				$output = '<ul>';
				foreach ( $titles as $title ) {
					$titleText = $title->getFullText();
					if ( $links ) {
						$titleText = "[[:$titleText]]";
					}
					$output .= "<li>$titleText</li>";
				}
				$output .= '</ul>';
				break;

			case 'count':
				$output = count( $titles );
				break;

			case 'plain':
				$separator = $params['separator'] ?? ', ';
				$titleTexts = [];
				foreach ( $titles as $title ) {
					$titleText = $title->getFullText();
					$titleTexts[] = $titleText;
				}
				$output = implode( $separator, $titleTexts );
				break;

			case 'json':
				$output = json_encode( $data );
				break;

			case 'template':
				$template = $params['template'] ?? null;
				if ( !$template ) {
					return self::error( 'searchparserfunction-no-template' );
				}
				foreach ( $titles as $title ) {
					$titleText = $title->getFullText();
					$output .= "{{ $template
					| 1 = $titleText
					}}";
				}
				$output = $parser->recursivePreprocess( $output );
				break;
		}

		// Allow others to add formats or otherwise modify the output
		$hookContainer->run( 'SearchParserFunctionOutput', [ &$output, $format, $results, $params, &$parser ] );

		return $output;
	}

	/**
	 * Helper method to print an error message
	 */
	private static function error( $message, $param = null ) {
		$error = wfMessage( $message, $param );
		return Html::rawElement( 'span', [ 'class' => 'error' ], $error );
	}

	/**
	 * Helper method to convert an array of values in form [0] => "name=value"
	 * into a real associative array in form [name] => value
	 * If no = is provided, true is assumed like this: [name] => true
	 *
	 * @param array $params
	 * @return array
	 */
	private static function parseParams( array $params ) {
		$array = [];
		foreach ( $params as $param ) {
			$pair = array_map( 'trim', explode( '=', $param, 2 ) );
			if ( count( $pair ) === 2 ) {
				$array[ $pair[0] ] = $pair[1];
			} elseif ( count( $pair ) === 1 ) {
				$array[ $pair[0] ] = true;
			}
		}
		return $array;
	}
}
