<?php


require_once('boot.php');

$a = new App;

@include('.htconfig.php');
require_once('dba.php');
$db = new dba($db_host, $db_user, $db_pass, $db_data, $install);
	unset($db_host, $db_user, $db_pass, $db_data);

require_once('session.php');
require_once('datetime.php');
require_once('simplepie/simplepie.inc');
require_once('include/items.php');

if($argc < 2)
	exit;

	$a->set_baseurl($argv[1]);

	$contacts = q("SELECT * FROM `contact` WHERE `dfrn-id` != '' AND `self` = 0 ORDER BY RAND()");

	if(! count($contacts))
		killme();

	foreach($contacts as $contact) {

		$importer_uid = $contact['uid'];

		$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1 LIMIT 1",
			intval($importer_uid)
		);
		if(! count($r))
			continue;

		$importer = $r[0];

		$last_update = (($contact['last-update'] == '0000-00-00 00:00:00') 
			? datetime_convert('UTC','UTC','now - 30 days','Y-m-d\TH:i:s\Z')
			: datetime_convert('UTC','UTC',$contact['last-update'],'Y-m-d\TH:i:s\Z'));

		$url = $contact['poll'] . '?dfrn_id=' . $contact['dfrn-id'] . '&type=data&last_update=' . $last_update ;

		$xml = fetch_url($url);

		if(! $xml)
			continue;

		$res = simplexml_load_string($xml);

		if((intval($res->status) != 0) || (! strlen($res->challenge)) || ($res->dfrn_id != $contact['dfrn-id']))
			continue;

		$postvars = array();

		$postvars['dfrn_id'] = $contact['dfrn-id'];
		$challenge = hex2bin($res->challenge);

		openssl_public_decrypt($challenge,$postvars['challenge'],$contact['pubkey']);

		$xml = post_url($contact['poll'],$postvars);
		if(! strlen($xml))
			continue;

echo "XML response:" . $xml . "\r\n";
echo "Length:" . strlen($xml) . "\r\n";

		$feed = new SimplePie();
		$feed->set_raw_data($xml);
		$feed->enable_order_by_date(false);
		$feed->init();

		foreach($feed->get_items() as $item) {

			$deleted = false;

			$rawdelete = $item->get_item_tags("http://purl.org/atompub/tombstones/1.0", 'deleted-entry');
			if(isset($rawdelete[0]['attribs']['']['ref'])) {
				$uri = $rawthread[0]['attribs']['']['ref'];
				$deleted = true;
				if(isset($rawdelete[0]['attribs']['']['when'])) {
					$when = $rawthread[0]['attribs']['']['when'];
					$when = datetime_convert('UTC','UTC', $when, 'Y-m-d H:i:s');
				}
				else
					$when = datetime_convert('UTC','UTC','now','Y-m-d H:i:s');
			}
			if($deleted) {
				$r = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
					dbesc($uri),
					intval($importer['uid'])
				);
				if(count($r)) {
					if($r[0]['uri'] == $r[0]['parent-uri']) {
						$r = q("UPDATE `item` SET `deleted` = 1, `edited` = '%s'
							WHERE `parent-uri` = '%s'"
							dbesc($when),
							dbesc($r[0]['uri'])
						);
					}
					else {
						$r = q("UPDATE `item` SET `deleted` = 1, `edited` = '%s' 
							WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
							dbesc($when),
							dbesc($uri),
							intval($importer['uid'])
						);
					}
				}	
				continue;
			}


			$is_reply = false;		
			$item_id = $item->get_id();
			$rawthread = $item->get_item_tags("http://purl.org/syndication/thread/1.0",'in-reply-to');
			if(isset($rawthread[0]['attribs']['']['ref'])) {
				$is_reply = true;
				$parent_uri = $rawthread[0]['attribs']['']['ref'];
			}


			if($is_reply) {

				// Have we seen it? If not, import it.

				$item_id = $item->get_id();

				$r = q("SELECT `uid`, `last-child`, `edited` FROM `item` WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
					dbesc($item_id),
					intval($importer['uid'])
				);
				// FIXME update content if 'updated' changes
				if(count($r)) {
					$allow = $item->get_item_tags('http://purl.org/macgirvin/dfrn/1.0','comment-allow');
					if($allow && $allow[0]['data'] != $r[0]['last-child']) {
						$r = q("UPDATE `item` SET `last-child` = %d WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
							intval($allow[0]['data']),
							dbesc($item_id),
							intval($importer['uid'])
						);
					}
					continue;
				}
				$datarray = get_atom_elements($item);
				$datarray['parent-uri'] = $parent_uri;
				$datarray['uid'] = $importer['uid'];
				$datarray['contact-id'] = $contact['id'];
				$r = post_remote($a,$datarray);
				continue;
			}

			else {
				// Head post of a conversation. Have we seen it? If not, import it.

				$item_id = $item->get_id();
				$r = q("SELECT `uid`, `last-child`, `edited` FROM `item` WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
					dbesc($item_id),
					intval($importer['uid'])
				);
				if(count($r)) {
					$allow = $item->get_item_tags('http://purl.org/macgirvin/dfrn/1.0','comment-allow');
					if($allow && $allow[0]['data'] != $r[0]['last-child']) {
						$r = q("UPDATE `item` SET `last-child` = %d WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
							intval($allow[0]['data']),
							dbesc($item_id),
							intval($importer['uid'])
						);
					}
					continue;
				}

				$datarray = get_atom_elements($item);
				$datarray['parent-uri'] = $item_id;
				$datarray['uid'] = $importer['uid'];
				$datarray['contact-id'] = $contact['id'];
				$r = post_remote($a,$datarray);
				continue;

			}

		}

		$r = q("UPDATE `contact` SET `last-update` = '%s' WHERE `id` = %d LIMIT 1",
			dbesc(datetime_convert()),
			intval($contact['id'])
		);

	}
		
	killme();



