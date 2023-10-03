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
	 * @param string $search Search query
	 * @return string Search results
	 */
	public static function onFunctionHook( Parser $parser, $search = '' ) {
		// Search term is required until we come up with a good fallback or default
		$search = trim( $search );
		if ( !$search ) {
			return self::error( 'searchparserfunction-no-query' );
		}

		// Get and process params
		$params = array_slice( func_get_args(), 2 );
		$params = self::parseParams( $params );
		$namespace = str_replace( ',', '|', $params['namespace'] ?? null );
		$limit = $params['limit'] ?? null;
		$offset = $params['offset'] ?? null;
		$profile = $params['profile'] ?? null;
		$what = $params['what'] ?? 'text';
		$info = $params['info'] ? str_replace( ',', '|', $params['info'] ) : null;
		$prop = $params['prop'] ? str_replace( ',', '|', $params['prop'] ) : null;
		$interwiki = $params['interwiki'] ?? null;
		$rewrites = $params['rewrites'] ?? null;
		$sort = $params['sort'] ?? null;
		$format = strtolower( $params['format'] ?? null );

		// Build query
		$query = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'query',
			'list' => 'search',
			'srsearch' => $search,
			'srnamespace' => $namespace,
			'srlimit' => $limit,
			'sroffset' => $offset,
			'srqiprofile' => $profile,
			'srwhat' => $what,
			'srinfo' => $info,
			'srprop' => $prop,
			'srinterwiki' => $interwiki,
			'srenablerewrites' => $rewrites,
			'srsort' => $sort,
		];
		$query = array_filter( $query );

		// Allow others to modify the query
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		$hookContainer->run( 'SearchParserFunctionQuery', [ &$query, $params ] );

		// Make API call
		$context = RequestContext::getMain();
		$request = $context->getRequest();
		$derivative = new DerivativeRequest( $request, $query );
		$api = new ApiMain( $derivative );
		$api->execute();

		// Extract search results
		$result = $api->getResult();
		$data = $result->getResultData();
		if ( array_key_exists( 'error', $data ) ) {
			return self::error( 'search-error', $data['error']['info'] );
		}
		$results = $data['query']['search'];

		// Filter stuff that are not search results
		$results = array_filter( $results, 'is_array' );

		// Filter the current title
		$results = array_filter( $results, static function ( $result ) use ( $parser ) {
			return $result['title'] !== $parser->getTitle()->getFullText();
		} );

		// If no results, return nothing, because any other response may be
		// mistaken for a search result by templates, Lua modules, etc
		if ( !$results ) {
			return '';
		}

		// Build output
		$output = '';
		switch ( $format ) {

			default:
				$links = $params['links'] ?? true;
				$links = filter_var( $links, FILTER_VALIDATE_BOOLEAN );
				$output = '<ul>';
				foreach ( $results as $result ) {
					$title = $result['title'];
					if ( $links ) {
						$title = "[[:$title]]";
					}
					$output .= "<li>$title</li>";
				}
				$output .= '</ul>';
				break;

			case 'count':
				$count = $data['query']['searchinfo']['totalhits'];
				$count--; // Don't count the current page
				$output = $count;
				break;

			case 'plain':
				$separator = $params['separator'] ?? ', ';
				$titles = [];
				foreach ( $results as $result ) {
					$title = $result['title'];
					$titles[] = $title;
				}
				$output = implode( $separator, $titles );
				break;

			case 'json':
				$output = json_encode( $data );
				break;

			case 'template':
				$template = $params['template'] ?? null;
				if ( !$template ) {
					return self::error( 'searchparserfunction-no-template' );
				}
				foreach ( $results as $result ) {
					$ns = $result['ns'];
					$title = $result['title'];
					$pageid = $result['pageid'];
					$size = $result['size'];
					$wordcount = $result['wordcount'];
					$timestamp = $result['timestamp'];
					$snippet = $result['snippet'];
					$titlesnippet = $result['titlesnippet'] ?? '';
					$redirecttitle = $result['redirecttitle'] ?? '';
					$redirectsnippet = $result['redirectsnippet'] ?? '';
					$sectiontitle = $result['sectiontitle'] ?? '';
					$sectionsnippet = $result['sectionsnippet'] ?? '';
					$isfilematch = $result['isfilematch'] ?? '';
					$categorysnippet = $result['categorysnippet'] ?? '';
					$extensiondata = $result['extensiondata'] ?? '';

					$output .= "{{ $template
					| ns = $ns
					| title = $title
					| size = $size
					| wordcount = $wordcount
					| timestamp = $timestamp
					| snippet = $snippet
					| titlesnippet = $titlesnippet
					| redirecttitle = $redirecttitle
					| redirectsnippet = $redirectsnippet
					| sectiontitle = $sectiontitle
					| sectionsnippet = $sectionsnippet
					| isfilematch = $isfilematch
					| categorysnippet = $categorysnippet
					| extensiondata = $extensiondata
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
