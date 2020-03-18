<?php

header( 'Content-type: application/json' );
error_reporting( E_DEPRECATED - 1 );
set_time_limit ( 30 );
ini_set('memory_limit', '100M');
//ini_set('display_errors', 1);

function get_value( $value, $fallback = '' ) {
	return !empty( $_GET[$value] ) ? $_GET[$value] : $fallback;
}

function getDBs() {
	$ts_pw = posix_getpwuid( posix_getuid() );
	$ts_mycnf = parse_ini_file( $ts_pw['dir'] . '/replica.my.cnf' );
	$db = mysqli_connect( 'tools.db.svc.eqiad.wmflabs', $ts_mycnf['user'], $ts_mycnf['password'], $ts_mycnf['user'] . '__data' );
	$wd = mysqli_connect( 'wikidatawiki.analytics.db.svc.eqiad.wmflabs', $ts_mycnf['user'], $ts_mycnf['password'], 'wikidatawiki_p' );
	mysqli_set_charset( $db, 'utf8' );
	return [ $db, $wd ];
}

$action = get_value( 'action', '' );
$callback = get_value( 'callback', '' );

list( $db, $wd ) = getDBs();

$data = [];

if ( $action === 'desc' ) {

	$data = [
		'label' => [ 'en' => 'Items without descriptions' ] ,
		'description' => [ 'en' => 'Add descriptions found inside Wikipedia articles' ],
		'instructions' => [ 'en' => '* Please read [https://www.wikidata.org/wiki/Help:Description Help:Description] ' .
			"before playing to know what a good description looks like.\n* Currently available in English and Czech. O:)" ],
		'icon' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3e/AIGA_information.svg/120px-AIGA_information.svg.png',
	];

} elseif ( $action === 'tiles' ) {

	$count = (int)get_value( 'num', 1 );
	$lang = get_value( 'lang', '' );
	$lang = ( $lang === 'cs' ) ? $lang : 'en';
	$in_cache = mysqli_real_escape_string( $db, get_value( 'in_cache', '' ) );
	$data['tiles'] = [];
	$already = [];
	while ( count( $data['tiles'] ) < $count ) {
		$random = rand( 0, pow( 2, 31 ) - 1 );
		$query  = "SELECT id, item, description FROM descriptions";
		$query .= " WHERE random >= $random AND lang = '$lang' AND status IS NULL";
		if ( $in_cache ) {
			$query .= " AND item NOT IN ( SELECT item FROM descriptions AS d2 WHERE d2.id IN ( $in_cache ) )";
		}
		if ( $already ) {
			$query .= sprintf( " AND item NOT IN ( '%s' )", implode( "', '", $already ) );
		}
		$query .= " ORDER BY random LIMIT " . ($count*2);
		$result = mysqli_query( $db, $query );
		if ( !$result ) {
			continue;
		}
		while ( $row = mysqli_fetch_object( $result ) ) {
			$item = $row->item;
			if ( in_array( $item, $already ) ) {
				continue;
			}
			$already[] = $item;
			$item_id = str_replace( 'Q', '', "$item" );
			$check_result = mysqli_query(
				$wd,
				"SELECT wbxl_text_id FROM wbt_item_terms" .
				" JOIN wbt_term_in_lang ON wbit_term_in_lang_id = wbtl_id" .
				" JOIN wbt_type ON wbtl_type_id = wby_id" .
				" JOIN wbt_text_in_lang ON wbtl_text_in_lang_id = wbxl_id" .
				" WHERE wbit_item_id = $item_id AND wbxl_language = '$lang'" .
				" AND wby_name = 'description' LIMIT 1" );
			if ( mysqli_fetch_object( $check_result ) ) {
				mysqli_query(
					$db,
					"UPDATE descriptions SET status = 'REPLACED'" .
					" WHERE item = '$item' AND lang = '$lang'" );
				continue;
			}
			$check_result = mysqli_query( $wd, "SELECT page_is_redirect FROM page" .
				" WHERE page_namespace = 0 AND page_title = '$item'" );
			$item_row = mysqli_fetch_object( $check_result );
			if ( !$item_row || (int)$item_row->page_is_redirect === 1 ) {
				mysqli_query( $db, "UPDATE descriptions SET status = 'DELETED' WHERE item = '$item'" );
				continue;
			}
			$tile = [];
			$tile['id'] = $row->id;
			$tile['sections'] = [
				[
					'type' => 'item',
					'q' => $item,
				],
				[
					'type' => 'text',
					'title' => 'Is this a good description for this item?',
					'text' => $row->description,
				],
			];
			$tile['controls'] = [
				[
					'type' => 'buttons',
					'entries' => [
						[
							'type' => 'green',
							'decision' => 'yes',
							'label' => 'Yes',
							'api_action' => [
								'action' => 'wbsetdescription',
								'id' => $row->item,
								'language' => $lang,
								'value' => $row->description,
							],
						],
						[ 'type' => 'white', 'decision' => 'skip', 'label' => "Don't know" ],
						[ 'type' => 'red', 'decision' => 'no', 'label' => 'No' ],
					],
				],
			];
			$data['tiles'][] = $tile;
			if ( count( $data['tiles'] ) === $count ) {
				break;
			}
		}
	}

} elseif ( $action === 'log_action' ) {

	$tile = mysqli_real_escape_string( $db, get_value( 'tile', '' ) );
	$decision = get_value( 'decision', '' );
	if ( $decision === 'yes' ) {
		$query = "SELECT lang, item FROM descriptions WHERE id = '$tile'";
		$row = mysqli_fetch_object( mysqli_query( $db, $query ) );
		$query = "UPDATE descriptions SET status = 'DONE' WHERE id = '$tile'";
		mysqli_query( $db, $query );
		$query = "UPDATE descriptions SET status = 'REPLACED'";
		$query .= " WHERE lang = '{$row->lang}' AND item = '{$row->item}'";
		$query .= " AND status IS NULL";
		mysqli_query( $db, $query );
	} elseif ( $decision === 'no' ) {
		$query = "UPDATE descriptions SET status = 'NO' WHERE id = '$tile'";
		mysqli_query( $db, $query );
	}

} else {

	$data['error'] = 'Invalid action!';

}

mysqli_close( $db );
mysqli_close( $wd );

echo "{$callback}(";
echo json_encode( $data );
echo ")\n";
