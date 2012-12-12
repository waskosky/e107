<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2010 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 * e107 Main
 *
 * $URL$
 * $Id$
*/


/**
 *	@package    e107
 *	@subpackage	e107_handlers
 *	@version 	$Id$;
 *
 *	Handler to keep track of online users
 */

/*
  online_timestamp int(10) unsigned NOT NULL default '0',		Start of time period over which accesses counted
  online_flag tinyint(3) unsigned NOT NULL default '0',			Not used? (displayed in admin log only)
  online_user_id varchar(100) NOT NULL default '',
  online_ip varchar(45) NOT NULL default '',
  online_location text NOT NULL,        						Current page being accessed
  online_pagecount tinyint(3) unsigned NOT NULL default '0',	Number of page accesses (within most recent timeout interval?)
  online_active int(10) unsigned NOT NULL default '0',			Not used? Actually added in the update routines, version 0.7.6. (Also displayed in admin log)
  online_agent varchar(255) NOT NULL default ''					User agent - use for bot identification

  Current count() queries:
	a) Total
	b) $sql->db_Count('online', '(*)', "WHERE `online_location` = '{$page}' "));

  Also forum_viewforum.php:
  	$member_users = $sql->db_Count('online', '(*)', "WHERE online_location REGEXP('viewforum.php.id=$forumId\$') AND online_user_id != 0");
	$guest_users = $sql->db_Count('online', '(*)', "WHERE online_location REGEXP('viewforum.php.id=$forumId\$') AND online_user_id = 0");


Following single query gives two rows of data - one for members (online_user_id != 0) and one for guests (online_user_id == 0)
SELECT COUNT(`online_user_id`) AS ol_count, `online_user_id` FROM `#online` GROUP BY (`online_user_id` = 0)
*/

/**
 *	@todo	Don't create list of online members by default - $listuserson used in forum, online_menu.php, online.php
 *	@todo	$member_list defined as MEMBER_LIST - used in online_shortcodes.php
 *	@todo	$members_online, defined as MEMBERS_ONLINE - used in online_menu.php, online_shortcodes.php, online_template.php, online.php, forum.php
 *	@todo	$total_online defined (indirectly) as GUESTS_ONLINE + MEMBERS_ONLINE - used in online_menu.php, online_shortcodes.php, online_template.php, online.php
 *	@todo	Possibly online_pagecount should be bigger than tinyint(3)
 *	@todo	Possibly shouldn't log access to index.php - its usually a redirect - but not always!
 *	@todo	Can we distinguish between different users at same IP? Browser sig, maybe?
 *	@todo	Change queries to array access
 *	@todo 	Eliminate other globals
 *	@todo	Can we simplify counts into one query?
*/
class e_online
{
	
	public $users = array();	
	
	
	function __construct()
	{
		
		
	}
	
	
	
	/**
	 * Go online
	 * @param boolean $online_tracking
	 * @param boolean $flood_control
	 * @return void
	 */
	public function goOnline($online_tracking = false, $flood_control = false)
	{
		// global $pref, $e_event; // Not needed as globals
		//global $online_timeout, $online_warncount, $online_bancount;	// Not needed as globals
		//global $members_online, $total_online;						// Not needed as globals
		global $listuserson; // FIXME - remove it, make it property, call e_online signleton - e107::getOnline()

		$e107 = e107::getInstance();
		$sql = e107::getDb();
		$user = e107::getUser();

		if($online_tracking || $flood_control)
		{
			$online_timeout = 300;

			list($ban_access_guest,$ban_access_member) = explode(',',e107::getPref('ban_max_online_access', '100,200'));
			$online_bancount = max($ban_access_guest,50);					// Safety net for incorrect values
			if ($user->isUser())
			{
				$online_bancount = max($online_bancount,$ban_access_member);
			}

			$online_warncount = $online_bancount * 0.9;		// Set warning threshold at 90% of ban threshold
			//TODO Add support for all queries.
			// $page = (strpos(e_SELF, 'forum_') !== FALSE) ? e_SELF.'.'.e_QUERY : e_SELF;
			// $page = (strpos(e_SELF, 'comment') !== FALSE) ? e_SELF.'.'.e_QUERY : $page;
			// $page = (strpos(e_SELF, 'content') !== FALSE) ? e_SELF.'.'.e_QUERY : $page;
			$page = e_REQUEST_URI; // mod rewrite & single entry support
			// FIXME parse url, trigger registered e_online callbacks
			$page = $e107->tp->toDB($page, true);								/// @todo - try not to use toDB() - triggers prefilter
			$ip = e107::getIPHandler()->getIP(FALSE);
			$udata = ($user->isUser() ? $user->getId().'.'.$user->getName() : '0');
			$agent = $_SERVER['HTTP_USER_AGENT'];

			// XXX - more exceptions, e.g. hide online location for admins/users (pref), e_jlsib.php, etc
			// XXX - more advanced flod timing when  e_AJAX_REQUEST, e.g. $ban_access_ajax = 300
			$update_page = deftrue('e_AJAX_REQUEST') ? '' : ", online_location='{$page}'";

			$insert_query = array(
				'online_timestamp'	=> time(),
				'online_flag'		=> 0,
				'online_user_id'	=> $udata,
				'online_ip'			=> $ip,
				'online_location'	=> $page,
				'online_pagecount'	=> 1,
				'online_active'		=> 0,
				'online_agent'		=> $agent
			);

			// !deftrue('e_AJAX_REQUEST')
			// TODO add option to hide users from online list? boolean online_hide field?
			// don't do anything if main admin logged in as another user
			if ($user->isUser() && !$user->getParentId())
			{
				// Find record that matches IP or visitor, or matches user info
				if ($sql->db_Select('online', '*', "(`online_ip` = '{$ip}' AND `online_user_id` = '0') OR `online_user_id` = '{$udata}'"))
				{
					$row = $sql->db_Fetch();

					if ($row['online_user_id'] == $udata)
					{
						//Matching user record
						if ($row['online_timestamp'] < (time() - $online_timeout))
						{
							//It has been at least 'online_timeout' seconds since this user's info last logged
							//Update user record with timestamp, current IP, current page and set pagecount to 1
							$query = "online_timestamp='".time()."', online_ip='{$ip}'{$update_page}, online_pagecount=1 WHERE online_user_id='{$row['online_user_id']}' LIMIT 1";
						}
						else
						{
							if (!$user->isAdmin())
							{
								$row['online_pagecount'] ++;
							}
							// Update user record with current IP, current page and increment pagecount
							$query = "online_ip='{$ip}'{$update_page}, `online_pagecount` = '".intval($row['online_pagecount'])."' WHERE `online_user_id` = '{$row['online_user_id']}' LIMIT 1";
						}
					}
					else
					{
						//Found matching visitor record (ip only) for this user
						if ($row['online_timestamp'] < (time() - $online_timeout))
						{
							// It has been at least 'timeout' seconds since this user has connected
							// Update record with timestamp, current IP, current page and set pagecount to 1
							$query = "`online_timestamp` = '".time()."', `online_user_id` = '{$udata}'{$update_page}, `online_pagecount` = 1 WHERE `online_ip` = '{$ip}' AND `online_user_id` = '0' LIMIT 1";
						}
						else
						{	// Another visit within the timeout period
							if (!$user->isAdmin())
							{
								$row['online_pagecount'] ++;
							}
							//Update record with current IP, current page and increment pagecount
							$query = "`online_user_id` = '{$udata}'{$update_page}, `online_pagecount` = ".intval($row['online_pagecount'])." WHERE `online_ip` = '{$ip}' AND `online_user_id` = '0' LIMIT 1";
						}
					}
					$sql->db_Update('online', $query);
				}
				else
				{
					$sql->db_Insert('online',$insert_query);
				}
			}
			// don't do anything if main admin logged in as another user
			elseif(!$user->getParentId())
			{
				//Current page request is from a guest
				if ($sql->db_Select('online', '*', "`online_ip` = '{$ip}' AND `online_user_id` = '0'"))
				{	// Recent visitor
					$row = $sql->db_Fetch();

					if ($row['online_timestamp'] < (time() - $online_timeout)) //It has been at least 'timeout' seconds since this ip has connected
					{
						//Update record with timestamp, current page, and set pagecount to 1
						$query = "`online_timestamp` = '".time()."'{$update_page}, `online_pagecount` = 1 WHERE `online_ip` = '{$ip}' AND `online_user_id` = '0' LIMIT 1";
					}
					else
					{
						//Update record with current page and increment pagecount
						$row['online_pagecount'] ++;
						//   echo "here {$online_pagecount}";
						$query="`online_pagecount` = {$row['online_pagecount']}{$update_page} WHERE `online_ip` = '{$ip}' AND `online_user_id` = '0' LIMIT 1";
					}
					$sql->db_Update('online', $query);
				}
				else
				{	// New visitor
					$sql->db_Insert('online',$insert_query);
				}
			}

			if ($user->isAdmin() || (e107::getPref('autoban') != 1 && e107::getPref('autoban') != 2) || (!isset($row['online_pagecount']))) // Auto-Ban is switched off. (0 or 3)
			{
				$row['online_pagecount'] = 1;
			}

			// Always allow localhost - any problems are usually semi-intentional!
			if (($row['online_ip'] != '127.0.0.1') && ($row['online_ip'] != e107::LOCALHOST_IP))
			{
				// Check for excessive access
				if ($row['online_pagecount'] > $online_bancount)
				{
					include_lan(e_LANGUAGEDIR.e_LANGUAGE.'/admin/lan_banlist.php');
					if (e107::getIPHandler()->add_ban(2,str_replace('--HITS--',$row['online_pagecount'],BANLAN_78),$ip,0))
					{
						e107::getEvent()->trigger('flood', $ip);
						exit;
					}
				}
				elseif ($row['online_pagecount'] >= $online_warncount)
				{
					echo "<div style='text-align:center; font: 11px verdana, tahoma, arial, helvetica, sans-serif;'><b>".LAN_WARNING."</b><br /><br />".CORE_LAN6."<br /></div>";
					exit;
				}
			}

			// Delete records for users (and guests) not seen for a while
			// FIXME - DB optimization - mark records as deleted (online_deleted=1), delete once per hour (could be pref) via e_cron
			// FIXME - Additional prefs for this (it does 2-3 more queries no matter someone need them), could be also separate method
			// Speed up ajax requests
			if(!deftrue('e_AJAX_REQUEST'))
			{
				$sql->db_Delete('online', '`online_timestamp` < '.(time() - $online_timeout));

				// FIXME - don't use constants below, save data in class vars, call e_online signleton - e107::getOnline()
				$total_online = $sql->db_Count('online');
				if ($members_online = $sql->db_Select('online', '*', 'online_user_id != 0'))
				{
					$member_list = '';
					$listuserson = array();
					while ($row = $sql->db_Fetch())
					{
						$row['online_bot'] = $this->isBot($row['online_agent']);
						
						
						$vals = explode('.', $row['online_user_id'], 2);
						$member_list .= "<a href='".SITEURL."user.php?id.{$vals[0]}'>{$vals[1]}</a> ";
						$listuserson[$row['online_user_id']] = $row['online_location'];
						
						// Sort into usable format and add bot field. 
						$user = array(
							'user_id'			=> $vals[0],
							'user_name'			=> $vals[1],
							'user_location'		=> $row['online_location'],
							'user_bot'			=> $this->isBot($row['online_agent']),
							'user_agent'		=> $row['online_agent'],
							'user_ip'			=> $row['online_ip'],
							'user_currentvisit'	=> $row['online_timestamp'],
							'user_online'		=> $row['online_flag'],
							'user_pagecount'	=> $row['online_pagecount'],
							'user_active'		=> $row['online_active'],
							'online_user_id'	=> $row['online_user_id']
						);	
		
						$this->users[] = $user;	
						
					}
				}
				define('TOTAL_ONLINE', $total_online);
				define('MEMBERS_ONLINE', $members_online);
				define('GUESTS_ONLINE', $total_online - $members_online);
				define('ON_PAGE', $sql->db_Count('online', '(*)', "WHERE `online_location` = '{$page}' "));
				define('MEMBER_LIST', $member_list);

				//update most ever online
				$olCountPrefs = e107::getConfig('history');			// Get historic counts of members on line
				if ($total_online > ($olCountPrefs->get('most_members_online') + $olCountPrefs->get('most_guests_online')))
				{
					$olCountPrefs->set('most_members_online', MEMBERS_ONLINE);
					$olCountPrefs->set('most_guests_online', GUESTS_ONLINE);
					$olCountPrefs->set('most_online_datestamp', time());
					$olCountPrefs->save(false, true, false);
				}
			}
		}
		else
		{
			define('e_TRACKING_DISABLED', true);		// Used in forum, online menu
			define('TOTAL_ONLINE', '');
			define('MEMBERS_ONLINE', '');
			define('GUESTS_ONLINE', '');
			define('ON_PAGE', '');
			define('MEMBER_LIST', '');
		}
	}


	function userList()
	{
		return $this->users;		
		
	}


	
	
	function isBot($userAgent='')
	{
		if(!$userAgent){ return false; }
		
		$botlist = array("Teoma", "alexa", "froogle", "Gigabot", "inktomi",
		"looksmart", "URL_Spider_SQL", "Firefly", "NationalDirectory",
		"Ask Jeeves", "TECNOSEEK", "InfoSeek", "WebFindBot", "girafabot",
		"crawler", "www.galaxy.com", "Googlebot", "Scooter", "Slurp",
		"msnbot", "appie", "FAST", "WebBug", "Spade", "ZyBorg", "rabaz",
		"Baiduspider", "Feedfetcher-Google", "TechnoratiSnoop", "Rankivabot",
		"Mediapartners-Google", "Sogou web spider", "WebAlta Crawler","TweetmemeBot",
		"Butterfly","Twitturls","Me.dium","Twiceler");
		
		foreach($botlist as $bot)
		{
			if(strpos($userAgent, $bot) !== false){ return true; }
		}
		return false;
	}

	

	

}