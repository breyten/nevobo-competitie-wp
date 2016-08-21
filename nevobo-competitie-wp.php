<?php
/**
* Plugin Name: Nevobo Competitie
* Plugin URI: http://www.volleybal.nl/competitie/
* Description: Allows to view data from dutch volleyball competition
* Version: 0.0.1
* Author: Breyten Ernsting
* Author URI: http://yerb.net/
* License: MIT
*/

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

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
  echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
  exit;
}

define( 'NEVCOM__PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NEVCOM__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEVCOM__DB_VERSION', 1.0);

require_once( NEVCOM__PLUGIN_DIR . 'class.nevobo-competitie-wp.php' );
require_once( NEVCOM__PLUGIN_DIR . 'class.simplepie.php' );

register_activation_hook( __FILE__, array( 'NevCom', 'plugin_activation' ) );
register_deactivation_hook( __FILE__, array( 'NevCom', 'plugin_deactivation' ) );

add_action( 'init', array( 'NevCom', 'init' ) );
