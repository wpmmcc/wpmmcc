<?php
/**
 * Stateful in-memory $wpdb double for queue-level unit tests.
 *
 * The stock Mock_wpdb in bootstrap.php only records SQL text and answers
 * through per-test callbacks, which cannot prove queue *behaviour* (what is
 * still pending after a push, which peer sees which row). This double keeps
 * real rows and executes the small SQL subset the sync queue issues:
 *
 *   SELECT *|col|COUNT(*) FROM t [WHERE p AND p ...] [ORDER BY col [ASC|DESC]] [LIMIT n]
 *   UPDATE t SET col = v[, ...] WHERE p AND p ... [LIMIT n]
 *   DELETE FROM t WHERE p AND p ... [LIMIT n]
 *
 * with predicates  col =|<>|!=|<|<=|>|>= v,  col IN (v, ...),  col IS [NOT] NULL
 * and the uncorrelated  col [NOT] IN (SELECT col FROM t WHERE ...).
 * There is deliberately no OR / parenthesised grouping / JOIN / correlated
 * subquery: SQL that stays inside this grammar is portable, and anything
 * outside it fails loudly ("unsupported SQL") instead of silently returning
 * nothing.
 *
 * A table can also be a read-only VIEW over state the test already keeps in
 * memory (create_view()), so code that reads wp_posts / wp_postmeta through
 * SQL and code that goes through get_post() / get_post_meta() see one truth.
 *
 * @package WPMMCC\Tests
 */

if ( ! class_exists( 'Mini_SQL_wpdb' ) ) {
	class Mini_SQL_wpdb extends Mock_wpdb {

		/**
		 * @var array<string,array{defaults:array,rows:array<int,array>,auto:int}>
		 */
		public $tables = array();

		/**
		 * Read-only tables computed on every read.
		 *
		 * @var array<string,callable>
		 */
		public $views = array();
		public $engines = array();
		public $before_query = null;
		private $transaction = null;
		private $savepoints = array();

		public function __construct() {
			parent::__construct();
			foreach ( array( $this->posts, $this->postmeta, $this->terms, $this->termmeta,
				$this->term_taxonomy, $this->term_relationships ) as $table ) {
				$this->create_table( $table, array() );
			}
			$this->create_table( $this->prefix . 'wpmmcc_delivery_receipts', array(
				'receipt_key' => '', 'packet_digest' => '', 'phase' => '', 'result_json' => '', 'created_at' => '',
			) );
			$this->create_table( $this->prefix . 'wpmmcc_source_deliveries', array(
				'delivery_key' => '', 'scope_digest' => '', 'intent_json' => '', 'phase' => '', 'result_json' => '', 'created_at' => '',
			) );
			$this->create_table( $this->prefix . 'wpmmcc_outbound_deliveries', array(
				'delivery_key' => '', 'peer_uuid' => '', 'canonical_uuid' => '', 'event_id' => 0,
				'intent_json' => '', 'phase' => '', 'result_json' => '', 'created_at' => '',
			) );
			$this->create_table( $this->prefix . 'wpmmcc_media_deliveries', array(
				'delivery_key' => '', 'state_json' => '', 'reserved_bytes' => 0, 'created_at' => '',
			) );
		}

		/**
		 * Register a read-only table whose rows come from a callback.
		 *
		 * @param string   $name     Full table name (with prefix).
		 * @param callable $provider Returns array<int,array> of associative rows.
		 */
		public function create_view( string $name, callable $provider ): void {
			$this->views[ $name ] = $provider;
		}

		/**
		 * Register a table with per-column defaults (insert() fills gaps).
		 *
		 * @param string $name     Full table name (with prefix).
		 * @param array  $defaults Column => default value.
		 */
		public function create_table( string $name, array $defaults ): void {
			$this->engines[ $name ] = 'InnoDB';
			$this->tables[ $name ] = array(
				'defaults' => $defaults,
				'rows'     => array(),
				'auto'     => 0,
			);
		}

		/**
		 * Raw row access for assertions (array of associative arrays).
		 *
		 * @param string $name Table name.
		 * @return array<int,array>
		 */
		public function rows( string $name ): array {
			return array_values( $this->tables[ $name ]['rows'] ?? array() );
		}

		public function insert( $table, $data, $format = null ) {
			$this->need( $table );
			$t                 = &$this->tables[ $table ];
			$t['auto']++;
			$row               = array_merge( $t['defaults'], $data );
			$row['id']         = $t['auto'];
			$t['rows'][ $row['id'] ] = $row;
			$this->insert_id   = $row['id'];
			$this->last_query  = "INSERT INTO {$table} " . json_encode( $data );
			$this->queries[]   = $this->last_query;
			return 1;
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->need( $table );
			$this->last_query = "UPDATE {$table} SET " . json_encode( $data ) . ' WHERE ' . json_encode( $where );
			$this->queries[]  = $this->last_query;
			$n                = 0;
			foreach ( $this->tables[ $table ]['rows'] as $id => $row ) {
				foreach ( $where as $col => $val ) {
					if ( ! $this->cmp_eq( $row[ $col ] ?? null, $val ) ) {
						continue 2;
					}
				}
				$this->tables[ $table ]['rows'][ $id ] = array_merge( $row, $data );
				$n++;
			}
			return $n;
		}

		public function delete( $table, $where, $where_format = null ) {
			$this->need( $table );
			$this->last_query = "DELETE FROM {$table} WHERE " . json_encode( $where );
			$this->queries[]  = $this->last_query;
			$n                = 0;
			foreach ( $this->tables[ $table ]['rows'] as $id => $row ) {
				foreach ( $where as $col => $val ) {
					if ( ! $this->cmp_eq( $row[ $col ] ?? null, $val ) ) {
						continue 2;
					}
				}
				unset( $this->tables[ $table ]['rows'][ $id ] );
				$n++;
			}
			return $n;
		}

		/**
		 * REPLACE emulation (wpdb::replace). Real MySQL replaces on the
		 * table's natural key, which the sync code exercises on
		 * wpmmcc_cross_mappings (UNIQUE canonical_uuid, peer_uuid) and
		 * wpmmcc_asset_registry (UNIQUE sha256). Rows matching every key
		 * column present in $data are dropped, then the row inserts — the
		 * parent Mock_wpdb stub only returns 1 and stores nothing, which
		 * would silently break any test that reads a mapping back.
		 */
		public function replace( $table, $data, $format = null ) {
			$this->need( $table );
			$natural_keys = array(
				'wp_wpmmcc_cross_mappings' => array( 'canonical_uuid', 'peer_uuid' ),
				'wp_wpmmcc_asset_registry'  => array( 'sha256' ),
			);
			$key_cols = array();
			foreach ( ( $natural_keys[ $table ] ?? array() ) as $col ) {
				if ( array_key_exists( $col, $data ) ) {
					$key_cols[ $col ] = $data[ $col ];
				}
			}
			$n = 0;
			if ( ! empty( $key_cols ) ) {
				$n = $this->delete( $table, $key_cols );
			}
			return $n + $this->insert( $table, $data, $format );
		}

		public function query( $sql ) {
			$this->last_error = '';
			$this->last_query = $sql;
			$this->queries[]  = $sql;
			$norm             = $this->norm( $sql );
			if ( is_callable( $this->before_query ) ) {
				$result = call_user_func( $this->before_query, $sql, $this );
				if ( null !== $result ) {
					return $result;
				}
			}
			if ( 'SET TRANSACTION READ WRITE' === strtoupper( $norm ) ) {
				if ( null !== $this->transaction ) {
					$this->last_error = 'Transaction characteristics cannot change inside a transaction';
					return false;
				}
				return 0;
			}
			if ( 'START TRANSACTION' === strtoupper( $norm ) ) {
				if ( null !== $this->transaction ) {
					throw new RuntimeException( 'Mini_SQL_wpdb: nested transaction refused' );
				}
				$this->transaction = array(
					'tables' => $this->tables, 'posts' => $GLOBALS['wp_mock_posts'] ?? array(),
					'meta' => $GLOBALS['wp_mock_meta'] ?? array(),
				);
				$this->dbh->server_status = 1;
				return 0;
			}
			if ( preg_match( '/^(SAVEPOINT|RELEASE SAVEPOINT)\s+(\w+)$/i', $norm, $m ) ) {
				if ( null === $this->transaction || ( 'RELEASE SAVEPOINT' === strtoupper( $m[1] ) && ! isset( $this->savepoints[ $m[2] ] ) ) ) {
					$this->last_error = 'Fixture transaction fence is missing';
					return false;
				}
				if ( 'SAVEPOINT' === strtoupper( $m[1] ) ) {
					$this->savepoints[ $m[2] ] = true;
				} else {
					unset( $this->savepoints[ $m[2] ] );
				}
				return 0;
			}
			if ( in_array( strtoupper( $norm ), array( 'COMMIT', 'ROLLBACK' ), true ) ) {
				if ( 'ROLLBACK' === strtoupper( $norm ) && null !== $this->transaction ) {
					$this->tables = $this->transaction['tables'];
					$GLOBALS['wp_mock_posts'] = $this->transaction['posts'];
					$GLOBALS['wp_mock_meta'] = $this->transaction['meta'];
				}
				$this->transaction = null;
				$this->savepoints = array();
				$this->dbh->server_status = 0;
				return 0;
			}
			if ( preg_match( '/^INSERT(\s+IGNORE)?\s+INTO\s+(\w+)\s*\(([^)]+)\)\s+VALUES\s*\((.+)\)$/is', $norm, $m ) ) {
				$cols = array_map( 'trim', explode( ',', $m[3] ) );
				$values = array_map( array( $this, 'value' ), $this->split_top( $m[4], ',' ) );
				if ( count( $cols ) !== count( $values ) ) {
					throw new RuntimeException( 'Mini_SQL_wpdb: INSERT column count differs' );
				}
				$data = array_combine( $cols, $values );
				foreach ( array( 'delivery_key', 'receipt_key', 'nonce' ) as $key ) {
					if ( ! array_key_exists( $key, $data ) ) {
						continue;
					}
					foreach ( $this->rows( $m[2] ) as $row ) {
						if ( $this->cmp_eq( $row[ $key ], $data[ $key ] ) ) {
							if ( ! empty( $m[1] ) ) {
								return 0;
							}
							$this->last_error = 'Fixture duplicate key';
							return false;
						}
					}
				}
				return $this->insert( $m[2], $data );
			}
			if ( preg_match( '/^UPDATE\s+(\S+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $norm, $m ) ) {
				return $this->exec_update( $m[1], $m[2], $m[3] );
			}
			if ( preg_match( '/^DELETE\s+FROM\s+(\S+)\s+WHERE\s+(.+)$/is', $norm, $m ) ) {
				return $this->exec_delete( $m[1], $m[2] );
			}
			throw new RuntimeException( 'Mini_SQL_wpdb: unsupported statement: ' . $sql );
		}

		public function get_results( $sql, $output = 'OBJECT' ) {
			$this->last_error = '';
			$this->last_query = $sql;
			$this->queries[]  = $sql;
			$rows             = $this->select( $sql );
			if ( 'ARRAY_A' === $output ) {
				return $rows;
			}
			return array_map(
				static function ( $r ) {
					return (object) $r;
				},
				$rows
			);
		}

		public function get_row( $sql, $output = 'OBJECT' ) {
			$this->last_error = '';
			$this->last_query = $sql;
			$rows             = $this->select( $sql );
			if ( empty( $rows ) ) {
				return null;
			}
			return 'ARRAY_A' === $output ? $rows[0] : (object) $rows[0];
		}

		public function get_var( $sql ) {
			$this->last_query = $sql;
			$hit              = $this->native_builtin( (string) $sql, $handled );
			if ( $handled ) {
				return $hit;
			}
			$rows = $this->select( $sql );
			if ( empty( $rows ) ) {
				return null;
			}
			$first = reset( $rows );
			return reset( $first );
		}

		// ---------------------------------------------------------------
		// Engine
		// ---------------------------------------------------------------

		private function need( string $table ): void {
			if ( isset( $this->views[ $table ] ) ) {
				throw new RuntimeException( "Mini_SQL_wpdb: {$table} is a read-only view" );
			}
			if ( ! isset( $this->tables[ $table ] ) ) {
				throw new RuntimeException( "Mini_SQL_wpdb: unknown table {$table}" );
			}
		}

		private function need_readable( string $table ): void {
			if ( ! isset( $this->views[ $table ] ) ) {
				$this->need( $table );
			}
		}

		private function norm( string $sql ): string {
			$sql = trim( preg_replace( '/\s+/', ' ', rtrim( trim( $sql ), ';' ) ) );
			return preg_replace( '/`([^`]*)`/', '$1', $sql );
		}

		private function select( string $sql ): array {
			$norm = preg_replace( '/\s+FOR UPDATE$/i', '', $this->norm( $sql ) );
			if ( preg_match( '/^SELECT TABLE_NAME, ENGINE FROM information_schema\.TABLES WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME = (.+)$/i', $norm, $m ) ) {
				$name = $this->value( $m[1] );
				if ( isset( $this->tables[ $name ], $this->engines[ $name ] ) ) {
					return array( array( 'TABLE_NAME' => $name, 'ENGINE' => $this->engines[ $name ] ) );
				}
				return array();
			}
			if ( preg_match( '/^SELECT TABLE_NAME, ENGINE FROM information_schema\.TABLES WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME IN \((.*)\)$/i', $norm, $m ) ) {
				$rows = array();
				foreach ( $this->split_top( $m[1], ',' ) as $literal ) {
					$name = $this->value( $literal );
					if ( isset( $this->tables[ $name ], $this->engines[ $name ] ) ) {
						$rows[] = array( 'TABLE_NAME' => $name, 'ENGINE' => $this->engines[ $name ] );
					}
				}
				return $rows;
			}
			if ( ! preg_match( '/^SELECT\s+(.+?)\s+FROM\s+(\S+)(?:\s+WHERE\s+(.+?))?(?:\s+ORDER\s+BY\s+(\w+)(?:\s+(ASC|DESC))?)?(?:\s+LIMIT\s+\'?(\d+)\'?)?$/is', $norm, $m ) ) {
				throw new RuntimeException( 'Mini_SQL_wpdb: unsupported SQL: ' . $sql );
			}
			$list  = trim( $m[1] );
			$table = $m[2];
			$this->need_readable( $table );
			$rows = $this->filter( $table, $m[3] ?? '' );

			if ( ! empty( $m[4] ) ) {
				$col = $m[4];
				$dir = strtoupper( $m[5] ?? 'ASC' );
				usort(
					$rows,
					function ( $a, $b ) use ( $col, $dir ) {
						$c = $this->compare( $a[ $col ] ?? null, $b[ $col ] ?? null );
						return 'DESC' === $dir ? -$c : $c;
					}
				);
			}
			if ( ! empty( $m[6] ) ) {
				$rows = array_slice( $rows, 0, (int) $m[6] );
			}

			if ( 0 === stripos( $list, 'COUNT(' ) ) {
				return array( array( 'COUNT(*)' => count( $rows ) ) );
			}
			if ( preg_match( '/^COALESCE\(SUM\((\w+)\), 0\)$/i', $list, $sum ) ) {
				return array( array( $list => array_sum( array_column( $rows, $sum[1] ) ) ) );
			}
			if ( preg_match( '/^MAX\((\w+)\)$/i', $list, $max ) ) {
				$values = array_filter( array_column( $rows, $max[1] ), static function ( $value ) { return null !== $value; } );
				return array( array( $list => empty( $values ) ? null : max( $values ) ) );
			}
			if ( '*' === $list ) {
				return array_values( $rows );
			}
			$cols = array_map( 'trim', explode( ',', $list ) );
			foreach ( $cols as $col ) {
				if ( ! preg_match( '/^\w+$/', $col ) ) {
					throw new RuntimeException( 'Mini_SQL_wpdb: unsupported projection: ' . $list );
				}
			}
			return array_values(
				array_map(
					static function ( $r ) use ( $cols ) {
						$out = array();
						foreach ( $cols as $c ) {
							$out[ $c ] = $r[ $c ] ?? null;
						}
						return $out;
					},
					$rows
				)
			);
		}

		private function exec_update( string $table, string $set, string $where ): int {
			$this->need( $table );
			$assign = array();
			foreach ( $this->split_top( $set, ',' ) as $part ) {
				if ( ! preg_match( '/^(\w+)\s*=\s*(.+)$/s', trim( $part ), $pm ) ) {
					throw new RuntimeException( 'Mini_SQL_wpdb: unsupported SET: ' . $part );
				}
				$assign[ $pm[1] ] = trim( $pm[2] );
			}
			$limit = null;
			if ( preg_match( '/^(.*?)\s+LIMIT\s+\'?(\d+)\'?$/is', $where, $lm ) ) {
				$where = $lm[1];
				$limit = (int) $lm[2];
			}
			$n = 0;
			foreach ( $this->filter( $table, $where ) as $row ) {
				if ( null !== $limit && $n >= $limit ) {
					break;
				}
				$id = $row['id'];
				foreach ( $assign as $col => $expr ) {
					if ( preg_match( '/^GREATEST\(\s*(\w+)\s*,\s*(.+?)\s*\)$/i', $expr, $gm ) ) {
						$row[ $col ] = max( (int) $row[ $gm[1] ], (int) $this->value( $gm[2] ) );
					} else {
						$row[ $col ] = $this->value( $expr );
					}
				}
				$this->tables[ $table ]['rows'][ $id ] = $row;
				$n++;
			}
			return $n;
		}

		private function exec_delete( string $table, string $where ): int {
			$this->need( $table );
			$limit = null;
			if ( preg_match( '/^(.*?)\s+LIMIT\s+\'?(\d+)\'?$/is', $where, $lm ) ) {
				$where = $lm[1];
				$limit = (int) $lm[2];
			}
			$n = 0;
			foreach ( $this->filter( $table, $where ) as $row ) {
				if ( null !== $limit && $n >= $limit ) {
					break;
				}
				unset( $this->tables[ $table ]['rows'][ $row['id'] ] );
				$n++;
			}
			return $n;
		}

		private function filter( string $table, string $where ): array {
			$rows = isset( $this->views[ $table ] )
				? array_values( (array) call_user_func( $this->views[ $table ] ) )
				: array_values( $this->tables[ $table ]['rows'] );
			if ( '' === trim( $where ) ) {
				return $rows;
			}
			$preds = $this->split_and( $where );
			return array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $preds ) {
						foreach ( $preds as $p ) {
							if ( ! $this->match( $row, trim( $p ) ) ) {
								return false;
							}
						}
						return true;
					}
				)
			);
		}

		private function match( array $row, string $pred ): bool {
			if ( preg_match( '/^(\w+)\s+(NOT\s+)?IN\s*\(\s*(SELECT\s.+)\)$/is', $pred, $m ) ) {
				$cell = $row[ $m[1] ] ?? null;
				$hit  = false;
				foreach ( $this->select( $m[3] ) as $inner ) {
					if ( $this->cmp_eq( $cell, reset( $inner ) ) ) {
						$hit = true;
						break;
					}
				}
				return empty( $m[2] ) ? $hit : ! $hit;
			}
			if ( preg_match( '/^(\w+)\s+IN\s*\((.*)\)$/is', $pred, $m ) ) {
				$cell = $row[ $m[1] ] ?? null;
				foreach ( $this->split_top( $m[2], ',' ) as $v ) {
					if ( $this->cmp_eq( $cell, $this->value( trim( $v ) ) ) ) {
						return true;
					}
				}
				return false;
			}
			if ( preg_match( '/^(\w+)\s+IS\s+(NOT\s+)?NULL$/i', $pred, $m ) ) {
				$is_null = null === ( $row[ $m[1] ] ?? null );
				return empty( $m[2] ) ? $is_null : ! $is_null;
			}
			if ( preg_match( '/^(\w+)\s*(<>|!=|<=|>=|=|<|>)\s*(.+)$/s', $pred, $m ) ) {
				$cell = $row[ $m[1] ] ?? null;
				$val  = $this->value( trim( $m[3] ) );
				switch ( $m[2] ) {
					case '=':
						return $this->cmp_eq( $cell, $val );
					case '<>':
					case '!=':
						return ! $this->cmp_eq( $cell, $val );
					case '<':
						return $this->compare( $cell, $val ) < 0;
					case '<=':
						return $this->compare( $cell, $val ) <= 0;
					case '>':
						return $this->compare( $cell, $val ) > 0;
					case '>=':
						return $this->compare( $cell, $val ) >= 0;
				}
			}
			throw new RuntimeException( 'Mini_SQL_wpdb: unsupported predicate: ' . $pred );
		}

		/**
		 * Parse a SQL literal: 'quoted' (addslashes-escaped), number, NULL.
		 *
		 * @return mixed
		 */
		private function value( string $lit ) {
			$lit = trim( $lit );
			if ( 0 === strcasecmp( $lit, 'NULL' ) ) {
				return null;
			}
			if ( strlen( $lit ) >= 2 && "'" === $lit[0] && "'" === substr( $lit, -1 ) ) {
				return stripslashes( substr( $lit, 1, -1 ) );
			}
			if ( is_numeric( $lit ) ) {
				return $lit + 0;
			}
			throw new RuntimeException( 'Mini_SQL_wpdb: unsupported literal: ' . $lit );
		}

		/**
		 * Split a WHERE clause on top-level AND (an AND inside quotes or a
		 * parenthesised subquery belongs to that operand).
		 *
		 * @return string[]
		 */
		private function split_and( string $where ): array {
			$parts = array();
			$cur   = '';
			$in    = false;
			$depth = 0;
			$len   = strlen( $where );
			for ( $i = 0; $i < $len; $i++ ) {
				$ch = $where[ $i ];
				if ( '\\' === $ch && $in && $i + 1 < $len ) {
					$cur .= $ch . $where[ ++$i ];
					continue;
				}
				if ( "'" === $ch ) {
					$in = ! $in;
				} elseif ( ! $in && '(' === $ch ) {
					$depth++;
				} elseif ( ! $in && ')' === $ch ) {
					$depth--;
				}
				if ( ! $in && 0 === $depth && preg_match( '/\G\s+AND\s+/i', $where, $m, 0, $i ) ) {
					$parts[] = $cur;
					$cur     = '';
					$i      += strlen( $m[0] ) - 1;
					continue;
				}
				$cur .= $ch;
			}
			$parts[] = $cur;
			return $parts;
		}

		/**
		 * Split on a separator that is outside single-quoted strings and
		 * parentheses (so GREATEST(a, b) stays one expression).
		 */
		private function split_top( string $s, string $sep ): array {
			$parts = array();
			$cur   = '';
			$in    = false;
			$depth = 0;
			$len   = strlen( $s );
			for ( $i = 0; $i < $len; $i++ ) {
				$ch = $s[ $i ];
				if ( '\\' === $ch && $in && $i + 1 < $len ) {
					$cur .= $ch . $s[ ++$i ];
					continue;
				}
				if ( "'" === $ch ) {
					$in = ! $in;
				} elseif ( ! $in && '(' === $ch ) {
					$depth++;
				} elseif ( ! $in && ')' === $ch ) {
					$depth--;
				}
				if ( $ch === $sep && ! $in && 0 === $depth ) {
					$parts[] = $cur;
					$cur     = '';
					continue;
				}
				$cur .= $ch;
			}
			$parts[] = $cur;
			return $parts;
		}

		private function cmp_eq( $a, $b ): bool {
			if ( null === $a || null === $b ) {
				return $a === $b;
			}
			if ( is_numeric( $a ) && is_numeric( $b ) ) {
				return (float) $a === (float) $b;
			}
			return (string) $a === (string) $b;
		}

		private function compare( $a, $b ): int {
			if ( is_numeric( $a ) && is_numeric( $b ) ) {
				return (float) $a <=> (float) $b;
			}
			return strcmp( (string) $a, (string) $b );
		}
	}
}
