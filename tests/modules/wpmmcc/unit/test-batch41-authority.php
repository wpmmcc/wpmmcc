<?php
/**
 * Private Batch41 queue, cleanup, transaction and media-authority regressions.
 * SQL doubles prove the checked contract, not native MySQL or WordPress hooks.
 */
require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

use WPMMCC\Sync\Asset_Registry;
use WPMMCC\Sync\Delivery_Receipt;
use WPMMCC\Sync\Journal_Cleanup;
use WPMMCC\Sync\Media_Delivery;
use WPMMCC\Sync\Packet;
use WPMMCC\Sync\Private_Document;
use WPMMCC\Sync\Push_Queue;
use WPMMCC\Sync\SQL_Lease;
use WPMMCC\Sync\SQL_Transaction;

$passed = 0;
function b41_check( bool $condition, string $name ): void {
	global $passed;
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $name );
	}
	++$passed;
	echo "PASS: {$name}\n";
}

function b41_refuses( callable $operation, string $name ): void {
	try {
		$operation();
	} catch ( RuntimeException $error ) {
		if ( 0 === strpos( $error->getMessage(), 'Mini_SQL_wpdb:' ) || 0 === strpos( $error->getMessage(), 'FAIL:' ) ) {
			throw $error;
		}
		b41_check( true, $name );
		return;
	}
	b41_check( false, $name );
}

function b41_reset(): void {
	global $wpdb, $wp_mock_cache, $wp_mock_options, $wp_mock_meta, $wp_mock_posts;
	$wpdb = new Mini_SQL_wpdb();
	$wpdb->create_table( 'wp_wpmmcc_journal', array(
		'occurred_at' => current_time( 'mysql' ), 'consumed' => 1, 'outcome' => 'success',
		'event_type' => 'peer_disconnect', 'direction' => 'local', 'object_type' => 'peer',
		'canonical_uuid' => '', 'packet_id' => '', 'payload_digest' => '', 'source_fingerprint' => '',
		'local_object_id' => 0, 'remote_object_id' => 0, 'peer_uuid' => 'peer-a',
		'diff_summary' => null, 'message' => null,
	) );
	$wpdb->create_table( 'wp_wpmmcc_peers', array( 'peer_uuid' => '', 'status' => 'active', 'direction' => 'bidirectional' ) );
	$wpdb->create_table( 'wp_wpmmcc_asset_registry', array(
		'sha256' => '', 'attachment_id' => 0, 'size_bytes' => 0, 'origin_peer_uuid' => '', 'first_seen_at' => '',
	) );
	$wpdb->create_table( 'wp_wpmmcc_cross_mappings', array(
		'canonical_uuid' => '', 'local_object_type' => 'post', 'local_object_id' => 0,
		'peer_uuid' => '', 'vector_clock' => 1,
	) );
	$wp_mock_cache = array();
	$wp_mock_options = array( 'wpmmcc_origin_uuid' => 'origin-local' );
	$wp_mock_meta = array();
	$wp_mock_posts = array();
}

function b41_journal( array $overrides = array() ): int {
	global $wpdb;
	$wpdb->insert( 'wp_wpmmcc_journal', $overrides );
	return (int) $wpdb->insert_id;
}

b41_reset();
$eligible = b41_journal();
$connect = b41_journal( array( 'event_type' => 'peer_connect', 'outcome' => 'skipped' ) );
foreach ( array(
	array( 'consumed' => 0 ), array( 'consumed' => 2 ), array( 'outcome' => 'failed' ),
	array( 'event_type' => 'unknown_future' ), array( 'object_type' => 'post' ),
	array( 'canonical_uuid' => 'uuid' ), array( 'packet_id' => 'packet' ),
	array( 'payload_digest' => str_repeat( 'a', 64 ) ), array( 'source_fingerprint' => str_repeat( 'b', 64 ) ),
	array( 'local_object_id' => 7 ), array( 'remote_object_id' => 8 ),
	array( 'diff_summary' => '{"unknown_reference":"must-retain"}' ),
) as $payload ) {
	b41_journal( $payload );
}
$referenced = b41_journal();
$wpdb->insert( 'wp_wpmmcc_outbound_deliveries', array( 'delivery_key' => 'reference', 'event_id' => $referenced ) );
$wpdb->insert( 'wp_wpmmcc_outbound_deliveries', array( 'delivery_key' => 'nullable', 'event_id' => null ) );
$preview = Journal_Cleanup::preview();
b41_check( array( $eligible, $connect ) === array_map( 'intval', array_column( $preview['rows'], 'id' ) ), 'cleanup allowlist excludes every delivery, failure, reference and unknown payload' );
$before = $wpdb->rows( 'wp_wpmmcc_journal' );
b41_refuses( static function () use ( $preview ) { Journal_Cleanup::apply( $preview['revision'], false ); }, 'cleanup refuses missing explicit confirmation' );
b41_refuses( static function () { Journal_Cleanup::apply( str_repeat( '0', 64 ), true ); }, 'cleanup refuses changed revision' );
b41_check( $before === $wpdb->rows( 'wp_wpmmcc_journal' ), 'revision and confirmation refusals keep exact original rows' );
$wpdb->before_query = static function ( $sql, $db ) {
	if ( 0 === strpos( str_replace( '`', '', (string) $sql ), 'DELETE FROM wp_wpmmcc_journal' ) ) {
		$db->before_query = null;
		// One row becomes ineligible after the locked preview. Exact-count
		// failure must roll back both the partial delete and this mutation.
		$db->update( 'wp_wpmmcc_journal', array( 'packet_id' => 'racing-reference' ), array( 'id' => 1 ) );
	}
	return null;
};
b41_refuses( static function () use ( $preview ) { Journal_Cleanup::apply( $preview['revision'], true ); }, 'changed deletion scope refuses partial cleanup' );
b41_check( $before === $wpdb->rows( 'wp_wpmmcc_journal' ), 'partial cleanup rolls back the complete original scope' );
b41_check( 2 === Journal_Cleanup::apply( $preview['revision'], true ), 'unchanged confirmed cleanup deletes exactly reviewed eligible rows' );
b41_check( count( $before ) - 2 === count( $wpdb->rows( 'wp_wpmmcc_journal' ) ), 'all noneligible evidence survives successful cleanup' );
b41_check( 0 === Journal_Cleanup::apply( Journal_Cleanup::preview()['revision'], true ), 'empty confirmed cleanup is a checked no-op' );

b41_reset();
b41_journal();
$revision = Journal_Cleanup::preview()['revision'];
$wpdb->engines['wp_wpmmcc_journal'] = 'MyISAM';
b41_refuses( static function () use ( $revision ) { Journal_Cleanup::apply( $revision, true ); }, 'cleanup refuses nontransactional storage before deletion' );
b41_check( 1 === count( $wpdb->rows( 'wp_wpmmcc_journal' ) ), 'nontransactional refusal retains evidence' );
$wpdb->engines['wp_wpmmcc_journal'] = 'InnoDB';
$wpdb->query( 'START TRANSACTION' );
b41_refuses( static function () use ( $revision ) { Journal_Cleanup::apply( $revision, true ); }, 'cleanup never commits a caller-owned transaction' );
$wpdb->query( 'ROLLBACK' );
$lease = SQL_Lease::acquire( 'fixture-transaction' );
$transaction = SQL_Transaction::begin( $lease, array( 'wp_wpmmcc_journal' ) );
$wpdb->query( 'COMMIT' );
$wpdb->query( 'START TRANSACTION' );
b41_refuses( static function () use ( $transaction ) { $transaction->commit(); }, 'a hook replacement transaction cannot inherit the original fence' );
$transaction->rollback();
b41_check( 1 === $wpdb->dbh->server_status, 'guard refuses to roll back a hook-owned replacement transaction' );
$wpdb->query( 'ROLLBACK' );

b41_reset();
foreach ( array( 'peer-a', 'peer-b' ) as $peer ) {
	$wpdb->insert( 'wp_wpmmcc_peers', array( 'peer_uuid' => $peer ) );
	b41_journal( array( 'direction' => 'outbound', 'consumed' => 0, 'object_type' => 'post',
		'event_type' => 'insert', 'canonical_uuid' => 'entity', 'peer_uuid' => $peer ) );
}
b41_check( Push_Queue::coalesce( 'entity', 'update', 'latest' ), 'rapid save coalesces a complete multi-peer group' );
b41_check( array( 'latest', 'latest' ) === array_column( $wpdb->rows( 'wp_wpmmcc_journal' ), 'payload_digest' ), 'every peer receives the latest payload digest' );
$wpdb->insert( 'wp_wpmmcc_outbound_deliveries', array( 'delivery_key' => 'frozen', 'canonical_uuid' => 'entity', 'event_id' => 1 ) );
$before = $wpdb->rows( 'wp_wpmmcc_journal' );
b41_check( ! Push_Queue::coalesce( 'entity', 'update', 'replacement' ), 'a frozen delivery prevents covered evidence coalescing' );
b41_check( $before === $wpdb->rows( 'wp_wpmmcc_journal' ), 'frozen evidence refusal preserves all peer rows byte-for-byte' );
$wpdb->blocked_locks[ 'wmc:' . substr( hash( 'sha256', "wp_\nqueue:entity" ), 0, 60 ) ] = true;
b41_check( ! Push_Queue::coalesce( 'entity', 'update', 'busy' ), 'a concurrent freezer causes new-event fallback without overwriting rows' );

b41_reset();
$wpdb->insert( 'wp_wpmmcc_peers', array( 'peer_uuid' => 'peer-a' ) );
$wpdb->insert( 'wp_wpmmcc_peers', array( 'peer_uuid' => 'peer-b' ) );
b41_journal( array( 'direction' => 'outbound', 'consumed' => 0, 'object_type' => 'post',
	'canonical_uuid' => 'entity', 'peer_uuid' => 'peer-a' ) );
b41_check( ! Push_Queue::coalesce( 'entity', 'update', 'new-peer' ), 'missing peer coverage forces fan-out rather than dropping the new peer update' );
$wpdb->before_query = static function ( $sql ) {
	$bare = str_replace( '`', '', (string) $sql );
	return 0 === strpos( $bare, 'UPDATE wp_wpmmcc_journal' ) ? false : null;
};
b41_refuses( static function () { Push_Queue::forget_peer( 'peer-a' ); }, 'failed disconnect parking is not reported as success' );
b41_refuses( static function () { Push_Queue::requeue_dead( 'peer-a' ); }, 'failed requeue is not reported as success' );
$wpdb->before_query = null;
b41_check( 1 === Push_Queue::forget_peer( 'peer-a' ), 'disconnect parks the exact original queue row' );
b41_check( 2 === (int) $wpdb->rows( 'wp_wpmmcc_journal' )[0]['consumed'], 'disconnect retains parked queue evidence' );

b41_reset();
$root = sys_get_temp_dir() . '/wpmmcc-batch41-media-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700 );
$GLOBALS['wp_mock_upload_dir'] = $root;
$scope = array( 'file_uuid' => 'frozen-session', 'total' => 2, 'filename' => 'original.txt',
	'mime_type' => 'text/plain', 'expected_sha256' => hash( 'sha256', 'abcdef' ), 'expected_size' => 6 );
$status = Media_Delivery::status( 'sender-a', $scope );
b41_check( 'absent' === $status['phase'] && array( 0, 1 ) === $status['missing_chunks'], 'no intent under native lease proves media zero effect for the original session' );
$chunk = array_merge( $scope, array( 'chunk_index' => 0 ) );
$ack = Media_Delivery::accept( 'sender-a', $chunk, 'abc' );
b41_check( true === $ack['chunk_acked'] && false === $ack['completed'], 'first chunk saves original authority before acknowledging bytes' );
$status = Media_Delivery::status( 'sender-a', $scope );
b41_check( 'receiving' === $status['phase'] && array( 1 ) === $status['missing_chunks'], 'receiving status returns only the exact missing original chunk' );
b41_refuses( static function () use ( $chunk ) { Media_Delivery::accept( 'sender-a', $chunk, 'changed' ); }, 'same session and chunk index cannot replace original bytes' );
b41_refuses( static function () use ( $scope ) { Media_Delivery::status( 'sender-a', array_merge( $scope, array( 'filename' => 'different.txt' ) ) ); }, 'media status refuses changed frozen scope' );
$completed = Media_Delivery::accept( 'sender-a', array_merge( $scope, array( 'chunk_index' => 1 ) ), 'def' );
b41_check( true === $completed['completed'] && $completed['assembled_sha256'] === $scope['expected_sha256'], 'same media session assembles and checks exact frozen content' );
b41_check( 'abcdef' === file_get_contents( get_attached_file( $completed['attachment_id'] ) ), 'completed media receipt points at the actual original bytes' );
$replay = Media_Delivery::status( 'sender-a', $scope );
b41_check( 'applied' === $replay['phase'] && $completed['attachment_id'] === $replay['attachment_id'], 'applied status replays the original materialization receipt' );
$key = hash( 'sha256', "sender-a\nfrozen-session" );
$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT state_json FROM wp_wpmmcc_media_deliveries WHERE delivery_key = %s', $key ) );
$state = Private_Document::decode( $raw, 'media:' . $key );
$state['phase'] = 'applying';
$state['materialization'] = array( 'attachment_id' => 0, 'verified' => false, 'reused' => false );
$wpdb->update( 'wp_wpmmcc_media_deliveries', array( 'state_json' => Private_Document::encode( $state, 'media:' . $key ) ), array( 'delivery_key' => $key ) );
b41_check( $completed['attachment_id'] === Asset_Registry::lookup_cas_attachment( $scope['expected_sha256'] ), 'unrelated CAS hit exists for unknown materialization regression' );
b41_refuses( static function () use ( $scope ) { Media_Delivery::status( 'sender-a', $scope ); }, 'CAS equality alone cannot close unknown materialization' );
$state['phase'] = 'receiving';
$wpdb->update( 'wp_wpmmcc_media_deliveries', array( 'state_json' => Private_Document::encode( $state, 'media:' . $key ) ), array( 'delivery_key' => $key ) );
b41_check( array( 1 ) === Media_Delivery::status( 'sender-a', $scope )['missing_chunks'], 'all retained parts resume by replaying only the exact final original part' );
$wpdb->before_query = static function ( $sql ) {
	$bare = str_replace( '`', '', (string) $sql );
	return 0 === strpos( $bare, 'UPDATE wp_wpmmcc_media_deliveries' ) ? false : null;
};
b41_refuses( static function () use ( $scope ) { Media_Delivery::accept( 'sender-a', array_merge( $scope, array( 'chunk_index' => 1 ) ), 'def' ); }, 'failed media checkpoint cannot acknowledge completion' );
$wpdb->before_query = null;
$GLOBALS['wp_mock_empty_attachment_metadata'] = true;
$empty_scope = array_merge( $scope, array( 'file_uuid' => 'native-empty-metadata',
	'total' => 1, 'filename' => 'empty-metadata.txt',
	'expected_sha256' => hash( 'sha256', 'owned empty metadata' ), 'expected_size' => 20 ) );
$empty_result = Media_Delivery::accept( 'sender-a', array_merge( $empty_scope, array( 'chunk_index' => 0 ) ), 'owned empty metadata' );
b41_check( $empty_result['completed'] && false === wp_get_attachment_metadata( $empty_result['attachment_id'] ),
	'core-compatible empty non-image metadata is verified as absent without weakening readback' );
$GLOBALS['wp_mock_corrupt_attachment_metadata'] = true;
$bad_scope = array_merge( $empty_scope, array( 'file_uuid' => 'bad-empty-metadata',
	'expected_sha256' => hash( 'sha256', 'owned metadata fault' ), 'expected_size' => 20 ) );
b41_refuses( static function () use ( $bad_scope ) {
	Media_Delivery::accept( 'sender-a', array_merge( $bad_scope, array( 'chunk_index' => 0 ) ), 'owned metadata fault' );
}, 'empty metadata generation cannot excuse a changed nonempty readback' );
unset( $GLOBALS['wp_mock_empty_attachment_metadata'], $GLOBALS['wp_mock_corrupt_attachment_metadata'] );
echo 'MEDIA_EVIDENCE_ROOT=' . $root . "\n";
echo "RESULT {$passed} passed, 0 failed\n";
