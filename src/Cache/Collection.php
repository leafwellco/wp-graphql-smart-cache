<?php
/**
 * When processing a GraphQL query, collect nodes based on the query and url they are part of.
 * When content changes for nodes, invalidate and trigger actions that allow caches to be invalidated for nodes, queries, urls.
 */

namespace WPGraphQL\Labs\Cache;

use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\TypeInfo;
use WPGraphQL\Labs\Admin\Settings;
use GraphQLRelay\Relay;

class Collection extends Query {

	// Nodes that are part of the current/in-progress/excuting query
	public $nodes = [];

	// whether the query is a query (not a mutation or subscription)
	public $is_query;

	// Types that are referenced in the query
	public $type_names = [];

	public function init() {
		add_action( 'graphql_return_response', [ $this, 'save_query_mapping_cb' ], 10, 7 );
		add_filter( 'pre_graphql_execute_request', [ $this, 'before_executing_query_cb' ], 10, 2 );
		add_filter( 'graphql_dataloader_get_model', [ $this, 'data_loaded_process_cb' ], 10, 1 );

		add_action( 'graphql_after_resolve_field', [ $this, 'during_query_resolve_field' ], 10, 6 );

		// post
		add_action( 'wp_insert_post', [ $this, 'on_post_change_cb' ], 10, 3 );
		// user/author
		add_filter( 'insert_user_meta', [ $this, 'on_user_change_cb' ], 10, 3 );
		// meta For acf, which calls WP function update_metadata
		add_action( 'updated_postmeta', [ $this, 'on_postmeta_change_cb' ], 10, 4 );

		add_action(
			'graphql_before_execute',
			function ( $request ) {
				$schema = \WPGraphQL::get_schema();
				$query  = $request->params->query ?? null;
				$this->get_query_types( $schema, $query );
			},
			10,
			1
		);

		parent::init();
	}

	public function before_executing_query_cb( $result, $request ) {
		// Consider this the start of query execution. Clear if we had a list of saved nodes
		$this->runtime_nodes    = [];
		$this->connection_names = [];
		return $result;
	}

	/**
	 * Filter the model before returning.
	 *
	 * @param mixed              $model The Model to be returned by the loader
	 * @param mixed              $entry The entry loaded by dataloader that was used to create the Model
	 * @param mixed              $key   The Key that was used to load the entry
	 * @param AbstractDataLoader $this  The AbstractDataLoader Instance
	 */
	public function data_loaded_process_cb( $model ) {
		if ( $model->id ) {
			$this->runtime_nodes[] = $model->id;
		}
		return $model;
	}

	/**
	 * An action after the field resolves
	 *
	 * @param mixed           $source    The source passed down the Resolve Tree
	 * @param array           $args      The args for the field
	 * @param AppContext      $context   The AppContext passed down the ResolveTree
	 * @param ResolveInfo     $info      The ResolveInfo passed down the ResolveTree
	 * @param string          $type_name The name of the type the fields belong to
	 */
	public function during_query_resolve_field( $source, $args, $context, $info, $field_resolver, $type_name ) {
		// If at any point while processing fields and it shows this request is a query, track that.
		if ( 'RootQuery' === $type_name ) {
			$this->is_query = true;
		}
	}

	/**
	 * Unique identifier for this request for use in the collection map
	 *
	 * @param string $request_key Id for the node
	 *
	 * @return string unique id for this request
	 */
	public function nodes_key( $request_key ) {
		return 'node:' . $request_key;
	}

	/**
	 * Unique identifier for this request for use in the collection map
	 *
	 * @param string $request_key Id for the node
	 *
	 * @return string unique id for this request
	 */
	public function urls_key( $request_key ) {
		return 'url:' . $request_key;
	}

	/**
	 * @param string $key The identifier to the list
	 * @param string $content to add
	 * @return array The unique list of content stored
	 */
	public function store_content( $key, $content ) {
		$data   = $this->get( $key );
		$data[] = $content;
		$data   = array_unique( $data );
		$this->save( $key, $data );
		return $data;
	}

	/**
	 * @param $id The content node identifier
	 * @return array The unique list of content stored
	 */
	public function retrieve_nodes( $id ) {
		$key = $this->nodes_key( $id );
		return $this->get( $key );
	}

	/**
	 * @param $id The content node identifier
	 * @return array The unique list of content stored
	 */
	public function retrieve_urls( $id ) {
		$key = $this->urls_key( $id );
		return $this->get( $key );
	}

	/**
	 * Given the Schema and a query string, return a list of GraphQL Types that are being asked for
	 * by the query.
	 *
	 * @param Schema $schema The WPGraphQL Schema
	 * @param string $query  The query string
	 *
	 * @return array
	 * @throws SyntaxError
	 */
	public function get_query_types( $schema, $query ) {
		$type_info = new TypeInfo( $schema );
		$ast       = Parser::parse( $query );
		$type_map  = [];

		$visitor = [
			'enter' => function ( $node ) use ( $type_info, &$type_map, $schema ) {
				$type_info->enter( $node );
				$type       = $type_info->getType();
				$named_type = Type::getNamedType( $type );

				if ( $named_type instanceof InterfaceType ) {
					$possible_types = $schema->getPossibleTypes( $named_type );
					foreach ( $possible_types as $possible_type ) {
						$type_map[] = $possible_type;
					}
				} else {
					$type_map[] = $named_type;
				}
			},
			'leave' => function ( $node ) use ( $type_info ) {
				$type_info->leave( $node );
			},
		];

		Visitor::visit( $ast, $visitor );
		return $type_map;
	}

	/**
	 * When a query response is being returned to the client, build map for each item and this query/queryId
	 * That way we will know what to invalidate on data change.
	 *
	 * @param $filtered_response GraphQL\Executor\ExecutionResult
	 * @param $response GraphQL\Executor\ExecutionResult
	 * @param $request WPGraphQL\Request
	 *
	 * @return void
	 */
	public function save_query_mapping_cb(
		$filtered_response,
		$response,
		$schema,
		$operation,
		$query,
		$variables,
		$request
	) {
		$request_key = $this->build_key( $request->params->queryId, $request->params->query, $request->params->variables, $request->params->operation );

		// Only store mappings of urls when it's a GET request
		$map_the_url = false;
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			$map_the_url = true;
		}

		// We don't want POSTs during mutations or nothing on the url. cause it'll purge /graphql*
		if ( $map_the_url && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			//phpcs:ignore
			$url_to_save = wp_unslash( $_SERVER['REQUEST_URI'] );

			// Save the url this query request came in on, so we can purge it later when something changes
			$urls = $this->store_content( $this->urls_key( $request_key ), $url_to_save );

			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
			error_log( "Graphql Save Urls: $request_key " . print_r( $urls, 1 ) );
		}

		// Save/add the node ids for this query.  When one of these change in the future, we can purge the query
		foreach ( $this->runtime_nodes as $node_id ) {
			$this->store_content( $this->nodes_key( $node_id ), $request_key );
		}

		$this->type_names = $this->get_query_types( $schema, $query );

		// For each connection resolver, store the url key
		if ( is_array( $this->type_names ) ) {
			$this->type_names = array_unique( $this->type_names );
			foreach ( $this->type_names as $type_name ) {
				$this->store_content( $type_name, $request_key );
			}
		}

		if ( is_array( $this->runtime_nodes ) ) {
			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
			error_log( "Graphql Save Nodes: $request_key " . print_r( $this->runtime_nodes, 1 ) );
		}
	}

	/**
	 * Fires once a post has been saved.
	 * Purge our saved/cached results data.
	 *
	 * @param int     $post_ID Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update Whether the post is being updated rather than created.
	 */
	public function on_post_change_cb( $post_id, $post, $update ) {
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// When any post changes, look up graphql queries previously queried containing post resources and purge those
		// Look up the specific post/node/resource to purge vs $this->purge_all();
		$id    = Relay::toGlobalId( 'post', $post_id );
		$nodes = $this->retrieve_nodes( $id );

		// Delete the cached results associated with this post/key
		if ( is_array( $nodes ) ) {
			do_action( 'wpgraphql_cache_purge_nodes', 'post', $this->nodes_key( $id ), $nodes );
		}

		// if created, clear any cached connection lists for this type
		if ( false === $update ) {
			$posts = $this->get( 'post' );
			if ( is_array( $posts ) ) {
				$post_type       = get_post_type( $post_id );
				$post_object     = get_post_type_object( $post_type );
				$connection_name = strtolower( $post_object->graphql_single_name );
				do_action( 'wpgraphql_cache_purge_nodes', 'post', $connection_name, $posts );
			}
		}
	}

	/**
	 *
	 * @param array $meta
	 * @param WP_User $user   User object.
	 * @param bool    $update Whether the user is being updated rather than created.
	 */
	public function on_user_change_cb( $meta, $user, $update ) {
		if ( false === $update ) {
			// if created, clear any cached connection lists for this type
			do_action( 'wpgraphql_cache_purge_nodes', 'user', 'users', [] );
		} else {
			$id    = Relay::toGlobalId( 'user', (string) $user->ID );
			$nodes = $this->retrieve_nodes( $id );

			// Delete the cached results associated with this key
			if ( is_array( $nodes ) ) {
				do_action( 'wpgraphql_cache_purge_nodes', 'user', $this->nodes_key( $id ), $nodes );
			}
		}
		return $meta;
	}

	/**
	 * @param int    $meta_id    ID of updated metadata entry.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value. This will be a PHP-serialized string representation of the value
	 *                           if the value is an array, an object, or itself a PHP-serialized string.
	 */
	public function on_postmeta_change_cb( $meta_id, $post_id, $meta_key, $meta_value ) {
		// When any post changes, look up graphql queries previously queried containing post resources and purge those
		// Look up the specific post/node/resource to purge vs $this->purge_all();
		$post_type = get_post_type( $post_id );
		$id        = Relay::toGlobalId( $post_type, $post_id );
		$nodes     = $this->retrieve_nodes( $id );

		// Delete the cached results associated with this post/key
		if ( is_array( $nodes ) ) {
			do_action( 'wpgraphql_cache_purge_nodes', $post_type, $this->nodes_key( $id ), $nodes );
		}

		// clear any cached connection lists for this type
		$post_object     = get_post_type_object( $post_type );
		$connection_name = strtolower( $post_object->graphql_single_name );
		if ( $connection_name ) {
			$posts = $this->get( $connection_name );
			if ( is_array( $posts ) ) {
				do_action( 'wpgraphql_cache_purge_nodes', $post_type, $connection_name, $posts );
			}
		}
	}
}
