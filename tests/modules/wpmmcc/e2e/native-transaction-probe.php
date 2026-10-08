<?php
/**
 * Owned WordPress/MySQL probe. Execute only through an owned fixture context.
 * Keeps its exact-prefix scratch tables as evidence until fixture teardown.
 */
global $wpdb;
if ( ! preg_match( '/^owned[a-f0-9]{16}[ab]_$/D', $wpdb->prefix ) || ! ( $wpdb->dbh instanceof mysqli ) ) {
	throw new RuntimeException( 'Native transaction probe requires an owned mysqli fixture.' );
}
$wpdb->suppress_errors( true );
$run = bin2hex( random_bytes( 6 ) );
$table = $wpdb->prefix . 'batch41_tx_' . $run;
$myisam = $wpdb->prefix . 'batch41_nontransactional_' . $run;
$passed = 0;
$check = static function ( bool $condition, string $name ) use ( &$passed ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $name );
	}
	++$passed;
	echo "PASS: {$name}\n";
};
$refuses = static function ( callable $operation, string $name ) use ( $check ): void {
	try {
		$operation();
	} catch ( RuntimeException $error ) {
		$check( true, $name );
		return;
	}
	$check( false, $name );
};
$check( false !== $wpdb->query( "CREATE TABLE {$table} (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB" ), 'owned InnoDB scratch table created' );
$check( 1 === $wpdb->query( "INSERT INTO {$table} VALUES (1,0)" ), 'owned scratch baseline saved' );
$lease = WPMMCC\Sync\SQL_Lease::acquire( 'batch41-native-probe' );
$check( ! is_wp_error( $lease ), 'native connection execution lease acquired' );
$read = static function () use ( $wpdb, $table ): int { return (int) $wpdb->get_var( "SELECT value FROM {$table} WHERE id=1" ); };
$transaction = WPMMCC\Sync\SQL_Transaction::begin( $lease, array( $table ) );
$wpdb->query( "UPDATE {$table} SET value=1 WHERE id=1" );
$transaction->commit();
$check( 1 === $read(), 'native checked transaction commits its own write' );
$transaction = WPMMCC\Sync\SQL_Transaction::begin( $lease, array( $table ) );
$wpdb->query( "UPDATE {$table} SET value=2 WHERE id=1" );
$transaction->rollback();
$check( 1 === $read(), 'native checked rollback restores original bytes' );

$wpdb->query( 'START TRANSACTION' );
$wpdb->query( 'SAVEPOINT caller_owned' );
$wpdb->query( "UPDATE {$table} SET value=3 WHERE id=1" );
$refuses( static function () use ( $lease, $table ) { WPMMCC\Sync\SQL_Transaction::begin( $lease, array( $table ) ); }, 'native admission refuses an active caller transaction' );
$check( 3 === $read() && false !== $wpdb->query( 'RELEASE SAVEPOINT caller_owned' ), 'refusal preserves caller write and original savepoint' );
$wpdb->query( 'ROLLBACK' );
$check( 1 === $read(), 'caller rollback still restores its own baseline' );

$wpdb->query( 'SET autocommit=0' );
$refuses( static function () use ( $lease, $table ) { WPMMCC\Sync\SQL_Transaction::begin( $lease, array( $table ) ); }, 'native admission refuses autocommit disabled' );
$wpdb->query( 'ROLLBACK' );
$wpdb->query( 'SET autocommit=1' );
$check( false !== $wpdb->query( "CREATE TABLE {$myisam} (id INT PRIMARY KEY) ENGINE=MyISAM" ), 'owned nontransactional table created' );
$refuses( static function () use ( $lease, $myisam ) { WPMMCC\Sync\SQL_Transaction::begin( $lease, array( $myisam ) ); }, 'native engine gate refuses MyISAM' );

$transaction = WPMMCC\Sync\SQL_Transaction::begin( $lease, array( $table ) );
$wpdb->query( "UPDATE {$table} SET value=4 WHERE id=1" );
$wpdb->query( 'COMMIT' ); // Simulate a hook ending the original transaction.
$refuses( static function () use ( $transaction ) { $transaction->assert_active(); }, 'native savepoint fence detects a hook commit' );
$wpdb->query( 'START TRANSACTION' );
$wpdb->query( 'SAVEPOINT replacement_owned' );
$wpdb->query( "UPDATE {$table} SET value=5 WHERE id=1" );
$transaction->rollback();
$check( 5 === $read() && false !== $wpdb->query( 'RELEASE SAVEPOINT replacement_owned' ), 'original rollback never takes over a replacement transaction' );
$wpdb->query( 'ROLLBACK' );
$check( 4 === $read(), 'replacement rollback preserves only the committed hook effect' );
echo "RESULT {$passed} passed, 0 failed (native WordPress/mysqli/MySQL, not hook or multisite coverage)\n";
