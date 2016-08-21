<?php

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

class NevCom {
  private static $initiated = false;
  private static $following_clubs = array(
    "CKL7K12" => "US",
  );

  public static function init() {
    if ( ! self::$initiated ) {
      self::init_hooks();
    }
  }

  /**
  * Initializes WordPress hooks
  */
  private static function init_hooks() {
    self::$initiated = true;

    //Hook our function , wi_create_backup(), into the action wi_create_daily_backup
    add_action( 'nevcom_get_program', array( 'NevCom', 'update_program' ) );
    add_action( 'wp_head', array('NevCom', 'inject_styles_and_scripts' ) );
    // filters
    add_filter( 'the_content', array( 'NevCom', 'show_games' ) );
    // ajax form submission
    add_action('wp_ajax_nevcom_submit_form', array( 'NevCom', 'submit_form' ) );
    add_action('wp_ajax_nopriv_nevcom_submit_form', array( 'NevCom', 'submit_form' ) );
    add_action('wp_ajax_nevcom_clear_game', array( 'NevCom', 'clear_game' ) );
  }

  private static function _table() {
    // create the table
    global $wpdb;

    return $wpdb->prefix . 'nevcom';

  }

  /**
  * Attached to activate_{ plugin_basename( __FILES__ ) } by register_activation_hook()
  * @static
  */
  public static function plugin_activation() {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
      id mediumint(11) NOT NULL AUTO_INCREMENT,
      time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
      time_str varchar(255) DEFAULT '' NOT NULL,
      timestamp int(11) NOT NULL,
      url varchar(255) DEFAULT '' NOT NULL,
      code varchar(255) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT '' NOT NULL,
      code_human varchar(255) DEFAULT '' NOT NULL,
      code_link varchar(255) DEFAULT '' NOT NULL,
      title tinytext not null,
      description text not null,
      home VARCHAR(255),
      away VARCHAR(255),
      location VARCHAR(255),
      court VARCHAR(10),
      sets_home INT,
      sets_away INT,
      sets_details TEXT,
      UNIQUE KEY id (id),
      PRIMARY KEY pk (code)
    ) $charset_collate;";

    require_once( ABSPATH .'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    add_option( 'nevcom_db_version', USREF__DB_VERSION );

    // add the cron job
    $timestamp = wp_next_scheduled( 'nevcom_get_program' );

    if( $timestamp == false ){
      wp_schedule_event( time(), 'hourly', 'nevcom_get_program' );
    }

  }

  /**
  * Removes all connection options
  * @static
  */
  public static function plugin_deactivation( ) {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $sql = "DROP TABLE $table_name;";

    //$wpdb->get_var( $sql );

    wp_clear_scheduled_hook( 'nevcom_get_program' );
  }

  public static function inject_styles_and_scripts() {
    $output = '
    <style type="text/css">
    </style>';

    $output .= '
    <script type="text/javascript">
    jQuery(document).ready(function () {
    });
    </script>';

    print $output;
  }

  public static function show_games($content) {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $results = $wpdb->get_results(
      "SELECT * FROM $table_name WHERE DATE(`time`) >= DATE(NOW()) ORDER BY `time`",
      OBJECT
    );

    $output = array();
    $output[] = '<table id="games-table" class="table table-condensed">';

    $old_date = '';
    $dtza = new DateTimeZone("Europe/Amsterdam");
    $utcz = new DateTimeZone("UTC");
    //$utc_diff = $utcz->getOffset(new DateTime("now", $dtza));
    foreach($results as $result) {
      list ($date, $time) = preg_split('/\s/', $result->time, 2);
      $game_time = new DateTime($result->time, $dtza);
      $game_time_utc = new DateTime($result->time, $dtzu);
      $offset = $dtza->getOffset($game_time_utc);
      $game_time->add(new DateInterval('PT'. $offset .'S'));
      if ($date != $old_date) {
        $i18n_date = date_i18n('l j F Y', strtotime($date));
        $output[] = '<tr><td colspan="4"><h3>'. $i18n_date .'</h3></td></tr>';
        $old_date = $date;
      }
      $output[] = '<tr class="game-info">';
      $output[] = '<td>'. $game_time->format('H:i') .'</td>';
      $output[] = sprintf(
        '<td><a href="%s" target="_blank">%s - %s</a></td>',
        $result->code_link, $result->home, $result->away
      );
      $output[] = '<td>'. $result->location .'</td>';
      # FIXME: something with results here ...
      $output[] = '</tr>';
    }

    $output[] = '</table>';

    return str_replace('[nevcom]', implode("\n", $output), $content);
  }

  private static function _get_teams($item) {
    list ($date, $title) = preg_split('/:\s+/', $item->get_title(), 2);
    list ($home, $away) = preg_split('/\s+-\s+/', $title, 2);
    return array($home, $away);
  }

  private static function _get_location($item) {
    $info = preg_split('/,\s+/', $item->get_description());
    return str_replace('Speellocatie: ', '', $info[3]);
  }

  private static function _can_include_game($home, $away, $item, $club_name) {
    return true;
  }

  private static function _get_code($item) {
    $info = preg_split('/,\s+/', $item->get_description());
    return trim(preg_replace('/Wedstrijd:\s+/', '', $info[0]));
  }

  public static function update_program() {
    foreach(self::$following_clubs as $club_code => $club_name) {
      self::update_program_for_club($club_code, $club_name);
    }
  }

  public static function update_program_for_club($club_code, $club_name) {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    // FIXME: links should be like this now:
    // http://www.volleybal.nl/handlers/competition/program.json?club=CKL7K12&start=0&amount=20&filtervalue=&filtertype=
    //$url = 'http://www.volleybal.nl/application/handlers/export.php?format=rss&type=team&programma=3208DS+1&iRegionId=9000';
    //https://api.nevobo.nl/export/vereniging/CKL7K12/programma.rss
    $url = 'https://api.nevobo.nl/export/vereniging/'. $club_code .'/programma.rss';

    $feed = new SimplePie();
    $feed->set_feed_url($url);
    $feed->init();

    foreach($feed->get_items() as $key=>$item) {
      list ($home, $away) = self::_get_teams($item);
      if (self::_can_include_game($home, $away, $item, $club_name)) {
        $code = self::_get_code($item);

        $existing = $wpdb->get_row(
          $wpdb->prepare("SELECT id FROM $table_name WHERE code = %s", $code)
        );

        if ($existing) {
          $wpdb->update(
            $table_name,
            array(
              'time' => $item->get_date( 'Y-m-d H:i:s' ),
              'time_str' => $item->get_date( 'Y-m-d H:i:s' ),
              'timestamp' => $item->get_date( 'U' ),
              'url' => $item->get_link(),
              'code' => $code,
              'code_human' => $code,
              'code_link' => $item->get_id(),
              'title' => $item->get_title(),
              'description' => $item->get_description(),
              'home' => $home,
              'away' => $away,
              'location' => self::_get_location($item),
              'court' => 'Onbekend'
            ),
            array(
              'id' => $existing->id,
            )
          );
        } else {
          $wpdb->replace(
            $table_name,
            array(
              'time' => $item->get_date( 'Y-m-d H:i:s' ),
              'time_str' => $item->get_date( 'Y-m-d H:i:s' ),
              'timestamp' => $item->get_date( 'U' ),
              'url' => $item->get_link(),
              'code' => $code,
              'code_human' => $code,
              'code_link' => $item->get_id(),
              'title' => $item->get_title(),
              'description' => $item->get_description(),
              'home' => $home,
              'away' => $away,
              'location' => self::_get_location($item),
              'court' => 'Onbekend'
            )
          );
        }
      }
    }
  }
}
