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
  private static $home_location = "Amstelcampus";

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
    add_action( 'nevcom_empty_program', array( 'NevCom', 'empty_program' ) );
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
    .rankings {
    }
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

    $where_clauses = array();

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
        return self::show_rankings_for($where_sql, "team_id, right(poule, 1) desc", false);
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
      } elseif ($poule[0] == 'N') {
        return 'Nationale Beker '. $type_conversions[$poule[2]] .' Ronde '. $poule[3];
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
    $last_update_html = '<!-- Nevcom Bijgewerkt: '. $last_update .' -->';

    $show_fields = array(
      "position" => "col-xs-1 col-sm-1 col-md-1 col-lg-1",
      "team" => "col-xs-9 col-sm-4 col-md-5 col-lg-5",
      "games" => "col-xs-1 col-sm-1 col-md-1 col-lg-1 hidden-xs visible-sm-block visible-md-block visible-lg-block",
      "points" => "col-xs-1 col-sm-1 col-md-1 col-lg-1",
      "sets_won" => "col-xs-6 col-sm-1 col-md-1 col-lg-1 hidden-xs hidden-sm visible-md-block visible-lg-block",
      "sets_lost" => "col-xs-6 col-sm-1 col-md-1 col-lg-1 hidden-xs hidden-sm visible-md-block visible-lg-block",
    );
    $fields_headers = array(
      "position" => "#",
      "team" => "Team",
      "games" => "W",
      "points" => "P",
      "sets_won" => "Sw",
      "sets_lost" => "Sv",
    );
    $fields_tooltips = array(
      "position" => "Plek",
      "team" => "Team",
      "games" => "Wedstrijden gespeeld",
      "points" => "Punten",
      "sets_won" => "Sets gewonnen",
      "sets_lost" => "Sets verloren",
    );


    $output[] = '<div class="row rankings-header">';
    foreach($show_fields as $field => $class_names) {
      $output[] = "<div class=\"$class_names rankings-header-$field\"><span data-toggle=\"tooltip\" data-placement=\"top\" title=\"". $fields_tooltips[$field] ."\">". $fields_headers[$field] ."</span></div>";
    }
    $output[] = '</div>';

    $prev_team = "";
    foreach($results as $result) {
      if (($prev_team != $result->team_id) && (substr($result->poule, 0, 2) != "NB")) {
        $output[] = '<div class="row rankings-info" data-prev-team="'. $prev_team .'">';
        if (!$show_header) {
          $poule_size = $wpdb->get_results(
            "SELECT COUNT(*) AS `num_teams` FROM $table_name WHERE `regio` = \"". $result->regio ."\" AND `poule` = \"". $result->poule ."\" LIMIT 1",
            OBJECT
          );
          $result->position = "<span data-toggle=\"tooltip\" data-placement=\"top\" title=\"van ". $poule_size[0]->num_teams ."\">".$result->position."</span>";
          $result->team = '<a href="'. $result->url .'" target="_blank">'. $result->team .'</a>';
          $result->sets_won  = "<span data-toggle=\"tooltip\" data-placement=\"top\" title=\"Punten: ". $result->points_won ."\">".$result->sets_won."</span>";
          $result->sets_lost  = "<span data-toggle=\"tooltip\" data-placement=\"top\" title=\"Punten: ". $result->points_lost ."\">".$result->sets_lost."</span>";
        }
        foreach($show_fields as $field => $class_names) {
          $output[] = "<div class=\"$class_names rankings rankings-$field\">". $result->$field ."</div>";
        }
        $output[] = '</div>';
        $prev_team = $result->team_id;
      }
    }

    $output[] = $last_update_html;
    $output[] = '</div>';

    return implode("\n", $output);

  }

  public static function show_games($attrs=array(), $content='', $tag='') {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $where_clauses = array();

    if (array_key_exists('when', $attrs)) {
      $when = $attrs['when'];
    } else {
      $when = 'future';
    }

    $show_location = !array_key_exists('show_location', $attrs);

    if ($when == 'future') {
      $where_clauses[] = 'DATE(`time`) >= DATE(NOW())';
    } elseif ($when == 'past') {
      $where_clauses[] = '`time` <= NOW()';
    } elseif ($when == 'saturday') {
      $nextSaturday = new DateTime('saturday');
      $where_clauses[] = 'DATE(`time`) = "'. $nextSaturday->format('Y-m-d') .'"';
      $where_clauses[] = '`location` = "'. self::$home_location .'"';
    } else {
      $where_clauses[] = '1';
    }

    if (array_key_exists('team', $attrs)) {
      $where_team = $attrs['team'];
      $where_clauses[] = "((`home` = \"$where_team\") OR (`away` = \"$where_team\"))";
    }

    $where_sql = implode(' AND ', $where_clauses);

    $results = $wpdb->get_results(
      "SELECT * FROM $table_name WHERE $where_sql ORDER BY `time`, `home`, `away`, `id` DESC",
      OBJECT
    );

    $output = array();
    $output[] = '<div id="games-table games-'. $when .'">';

    $time_result = $wpdb->get_results(
      "SELECT * FROM $table_name ORDER BY `updated_at` DESC LIMIT 1",
      OBJECT
    );
    $last_update = human_time_diff( $time_result[0]->updated_at, current_time('timestamp') ) . ' geleden';
    $last_update_html = '<!-- Nevom Bijgewerkt: '. $last_update .' -->';

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
      if ($show_location) {
        $output[] = '<div class="col-xs-12 col-md-3 col-lg-3">'. $result->location .'</div>';
      }
      # FIXME: something with results here ...
      $output[] = '<div class="col-xs-12 col-md-2 col-lg-2">';
      if ($result->sets_details) {
        $output[] = '<abbr title="'. $result->sets_details .'">'. $result->sets_home .'-'. $result->sets_away .'</abbr>';
      } else {
        $output[] = '&nbsp;';
      }
      $output[] = '</div>';
      $output[] = '</div>';
    }

    $output[] = $last_update_html;
    $output[] = '</div>';

    return implode("\n", $output);
  }

  private static function _get_teams($item) {
    $title = "";
    if (preg_match('/,\s+Uitslag:\s+/', $item->get_title())) {
      list ($title, $result) = preg_split('/,\s+Uitslag:\s+/', $item->get_title(), 2);
    } else {
      list ($date, $title) = preg_split('/:\s+/', $item->get_title(), 2);
    }
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
    return $item->get_link();
  }

  private static function _get_regio_and_poule($item) {
    list($code_full, $dummy) = preg_split('/,\s+Datum:/', $item->get_description(), 2);
    $code = str_replace('Wedstrijd: ', '', $code_full);
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
      self::update_results_for_club($club_code);
    }

    foreach(self::_get_all_poules() as $record) {
      if (!empty($record->regio)) {
        self::update_standings($record->regio, $record->poule);
      }
    }
  }

  public static function empty_program() {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $records = $wpdb->get_results(
      "DELETE FROM $table_name",
      OBJECT
    );
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
    $feed->set_cache_location('/var/www/vhosts/usvolleybal.nl/httpdocs/wp-content/plugins/nevobo-competitie-wp/cache');
    $feed->set_feed_url($feed_url);
    $feed->init();

    $items = $feed->get_items();

    $rankings = $feed->get_channel_tags('https://www.nevobo.nl/competitie/', 'ranking');

    $seq = 0;
    foreach($rankings as $ranking_raw) {
      $ranking = $ranking_raw['child']['https://www.nevobo.nl/competitie/'];
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
          "SELECT id FROM $table_name WHERE url = %s AND team_id = %s",
          $record['url'], $record['team_id'])
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

  public static function update_results_for_club($club_code) {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $url = 'https://api.nevobo.nl/export/vereniging/'. $club_code .'/resultaten.rss';

    $feed = new SimplePie();
    $feed->set_cache_location('/var/www/vhosts/usvolleybal.nl/httpdocs/wp-content/plugins/nevobo-competitie-wp/cache');
    $feed->set_feed_url($url);
    $feed->init();

    foreach($feed->get_items() as $key=>$item) {
      $existing = $wpdb->get_row(
        $wpdb->prepare("SELECT id FROM $table_name WHERE url = %s", $item->get_link())
      );

      if (!$existing) {
        list ($home, $away) = self::_get_teams($item);
        if (self::_can_include_game($home, $away, $item, $club_name)) {
          $code = self::_get_code($item);

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
              'court' => 'Onbekend',
              'updated_at' => current_time('timestamp')
            )
          );

          $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM $table_name WHERE url = %s", $item->get_link())
          );
        }
      }

      $matches = array();
      if ($existing && preg_match('/,\s+Uitslag:\s+(\d+)\-(\d+)$/', $item->get_title(), $matches)) {
        $set_matches = array();
        if (preg_match('/,\s+Setstanden:\s+(.*)$/', $item->get_description(), $set_matches)) {
          $set_result = $set_matches[1];
        } else {
          $set_result = NULL;
        }
        $wpdb->update(
          $table_name,
          array(
            'sets_home' => $matches[1],
            'sets_away' => $matches[2],
            'sets_details' => $set_result,
            'updated_at' => current_time('timestamp')
          ),
          array(
            'id' => $existing->id,
          )
        );
      }
    }
  }

  public static function update_program_for_club($club_code, $club_name) {
    // create the table
    global $wpdb;

    $table_name = self::_table();

    $url = 'https://api.nevobo.nl/export/vereniging/'. $club_code .'/programma.rss';

    $feed = new SimplePie();
    $feed->set_cache_location('/var/www/vhosts/usvolleybal.nl/httpdocs/wp-content/plugins/nevobo-competitie-wp/cache');
    $feed->set_feed_url($url);
    $feed->init();

    foreach($feed->get_items() as $key=>$item) {
      list ($home, $away) = self::_get_teams($item);
      if (self::_can_include_game($home, $away, $item, $club_name)) {
        $code = self::_get_code($item);
        list ($regio, $poule) = self::_get_regio_and_poule($item);
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
              'updated_at' => current_time('timestamp')
            )
          );
        }
      }
    }
  }
}
