<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2009 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 * Plugin administration - newsfeeds
 *
 * $Source: /cvs_backup/e107_0.8/e107_plugins/trackback/e_meta.php,v $
 * $Revision$
 * $Date$
 * $Author$
 *
*/
if (!defined('e107_INIT')) { exit; }

if(plugInstalled('trackback') && isset($pref['trackbackEnabled']))
{
	echo "<link rel='pingback' href='".SITEURLBASE.e_PLUGIN_ABS."trackback/trackback.php' />";
}

?>