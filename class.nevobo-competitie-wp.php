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
      ref_team VARCHAR(255),
      ref_name VARCHAR(255),
      ref_code VARCHAR(32),
      ref_posted_at datetime,
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
    .game-taken {
      color: black;
      text-decoration: none;
    }
    .game-form {
      display: none;
    }
    .game-form td {
      vertical-align: middle;
    }
    .game-form .btn {
      margin-top: 0;
    }
    </style>';

    $output .= '
    <script type="text/javascript">
    jQuery(document).ready(function () {
      jQuery(".game-register").on("click", function (e) {
        var $form = jQuery(this).parent().parent().next();
        $form.toggle("slow");
        $form.find("input.form-control:first").focus();
        return false;
      });

      jQuery(".game-clear").on("click", function (e) {
        var $link = jQuery(this);

        jQuery.ajax({
          type:"GET",
          url: $link.attr("href"),
          success:function(data){
            $link.parent().find(".alert").remove();
            $link.parent().text("").prepend(jQuery(data));
            if (data.indexOf("alert alert-success") >= 0) {
              $link.hide();
            }
          },
          error:function(xhr,ts,msg){
            $link.parent().find(".alert").remove();
            $link.parent().prepend(jQuery(data));
          }
        });

        return false;

      });

      jQuery(".game-form form").submit(function(e) {
        var $form = jQuery(this);

        jQuery.ajax({
          type:"POST",
          url: "'. home_url() .'/wp-admin/admin-ajax.php",
          data: $form.serialize(),
          success:function(data){
            $form.parent().find(".alert").remove();
            $form.parent().prepend(jQuery(data));
            if (data.indexOf("alert alert-success") >= 0) {
              $form.hide();
            }
          },
          error:function(xhr,ts,msg){
            $form.parent().find(".alert").remove();
            $form.parent().prepend(jQuery(data));
          }
        });

        return false;
      });
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
      if (empty($result->ref_name)) {
        $output[] = '<td><a href="#" class="game-register">inschrijven voor de wedstrijd &gt;</a></td>';
      } else {
        if (current_user_can('delete_others_posts')) {
          $additional = '<a class="game-clear" href="'. home_url() .'/wp-admin/admin-ajax.php?action=nevcom_clear_game&id='. $result->id .'" class="close" aria-label="Close"><span class="glyphicon glyphicon-remove" aria-hidden="true"></span></a>';
          $name_class = 'game-register';
        } else {
          $additional = '';
          $name_class = 'game-taken';
        }
        $output[] = sprintf('<td><a href="#" class="%s">%s (%s)</a> %s</td>', $name_class, $result->ref_name, $result->ref_team, $additional);
      }
      $output[] = '</tr>';
      $output[] = '<tr class="game-form"><td colspan="4">
      <form class="form-inline">
      <input type="hidden" name="id" value="'. $result->id .'" />
      <input type="hidden" name="action" value="nevcom_submit_form"/>
      <div class="form-group">
      <label class="sr-only" for="naam">Naam</label>
      <input type="text" class="form-control" name="naam" placeholder="Naam" value="'. $result->ref_name .'">
      </div>
      <div class="form-group">
      <label class="sr-only" for="team">Team</label>
      <input type="text" class="form-control" name="team" placeholder="Team" value="'. $result->ref_team .'">
      </div>
      <div class="form-group">
      <label class="sr-only" for="code">Relatiecode</label>
      <input type="text" class="form-control" name="code" placeholder="Relatiecode" value="'. $result->ref_code .'">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">inschrijven</button>
      </form>
      </td></tr>';
    }

    $output[] = '</table>';

    return str_replace('[nevcom]', implode("\n", $output), $content);
  }

  public static function clear_game() {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    if ($wpdb->update(
      $table_name,
      array(
        'ref_team' => null,
        'ref_name' => null,
        'ref_code' => null,
        'ref_posted_at' => null,
      ),
      array(
        'id' => $_GET['id'],
      )
    ) === false) {
      echo "<div class=\"alert alert-danger\" role=\"alert\">Er ging iets fout bij het vrijgeven</div>";
    } else {
      echo "<div class=\"alert alert-success\" role=\"alert\">De wedstrijd is succesvol vijgegeven</div>";
    }

    die();

  }

  public static function submit_form() {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    if (trim($_POST['team'] . $_POST['naam']) == '') {
      echo "<div class=\"alert alert-danger\" role=\"alert\">Naam en team moeten ingevuld zijn</div>";
      die();
    }

    if ($wpdb->update(
      $table_name,
      array(
        'ref_team' => $_POST['team'],
        'ref_name' => $_POST['naam'],
        'ref_code' => $_POST['code'],
        'ref_posted_at' => current_time( 'mysql' ),
      ),
      array(
        'id' => $_POST['id'],
      )
    ) === false) {
      echo "<div class=\"alert alert-danger\" role=\"alert\">Er ging iets fout bij het opslaan</div>";
    } else {
      echo "<div class=\"alert alert-success\" role=\"alert\">Succesvol opgeslagen</div>";
    }

    die();
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

  private static function _can_ref_game($home, $away, $item, $club_name) {
    $is_home_game = preg_match('/'. $club_name .' (D|H)S\s?[\d]+$/', $home);
    $is_lower_division = preg_match('/^3000\s*(D|H)\d[A-Z]\d?/', self::_get_code($item));
    return ($is_home_game && $is_lower_division);
    //return true;
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
      if (self::_can_ref_game($home, $away, $item, $club_name)) {
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
