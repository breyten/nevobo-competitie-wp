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
    // filters/shortcodes
    add_shortcode('nevcom', array( 'NevCom', 'show_games' ));
    add_shortcode('nevcom-rankings', array( 'NevCom', 'show_rankings' ));
  }

  private static function _table($basename = "nevcom") {
    // create the table
    global $wpdb;

    return $wpdb->prefix . $basename;

  }

  /**
  * Attached to activate_{ plugin_basename( __FILES__ ) } by register_activation_hook()
  * @static
  */
  public static function plugin_activation() {
    // create the table
    global $wpdb;

    $table_name = self::_table();
    $standings_table = self::_table('nevcom_standings');

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
      regio VARCHAR(255) not null,
      poule VARCHAR(255) not null,
      title tinytext not null,
      description text not null,
      home VARCHAR(255),
      away VARCHAR(255),
      location VARCHAR(255),
      court VARCHAR(10),
      sets_home INT,
      sets_away INT,
      sets_details TEXT,
      updated_at INT,
      UNIQUE KEY id (id),
      PRIMARY KEY pk (code)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE $standings_table (
      id mediumint(11) NOT NULL AUTO_INCREMENT,
      url VARCHAR(255) not null,
      sequence INT not null,
      position INT not null,
      regio VARCHAR(255) not null,
      poule VARCHAR(255) not null,
      team VARCHAR(255) not null,
      team_id VARCHAR(255) not null,
      games INT not null default 0,
      points INT not null default 0,
      sets_won INT not null default 0,
      sets_lost INT not null default 0,
      points_won INT not null default 0,
      points_lost INT not null default 0,
      updated_at INT,
      UNIQUE KEY id (id),
      PRIMARY KEY pk (url, team)
    ) $charset_collate;";
    require_once( ABSPATH .'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    dbDelta( $sql2 );

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
    $standings_table = self::_table('nevcom_standings');

    $sql = "DROP TABLE $table_name;";

    //$wpdb->get_var( $sql );

    wp_clear_scheduled_hook( 'nevcom_get_program' );
  }

  public static function inject_styles_and_scripts() {
    $output = '
    <style type="text/css">
    .rankings-header div {
      font-weight: bold;
      padding-bottom: 5px;
    }
    .rankings-info div {
      padding-bottom: 5px;
    }
    .game-last-updated div {
      margin-top: 10px;
      text-align: right;
    }
    .game-header {
      border-bottom: 1px solid #e7ecf1;
    }
    .game-header h3 {
      margin-bottom: 20px;
    }
    .game-info {
      border-bottom: 1px solid #e7ecf1;
      display: flex;
      align-items: center;
    }
    .game-info div, .game-header div {
      padding-bottom: 0 !important;
    }
    /* Medium Devices, Desktops */
    @media only screen and (max-width : 992px) {
      .game-info {
        flex-direction: column;
        justify-content: center;
      }
      .game-info div {
        text-align: center;
      }
    }
    /* Small Devices, Tablets */
    @media only screen and (max-width : 768px) {
      .game-info {
        flex-direction: row;
      }
    }
    @media only screen and (max-width: 480px) {
      .game-info {
        flex-direction: column;
        justify-content: center;
      }
      .game-info div {
        text-align: center;
      }
    }
    @media only screen and (min-width : 993px) {
      .game-info {
        flex-direction: row;
      }
    }
    </style>';

    $output .= '
    <script type="text/javascript">
    jQuery(document).ready(function () {
    });
    </script>';

    print $output;
  }

  public static function show_rankings($attrs, $content, $tag) {
    // create the table
    global $wpdb;

    $table_name = self::_table('nevcom_standings');

    $where_clauses = [
    ];

    if (array_key_exists('team', $attrs)) {
      $where_team = $attrs['team'];
      $where_clauses[] = "`url` = (SELECT `url` FROM $table_name WHERE team = \"$where_team\" LIMIT 1)";
      $where_sql = implode(' AND ', $where_clauses);

      return self::show_rankings_for($where_sql);
    } elseif (array_key_exists('club', $attrs)) {
      $where_club = $attrs['club'];
      $where_clauses[] = "`team_id` LIKE \"$where_club%\"";
      $where_sql = implode(' AND ', $where_clauses);

      if (array_key_exists('mode', $attrs)) {
        return self::show_rankings_for($where_sql, "team_id", false);
      } else {
        $output = array();
        $urls = $wpdb->get_results(
          "SELECT DISTINCT(`url`) FROM $table_name WHERE $where_sql ORDER BY team_id",
          OBJECT
        );

        foreach($urls as $url) {
          $output[] = self::show_rankings_for("`url` = \"$url->url\"");
        }

        return implode("\n", $output);
      }
    }
  }

  public static function _poule_header($regio, $poule) {
    $type_conversions = array(
      'D' => 'Dames',
      'H' => 'Heren'
    );
    $season_part_conversions = array(
      '1' => 'Eerste Helft',
      '2' => 'Tweede Helft'
    );

    if ($regio == '3000') {
      if ($poule[0] == '3') {
        return $poule[0] .'e Divisie '. $poule[1] .' '. $type_conversions[$poule[2]];
      } elseif ($poule[1] == 'P') {
        return 'Promotieklasse '. $poule[2] .' '. $type_conversions[$poule[0]];
      } else {
        return $poule[1] .'e Klasse '. $poule[2] .' '. $type_conversions[$poule[0]] .' '. $season_part_conversions[$poule[3]];
      }
      return $regio .' '. $poule;
    } elseif ($regio == '9000') {
      if ($poule[0] == 'E') {
        return "Eredivisie ". $type_conversions[$poule[1]];
      } elseif ($poule[0] == 'T') {
        return "Topdivisie ". $type_conversions[$poule[1]];
      } else {
        return $poule[0] .'e Divisie '. $poule[1] .' '. $type_conversions[$poule[2]];
      }
    } else {
      return $regio .' '. $poule;
    }
  }

  public static function show_rankings_for($where_sql, $sort_sql="`regio`, `poule`, `url`, `position`, `sequence`", $show_header=true) {
    // create the table
    global $wpdb;

    $table_name = self::_table('nevcom_standings');

    $results = $wpdb->get_results(
      "SELECT * FROM $table_name WHERE $where_sql ORDER BY $sort_sql",
      OBJECT
    );

    $output = array();
    $output[] = '<div class="rankings-table">';

    if ((count($results) > 0) && $show_header) {
      $poule_name = self::_poule_header($results[0]->regio, $results[0]->poule);
      $output[] = '<div class="row"><div class="col-xs-12"><h3>'. $poule_name .'</h3></div></div>';
    }

    $time_result = $wpdb->get_results(
      "SELECT * FROM $table_name WHERE $where_sql ORDER BY `updated_at` DESC LIMIT 1",
      OBJECT
    );
    $last_update = human_time_diff( $time_result[0]->updated_at, current_time('timestamp') ) . ' geleden';
    $last_update_html = '<div class="row game-last-updated"><div class="col-xs-12"><strong>Bijgewerkt:</strong> '. $last_update .' </div></div>';

    $show_fields = array(
      "position" => "col-xs-1 col-sm-1 col-md-1 col-lg-1",
      "team" => "col-xs-11 col-sm-4 col-md-5 col-lg-5",
      "games" => "col-xs-6 col-sm-1 col-md-1 col-lg-1",
      "points" => "col-xs-6 col-sm-1 col-md-1 col-lg-1",
      "sets_won" => "col-xs-6 col-sm-1 col-md-1 col-lg-1",
      "sets_lost" => "col-xs-6 col-sm-1 col-md-1 col-lg-1",
      "points_won" => "col-xs-6 col-sm-1 col-md-1 col-lg-1",
      "points_lost" => "col-xs-6 col-sm-1 col-md-1 col-lg-1",
    );
    $fields_headers = array(
      "position" => "#",
      "team" => "Team",
      "games" => "W",
      "points" => "P",
      "sets_won" => "Sw",
      "sets_lost" => "Sv",
      "points_won" => "Pw",
      "points_lost" => "Pv",
    );

    $output[] = '<div class="row rankings-header">';
    foreach($show_fields as $field => $class_names) {
      $output[] = "<div class=\"$class_names rankings-header-$field\">". $fields_headers[$field] ."</div>";
    }
    $output[] = '</div>';

    foreach($results as $result) {
      $output[] = '<div class="row rankings-info">';
      foreach($show_fields as $field => $class_names) {
        $output[] = "<div class=\"$class_names rankings-$field\">". $result->$field ."</div>";
      }
      $output[] = '</div>';
    }

    $output[] = $last_update_html;
    $output[] = '</div>';

    return implode("\n", $output);

  }

  public static function show_games($attrs=array(), $content='', $tag='') {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $where_clauses = [
      'DATE(`time`) >= DATE(NOW())',
    ];

    if (array_key_exists('team', $attrs)) {
      $where_team = $attrs['team'];
      $where_clauses[] = "((`home` = \"$where_team\") OR (`away` = \"$where_team\"))";
    }

    $where_sql = implode(' AND ', $where_clauses);

    $results = $wpdb->get_results(
      "SELECT * FROM $table_name WHERE $where_sql ORDER BY `time`",
      OBJECT
    );

    $output = array();
    $output[] = '<div id="games-table">';

    $time_result = $wpdb->get_results(
      "SELECT * FROM $table_name ORDER BY `updated_at` DESC LIMIT 1",
      OBJECT
    );
    $last_update = human_time_diff( $time_result[0]->updated_at, current_time('timestamp') ) . ' geleden';
    $last_update_html = '<div class="row game-last-updated"><div class="col-xs-12"><strong>Bijgewerkt:</strong> '. $last_update .' </div></div>';

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
        $output[] = '<div class="row game-header"><div class="col-xs-12"><h3>'. $i18n_date .'</h3></div></div>';
        $old_date = $date;
      }
      $output[] = '<div class="row game-info">';
      $output[] = '<div class="col-xs-12 col-md-1 col-lg-1">'. $game_time->format('H:i') .'</div>';
      $output[] = sprintf(
        '<div class="col-xs-12 col-md-6 col-lg-6"><a href="%s" target="_blank">%s - %s</a></div>',
        $result->code_link, $result->home, $result->away
      );
      $output[] = '<div class="col-xs-12 col-md-4 col-lg-4">'. $result->location .'</div>';
      # FIXME: something with results here ...
      $output[] = '<div class="col-xs-12 col-md-1 col-lg-1">';
      $output[] = '-'; //$result->court;
      $output[] = '</div>';
      $output[] = '</div>';
    }

    $output[] = $last_update_html;
    $output[] = '</div>';

    return implode("\n", $output);
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

  private static function _get_regio_and_poule($code) {
    $matches = array();
    if (preg_match('/^(\d{4})([\d\w]+)\s/', $code, $matches)) {
      array_shift($matches);
      return $matches;
    } else {
      return array(null, null);
    }
  }

  public static function _get_all_poules() {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $records = $wpdb->get_results(
      "SELECT regio, poule FROM $table_name GROUP BY regio, poule",
      OBJECT
    );

    return $records;
  }

  public static function update_program() {
    foreach(self::$following_clubs as $club_code => $club_name) {
      self::update_program_for_club($club_code, $club_name);
    }

    foreach(self::_get_all_poules() as $record) {
      self::update_standings($record->regio, $record->poule);
    }
  }

  public static function update_standings($regio, $poule) {
    // create the table
    global $wpdb;

    $table_name = self::_table('nevcom_standings');

    // ugh stable urls or something
    $regio_as_human = array(
      '3000' => 'regio-west',
      '9000' => 'nationale-competitie'
    );

    $field_conversions = array(
      'nummer' => 'position',
      'team' => 'team',
      'wedstrijden' => 'games',
      'punten' => 'points',
      'setsvoor' => 'sets_won',
      'setstegen' => 'sets_lost',
      'puntenvoor' => 'points_won',
      'puntentegen' => 'points_lost'
    );

    $feed_url = 'https://api.nevobo.nl/export/poule/'. $regio_as_human[$regio] .'/'. $poule .'/stand.rss';

    $feed = new SimplePie();
    $feed->set_feed_url($feed_url);
    $feed->init();

    $items = $feed->get_items();

    $rankings = $feed->get_channel_tags('http://www.nevobo.nl/competitie/', 'ranking');

    $seq = 0;
    foreach($rankings as $ranking_raw) {
      $ranking = $ranking_raw['child']['http://www.nevobo.nl/competitie/'];
      $record = array(
        'url' => $items[0]->get_link(),
        'updated_at' => current_time('timestamp'),
        'sequence' => $seq,
        'regio' => $regio,
        'poule' => $poule
      );

      foreach($ranking as $veld => $data) {
        $dst_field = $field_conversions[$veld];
        $record[$dst_field] = $data[0]['data'];
        // ugly, but works
        if ($veld == 'team') {
          $record['team_id'] = $data[0]['attribs']['']['id'];
        }
      }

      $existing = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT id FROM $table_name WHERE url = %s AND team = %s",
          $record['url'], $record['team'])
      );

      if ($existing) {
        $wpdb->update(
          $table_name, $record,
          array(
            'id' => $existing->id,
          )
        );
      } else {
        $wpdb->replace($table_name, $record);
      }
      $seq += 1;
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
        list ($regio, $poule) = self::_get_regio_and_poule($code);
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
              'regio' => $regio,
              'poule' => $poule,
              'title' => $item->get_title(),
              'description' => $item->get_description(),
              'home' => $home,
              'away' => $away,
              'location' => self::_get_location($item),
              'court' => 'Onbekend',
              'updated_at' => current_time('timestamp')
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
              'regio' => $regio,
              'poule' => $poule,
              'title' => $item->get_title(),
              'description' => $item->get_description(),
              'home' => $home,
              'away' => $away,
              'location' => self::_get_location($item),
              'court' => 'Onbekend',
              'updated_at' => urrent_time('timestamp')
            )
          );
        }
      }
    }
  }
}
