<?php

/**
 * Test the RSS 2.0 feed by generating a feed, parsing it, and checking that the
 * parsed contents match the contents of the posts stored in the database.  Since
 * we're using a real XML parser, this confirms that the feed is valid, well formed,
 * and contains the right stuff.
 *
 * @group feed
 */
class Tests_Feeds_RSS2 extends WP_UnitTestCase {
	static $user_id;
	static $posts;
	static $category;
	static $post_date;

	/**
	 * Setup a new user and attribute some posts.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		// Create a user
		self::$user_id = $factory->user->create(
			array(
				'role'         => 'author',
				'user_login'   => 'test_author',
				'display_name' => 'Test A. Uthor',
			)
		);

		// Create a taxonomy
		self::$category = $factory->category->create_and_get(
			array(
				'name' => 'Foo Category',
				'slug' => 'foo',
			)
		);

		// Set a predictable time for testing date archives.
		self::$post_date = strtotime( '2003-05-27 10:07:53' );

		// By default the option is 0 and rewrite rules for feeds to not work.
		// Setting it to non zero for the test to make sense.
		update_option( 'posts_per_rss', 5 );

		$count = get_option( 'posts_per_rss' ) + 1;

		self::$posts = array();
		// Create a few posts
		for ( $i = 1; $i <= $count; $i++ ) {
			self::$posts[] = $factory->post->create(
				array(
					'post_author'  => self::$user_id,
					// Separate post dates 5 seconds apart.
					'post_date'    => gmdate( 'Y-m-d H:i:s', self::$post_date + ( 5 * $i ) ),
					'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Donec velit massa, ultrices eu est suscipit, mattis posuere est. Donec vitae purus lacus. Cras vitae odio odio.',
					'post_excerpt' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
					'comment_status' => ( $i === 1) ? 'open' : '',
				)
			);
		}

		// Assign a category to those posts
		foreach ( self::$posts as $post ) {
			wp_set_object_terms( $post, self::$category->slug, 'category' );
		}

		// Assign authors to some posts.
		$term1 = wp_insert_term( 'author 1', calmpress\post_authors\Post_Authors_As_Taxonomy::TAXONOMY_NAME, [] );
		$term2 = wp_insert_term( 'second', calmpress\post_authors\Post_Authors_As_Taxonomy::TAXONOMY_NAME, [] );

		wp_set_object_terms( self::$posts[0], [ $term1['term_id'], $term2['term_id'] ], calmpress\post_authors\Post_Authors_As_Taxonomy::TAXONOMY_NAME );
		wp_set_object_terms( self::$posts[1], [ $term1['term_id'] ], calmpress\post_authors\Post_Authors_As_Taxonomy::TAXONOMY_NAME );
		wp_set_object_terms( self::$posts[2], [ $term2['term_id'] ], calmpress\post_authors\Post_Authors_As_Taxonomy::TAXONOMY_NAME );
	}

	/**
	 * Setup.
	 */
	public function setUp() {
		parent::setUp();

		$this->post_count   = (int) get_option( 'posts_per_rss' );
		$this->excerpt_only = get_option( 'rss_use_excerpt' );
		// this seems to break something

		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		create_initial_taxonomies();
	}

	/**
	 * This is a bit of a hack used to buffer feed content.
	 */
	function do_rss2() {
		ob_start();
		// Nasty hack! In the future it would better to leverage do_feed( 'rss2' ).
		global $post;
		try {
			@require( ABSPATH . 'wp-includes/feed-rss2.php' );
			$out = ob_get_clean();
		} catch ( Exception $e ) {
			$out = ob_get_clean();
			throw($e);
		}
		return $out;
	}

	/**
	 * Test the <rss> element to make sure its present and populated
	 * with the expected child elements and attributes.
	 */
	function test_rss_element() {
		$this->go_to( '/feed/' );
		$feed = $this->do_rss2();
		$xml  = xml_to_array( $feed );

		// Get the <rss> child element of <xml>.
		$rss = xml_find( $xml, 'rss' );

		// There should only be one <rss> child element.
		$this->assertEquals( 1, count( $rss ) );

		$this->assertEquals( '2.0', $rss[0]['attributes']['version'] );
		$this->assertEquals( 'http://purl.org/rss/1.0/modules/content/', $rss[0]['attributes']['xmlns:content'] );
		$this->assertEquals( 'http://purl.org/dc/elements/1.1/', $rss[0]['attributes']['xmlns:dc'] );

		// rss should have exactly one child element (channel)
		$this->assertEquals( 1, count( $rss[0]['child'] ) );
	}

	/**
	 * [test_channel_element description]
	 *
	 * @return [type] [description]
	 */
	function test_channel_element() {
		$this->go_to( '/feed/' );
		$feed = $this->do_rss2();
		$xml  = xml_to_array( $feed );

		// get the rss -> channel element
		$channel = xml_find( $xml, 'rss', 'channel' );

		// The channel should be free of attributes
		$this->assertTrue( empty( $channel[0]['attributes'] ) );

		// Verify the channel is present and contains a title child element
		$title = xml_find( $xml, 'rss', 'channel', 'title' );
		$this->assertEquals( get_option( 'blogname' ), $title[0]['content'] );

		$desc = xml_find( $xml, 'rss', 'channel', 'description' );
		$this->assertEquals( get_option( 'blogdescription' ), $desc[0]['content'] );

		$link = xml_find( $xml, 'rss', 'channel', 'link' );
		$this->assertEquals( get_option( 'siteurl' ), $link[0]['content'] );

		$pubdate = xml_find( $xml, 'rss', 'channel', 'lastBuildDate' );
		$this->assertEquals( strtotime( get_lastpostmodified() ), strtotime( $pubdate[0]['content'] ) );
	}

	/**
	 * Test that translated feeds have a valid listed date.
	 *
	 * @group 39141
	 */
	function test_channel_pubdate_element_translated() {
		$original_locale = $GLOBALS['wp_locale'];
		/* @var WP_Locale $locale */
		$locale = clone $GLOBALS['wp_locale'];

		$locale->weekday[2]                           = 'Tuesday_Translated';
		$locale->weekday_abbrev['Tuesday_Translated'] = 'Tue_Translated';

		$GLOBALS['wp_locale'] = $locale;

		$this->go_to( '/feed/' );
		$feed = $this->do_rss2();

		// Restore original locale.
		$GLOBALS['wp_locale'] = $original_locale;

		$xml = xml_to_array( $feed );

		// Verify the date is untranslated.
		$pubdate = xml_find( $xml, 'rss', 'channel', 'lastBuildDate' );
		$this->assertNotContains( 'Tue_Translated', $pubdate[0]['content'] );
	}

	function test_item_elements() {
		global $post;
		$this->go_to( '/feed/' );
		$feed = $this->do_rss2();
		$xml  = xml_to_array( $feed );

		// Get all the <item> child elements of the <channel> element
		$items = xml_find( $xml, 'rss', 'channel', 'item' );

		// Verify we are displaying the correct number of posts.
		$this->assertCount( $this->post_count, $items );

		// We Really only need to test X number of items unless the content is different
		$items = array_slice( $items, 1 );

		// Check each of the desired entries against the known post data
		foreach ( $items as $key => $item ) {

			// Get post for comparison
			$guid = xml_find( $items[$key]['child'], 'guid' );
			$post = get_post( url_to_postid( $guid[0]['content'] ) );

			// Title
			$title = xml_find( $items[ $key ]['child'], 'title' );
			$this->assertEquals( $post->post_title, $title[0]['content'] );

			// Link
			$link = xml_find( $items[ $key ]['child'], 'link' );
			$this->assertEquals( get_permalink( $post ), $link[0]['content'] );

			// Comment link.
			$comments_link = xml_find( $items[ $key ]['child'], 'comments' );
			// It is there only if comments are open.
			if ( 'open' === $post->comment_open ) {
				$this->assertEquals( get_permalink( $post ) . '#respond', $comments_link[0]['content'] );
			} else {
				$this->assertEquals( 0, count( $comments_link ) );
			}

			// Pub date
			$pubdate = xml_find( $items[ $key ]['child'], 'pubDate' );
			$this->assertEquals( strtotime( $post->post_date_gmt ), strtotime( $pubdate[0]['content'] ) );

			// Author
			$creator = xml_find( $items[$key]['child'], 'dc:creator' );
			if ( ! isset( $creator[0]['content'] ) ) {
				// Will happen in case there is no author.
				$this->assertEmpty( get_the_author() );
			} else {
				$this->assertEquals( get_the_author(), $creator[0]['content'] );
			}

			// Categories (perhaps multiple)
			$categories = xml_find( $items[ $key ]['child'], 'category' );
			$cats       = array();
			foreach ( get_the_category( $post->ID ) as $term ) {
				$cats[] = $term->name;
			}

			$tags = get_the_tags( $post->ID );
			if ( $tags ) {
				foreach ( get_the_tags( $post->ID ) as $term ) {
					$cats[] = $term->name;
				}
			}
			$cats = array_filter( $cats );
			// Should be the same number of categories
			$this->assertEquals( count( $cats ), count( $categories ) );

			// ..with the same names
			foreach ( $cats as $id => $cat ) {
				$this->assertEquals( $cat, $categories[ $id ]['content'] );
			}

			// GUID
			$guid = xml_find( $items[ $key ]['child'], 'guid' );
			$this->assertEquals( 'false', $guid[0]['attributes']['isPermaLink'] );
			$this->assertEquals( $post->guid, $guid[0]['content'] );

			// Description / Excerpt
			if ( ! empty( $post->post_excerpt ) ) {
				$description = xml_find( $items[ $key ]['child'], 'description' );
				$this->assertEquals( trim( $post->post_excerpt ), trim( $description[0]['content'] ) );
			}

			// Post content
			if ( ! $this->excerpt_only ) {
				$content = xml_find( $items[ $key ]['child'], 'content:encoded' );
				$this->assertEquals( trim( apply_filters( 'the_content', $post->post_content ) ), trim( $content[0]['content'] ) );
			}
		}
	}

	/**
	 * @ticket 9134
	 */
	function test_items_comments_closed() {
		add_filter( 'comments_open', '__return_false' );

		$this->go_to( '/feed/' );
		$feed = $this->do_rss2();
		$xml  = xml_to_array( $feed );

		// get all the rss -> channel -> item elements
		$items = xml_find( $xml, 'rss', 'channel', 'item' );

		// check each of the items against the known post data
		foreach ( $items as $key => $item ) {
			// Get post for comparison
			$guid = xml_find( $items[$key]['child'], 'guid' );
			$post = get_post( url_to_postid( $guid[0]['content'] ) );

			// comment link
			$comments_link = xml_find( $items[ $key ]['child'], 'comments' );
			$this->assertEmpty( $comments_link );
		}

		remove_filter( 'comments_open', '__return_false' );
	}

	/*
	 * Check to make sure we are rendering feed templates for the home feed.
	 * e.g. https://example.com/feed/
	 *
	 * @ticket 30210
	 */
	function test_valid_home_feed_endpoint() {
		// An example of a valid home feed endpoint.
		$this->go_to( 'feed/' );

		// Verify the query object is a feed.
		$this->assertQueryTrue( 'is_feed' );

		// Queries performed on valid feed endpoints should contain posts.
		$this->assertTrue( have_posts() );

		// Check to see if we have the expected XML output from the feed template.
		$feed = $this->do_rss2();

		$xml = xml_to_array( $feed );

		// Get the <rss> child element of <xml>.
		$rss = xml_find( $xml, 'rss' );

		// There should only be one <rss> child element.
		$this->assertEquals( 1, count( $rss ) );
	}

	/*
	 * Check to make sure we are rendering feed templates for the taxonomy feeds.
	 * e.g. https://example.com/category/foo/feed/
	 *
	 * @ticket 30210
	 */
	function test_valid_taxonomy_feed_endpoint() {
		// An example of an valid taxonomy feed endpoint.
		$this->go_to( 'category/foo/feed/' );

		// Verify the query object is a feed.
		$this->assertQueryTrue( 'is_feed', 'is_archive', 'is_category' );

		// Queries performed on valid feed endpoints should contain posts.
		$this->assertTrue( have_posts() );

		// Check to see if we have the expected XML output from the feed template.
		$feed = $this->do_rss2();

		$xml = xml_to_array( $feed );

		// Get the <rss> child element of <xml>.
		$rss = xml_find( $xml, 'rss' );

		// There should only be one <rss> child element.
		$this->assertEquals( 1, count( $rss ) );
	}

	/*
	 * Check to make sure we are rendering feed templates for the search archive feeds.
	 *
	 * @ticket 30210
	 */
	function test_valid_search_feed_endpoint() {
		// An example of an valid search feed endpoint
		$this->go_to( '/search/Lorem/rss2' );

		// Verify the query object is a feed.
		$this->assertQueryTrue( 'is_feed', 'is_search' );

		// Queries performed on valid feed endpoints should contain posts.
		$this->assertTrue( have_posts() );

		// Check to see if we have the expected XML output from the feed template.
		$feed = $this->do_rss2();

		$xml = xml_to_array( $feed );

		// Get the <rss> child element of <xml>.
		$rss = xml_find( $xml, 'rss' );

		// There should only be one <rss> child element.
		$this->assertEquals( 1, count( $rss ) );
	}

	/**
	 * Test <rss> element has correct last build date.
	 *
	 * @ticket 4575
	 *
	 * @dataProvider data_test_get_feed_build_date
	 */
	public function test_get_feed_build_date( $url, $element ) {
		$this->go_to( $url );
		$feed = $this->do_rss2();
		$xml  = xml_to_array( $feed );

		// Get the <rss> child element of <xml>.
		$rss             = xml_find( $xml, $element );
		$last_build_date = $rss[0]['child'][0]['child'][4]['content'];
		$this->assertEquals( strtotime( get_feed_build_date( 'r' ) ), strtotime( $last_build_date ) );
	}


	public function data_test_get_feed_build_date() {
		return array(
			array( '/?feed=rss2', 'rss' ),
			array( '/?feed=commentsrss2', 'rss' ),
		);

	}
}
