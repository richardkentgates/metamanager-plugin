<?php
/**
 * MM_Mod_Author — author archive SEO + Person schema nodes.
 *
 * Adds Person nodes on author archive pages.  On BlogPosting nodes
 * built by the Schema module, the author Person @id is referenced
 * by URL so this module can be loaded in any order.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mod author.
 *
 * @package Metamanager
 */
class MM_Mod_Author extends MM_Mod_Base {

	/**
	 * Populate.
	 *
	 * @param array            $data Data.
	 * @param MM_Page_Context  $context Context.
	 * @param MM_Site_Settings $settings Settings.
	 */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		if ( ! $settings->get( 'authors.enabled', true ) ) {
			return;
		}

		// Author archive Person node — controlled by its own setting.
		if ( $context->is_author() && $settings->get( 'authors.person_schema_archives', true ) ) {
			$author = $context->get_author();
			if ( $author ) {
				$this->add_node( $data, $this->build_person_node( $author, $settings ) );
			}
		}

		// Singular Person node (author of the post). Pages and posts have
		// separate settings; CPTs (mm_service, mm_trip, …) follow the posts
		// setting. Template pages (About, Contact, Calendar) are business
		// pages, not authored content — always skipped.
		if ( $context->is_singular() ) {
			$post = $context->get_post();
			if ( $post ) {
				if ( 'page' === $post->post_type ) {
					$template = MM_Schema_Post_Types::get_post_template( $post );
					if ( $template ) {
						return;
					}
					if ( ! $settings->get( 'authors.person_schema_pages', true ) ) {
						return;
					}
				} elseif ( ! $settings->get( 'authors.person_schema_posts', true ) ) {
					return;
				}
				$author = get_userdata( (int) $post->post_author );
				if ( $author ) {
					$this->add_node( $data, $this->build_person_node( $author, $settings ) );
				}
			}
		}
	}

	/**
	 * Build person node.
	 *
	 * @param \WP_User         $author Author.
	 * @param MM_Site_Settings $settings Settings.
	 * @return array Resulting array.
	 */
	private function build_person_node( \WP_User $author, MM_Site_Settings $settings ): array {
		$meta       = $settings->get_user_meta( $author->ID );
		$author_url = get_author_posts_url( $author->ID );

		$node = array(
			'@type' => 'Person',
			'@id'   => $author_url . '#person',
			'name'  => $author->display_name,
			'url'   => $author_url,
		);

		if ( $author->description ) {
			$node['description'] = $author->description;
		}

		// Avatar / profile picture.
		$avatar_url = get_avatar_url( $author->ID, array( 'size' => 256 ) );
		if ( $avatar_url ) {
			$node['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $avatar_url,
			);
		}

		// Social profiles — from user meta social fields.
		$social   = $meta['social'] ?? array();
		$same_as  = array();
		$profiles = array(
			'twitter'   => 'https://twitter.com/',
			'linkedin'  => 'https://www.linkedin.com/in/',
			'instagram' => 'https://www.instagram.com/',
			'bluesky'   => 'https://bsky.app/profile/',
			'website'   => '',
		);
		foreach ( $profiles as $key => $prefix ) {
			$value = trim( $social[ $key ] ?? '' );
			if ( $value ) {
				if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$same_as[] = $value;
				} elseif ( $prefix ) {
					$same_as[] = $prefix . ltrim( $value, '@/' );
				}
			}
		}
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}

		return $node;
	}
}
