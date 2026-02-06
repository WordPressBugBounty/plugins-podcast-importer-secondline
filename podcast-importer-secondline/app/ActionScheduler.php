<?php

namespace PodcastImporterSecondLine;

use PodcastImporterSecondLine\Helper\Importer as PIS_Helper_Importer;
use PodcastImporterSecondLine\Helper\Scheduler as PIS_Helper_Scheduler;
use PodcastImporterSecondLine\Settings as PIS_Settings;

class ActionScheduler {

  /**
   * @var null|ActionScheduler;
   */
  protected static $_instance = null;

  /**
   * @return ActionScheduler
   */
  public static function instance(): ActionScheduler {
    if( self::$_instance === null )
      self::$_instance = new self();

    return self::$_instance;
  }

  private $_added_events = false;

  public function setup() {
    // Async Scheduled
    add_action( 'action_scheduler_begin_execute', [ $this, '_action_scheduler_begin_execute' ] );
    add_action( PODCAST_IMPORTER_SECONDLINE_ALIAS . '_scheduler_image_sync', [ $this, '_image_sync' ], 10, 2 );
    add_action( PODCAST_IMPORTER_SECONDLINE_ALIAS . '_scheduler_images_md5_checksum', [ $this, '_images_md5_checksum' ], 10, 1 );
  }

  public function _action_scheduler_begin_execute() {
    if( $this->_added_events )
      return;

    $post_ids = get_posts( [
      'post_type'    	 => PODCAST_IMPORTER_SECONDLINE_POST_TYPE_IMPORT,
      'posts_per_page' => -1,
      'fields'         => 'ids'
    ] );

    foreach( $post_ids as $post_id )
      add_action( PODCAST_IMPORTER_SECONDLINE_SCHEDULER_FEED_PREFIX . $post_id, [ $this, '_feed_sync' ], 10, 2 );

    $this->_added_events = true;
  }

  public function _feed_sync( $feed_post_id ) {
    $all_meta = get_post_meta( $feed_post_id );
    $meta_map = [];

    foreach( $all_meta as $k => $v ) {
      if( is_array( $v ) && count( $v ) === 1 )
        $v = maybe_unserialize( $v[ 0 ] );

      $meta_map[ $k ] = $v;
    }

    // Maybe deleted after queued, need to ensure it's fine.
    if( isset( $meta_map[ 'secondline_rss_feed' ] ) ) {
      $importer = PIS_Helper_Importer::from_meta_map( $meta_map );
      $response = $importer->import_current_feed();
    }
  }

  public function _image_sync( $post_id, $image_path ) {
    $post_id = intval( $post_id );

    // Early exit if post no longer exists (deleted after queue)
    if( !get_post_status( $post_id ) )
      return;

    // Early exit if post already has a thumbnail (safety check for queued actions)
    if( has_post_thumbnail( $post_id ) )
      return;

    // Check retry count - stop after max retries to prevent infinite loop
    $retry_count = intval( get_post_meta( $post_id, '_secondline_image_retry_count', true ) );
    $max_retries = apply_filters( 'podcast_importer_secondline_image_max_retries', 3 );

    if( $retry_count >= $max_retries ) {
      // Max retries reached, mark as permanently failed and stop
      update_post_meta( $post_id, '_secondline_image_import_failed', true );
      return;
    }

    if( !function_exists( 'media_sideload_image' ) ) {
      require_once( ABSPATH . 'wp-admin/includes/media.php' );
      require_once( ABSPATH . 'wp-admin/includes/file.php' );
      require_once( ABSPATH . 'wp-admin/includes/image.php' );
    }

    $corrected_image_path = str_replace('?.jpg', '?img.jpg', $image_path);
    $image_response = wp_remote_get( $corrected_image_path );

    // Check for WP_Error from wp_remote_get
    if( is_wp_error( $image_response ) ) {
      update_post_meta( $post_id, '_secondline_image_retry_count', $retry_count + 1 );
      return;
    }

    $image_contents = wp_remote_retrieve_body( $image_response );

    if( empty( $image_contents ) ) {
      update_post_meta( $post_id, '_secondline_image_retry_count', $retry_count + 1 );
      return;
    }

    $image_md5 = md5( $image_contents );

    global $wpdb;

    $attachment_post_id = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'secondline_attachment_md5' AND meta_value = %s",
        $image_md5
      )
    );

    if( null === $attachment_post_id ) {
      $attachment_post_id = media_sideload_image( $corrected_image_path, $post_id, get_the_title( $post_id ), 'id' );

      if( is_wp_error( $attachment_post_id ) ) {
        update_post_meta( $post_id, '_secondline_image_retry_count', $retry_count + 1 );
        return;
      }

      update_post_meta( $attachment_post_id, 'secondline_attachment_md5', $image_md5 );
    } else {
      $attachment_post_id = intval( $attachment_post_id );
    }

    $result = set_post_thumbnail( $post_id, $attachment_post_id );

    // If thumbnail was set successfully, clear retry count
    if( $result ) {
      delete_post_meta( $post_id, '_secondline_image_retry_count' );
      delete_post_meta( $post_id, '_secondline_image_import_failed' );
    } else {
      // set_post_thumbnail failed
      update_post_meta( $post_id, '_secondline_image_retry_count', $retry_count + 1 );
    }
  }

  public function _images_md5_checksum( $attachment_ids ) {
    foreach( $attachment_ids as $attachment_id ) {
      $url = wp_get_attachment_url( $attachment_id );

      if( empty( $url ) || is_array( $url ) )
        continue;

      $image_contents = wp_remote_get( $url );
      $image_contents = wp_remote_retrieve_body( $image_contents );

      update_post_meta( $attachment_id, 'secondline_attachment_md5', md5( $image_contents ) );
    }
  }

}