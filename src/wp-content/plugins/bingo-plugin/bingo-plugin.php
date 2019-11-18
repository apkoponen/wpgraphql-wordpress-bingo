<?php
/**
 * Plugin Name: Original Bingo!
 * Description: Let's expose a bingo GraphQL API!
 * Version:     0.0.0.0.1
 * Author:      A-P
 * License:     GPL2 etc
 */

use GraphQL\Error\UserError;
use WPGraphQL\Model\Post;


add_action( 'init', function () {
	register_post_type( 'bingo_games', [
		'show_ui'             => true,
		'labels'              => [
			'menu_name' => __( 'Bingo Games', 'bingo' ),
		],
		'menu_icon'           => 'dashicons-grid-view',
		'show_in_graphql'     => true,
		'hierarchical'        => false,
		'supports'            => [ 'title' ],
		'graphql_single_name' => 'BingoGame',
		'graphql_plural_name' => 'BingoGames',
	] );

	register_post_type( 'bingo_players', [
		'show_ui'             => true,
		'labels'              => [
			'menu_name' => __( 'Bingo Players', 'bingo' ),
		],
		'menu_icon'           => 'dashicons-admin-users',
		'show_in_graphql'     => true,
		'hierarchical'        => false,
		'supports'            => [ 'title' ],
		'graphql_single_name' => 'BingoPlayer',
		'graphql_plural_name' => 'BingoPlayers',
	] );

	register_post_type( 'bingo_teams', [
		'show_ui'             => true,
		'labels'              => [
			'menu_name' => __( 'Bingo Teams', 'bingo' ),
		],
		'menu_icon'           => 'dashicons-groups',
		'show_in_graphql'     => true,
		'hierarchical'        => false,
		'supports'            => [ 'title' ],
		'graphql_single_name' => 'BingoTeam',
		'graphql_plural_name' => 'BingoTeams',
	] );
} );

add_action( 'graphql_register_types', function () {
	register_graphql_field( 'BingoGame', 'words', [
		'type'        => [ 'list_of' => 'String' ],
		'description' => __( 'The terms of the bingo', 'bingo' ),
		'resolve'     => function ( $post ) {
			$terms = get_field( 'terms', $post->ID );

			return ! empty( $terms ) ? array_map( function ( $term ) {
				return $term['term'];
			}, $terms ) : [];
		}
	] );

	register_graphql_field( 'BingoGame', 'players', [
		'type'        => [ 'list_of' => 'BingoPlayer' ],
		'description' => __( 'The players of the bingo', 'bingo' ),
		'resolve'     => function ( $post ) {
			$relationship = [];
			$value        = get_field( 'players', $post->ID, false );
			if ( ! empty( $value ) && is_array( $value ) ) {
				foreach ( $value as $post_id ) {
					$post_object = get_post( $post_id );
					if ( $post_object instanceof \WP_Post ) {
						$post_model     = new Post( $post_object );
						$relationship[] = $post_model;
					}
				}
			}

			return ! empty( $value ) ? $relationship : null;
		}
	] );

	register_graphql_field( 'BingoPlayer', 'team', [
		'type'        => 'BingoTeam',
		'description' => __( 'The team of the bingo player', 'bingo' ),
		'resolve'     => function ( $post ) {
			$relationship = [];
			$value        = get_field( 'team', $post->ID, false );
			if ( ! empty( $value ) && is_array( $value ) ) {
				foreach ( $value as $post_id ) {
					$post_object = get_post( $post_id );
					if ( $post_object instanceof \WP_Post ) {
						$post_model     = new Post( $post_object );
						$relationship[] = $post_model;
					}
				}
			}

			return ! empty( $relationship ) ? $relationship[0] : null;
		}
	] );


	register_graphql_mutation( 'addPlayerToBingo', [
		'inputFields'         => [
			'playerTitle' => [
				'type'        => 'String',
				'description' => __( 'Name of the player', 'bingo' ),
			],
			'teamTitle'   => [
				'type'        => 'String',
				'description' => __( 'Name of the team', 'bingo' ),
			],
			'gameTitle'   => [
				'type'        => 'String',
				'description' => __( 'Name of the bingo game', 'bingo' ),
			]
		],
		'outputFields'        => [
			'gameTitle' => [
				'type'        => 'String',
				'description' => __( 'Name of the bingo game', 'bingo' ),
			]
		],
		'mutateAndGetPayload' => function ( $input, $context, $info ) {
			$gameTitle = $input['gameTitle'];
			if ( empty( $gameTitle ) ) {
				throw new UserError( __( 'No title provided for game', 'bingo' ) );
			}
			$playerTitle = $input['playerTitle'];
			if ( empty( $playerTitle ) ) {
				throw new UserError( __( 'No title provided for player', 'bingo' ) );
			}
			$teamTitle = $input['teamTitle'];
			if ( empty( $teamTitle ) ) {
				throw new UserError( __( 'No title provided for team', 'bingo' ) );
			}
			$gameId = bingo_post_exists( $gameTitle, 'bingo_games' );
			if ( empty( $gameId ) ) {
				throw new UserError( __( 'Game does not exist', 'bingo' ) );
			}
			$playerId = bingo_post_exists( $playerTitle, 'bingo_players' );
			if ( empty( $playerId ) ) {
				$playerId = wp_insert_post( [ 'post_title'  => $playerTitle,
				                              'post_type'   => 'bingo_players',
				                              'post_status' => 'publish'
				] );
				if ( empty( $playerId ) ) {
					throw new UserError( __( 'Player creation failed', 'bingo' ) );
				}
			}
			$teamId = bingo_post_exists( $teamTitle, 'bingo_teams' );
			if ( empty( $teamId ) ) {
				$teamId = wp_insert_post( [ 'post_title'  => $teamTitle,
				                            'post_type'   => 'bingo_teams',
				                            'post_status' => 'publish'
				] );
				if ( empty( $teamId ) ) {
					throw new UserError( __( 'Team creation failed', 'bingo' ) );
				}
			}


			update_field( 'team', [ $teamId ], $playerId );
			$active_players = get_field( 'players', $gameId, false );
			$updated_active_players = empty($active_players)
				? [$playerId]
				: array_values(array_unique(array_merge($active_players, [$playerId])));
			update_field( 'players', $updated_active_players, $gameId );

			return [
				'playerTitle' => $playerTitle,
				'teamTitle'   => $teamTitle,
				'gameTitle'   => $gameTitle
			];
		}
	] );
} );

function bingo_post_exists( $title, $type = '' ) {
	global $wpdb;

	$post_title = wp_unslash( sanitize_post_field( 'post_title', $title, 0, 'db' ) );
	$post_type  = wp_unslash( sanitize_post_field( 'post_type', $type, 0, 'db' ) );

	$query = "SELECT ID FROM $wpdb->posts WHERE 1=1";
	$args  = array();


	if ( ! empty( $title ) ) {
		$query  .= ' AND post_title = %s';
		$args[] = $post_title;
	}

	if ( ! empty( $type ) ) {
		$query  .= ' AND post_type = %s';
		$args[] = $post_type;
	}

	if ( ! empty( $args ) ) {
		return (int) $wpdb->get_var( $wpdb->prepare( $query, $args ) );
	}

	return 0;
}